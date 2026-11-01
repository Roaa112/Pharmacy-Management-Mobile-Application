<?php


namespace App\Modules\HealthService;

use App\Enums\Gender;
use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\BloodPressureMeasurement;
use App\Models\BloodSugarMeasurement;
use App\Models\BodyWeightResult;
use App\Models\Child;
use App\Models\OvulationResult;
use App\Models\PregnancyCalculation;
use App\Models\Vaccine;
use App\Modules\HealthService\Requests\HealthHistoryRequest;
use App\Modules\HealthService\Requests\StoreBloodPressureRequest;
use App\Modules\HealthService\Requests\StoreBloodSugarRequest;
use App\Modules\HealthService\Requests\StoreBodyWeightRequest;
use App\Modules\HealthService\Requests\StoreChildRequest;
use App\Modules\HealthService\Requests\StoreOvulationRequest;
use App\Modules\HealthService\Requests\StorePregnancyRequest;
use App\Modules\HealthService\Services\HealthService;
use App\Support\StoreClock;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HealthServiceController extends Controller
{
    public function __construct(protected HealthService $healthService)
    {
    }

    // ------------------------------------------------------------ dashboard
    // Customers' health records. The routes require the health_data permission; every page view
    // is written to the audit log.

    private function adminList($query, Request $request, string $page)
    {
        AdminAuditLog::record('health.view', null, ['page' => $page, 'search' => $request->input('search')]);

        $search = $request->input('search');

        return $query->when($search, fn ($q) => $q->whereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%")));
    }

    public function bodyWeight(Request $request)
    {
        $results = $this->adminList(BodyWeightResult::with('user:id,name'), $request, 'body_weight')->latest()->paginate(20);

        return view('dashboard.health-results.body-weight', compact('results'));
    }

    public function bloodSugar(Request $request)
    {
        $bloodSugars = $this->adminList(BloodSugarMeasurement::with('user:id,name'), $request, 'blood_sugar')->latest()->paginate(20);

        return view('dashboard.health-results.blood-sugar', compact('bloodSugars'));
    }

    public function bloodPressure(Request $request)
    {
        $bloodPressures = $this->adminList(BloodPressureMeasurement::with('user:id,name'), $request, 'blood_pressure')->latest()->paginate(20);

        return view('dashboard.health-results.blood-pressure', compact('bloodPressures'));
    }

    public function ovulation(Request $request)
    {
        $ovulations = $this->adminList(OvulationResult::with('user:id,name'), $request, 'ovulation')
            ->select('ovulation_results.*')
            ->join(DB::raw('(SELECT user_id, MAX(id) AS latest_id FROM ovulation_results GROUP BY user_id) latest'), 'ovulation_results.id', '=', 'latest.latest_id')
            ->paginate(20);

        return view('dashboard.health-results.ovulation', compact('ovulations'));
    }

    public function pregnancy(Request $request)
    {
        $pregnancies = $this->adminList(PregnancyCalculation::with('user:id,name'), $request, 'pregnancy')->latest()->paginate(20);

        return view('dashboard.health-results.pregnancy', compact('pregnancies'));
    }

    public function children(Request $request)
    {
        $children = $this->adminList(Child::with(['user:id,name', 'vaccines']), $request, 'children')->latest()->paginate(20);

        return view('dashboard.health-results.children', compact('children'));
    }

    // --------------------------------------------------------- measurements

    private function historyResponse($query, HealthHistoryRequest $request)
    {
        return response()->json($this->healthService->history(
            $query,
            $request->filled('page') ? $request->integer('page') : null,
            $request->filled('per_page') ? $request->integer('per_page') : null,
        ));
    }

    public function getBloodPressure(HealthHistoryRequest $request)
    {
        return $this->historyResponse($this->healthService->bloodPressureQuery($request->user()->id), $request);
    }

    public function getBloodSugar(HealthHistoryRequest $request)
    {
        return $this->historyResponse($this->healthService->bloodSugarQuery($request->user()->id), $request);
    }

    public function getWeight(HealthHistoryRequest $request)
    {
        return $this->historyResponse($this->healthService->weightQuery($request->user()->id), $request);
    }

    public function storeBodyWeight(StoreBodyWeightRequest $request)
    {
        return response()->json($this->healthService->calculateBodyWeight($request->user()->id, $request->validated()));
    }

    public function storeBloodSugar(StoreBloodSugarRequest $request)
    {
        return response()->json($this->healthService->storeBloodSugar($request->user()->id, $request->validated()));
    }

    public function storeBloodPressure(StoreBloodPressureRequest $request)
    {
        return response()->json($this->healthService->storeBloodPressure($request->user()->id, $request->validated()));
    }

    // ------------------------------------------------------------ ovulation

    public function storeOvulation(StoreOvulationRequest $request)
    {
        return response()->json($this->healthService->calculateOvulation($request->user()->id, $request->validated()));
    }

    public function getCycleForCurrentMonth(Request $request)
    {
        $result = OvulationResult::where('user_id', $request->user()->id)
            ->orderByDesc('start_day_of_cycle')
            ->first();

        if (!$result) {
            return ['message' => api_trans('health.messages.cycle_no_data'), 'code' => 'no_cycle_data'];
        }

        $startDate = $result->start_day_of_cycle->copy()->startOfDay();
        $cycleLength = (int) $result->cycle_length;
        $periodLength = (int) ($result->result['period_length'] ?? 5);

        // "Today" on the pharmacy's clock (not UTC), so it changes at local midnight.
        $today = StoreClock::now()->startOfDay();

        // Previous, current and next month.
        $rangeStart = $today->copy()->subMonthNoOverflow()->startOfMonth();
        $rangeEnd = $today->copy()->addMonthNoOverflow()->endOfMonth();

        // Start of the cycle that contains the first day of the range (projected either way).
        $cyclesUntilRange = (int) floor($startDate->diffInDays($rangeStart, false) / $cycleLength);
        $cycleStart = $startDate->copy()->addDays($cyclesUntilRange * $cycleLength);

        $allDays = [];
        $todayInfo = null;

        while ($cycleStart <= $rangeEnd) {
            $ovulationDay = $this->healthService->ovulationDay($cycleStart, $cycleLength);

            for ($i = 0; $i < $cycleLength; $i++) {
                $day = $cycleStart->copy()->addDays($i);

                if ($day < $rangeStart || $day > $rangeEnd) {
                    continue;
                }

                $info = $this->healthService->ovulationDayPhase($i, $day, $ovulationDay, $periodLength, 'calendar');
                $isToday = $day->isSameDay($today);

                if ($isToday) {
                    $todayInfo = $info;
                }

                $allDays[] = [
                    'date' => $day->format('Y-m-d'),
                    'month' => $day->format('Y-m'),
                    'phase' => $info['phase'],
                    'phase_code' => $info['phase_code'],
                    'pregnancy_chance' => $info['pregnancy_chance'],
                    'is_today' => $isToday,
                    'phase_label' => $info['phase_label'],
                ];
            }

            $cycleStart->addDays($cycleLength);
        }

        return [
            'message' => api_trans('health.messages.cycle_calculated'),
            'months' => collect($allDays)->groupBy('month'),
            'today' => [
                'date' => $today->toDateString(),
                'phase' => $todayInfo['phase'] ?? __('health.legacy.ovulation_calendar.unknown', [], 'ar'),
                'phase_code' => $todayInfo['phase_code'] ?? 'unknown',
                'pregnancy_chance' => $todayInfo['pregnancy_chance'] ?? '0%',
                'phase_label' => $todayInfo['phase_label'] ?? api_trans('health.phase.unknown'),
            ],
        ];
    }

    // ------------------------------------------------------------ pregnancy

    public function storePregnancy(StorePregnancyRequest $request)
    {
        return response()->json($this->healthService->calculatePregnancy($request->user()->id, $request->validated()));
    }

    public function getCurrentPregnancyStage(Request $request)
    {
        $pregnancy = PregnancyCalculation::where('user_id', $request->user()->id)->latest()->first();

        if (!$pregnancy) {
            return response()->json(['message' => api_trans('health.pregnancy.no_data'), 'code' => 'no_pregnancy_data'], 404);
        }

        // Recalculated from the stored date, so the stage moves on day by day.
        return response()->json($this->healthService->recalculatePregnancyFromStoredData($pregnancy));
    }

    // ------------------------------------------------------- children/vaccines

    public function storeVaccination(StoreChildRequest $request)
    {
        $child = DB::transaction(function () use ($request) {
            $child = Child::create([
                'user_id' => $request->user()->id,
                'child_name' => $request->child_name,
                // Stored as the Arabic word, as always.
                'gender' => Gender::fromInput($request->gender)->storedValue(),
                'birth_date' => Carbon::parse($request->birth_date)->format('Y-m-d'),
            ]);

            // The schedule is copied from the dashboard-managed template (a snapshot).
            $this->healthService->generateDefaultVaccines($child);

            return $child;
        });

        return response()->json([
            'message' => api_trans('health.messages.child_created'),
            'child' => $child->load(['vaccines' => fn ($q) => $q->orderBy('scheduled_date')]),
        ]);
    }

    /** The caller's children with their vaccines (by date) and completion progress. */
    public function getVaccines(Request $request)
    {
        $children = Child::where('user_id', $request->user()->id)
            ->with(['vaccines' => fn ($query) => $query->orderBy('scheduled_date', 'asc')])
            ->get()
            ->map(function ($child) {
                $total = $child->vaccines->count();
                $completed = $child->vaccines->where('is_completed', true)->count();
                $child->progress = $total > 0 ? round(($completed / $total) * 100, 2) : 0;

                return $child;
            });

        return response()->json([
            'message' => api_trans('health.messages.children_loaded'),
            'children' => $children,
        ]);
    }

    /** Mark a vaccine as given (or undo it). */
    public function markAsCompleted(Request $request, Vaccine $vaccine)
    {
        // The vaccine must belong to one of the caller's children.
        abort_unless((int) $vaccine->child?->user_id === $request->user()->id, 404);

        $validated = $request->validate([
            'is_completed' => 'sometimes|boolean',
        ]);

        $completed = (bool) ($validated['is_completed'] ?? true);

        $vaccine->update([
            'is_completed' => $completed,
            'completed_at' => $completed ? ($vaccine->completed_at ?? now()) : null,
        ]);

        return response()->json([
            'message' => api_trans('health.messages.vaccine_updated'),
            'vaccine' => $vaccine,
        ]);
    }

    public function destroychild(Request $request, $id)
    {
        $child = Child::where('user_id', $request->user()->id)->find($id);

        if (!$child) {
            return response()->json(['message' => api_trans('health.messages.child_not_found')], 404);
        }

        $child->delete(); // Cascades to vaccines.

        return response()->json(['message' => api_trans('health.messages.child_deleted')], 200);
    }
}
