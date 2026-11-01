<?php

namespace App\Modules\HealthService;

/**
 * The vaccination schedule and pregnancy week content the app shipped with. Seeded into the
 * dashboard-managed tables by migration; the vaccine schedule is also the fallback when the
 * template table is empty. Editing this file does not change existing data.
 */
final class DefaultHealthContent
{
    /** [code, offset value, offset unit, name ar, name en, description ar, description en] */
    public const DEFAULT_VACCINES = [
        ['hepb_birth', 0, 'day', 'لقاح عند الولادة', 'Birth vaccine', 'لقاح التهاب الكبد الفيروسي B (الجرعة الصفرية) خلال أول 24 ساعة من الولادة.', 'Hepatitis B vaccine (birth dose) within the first 24 hours of life.'],
        ['week_1', 7, 'day', 'لقاح الأسبوع الأول', 'First-week vaccines', 'لقاح شلل الأطفال الفموي (الجرعة الصفرية) + لقاح السل (BCG).', 'Oral polio vaccine (zero dose) + tuberculosis vaccine (BCG).'],
        ['month_2', 2, 'month', 'لقاح عمر شهرين', '2-month vaccines', 'الجرعة الأولى من: شلل الأطفال الفموي، شلل الأطفال العضلي، الخماسي (الدفتيريا، الكزاز، السعال الديكي، التهاب الكبد B، المستدمية النزلية)، المكورات الرئوية، والروتا.', 'First dose of: oral polio, injectable polio, pentavalent (diphtheria, tetanus, pertussis, hepatitis B, Hib), pneumococcal and rotavirus.'],
        ['month_4', 4, 'month', 'لقاح عمر 4 أشهر', '4-month vaccines', 'الجرعة الثانية من: شلل الأطفال الفموي، شلل الأطفال العضلي، الخماسي، المكورات الرئوية، والروتا.', 'Second dose of: oral polio, injectable polio, pentavalent, pneumococcal and rotavirus.'],
        ['month_6', 6, 'month', 'لقاح عمر 6 أشهر', '6-month vaccines', 'الجرعة الثالثة من: شلل الأطفال الفموي، شلل الأطفال العضلي، الخماسي، والروتا.', 'Third dose of: oral polio, injectable polio, pentavalent and rotavirus.'],
        ['month_9', 9, 'month', 'لقاح عمر 9 أشهر', '9-month vaccines', 'جرعة منشطة من شلل الأطفال الفموي + لقاح الحصبة.', 'Oral polio booster + measles vaccine.'],
        ['month_12', 12, 'month', 'لقاح عمر 12 شهرًا', '12-month vaccines', 'لقاح الحصبة المختلطة (حصبة، حصبة ألمانية، نكاف - MMR) + الجرعة المنشطة الأولى من المكورات الرئوية.', 'MMR (measles, rubella, mumps) + first pneumococcal booster.'],
        ['month_18', 18, 'month', 'لقاح عمر 18 شهرًا', '18-month vaccines', 'جرعة منشطة من: شلل الأطفال الفموي، شلل الأطفال العضلي، الثلاثي (DTaP)، والجرعة المنشطة الثانية من المكورات الرئوية.', 'Boosters of: oral polio, injectable polio, DTaP, and the second pneumococcal booster.'],
        ['year_4', 4, 'year', 'لقاح عمر 4-6 سنوات', '4–6-year vaccines', 'الجرعة المنشطة الأخيرة من: شلل الأطفال الفموي، شلل الأطفال العضلي، والثلاثي (DTaP).', 'Final boosters of: oral polio, injectable polio and DTaP.'],
    ];

    public const DEFAULT_PREGNANCY_WEEKS = [
        [1, 5, 'بحجم بذرة سمسم', 'The size of a sesame seed', '0.1 جم', '0.1 g', '0.1 سم', '0.1 cm'],
        [6, 10, 'بحجم برقوق', 'The size of a plum', '5 جم', '5 g', '3.1 سم', '3.1 cm'],
        [11, 20, 'بحجم موزة', 'The size of a banana', '300 جم', '300 g', '25 سم', '25 cm'],
        [21, 25, 'بحجم كوسة', 'The size of a zucchini', '0.8 كجم', '0.8 kg', '35 سم', '35 cm'],
        [26, 30, 'بحجم باذنجانة كبيرة', 'The size of a large eggplant', '1.3 كجم', '1.3 kg', '40 سم', '40 cm'],
        [31, 35, 'بحجم أناناسة', 'The size of a pineapple', '2.4 كجم', '2.4 kg', '46 سم', '46 cm'],
        [36, 42, 'بحجم بطيخة', 'The size of a watermelon', '3.5 كجم', '3.5 kg', '51 سم', '51 cm'],
    ];
}
