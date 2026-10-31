<?php


namespace App\Modules\HealthService\Services;

use Carbon\Carbon;
use App\Models\Child;
use App\Models\Vaccine;
use App\Models\OvulationResult;
use App\Models\BodyWeightResult;
use App\Models\VaccinationSchedule;
use App\Models\PregnancyCalculation;
use App\Models\BloodSugarMeasurement;
use App\Models\BloodPressureMeasurement;
use App\Support\StoreClock;

class HealthService
{


public function getBloodPressureData($userId)
{
    return BloodPressureMeasurement::where('user_id', $userId)
        ->orderBy('created_at', 'desc')
        ->get();
}

public function getBloodSugarData($userId)
{
    return BloodSugarMeasurement::where('user_id', $userId)
        ->orderBy('created_at', 'desc')
        ->get();
}

public function getWeightData($userId)
{
    return BodyWeightResult::where('user_id', $userId)
        ->orderBy('created_at', 'desc')
        ->get();
}


    public function getAllOvulationResults()
    {
        return OvulationResult::where('user_id', auth()->id())->latest()->get();
    }

    public function getAllBodyWeightResults()
    {
        return BodyWeightResult::where('user_id', auth()->id())->latest()->get();
    }

    public function getAllBloodSugarResults()
    {
        return BloodSugarMeasurement::where('user_id', auth()->id())->latest()->get();
    }

    public function getAllBloodPressureResults()
    {
        return BloodPressureMeasurement::where('user_id', auth()->id())->latest()->get();
    }

    public function getAllPregnancyCalculations()
    {
        return PregnancyCalculation::where('user_id', auth()->id())->latest()->get();
    }

    public function getAllVaccinationSchedules()
    {
        return VaccinationSchedule::where('user_id', auth()->id())->latest()->get();
    }
public function calculateOvulation($userId, array $validated)
{
    $startDate = Carbon::parse($validated['start_day_of_cycle'])->startOfDay();
    $cycleLength = (int) $validated['cycle_length'];
    $periodLength = (int) $validated['period_length'];

    $ovulationDay = $startDate->copy()->addDays($cycleLength - 14);
    $fertileStart = $ovulationDay->copy()->subDays(5);
    $fertileEnd = $ovulationDay->copy()->addDays(2);

    $phases = [];
    $days = [];

    for ($i = 0; $i < $cycleLength; $i++) {
        $day = $startDate->copy()->addDays($i);
        $info = $this->ovulationDayPhase($i, $day, $ovulationDay, $periodLength, 'calculation');

        $formattedDate = $day->format('Y-m-d');

        $phases[$formattedDate] = $info;

        $days[] = ['date' => $formattedDate] + $info;
    }
$nextPeriodStart = $startDate->copy()->addDays($cycleLength);

   $result = OvulationResult::create([
    'user_id' => $userId,
    'start_day_of_cycle' => $startDate,
    'cycle_length' => $cycleLength,
    'result' => [
        'period_length' => $periodLength,
        'ovulation_date' => $ovulationDay->toDateString(),
        'fertile_start' => $fertileStart->toDateString(),
        'fertile_end' => $fertileEnd->toDateString(),
        'next_period_start' => $nextPeriodStart->toDateString(), // ✅ اإضافة
        'cycle_phase_by_day' => $phases,
    ],
]);

 return [
    'message' => 'تم حساب التبويض بنجاح',
    'data' => [
        'period' => [
            'user_id' => $userId,
            'start_day_of_cycle' => $startDate->toISOString(),
            'cycle_length' => $cycleLength,
            'period_length' => $periodLength,
        ],
        'ovulation_date' => $ovulationDay->toDateString(),
        'fertile_start' => $fertileStart->toDateString(),
        'fertile_end' => $fertileEnd->toDateString(),
            'next_period_start' => $nextPeriodStart->toDateString(), // ✅ الإضافة
        'days' => $days,
    ],
];

}

/**
 * Chance of pregnancy per cycle phase. Ovulation is cycle_length - 14 (14-day luteal phase);
 * the fertile window is the 5 days before ovulation, ovulation day and 2 days after.
 * Values follow the shape of Wilcox et al. (NEJM 1995): highest on the 2 days before and on
 * ovulation day, and close to zero once the egg is gone (1-2 days after).
 */
public const OVULATION_PHASE_CHANCES = [
    'menstruation' => '0%',
    'not_fertile' => '0%',
    'fertile_low' => '10%',     // 5 days before ovulation
    'fertile_medium' => '15%',  // 4-3 days before
    'fertile_high' => '25%',    // 2-1 days before
    'ovulation' => '30%',
    'fertile_after' => '5%',    // 1-2 days after
    'post_ovulation' => '0%',
];

/**
 * Display labels. Each endpoint keeps the Arabic wording it has always returned, because the
 * app may match on these strings; phase_code is the stable value to use instead.
 */
public const OVULATION_PHASE_LABELS = [
    // POST /user/health/ovulation
    'calculation' => [
        'menstruation' => 'حيض',
        'not_fertile' => 'غير مخصبة',
        'fertile_low' => 'خصوبة منخفضة',
        'fertile_medium' => 'خصوبة متوسطة',
        'fertile_high' => 'خصوبة عالية',
        'ovulation' => 'تبويض',
        'fertile_after' => 'نهاية الخصوبة',
        'post_ovulation' => 'ما بعد التبويض',
    ],
    // GET /user/health/ovulation/current (wording kept exactly as the app received it)
    'calendar' => [
        'menstruation' => 'حيض',
        'not_fertile' => 'غير خصبة',
        'fertile_low' => 'خصوبة منخفضة',
        'fertile_medium' => 'خصوبة متوسطة',
        'fertile_high' => 'خصوبة عالة',
        'ovulation' => 'تبويض',
        'fertile_after' => 'بداية التبويض',
        'post_ovulation' => 'ما بعد التبويض',
    ],
];

/**
 * حساب مرحلة اليوم ونسبة الحمل داخل الدورة
 * $dayIndex: رقم اليوم من بداية الدورة (0 = أول يوم)
 */
public function ovulationDayPhase(int $dayIndex, Carbon $day, Carbon $ovulationDay, int $periodLength, string $labels = 'calculation'): array
{
    // سالب = قبل التبويض، موجب = بعد التبويض
    $daysFromOvulation = (int) $ovulationDay->diffInDays($day, false);

    $code = match (true) {
        // أيام الحيض لها الأولوية حتى لو تداخلت مع نافذة الخصوبة في الدورات القصيرة
        $dayIndex < $periodLength => 'menstruation',
        $daysFromOvulation === 0 => 'ovulation',
        in_array($daysFromOvulation, [-1, -2], true) => 'fertile_high',
        in_array($daysFromOvulation, [-3, -4], true) => 'fertile_medium',
        $daysFromOvulation === -5 => 'fertile_low',
        in_array($daysFromOvulation, [1, 2], true) => 'fertile_after',
        $daysFromOvulation > 2 => 'post_ovulation',
        default => 'not_fertile',
    };

    return [
        'phase' => self::OVULATION_PHASE_LABELS[$labels][$code],
        'phase_code' => $code,
        'pregnancy_chance' => self::OVULATION_PHASE_CHANCES[$code],
    ];
}


   public function calculateBodyWeight($userId, array $data)
{
    $heightMeters = $data['unit'] === 'metric' ? $data['height'] / 100 : $data['height'] * 0.0254;
    $weightKg = $data['unit'] === 'metric' ? $data['weight'] : $data['weight'] * 0.453592;
    $bmi = round($weightKg / ($heightMeters * $heightMeters), 1); // زي الصورة: رقم عري واحد

    // وصف الحالة حسب قيمة BMI (تصنيف منظمة الصحة العالمية للبالغين)
    $status = '';
    $description = '';

    if ($bmi < 18.5) {
        $status = 'نقص في الوزن';
        $statusCode = 'underweight';
        $description = 'مؤشر كتل الجسم الخاص بك أل من 18.5، وهذا يعني أن وزنك أقل من الطبيعي. يُفضل مراجعة طبيب.';
    } elseif ($bmi < 25) {
        $status = 'وزن طبيعي';
        $statusCode = 'normal';
        $description = 'وزنك ضمن النطاق الصحي. اتسمر في الحفاظ على أسلوب حياة متوازن.';
    } elseif ($bmi < 30) {
        $status = 'زيادة في الوزن';
        $statusCode = 'overweight';
        $description = 'مؤشر كتلة الجسم بين 25 و30 يشير إلى زيادة في الوزن،  قد يؤدي لمشاكل صحية لاحقًا.';
    } elseif ($bmi < 35) {
        $status = 'سمنة من الدرجة الاولى';
        $statusCode = 'obese_class_1';
        $description = 'وزنك يُصنف كسمنة من الدرجة الأولى. يُنصح باتباع نظام غذائي صحي.';
    } elseif ($bmi < 40) {
        $status = 'سمنة من الدرجة الثانية';
        $statusCode = 'obese_class_2';
        $description = 'سمنة متوسطة، ُيفضل مراجعة مختص تغذية واتباع خطة صحية.';
    } else {
        $status = 'سمنة من الدرجة الثالثة';
        $statusCode = 'obese_class_3';
        $description = 'سمنة مفرطة. يُنصح بمراجعة الطبيب لاتخاذ خطوات تحسين صحتك.';
    }

    // حساب نطاق الوزن المثالي (BMI بن 18.5 و 24.9)
    $minIdealWeight = round(18.5 * ($heightMeters ** 2), 1);
    $maxIdealWeight = round(24.9 * ($heightMeters ** 2), 1);
    $idealRange = "{$minIdealWeight} - {$maxIdealWeight} كجم";

    // حفظ البيانات في قاعدة البيانات. الحالة والوصف والنطاق المثالي تُحسب من bmi_result ولا
    // توجد لها أعمدة في الجدول، فتُرجع في الاستجابة فقط.
    $result = BodyWeightResult::create([
        'user_id' => $userId,
        'height' => $data['height'],
        'weight' => $data['weight'],
        'unit' => $data['unit'],
        'bmi_result' => $bmi,
    ]);

    return [
        'message' => 'تم حساب مؤشر كتلة الجسم بنجاح',
        'data' => [
            'bmi' => $bmi,
            'status' => $status,
            'status_code' => $statusCode,
            'description' => $description,
            'ideal_range' => $idealRange,
        ],
    ];
}

  public function storeBloodSugar($userId, array $data)
{
    $data['user_id'] = $userId;
    $record = BloodSugarMeasurement::create($data);

    ['label' => $evaluation, 'code' => $evaluationCode] = $this->evaluateBloodSugar($data['value'], $data['condition_type']);

    return [
        'message' => 'تم تسجيل قياس السكر بنجاح',
        'data' => [
            'value' => $record->value,
            'condition_type' => $record->condition_type,
            'measured_at' => $record->measured_at,
            'evaluation' => $evaluation,
            'evaluation_code' => $evaluationCode,
        ]
    ];
}

public const BLOOD_SUGAR_LABELS = [
    'low' => 'منخفض',
    'normal' => 'طبيعي',
    'high' => 'مرتفع',
    'unknown' => 'غير معروف',
];

/**
 * mg/dL, ADA Standards of Care: below 70 is hypoglycaemia at any time; fasting normal is
 * below 100; 2 hours after a meal normal is below 140; a random value of 200+ is high.
 */
private function evaluateBloodSugar($value, $condition): array
{
    // ترجمة القيم القدمة من اواجهة الإنجليزية إلى العربية
    $condition = match($condition) {
        'fasting' => 'صائم',
        'before_breakfast' => 'قبل الإفطار',
        'before_meal' => 'قبل الأكل',
        'after_meal' => 'بعد الأكل',
        'random' => 'عشوائي',
        default => $condition
    };

    $code = match (true) {
        // أقل من 70 هبوط في السكر في أي حالة
        $value < 70 => 'low',
        in_array($condition, ['قبل الإفطار', 'قبل الأكل', 'صائم'], true) => $value < 100 ? 'normal' : 'high',
        $condition === 'بعد الأكل' => $value < 140 ? 'normal' : 'high',
        $condition === 'عشوائي' => $value < 200 ? 'normal' : 'high',
        default => 'unknown',
    };

    return ['label' => self::BLOOD_SUGAR_LABELS[$code], 'code' => $code];
}



    // public function storeBloodPressure($userId, array $data)
    // {
    //     $data['user_id'] = $userId;
    //     $record = BloodPressureMeasurement::create($data);

    //     $evaluation = $this->evaluateBloodPressure($data['systolic'], $data['diastolic']);

    //     return [
    //         'message' => 'تم سجي قياس ضغط الدم بناح',
    //         'data' => [
    //             'systolic' => $record->systolic,
    //             'diastolic' => $record->diastolic,
    //             'condition_type' => $record->condition_type,
    //             'measured_at' => $record->measured_at,
    //             'evaluation' => $evaluation
    //         ]
    //     ];
    // }

    // private function evaluateBloodPressure($systolic, $diastolic)
    // {
    //     if ($systolic < 90 && $diastolic < 60) {
    //         return 'ضغط نخفض';
    //     } elseif ($systolic >= 140 || $diastolic >= 90) {
    //         return 'ضغط مرتفع (المرح الثانية)';
    //     } elseif ($systolic >= 130 || $diastolic >= 80) {
    //         return 'ضغط مرتفع (الحلة الأولى)';
    //     } elseif ($systolic < 120 && $diastolic < 80) {
    //         return 'طبيعي';
    //     } else {
    //         return 'غير منتظم - يحتاج تقييم إضافي';
    //     }
    // }


    public function storeBloodPressure($userId, array $data)
{
    $data['user_id'] = $userId;
    $record = BloodPressureMeasurement::create($data);

    ['label' => $evaluation, 'code' => $evaluationCode] = $this->evaluateBloodPressure($data['systolic'], $data['diastolic']);

    return [
        'message' => 'تم تسجيل قياس ضغط الدم بنجاح',
        'data' => [
            'systolic' => $record->systolic,
            'diastolic' => $record->diastolic,
            'condition_type' => $record->condition_type,
            'measured_at' => $record->measured_at,
            'evaluation' => $evaluation,
            'evaluation_code' => $evaluationCode,
        ]
    ];
}

/**
 * Labels the app has always received. "elevated" keeps its original wording and a crisis
 * reading keeps the stage 2 wording; evaluation_code tells them apart.
 */
public const BLOOD_PRESSURE_LABELS = [
    'hypertensive_crisis' => 'ضغط مرتفع (المرحلة الثانية)',
    'stage_2' => 'ضغط مرتفع (المرحلة الثانية)',
    'stage_1' => 'ضغط مرتفع (المرحلة الأولى)',
    'low' => 'ضغط منخفض',
    'elevated' => 'غير منتظم - يحتاج قييم إضافي',
    'normal' => 'طبيعي',
];

/**
 * mmHg, 2017 ACC/AHA categories; the higher category of the two numbers wins. Low is below
 * 90 systolic or below 60 diastolic. Readings are classified as measured: the guideline has
 * no allowance for meals, so condition_type does not change the result.
 */
private function evaluateBloodPressure($systolic, $diastolic): array
{
    $code = match (true) {
        $systolic > 180 || $diastolic > 120 => 'hypertensive_crisis',
        $systolic >= 140 || $diastolic >= 90 => 'stage_2',
        $systolic >= 130 || $diastolic >= 80 => 'stage_1',
        $systolic < 90 || $diastolic < 60 => 'low',
        $systolic >= 120 => 'elevated',
        default => 'normal',
    };

    return ['label' => self::BLOOD_PRESSURE_LABELS[$code], 'code' => $code];
}

 public function calculatePregnancy($userId, array $data)
    {
        $lastPeriod = Carbon::parse($data['last_period_date'])->startOfDay();
        $result = $this->buildPregnancyResult($lastPeriod);

        // رسالة بدل النتيجة (تاريخ في المستقبل أو الحمل انتهى)
        if (isset($result['message'])) {
            return $result;
        }

        // ✅ الحفظ في الداتابيز
        return PregnancyCalculation::create([
            'user_id' => $userId,
            'last_period_date' => $lastPeriod,
            'due_date' => $result['due_date'],
            'result' => $result,
        ]);
    }

public function recalculatePregnancyFromStoredData(PregnancyCalculation $pregnancy)
{
    return $this->buildPregnancyResult($pregnancy->last_period_date->copy()->startOfDay());
}

private function buildPregnancyResult(Carbon $lastPeriod): array
{
    // "اليوم" بتوقيت الصيدلية وليس UTC، حتى لا يتأخر الأسبوع يومًا بعد منتصف الليل المحلي
    $today = StoreClock::now()->startOfDay();

    if ($lastPeriod->greaterThan($today)) {
        return ['message' => 'تاريخ آخر دورة لا يمكن أن يكون في المستقبل. برجاء إدخال تاريخ صحيح.', 'code' => 'future_date'];
    }

    // تاريخ الولادة المتوقع (قاعدة نايجل: أول يوم في آخر دورة + 280 يومًا)
    $dueDate = $lastPeriod->copy()->addDays(280);

    // عمر الحمل من أول يوم في آخر دورة. الأسبوع الحالي = عدد الأسابيع المكتملة + 1
    $diffInDays = (int) $lastPeriod->diffInDays($today);
    $completedWeeks = intdiv($diffInDays, 7);
    $currentWeek = $completedWeeks + 1;
    $currentDay = $diffInDays % 7;

    // ⛔ من 42 أسبوعًا مكتملًا (ما بعد موعد الولادة) → نوقف هنا. 41 أسبوعًا ما زال حملًا متأخرًا طبيعيًا
    if ($completedWeeks >= 42) {
        return ['message' => 'من المفترض أن الحمل قد انتهى. يُرجى مراجعة الطبيب للتأكد من الحالة.', 'code' => 'pregnancy_ended'];
    }

    // تحديد رقم الشهر بناءً على الأسبوع الحالي
    if ($currentWeek <= 4) {
        $monthNumber = 1;
    } elseif ($currentWeek <= 8) {
        $monthNumber = 2;
    } elseif ($currentWeek <= 13) {
        $monthNumber = 3;
    } elseif ($currentWeek <= 18) {
        $monthNumber = 4;
    } elseif ($currentWeek <= 22) {
        $monthNumber = 5;
    } elseif ($currentWeek <= 27) {
        $monthNumber = 6;
    } elseif ($currentWeek <= 31) {
        $monthNumber = 7;
    } elseif ($currentWeek <= 35) {
        $monthNumber = 8;
    } else {
        $monthNumber = 9;
    }

    // نحسب ترتيب الأسبوع داخل الشهر بناءً على النطاقات الفعلية
    $monthStartWeeks = [
        1 => 1,
        2 => 5,
        3 => 9,
        4 => 14,
        5 => 19,
        6 => 23,
        7 => 28,
        8 => 32,
        9 => 36,
    ];

    $weekInMonth = $currentWeek - $monthStartWeeks[$monthNumber] + 1;
    $dayInWeek = $currentDay + 1;

    $monthInfo = "اليوم {$dayInWeek} من الأسبوع {$weekInMonth} في الشهر {$monthNumber}";

    // حجم الجنين: كل صف يغطي الأسابيع حتى رقمه (قيم تقريبية لمتوسط النمو)
    $babySizes = [
        5 => ['size' => 'بحجم بذرة سمسم', 'weight' => '0.1 جم', 'length' => '0.1 سم'],
        10 => ['size' => 'بحجم برقوق', 'weight' => '5 جم', 'length' => '3.1 سم'],
        20 => ['size' => 'بحجم موزة', 'weight' => '300 جم', 'length' => '25 سم'],
        25 => ['size' => 'بحجم كوسة', 'weight' => '0.8 كجم', 'length' => '35 سم'],
        30 => ['size' => 'بحجم باذنجانة كبيرة', 'weight' => '1.3 كجم', 'length' => '40 سم'],
        35 => ['size' => 'بحجم أناناسة', 'weight' => '2.4 كجم', 'length' => '46 سم'],
        42 => ['size' => 'بحجم بطيخة', 'weight' => '3.5 كجم', 'length' => '51 سم'],
    ];

    $babyInfo = ['size' => 'غير متاح', 'weight' => 'غير متاح', 'length' => 'غير متاح'];
    foreach ($babySizes as $week => $info) {
        if ($currentWeek <= $week) {
            $babyInfo = $info;
            break;
        }
    }

    // صورة المرحلة ومرحلة الثلث (ACOG: الأول حتى 13 أسبوعًا و6 أيام، الثاني حتى 27 أسبوعًا و6 أيام)
    if ($completedWeeks <= 13) {
        $imageStage = 1;
        $trimester = 'الثلث الأول';
    } elseif ($completedWeeks <= 27) {
        $imageStage = 2;
        $trimester = 'الثلث الثاني';
    } else {
        $imageStage = 3;
        $trimester = 'الثلث الثالث';
    }

    // ✅ ملخص الحالة برسالة ودية
    $summary = "أنتِ الآن في الأسبوع {$currentWeek} من حملك، الجنين {$babyInfo['size']} تقريبًا، ووزنه حوالي {$babyInfo['weight']}.";

    return [
        'last_period_date' => $lastPeriod->toDateString(),
        'due_date' => $dueDate->toDateString(),
        'current_week' => $currentWeek,
        'current_day' => $currentDay,
        'month_info' => $monthInfo,
        'baby_size' => $babyInfo['size'],
        'baby_weight' => $babyInfo['weight'],
        'baby_length' => $babyInfo['length'],
        'image_stage' => $imageStage,
        'trimester' => $trimester,
        'trimester_number' => $imageStage,
        'summary' => $summary,
    ];
}



    /**
     * إنشاء جدول التطيمات الافتراي لك طف
     */
    public function generateDefaultVaccines(Child $child)
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
}
