<?php


namespace App\Modules\HealthService;

use Carbon\Carbon;
use App\Models\Child;
use App\Models\OvulationResult;
use Illuminate\Support\Facades\DB;
use App\Models\Vaccine;
use Illuminate\Http\Request;
use App\Models\PregnancyCalculation;
use App\Models\User;
use App\Models\BodyWeightResult;
use App\Models\BloodSugarMeasurement;
use App\Models\BloodPressureMeasurement;
use App\Models\VaccinationSchedule;
use App\Http\Controllers\Controller;
use App\Modules\HealthService\Services\HealthService;

class HealthServiceController extends Controller
{
    protected $healthService;

    public function __construct(HealthService $healthService)
    {
        $this->healthService = $healthService;
    }
    public function bodyWeight(Request $request)
    {
        $search = $request->input('search');
        $query = BodyWeightResult::with('user:id,name');

        if ($search) {
            $query->whereHas('user', fn($q) => $q->where('name', 'like', "%{$search}%"));
        }

        $results = $query->latest()->paginate(20);

        return view('dashboard.health-results.body-weight', compact('results'));
    }

    public function bloodSugar(Request $request)
    {
        $search = $request->input('search');
        $query = BloodSugarMeasurement::with('user:id,name');

        if ($search) {
            $query->whereHas('user', fn($q) => $q->where('name', 'like', "%{$search}%"));
        }

        $bloodSugars = $query->latest()->paginate(20);

        return view('dashboard.health-results.blood-sugar', compact('bloodSugars'));
    }

    public function bloodPressure(Request $request)
    {
        $search = $request->input('search');
        $query = BloodPressureMeasurement::with('user:id,name');

        if ($search) {
            $query->whereHas('user', fn($q) => $q->where('name', 'like', "%{$search}%"));
        }

        $bloodPressures = $query->latest()->paginate(20);

        return view('dashboard.health-results.blood-pressure', compact('bloodPressures'));
    }

    public function ovulation(Request $request)
    {
        $search = $request->input('search');
        $ovulations = OvulationResult::with('user:id,name')
            ->select('ovulation_results.*')
            ->join(DB::raw('(SELECT user_id, MAX(id) AS latest_id FROM ovulation_results GROUP BY user_id) latest'), 'ovulation_results.id', '=', 'latest.latest_id')
            ->when($search, fn($q) => $q->whereHas('user', fn($u) => $u->where('name', 'like', "%{$search}%")))
            ->paginate(20);

        return view('dashboard.health-results.ovulation', compact('ovulations'));
    }

    public function pregnancy(Request $request)
    {
        $search = $request->input('search');
        $query = PregnancyCalculation::with('user:id,name');

        if ($search) {
            $query->whereHas('user', fn($q) => $q->where('name', 'like', "%{$search}%"));
        }

        $pregnancies = $query->latest()->paginate(20);

        return view('dashboard.health-results.pregnancy', compact('pregnancies'));
    }

    public function children(Request $request)
    {
        $search = $request->input('search');
        $query = Child::with(['user:id,name', 'vaccines']);

        if ($search) {
            $query->whereHas('user', fn($q) => $q->where('name', 'like', "%{$search}%"));
        }

        $children = $query->latest()->paginate(20);

        return view('dashboard.health-results.children', compact('children'));
    }
    public function getBloodPressure(Request $request)
    {
        $results = $this->healthService->getBloodPressureData($request->user()->id);
        return response()->json(['data' => $results]);
    }

    public function getBloodSugar(Request $request)
    {
        $results = $this->healthService->getBloodSugarData($request->user()->id);
        return response()->json(['data' => $results]);
    }

    public function getWeight(Request $request)
    {
        $results = $this->healthService->getWeightData($request->user()->id);
        return response()->json(['data' => $results]);
    }



    public function getOvulationResults()
    {
        $results = $this->healthService->getAllOvulationResults();
        return response()->json(['data' => $results]);
    }
    public function storeOvulation(Request $request)
    {
        $validated = $request->validate([
            'start_day_of_cycle' => 'required|date',
            'cycle_length' => 'required|integer|min:21|max:35',
            'period_length' => 'required|integer|min:3|max:10',
        ]);

        return response()->json(
            $this->healthService->calculateOvulation($request->user()->id, $validated)
        );
    }
    public function getCycleForCurrentMonth(Request $request)
    {
        $userId = $request->user()->id;

        $result = OvulationResult::where('user_id', $userId)
            ->orderByDesc('start_day_of_cycle')
            ->first();

        if (!$result) {
            return ['message' => 'محتاج تدخل بيانات الدورة لأول مرة'];
        }

        $startDate = $result->start_day_of_cycle->copy();
        $cycleLength = $result->cycle_length;
        $periodLength = $result->period_length ?? 5;

        $today = now()->startOfDay();
        $daysPassed = $today->diffInDays($startDate);

        // جيب أب سايكل لليوم
        $cyclesPassed = floor($daysPassed / $cycleLength);
        $currentCycleStart = $startDate->copy()->addDays($cyclesPassed * $cycleLength);

        $allDays = [];

        // الشهور اللي عايي نجيها (قبل / احاي / بع)
        $months = [
            $today->copy()->subMonthNoOverflow(),
            $today->copy(),
            $today->copy()->addMonthNoOverflow(),
        ];

        foreach ($months as $monthDate) {
            $monthStart = $monthDate->copy()->startOfMonth();
            $monthEnd   = $monthDate->copy()->endOfMonth();

            // نولّ أيام كل سيكل تقاع الشهر المطلوب
            $cycleStart = $currentCycleStart->copy();
            while ($cycleStart <= $monthEnd) {
                $ovulationDay = $cycleStart->copy()->addDays($cycleLength - 14);
                $fertileStart = $ovulationDay->copy()->subDays(5);
                $fertileEnd   = $ovulationDay->copy()->addDays(2);

                for ($i = 0; $i < $cycleLength; $i++) {
                    $day = $cycleStart->copy()->addDays($i);

                    if ($day < $monthStart || $day > $monthEnd) {
                        continue; // نتكد إنه جوه الشهر
                    }

                    $phase = 'غير خصبة';
                    $chance = '0%';

                    if ($i < $periodLength) {
                        $phase = 'حيض';
                    } elseif ($day->between($fertileStart, $fertileEnd)) {
                        $daysFromOvulation = $day->diffInDays($ovulationDay, false);

                        if ($day->equalTo($ovulationDay)) {
                            $phase = 'تبويض';
                            $chance = '30%';
                        } elseif (in_array($daysFromOvulation, [-1, -2])) {
                            $phase = 'خصوبة عالة';
                            $chance = '25%';
                        } elseif (in_array($daysFromOvulation, [-3, -4])) {
                            $phase = 'خصوبة متوسطة';
                            $chance = '15%';
                        } elseif ($daysFromOvulation == -5) {
                            $phase = 'خصوبة منخفضة';
                            $chance = '10%';
                        } elseif (in_array($daysFromOvulation, [1, 2])) {
                            $phase = 'بداية التبويض';
                            $chance = '5%';
                        }
                    } elseif ($day->greaterThan($fertileEnd)) {
                        $phase = 'ما بعد التبويض';
                    }

                    $allDays[] = [
                        'date' => $day->format('Y-m-d'),
                        'month' => $day->format('Y-m'),
                        'phase' => $phase,
                        'pregnancy_chance' => $chance,
                        'is_today' => $day->isSameDay($today),
                    ];
                }

                // ✅ هنا بقت في الآخر
                $cycleStart->addDays($cycleLength);
            }
        }

        return [
            'message' => 'تم حساب الدورة ',
            'months' => collect($allDays)->groupBy('month'),
            'today' => [
                'date' => $today->toDateString(),
                'phase' => $allDays[array_search(true, array_column($allDays, 'is_today'))]['phase'] ?? 'غير معروف',
                'pregnancy_chance' => $allDays[array_search(true, array_column($allDays, 'is_today'))]['pregnancy_chance'] ?? '0%',
            ],
        ];
    }





    public function storeBodyWeight(Request $request)
    {
        $data = $request->only(['height', 'weight', 'unit']);
        return response()->json(
            $this->healthService->calculateBodyWeight($request->user()->id, $data)
        );
    }

    public function storeBloodSugar(Request $request)
    {
        $data = $request->only(['value', 'condition_type', 'measured_at']);
        return response()->json(
            $this->healthService->storeBloodSugar($request->user()->id, $data)
        );
    }

    public function storeBloodPressure(Request $request)
    {
        $data = $request->only(['systolic', 'diastolic', 'condition_type', 'measured_at']);
        return response()->json(
            $this->healthService->storeBloodPressure($request->user()->id, $data)
        );
    }

    public function storePregnancy(Request $request)
    {
        $data = $request->only(['last_period_date']);
        return response()->json(
            $this->healthService->calculatePregnancy($request->user()->id, $data)
        );
    }
    public function getCurrentPregnancyStage(Request $request)
    {
        $user = $request->user();

        // جيب آخر حساب م تسجيله للمستخد
        $pregnancy = PregnancyCalculation::where('user_id', $user->id)->latest()->first();

        if (!$pregnancy) {
            return response()->json(['message' => 'لم يتم إدخال تاريخ آخر دورة بعد.'], 404);
        }

        // نعيد حساب المرلة بناءً على التاريخ المسجل مسبقًا
        return response()->json(
            $this->healthService->recalculatePregnancyFromStoredData($pregnancy)
        );
    }

    // public function storeVaccination(Request $request)
    // {
    //     $request->validate([
    //         'child_name' => 'required|string',
    //         'gender' => 'required|in:ذكر,نثى',
    //         'birth_date' => 'required|date',
    //     ]);
    //     $birth_date = Carbon::parse($request->birth_date)->format('Y-m-d');

    //     $child = Child::create([
    //         'user_id' => auth()->id(),
    //         'child_name' => $request->child_name,
    //         'gender' => $request->gender,
    //         'birth_date' => $birth_date,
    //     ]);

    //     $this->generateDefaultVaccines($child);

    //     return response()->json([
    //         'message' => 'تم إنشء الطفل وجدول لتطعيمات بنجاح',
    //         'child' => $child,
    //     ]);
    // }

    // protected function generateDefaultVaccines(Child $child)
    // {
    //     $defaultVaccines = [
    //         [
    //             'name' => 'لقاح عن اولاد',
    //             'offset' => '0 days',
    //             'description' => 'لاح الهاب الكبد الفروي B (الجرعة الرية) خلال أول 24 ساة من الولادة.'
    //         ],
    //         [
    //             'name' => 'لقح الأسبوع الأول',
    //             'offset' => '7 days',
    //             'description' => 'لقاح شلل الفال الفموي (الرة لصفرية) + لقاح لسل BCG.'
    //         ],
    //         [
    //             'name' => 'لقاح عمر رين',
    //             'offset' => '2 months',
    //             'description' => 'الجرعة الأولى من: شلل الأطفال الفموي، لل اأفال العضي الخمسي (الدفتيريا، الكزاز، السعال الديكي، الكبد B، المستدمية النزلية)، الكورت الئوة، الرتا.'
    //         ],
    //         [
    //             'name' => 'قاح مر 4 أشه',
    //             'offset' => '4 months',
    //             'description' => 'الجرعة الثانة ن: شلل الطفال الموي، شلل الأطال اعضلي، الخماي، الكورات الرئية، الوتا.'
    //         ],
    //         [
    //             'name' => 'لقا عمر 6 أشر',
    //             'offset' => '6 months',
    //             'description' => 'الجعة الثالثة من: شل الطفال افموي، شلل الأطفا الضلي، الخماسي الرتا.'
    //         ],
    //         [
    //             'name' => 'لقاح عم 9 أشه',
    //             'offset' => '9 months',
    //             'description' => 'رعة منطة: شلل الطفال الموي + لقا الحصة.'
    //         ],
    //         [
    //             'name' => 'لقاح عم 12 شهر',
    //             'offset' => '12 months',
    //             'description' => 'قاح الحبة المخطة (حصبة، صب ألنية، نكاف MMR) + لجرعة المنشطة اولى للمكورات الرية.'
    //         ],
    //         [
    //             'name' => 'لقاح عمر 18 شه',
    //             'offset' => '18 months',
    //             'description' => 'جرعة نطة من: شلل الأطال الفموي، شلل الطفل العضلي، الثاثي (DTaP)، الجرعة المشطة الثانية للمكرات الرئوية.'
    //         ],
    //         [
    //             'name' => 'قاح عمر 4-6 سنوات',
    //             'offset' => '4 years',
    //             'description' => 'جرعة منشطة أخية: شلل الأطفال الموي، لل الطفال اعضلي، الثاثي (DTaP).'
    //         ],
    //     ];

    //     foreach ($defaultVaccines as $vaccine) {
    //         Vaccine::create([
    //             'child_id' => $child->id,
    //             'vaccine_name' => $vaccine['name'],
    //             'description' => $vaccine['description'],
    //             'scheduled_date' => \Carbon\Carbon::parse($child->birth_date)->add($vaccine['offset']),
    //         ]);
    //     }
    // }


    // public function getVaccines(Child $child)
    // {


    //     return response()->json($child->vaccines);
    // }

    // public function markAsCompleted(Request $request, Vaccine $vaccine)
    // {
    //     $vaccine->update([
    //         'is_completed' => $request->input('is_completed', true),
    //     ]);

    //     return response()->json([
    //         'message' => 'تم تديث حال لتطعيم',
    //         'vaccine' => $vaccine,
    //     ]);
    // }


    public function storeVaccination(Request $request)
    {
        $request->validate([
            'child_name' => 'required|string',
            'gender' => 'required|in:ذكر,أنثى',
            'birth_date' => 'required|date',
        ]);

        $birth_date = Carbon::parse($request->birth_date)->format('Y-m-d');

        $child = Child::create([
            'user_id' => auth()->id(),
            'child_name' => $request->child_name,
            'gender' => $request->gender,
            'birth_date' => $birth_date,
        ]);

        // تليد جدول اتطعيما تلقائيً
        $this->generateDefaultVaccines($child);

        return response()->json([
            'message' => 'تم إنشاء الطفل وجدول التطعيمات بنجاح',
            'child' => $child->load('vaccines'),
        ]);
    }

    /**
     * إنشاء جدول التطيمات الافتراي لك طف
     */
    protected function generateDefaultVaccines(Child $child)
    {
        $defaultVaccines = [
            [
                'name' => 'لقاح عند الولادة',
                'offset' => '0 days',
                'description' => 'لقاح التهاب الكبد الفيروسي B (الجرعة الصفرية) خلال أول 24 ساعة من الولادة.'
            ],
            [
                'name' => 'لقاح الأسبوع الأول',
                'offset' => '7 days',
                'description' => 'لقاح شلل الأطفال الفموي (الجرعة الصفرية) + لقاح السل (BCG).'
            ],
            [
                'name' => 'لقاح عمر شهرين',
                'offset' => '2 months',
                'description' => 'الجرعة الأولى من: شلل الأطفال الفموي، شلل الأطفال العضلي، الخماسي (الدفتيريا، الكزاز، السعال الديكي، التهاب الكبد B، المستدمية النزلية)، المكورات الرئوية، والروتا.'
            ],
            [
                'name' => 'لقاح عمر 4 أشهر',
                'offset' => '4 months',
                'description' => 'الجرعة الثانية من: شلل الأطفال الفموي، شلل الأطفال العضلي، الخماسي، المكورات الرئوية، والروتا.'
            ],
            [
                'name' => 'لقاح عمر 6 أشهر',
                'offset' => '6 months',
                'description' => 'الجرعة الثالثة من: شلل الأطفال الفموي، شلل الأطفال العضلي، الخماسي، والروتا.'
            ],
            [
                'name' => 'لقاح عمر 9 أشهر',
                'offset' => '9 months',
                'description' => 'جرعة منشطة من شلل الأطفال الفموي + لقاح الحصبة.'
            ],
            [
                'name' => 'لقاح عمر 12 شهرًا',
                'offset' => '12 months',
                'description' => 'لقاح الحصبة المختلطة (حصبة، حصبة ألمانية، نكاف - MMR) + الجرعة المنشطة الأولى من المكورات الرئوية.'
            ],
            [
                'name' => 'لقاح عمر 18 شهرًا',
                'offset' => '18 months',
                'description' => 'جرعة منشطة من: شلل الأطفال الفموي، شلل الأطفال العضلي، الثلاثي (DTaP)، والجرعة المنشطة الثانية من المكورات الرئوية.'
            ],
            [
                'name' => 'لقاح عمر 4-6 سنوات',
                'offset' => '4 years',
                'description' => 'الجرعة المنشطة الأخيرة من: شلل الأطفال الفموي، شلل الأطفال العضلي، والثلاثي (DTaP).'
            ],
        ];


        foreach ($defaultVaccines as $vaccine) {
            Vaccine::create([
                'child_id' => $child->id,
                'vaccine_name' => $vaccine['name'],
                'description' => $vaccine['description'],
                'scheduled_date' => Carbon::parse($child->birth_date)->add($vaccine['offset']),
                'is_completed' => false,
            ]);
        }
    }

    /**
     * جلب كل الأطفا المتبطين بالمستدم لحالي ع التطعمت الخاصة بكل طفل
     */
    public function getVaccines()
    {
        $children = Child::where('user_id', auth()->id())
            ->with(['vaccines' => function ($query) {
                $query->orderBy('scheduled_date', 'asc');
            }])
            ->get()
            ->map(function ($child) {
                $total = $child->vaccines->count();
                $completed = $child->vaccines->where('is_completed', true)->count();
                $child->progress = $total > 0 ? round(($completed / $total) * 100, 2) : 0;
                return $child;
            });

        return response()->json([
            'message' => 'تم جلب بيانات الأطفال والتطعيمات',
            'children' => $children,
        ]);
    }

    /**
     * تحدث حال التطعيم عند لتعليم عليه كمتم أو إلغاء التليم
     */
    public function markAsCompleted(Request $request, Vaccine $vaccine)
    {
        $vaccine->update([
            'is_completed' => $request->input('is_completed', true),
        ]);

        return response()->json([
            'message' => 'تم تحديث حالة التطعيم',
            'vaccine' => $vaccine,
        ]);
    }


    public function destroychild($id)
    {
        $child = Child::find($id);

        if (!$child) {
            return response()->json([
                'message' => 'الطفل غير موجود'
            ], 404);
        }

        $child->delete(); // Cascades to vaccines automatically

        return response()->json([
            'message' => 'تم حذف الطفل بنجاح'
        ], 200);
    }
}
