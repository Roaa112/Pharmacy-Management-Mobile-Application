<?php

/*
 * "legacy" groups hold the exact strings the app has always received in fields it may compare
 * against (phase, evaluation, status...). They are always served in Arabic and must not be
 * corrected; the *_label fields and *_code fields are the ones to use going forward.
 */
return [
    'legacy' => [
        'ovulation_calculation' => [
            'menstruation' => 'حيض',
            'not_fertile' => 'غير مخصبة',
            'fertile_low' => 'خصوبة منخفضة',
            'fertile_medium' => 'خصوبة متوسطة',
            'fertile_high' => 'خصوبة عالية',
            'ovulation' => 'تبويض',
            'fertile_after' => 'نهاية الخصوبة',
            'post_ovulation' => 'ما بعد التبويض',
        ],
        'ovulation_calendar' => [
            'menstruation' => 'حيض',
            'not_fertile' => 'غير خصبة',
            'fertile_low' => 'خصوبة منخفضة',
            'fertile_medium' => 'خصوبة متوسطة',
            'fertile_high' => 'خصوبة عالة',
            'ovulation' => 'تبويض',
            'fertile_after' => 'بداية التبويض',
            'post_ovulation' => 'ما بعد التبويض',
            'unknown' => 'غير معروف',
        ],
        'blood_sugar' => [
            'low' => 'منخفض',
            'normal' => 'طبيعي',
            'high' => 'مرتفع',
            'unknown' => 'غير معروف',
        ],
        'blood_pressure' => [
            'hypertensive_crisis' => 'ضغط مرتفع (المرحلة الثانية)',
            'stage_2' => 'ضغط مرتفع (المرحلة الثانية)',
            'stage_1' => 'ضغط مرتفع (المرحلة الأولى)',
            'low' => 'ضغط منخفض',
            'elevated' => 'غير منتظم - يحتاج قييم إضافي',
            'normal' => 'طبيعي',
        ],
    ],

    'phase' => [
        'menstruation' => 'حيض',
        'not_fertile' => 'غير خصبة',
        'fertile_low' => 'خصوبة منخفضة',
        'fertile_medium' => 'خصوبة متوسطة',
        'fertile_high' => 'خصوبة عالية',
        'ovulation' => 'تبويض',
        'fertile_after' => 'نهاية الخصوبة',
        'post_ovulation' => 'ما بعد التبويض',
        'unknown' => 'غير معروف',
    ],

    'bmi' => [
        'underweight' => ['status' => 'نقص في الوزن', 'description' => 'مؤشر كتلة الجسم الخاص بك أقل من 18.5، وهذا يعني أن وزنك أقل من الطبيعي. يُفضل مراجعة طبيب.'],
        'normal' => ['status' => 'وزن طبيعي', 'description' => 'وزنك ضمن النطاق الصحي. استمر في الحفاظ على أسلوب حياة متوازن.'],
        'overweight' => ['status' => 'زيادة في الوزن', 'description' => 'مؤشر كتلة الجسم بين 25 و30 يشير إلى زيادة في الوزن، قد يؤدي لمشاكل صحية لاحقًا.'],
        'obese_class_1' => ['status' => 'سمنة من الدرجة الاولى', 'description' => 'وزنك يُصنف كسمنة من الدرجة الأولى. يُنصح باتباع نظام غذائي صحي.'],
        'obese_class_2' => ['status' => 'سمنة من الدرجة الثانية', 'description' => 'سمنة متوسطة، يُفضل مراجعة مختص تغذية واتباع خطة صحية.'],
        'obese_class_3' => ['status' => 'سمنة من الدرجة الثالثة', 'description' => 'سمنة مفرطة. يُنصح بمراجعة الطبيب لاتخاذ خطوات تحسين صحتك.'],
        'ideal_range' => ':min - :max كجم',
        'adult_only' => 'هذا التصنيف خاص بالبالغين (20 سنة فأكثر).',
    ],

    'blood_sugar' => [
        'low' => 'منخفض',
        'normal' => 'طبيعي',
        'high' => 'مرتفع',
        'unknown' => 'غير معروف',
    ],

    'blood_pressure' => [
        'hypertensive_crisis' => 'أزمة ارتفاع ضغط الدم',
        'stage_2' => 'ضغط مرتفع (المرحلة الثانية)',
        'stage_1' => 'ضغط مرتفع (المرحلة الأولى)',
        'low' => 'ضغط منخفض',
        'elevated' => 'ضغط مرتفع قليلاً - يحتاج تقييم إضافي',
        'normal' => 'طبيعي',
        'crisis_advice' => 'قراءة ضغط الدم مرتفعة جدًا. إذا كان لديك ألم في الصدر أو ضيق في التنفس أو صداع شديد أو ضعف أو تشوش في الرؤية فاطلب الإسعاف فورًا. وإلا أعد القياس بعد 5 دقائق وتواصل مع طبيبك اليوم.',
    ],

    'pregnancy' => [
        'trimester' => [1 => 'الثلث الأول', 2 => 'الثلث الثاني', 3 => 'الثلث الثالث'],
        'month_info' => 'اليوم :day من الأسبوع :week في الشهر :month',
        'summary' => 'أنتِ الآن في الأسبوع :week من حملك، الجنين :size تقريبًا، ووزنه حوالي :weight.',
        'not_available' => 'غير متاح',
        'future_date' => 'تاريخ آخر دورة لا يمكن أن يكون في المستقبل. برجاء إدخال تاريخ صحيح.',
        'ended' => 'من المفترض أن الحمل قد انتهى. يُرجى مراجعة الطبيب للتأكد من الحالة.',
        'no_data' => 'لم يتم إدخال تاريخ آخر دورة بعد.',
    ],

    'messages' => [
        'ovulation_calculated' => 'تم حساب التبويض بنجاح',
        'cycle_calculated' => 'تم حساب الدورة ',
        'cycle_no_data' => 'محتاج تدخل بيانات الدورة لأول مرة',
        'bmi_calculated' => 'تم حساب مؤشر كتلة الجسم بنجاح',
        'blood_sugar_saved' => 'تم تسجيل قياس السكر بنجاح',
        'blood_pressure_saved' => 'تم تسجيل قياس ضغط الدم بنجاح',
        'child_created' => 'تم إنشاء الطفل وجدول التطعيمات بنجاح',
        'children_loaded' => 'تم جلب بيانات الأطفال والتطعيمات',
        'vaccine_updated' => 'تم تحديث حالة التطعيم',
        'child_not_found' => 'الطفل غير موجود',
        'child_deleted' => 'تم حذف الطفل بنجاح',
        'device_registered' => 'تم تسجيل الجهاز للإشعارات',
        'device_removed' => 'تم إلغاء تسجيل الجهاز',
    ],

    'medication' => [
        'future_dose' => 'لا يمكن تعديل حالة جرعة في المستقبل',
        'dose_updated' => 'تم تحديث حالة الجرعة بنجاح',
    ],

    'reminders' => [
        'vaccine_title' => 'تذكير بموعد تطعيم',
        'vaccine_body' => 'موعد :vaccine لـ :child يوم :date.',
        'medication_title' => 'تذكير بموعد الدواء',
        'medication_body' => 'حان موعد :medication (:quantity).',
    ],
];
