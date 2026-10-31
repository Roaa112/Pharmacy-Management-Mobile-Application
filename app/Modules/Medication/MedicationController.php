<?php

namespace App\Modules\Medication;

use Carbon\Carbon;
use App\Models\Medication;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Models\MedicationDay;
use App\Models\MedicationLog;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use App\Modules\Medication\Services\MedicationService;
use App\Modules\Medication\Resources\MedicationResource;
use App\Modules\Medication\Requests\StoreMedicationRequest;
use App\Modules\Medication\Requests\UpdateMedicationRequest;

class MedicationController extends Controller
{
    protected $service;

    public function __construct(MedicationService $service)
    {
        $this->service = $service;
    }

public function index(Request $request)
{
    $user = $request->user();

    if (!$user) {
        Log::error('Unauthenticated user attempt');
        return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
    }

    $weekDays = ['saturday', 'sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday'];

    // الأسبوع الحالي يبدأ السبت
    $weekStart = Carbon::today()->startOfWeek(Carbon::SATURDAY);

    // كل الأدوية وسجلات الأسبوع في استعلامين بدل استعلام لكل يوم ولكل موعد
    $medications = Medication::with('times', 'days')
        ->where('user_id', $user->id)
        ->get();

    $logStatuses = MedicationLog::where('user_id', $user->id)
        ->whereIn('medication_id', $medications->modelKeys())
        ->where('shown_date', '>=', $weekStart->toDateString())
        ->where('shown_date', '<', $weekStart->copy()->addDays(7)->toDateString())
        ->get()
        ->mapWithKeys(fn ($log) => [
            $this->logKey($log->medication_id, $log->shown_date, $log->getRawOriginal('time')) => $log->status,
        ]);

    $result = collect();

    foreach ($weekDays as $index => $day) {
        $date = $weekStart->copy()->addDays($index);

        $dayMedications = $medications
            ->filter(function ($medication) use ($day) {
                if ($medication->repeat_type === 'every_day') {
                    return true;
                }

                if ($medication->repeat_type === 'specific_days') {
                    return $medication->days->contains(fn ($d) => Str::lower($d->day) === $day);
                }

                if ($medication->repeat_type === 'once') {
                    return Str::lower((string) $medication->days->first()?->day) === $day;
                }

                return false;
            })
            ->map(function ($medication) use ($date, $weekDays, $logStatuses) {
                // نسخة لكل يوم حتى لا تتشارك الأيام نفس حالة المواعيد
                $copy = clone $medication;

                $copy->times = $medication->times->map(function ($time) use ($medication, $date, $logStatuses) {
                    $time = clone $time;
                    $time->status = $logStatuses[$this->logKey($medication->id, $date, $time->time)] ?? 'pending';

                    return $time;
                });

                if ($medication->repeat_type === 'every_day') {
                    $copy->days = collect($weekDays)->map(fn ($day) => ['day' => $day]);
                }

                return $copy;
            })->values();

        $result->push([
            'day' => $day,
            'medications' => MedicationResource::collection($dayMedications)
        ]);
    }

    return response()->json($result);
}


   

    public function store(StoreMedicationRequest $request)
    {
        $med = $this->service->create($request->validated(), auth()->user());
     return new MedicationResource($med->load('times', 'days'));
    }

    public function update(UpdateMedicationRequest $request, Medication $medication)
    {
        $this->ensureOwner($request, $medication);

        $updated = $this->service->update($medication, $request->validated());
        return response()->json($updated->load('times', 'days'));
    }

 public function updateLogStatus(Request $request, Medication $medication)
{
    $this->ensureOwner($request, $medication);

    $request->validate([
        'time' => 'required|date_format:H:i:s',
        'status' => 'required|in:taken,missed',
        'shown_date' => 'nullable|date_format:Y-m-d',
    ]);

    $user = $request->user();

    $shownDate = $request->input('shown_date') 
        ? Carbon::parse($request->input('shown_date'))->startOfDay() 
        : Carbon::today();

    // تحقق إذا التاريخ في المستقبل
    if ($shownDate->gt(Carbon::today())) {
        return response()->json([
            'success' => false,
            'message' => 'لا يمن تعديل حلة جرعة ي المستقبل',
        ], 422);
    }

    // إنشاء أو تحديث السجل
    $log = MedicationLog::firstOrCreate(
        [
            'user_id' => $user->id,
            'medication_id' => $medication->id,
            'shown_date' => $shownDate,
            'time' => $request->time,
        ],
        [
            'status' => $request->status,
        ]
    );

    if (!$log->wasRecentlyCreated) {
        $log->update(['status' => $request->status]);
    }

    return response()->json([
        'success' => true,
        'message' => 'تم تحديث حلة الجرعة بنجح',
    ]);
}
    public function destroy(Request $request, Medication $medication)
    {
        $this->ensureOwner($request, $medication);

        $medication->delete();
        return response()->json(['message' => 'Deleted successfully']);
    }
 public function deleteDayFromMedication(Request $request)
{
    $request->validate([
        // Not "exists": that would answer 422 vs 404 and reveal ids of other users' medications.
        'medication_id' => 'required|integer',
        'day' => 'required|in:saturday,sunday,monday,tuesday,wednesday,thursday,friday',
    ]);

    $medication = Medication::where('user_id', $request->user()->id)->find($request->medication_id);

    if (!$medication) {
        return response()->json(['success' => false, 'message' => 'Medication not found.'], 404);
    }

    // لو لنوع once  نحذفه باكامل
    if ($medication->repeat_type === 'once') {
        $medication->delete();
        return response()->json(['success' => true, 'message' => 'Medication deleted because it was one-time only.']);
    }

    // ل النع every_day → نحو لـ specific_days مع باقي الأيام
    if ($medication->repeat_type === 'every_day') {
        $allDays = ['saturday','sunday','monday','tuesday','wednesday','thursday','friday'];
        $remainingDays = array_filter($allDays, fn($d) => $d !== $request->day);

       
        MedicationDay::where('medication_id', $medication->id)->delete();

       
        foreach ($remainingDays as $day) {
            MedicationDay::create([
                'medication_id' => $medication->id,
                'day' => $day
            ]);
        }

    
        $medication->repeat_type = 'specific_days';
        $medication->save();

        return response()->json([
            'success' => true,
            'message' => 'Medication changed from every_day to specific_days, day removed.'
        ]);
    }

  
    $deleted = MedicationDay::where('medication_id', $medication->id)
        ->where('day', $request->day)
        ->delete();

    if ($deleted) {
        return response()->json(['success' => true, 'message' => 'Day removed from medication.']);
    }

    return response()->json(['success' => false, 'message' => 'Day not found for this medication.'], 404);
}

    /**
     * Medications are personal health data: only their owner may read or change them.
     */
    private function ensureOwner(Request $request, Medication $medication): void
    {
        abort_unless((int) $medication->user_id === $request->user()->id, 404);
    }

    /**
     * Identifies one dose on one date. Times are normalised to HH:MM:SS because a time
     * column may come back as "08:00" or "08:00:00" depending on the database.
     */
    private function logKey(int $medicationId, $date, string $time): string
    {
        return $medicationId . '|' . Carbon::parse($date)->toDateString() . '|' . Carbon::parse($time)->format('H:i:s');
    }

}
