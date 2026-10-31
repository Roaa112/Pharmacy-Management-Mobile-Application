<?php


namespace App\Modules\HealthService\Services;

use Carbon\Carbon;
use App\Models\OvulationResult;
use App\Models\BodyWeightResult;
use App\Models\VaccinationSchedule;
use App\Models\PregnancyCalculation;
use App\Models\BloodSugarMeasurement;
use App\Models\BloodPressureMeasurement;

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
        ['phase' => $phase, 'pregnancy_chance' => $chance] = $this->ovulationDayPhase($i, $day, $ovulationDay, $periodLength);

        $formattedDate = $day->format('Y-m-d');

        $phases[$formattedDate] = [
            'phase' => $phase,
            'pregnancy_chance' => $chance,
        ];

        $days[] = [
            'date' => $formattedDate,
            'phase' => $phase,
            'pregnancy_chance' => $chance,
        ];
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
 * حساب مرحلة اليوم ونسبة الحمل داخل الدورة
 * $dayIndex: رقم اليوم من بداية الدورة (0 = أول يوم)
 */
public function ovulationDayPhase(int $dayIndex, Carbon $day, Carbon $ovulationDay, int $periodLength): array
{
    // سالب = قبل التبويض، موجب = بعد التبويض
    $daysFromOvulation = (int) $ovulationDay->diffInDays($day, false);

    [$phase, $chance] = match (true) {
        $dayIndex < $periodLength => ['حيض', '0%'],
        $daysFromOvulation === 0 => ['تبويض', '30%'],
        in_array($daysFromOvulation, [-1, -2], true) => ['خصوبة عالية', '25%'],
        in_array($daysFromOvulation, [-3, -4], true) => ['خصوبة متوسطة', '15%'],
        $daysFromOvulation === -5 => ['خصوبة منخفضة', '10%'],
        $daysFromOvulation === 1 => ['خصوبة متوسطة', '15%'],
        $daysFromOvulation === 2 => ['خصوبة منخفضة', '10%'],
        $daysFromOvulation > 2 => ['ما بعد التبويض', '0%'],
        default => ['غير مخصبة', '0%'],
    };

    return ['phase' => $phase, 'pregnancy_chance' => $chance];
}


   public function calculateBodyWeight($userId, array $data)
{
    $heightMeters = $data['unit'] === 'metric' ? $data['height'] / 100 : $data['height'] * 0.0254;
    $weightKg = $data['unit'] === 'metric' ? $data['weight'] : $data['weight'] * 0.453592;
    $bmi = round($weightKg / ($heightMeters * $heightMeters), 1); // زي الصورة: رقم عري واحد

    // وصف الحالة حسب قيمة BMI
    $status = '';
    $description = '';

    if ($bmi < 18.5) {
        $status = 'نقص في الوزن';
        $description = 'مؤشر كتل الجسم الخاص بك أل من 18.5، وهذا يعني أن وزنك أقل من الطبيعي. يُفضل مراجعة طبيب.';
    } elseif ($bmi < 25) {
        $status = 'وزن طبيعي';
        $description = 'وزنك ضمن النطاق الصحي. اتسمر في الحفاظ على أسلوب حياة متوازن.';
    } elseif ($bmi < 30) {
        $status = 'زيادة في الوزن';
        $description = 'مؤشر كتلة الجسم بين 25 و30 يشير إلى زيادة في الوزن،  قد يؤدي لمشاكل صحية لاحقًا.';
    } elseif ($bmi < 35) {
        $status = 'سمنة من الدرجة الاولى';
        $description = 'وزنك يُصنف كسمنة من الدرجة الأولى. يُنصح باتباع نظام غذائي صحي.';
    } elseif ($bmi < 40) {
        $status = 'سمنة من الدرجة الثانية';
        $description = 'سمنة متوسطة، ُيفضل مراجعة مختص تغذية واتباع خطة صحية.';
    } else {
        $status = 'سمنة من الدرجة الثالثة';
        $description = 'سمنة مفرطة. يُنصح بمراجعة الطبيب لاتخاذ خطوات تحسين صحتك.';
    }

    // حساب نطاق الوزن المثالي (BMI بن 18.5 و 24.9)
    $minIdealWeight = round(18.5 * ($heightMeters ** 2), 1);
    $maxIdealWeight = round(24.9 * ($heightMeters ** 2), 1);
    $idealRange = "{$minIdealWeight} - {$maxIdealWeight} كجم";

    // حفظ ابيانت في قاعدة البيانات (اختياري)
    $result = BodyWeightResult::create([
        'user_id' => $userId,
        'height' => $data['height'],
        'weight' => $data['weight'],
        'unit' => $data['unit'],
        'bmi_result' => $bmi,
        'bmi_status' => $status,
        'bmi_description' => $description,
        'ideal_range' => $idealRange,
    ]);

    return [
        'message' => 'تم حساب مؤشر كتلة الجسم بنجاح',
        'data' => [
            'bmi' => $bmi,
            'status' => $status,
            'description' => $description,
            'ideal_range' => $idealRange,
        ],
    ];
}

  public function storeBloodSugar($userId, array $data)
{
    $data['user_id'] = $userId;
    $record = BloodSugarMeasurement::create($data);

    $evaluation = $this->evaluateBloodSugar($data['value'], $data['condition_type']);

    return [
        'message' => 'تم تسجيل قياس السكر بنجاح',
        'data' => [
            'value' => $record->value,
            'condition_type' => $record->condition_type,
            'measured_at' => $record->measured_at,
            'evaluation' => $evaluation
        ]
    ];
}

private function evaluateBloodSugar($value, $condition)
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

    // أقل من 70 هبوط في السكر في أي حالة
    if ($value < 70 && in_array($condition, ['قبل الإفطار', 'قبل الأكل', 'صائم', 'بعد الأكل', 'عشوائي'], true)) {
        return 'منخفض';
    }

    switch ($condition) {
        case 'قبل الإفطار':
        case 'قبل الأكل':
        case 'صائم':
            return $value <= 99 ? 'طبيعي' : 'مرتفع';
        case 'بعد الأكل':
            return $value < 140 ? 'طبيعي' : 'مرتفع';
        case 'عشوائي':
            return $value < 200 ? 'طبيعي' : 'مرتفع';
        default:
            return 'غير معروف';
    }
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

    // تعديل: مر نوع الحالة إلى لتييم
    $evaluation = $this->evaluateBloodPressure(
        $data['systolic'],
        $data['diastolic'],
        $data['condition_type'] ?? null
    );

    return [
        'message' => 'تم تسجيل قياس ضغط الدم بنجاح',
        'data' => [
            'systolic' => $record->systolic,
            'diastolic' => $record->diastolic,
            'condition_type' => $record->condition_type,
            'measured_at' => $record->measured_at,
            'evaluation' => $evaluation
        ]
    ];
}

private function evaluateBloodPressure($systolic, $diastolic, $conditionType = null)
{
    $isAfterMeal = in_array($conditionType, ['بعد الأكل', 'after_meal'], true);

    // السماح بهامش بسيط بعد الأكل
    $adjustment = $isAfterMeal ? -5 : 0;

    $adjustedSystolic = $systolic + $adjustment;
    $adjustedDiastolic = $diastolic + $adjustment;

    if ($adjustedSystolic >= 140 || $adjustedDiastolic >= 90) {
        return 'ضغط مرتفع (المرحلة الثانية)';
    } elseif ($adjustedSystolic >= 130 || $adjustedDiastolic >= 80) {
        return 'ضغط مرتفع (المرحلة الأولى)';
    } elseif ($adjustedSystolic < 90 || $adjustedDiastolic < 60) {
        return 'ضغط منخفض';
    } elseif ($adjustedSystolic >= 120) {
        return 'ضغط مرتفع قليلاً';
    } else {
        return 'طبيعي';
    }
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
    $today = Carbon::today();

    if ($lastPeriod->greaterThan($today)) {
        return ['message' => 'تاريخ آخر دورة لا يمكن أن يكون في المستقبل. برجاء إدخال تاريخ صحيح.'];
    }

    // تاريخ الولادة المتوقع
    $dueDate = $lastPeriod->copy()->addDays(280);

    // فرق الأيام والأسابيع (الأسبوع الحالي = عدد الأسابيع المكتملة + 1)
    $diffInDays = (int) $lastPeriod->diffInDays($today);
    $currentWeek = intdiv($diffInDays, 7) + 1;
    $currentDay = $diffInDays % 7;

    // ⛔ لو الحمل عدى 41 أسبوع → نوقف هنا
    if ($currentWeek > 41) {
        return ['message' => 'من المفترض أن الحمل قد انتهى. يُرجى مراجعة الطبيب للتأكد من الحالة.'];
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

    // حجم الجنين
    $babySizes = [
        5 => ['size' => 'بحجم بذرة سمسم', 'weight' => '0.1 جم', 'length' => '0.1 سم'],
        10 => ['size' => 'بحجم برقوق', 'weight' => '5 جم', 'length' => '3.1 سم'],
        20 => ['size' => 'بحجم موزة', 'weight' => '300 جم', 'length' => '25 سم'],
        25 => ['size' => 'بحجم كوسة', 'weight' => '0.8 كجم', 'length' => '35 سم'],
        30 => ['size' => 'بحجم باذنجانة كبيرة', 'weight' => '0.9 كجم', 'length' => '38 سم'],
        41 => ['size' => 'بحجم بطيخة', 'weight' => '3.5 كجم', 'length' => '51 سم'],
    ];

    $babyInfo = ['size' => 'غير متاح', 'weight' => 'غير متاح', 'length' => 'غير متاح'];
    foreach ($babySizes as $week => $info) {
        if ($currentWeek <= $week) {
            $babyInfo = $info;
            break;
        }
    }

    // صورة المرحلة ومرحلة الثلث
    if ($currentWeek <= 13) {
        $imageStage = 1;
        $trimester = 'الثلث الأول';
    } elseif ($currentWeek <= 27) {
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
        'summary' => $summary,
    ];
}


}
