<?php

return [
    // "legacy" strings exist only in lang/ar/health.php: those fields are always served in Arabic.

    'phase' => [
        'menstruation' => 'Period',
        'not_fertile' => 'Not fertile',
        'fertile_low' => 'Low fertility',
        'fertile_medium' => 'Medium fertility',
        'fertile_high' => 'High fertility',
        'ovulation' => 'Ovulation',
        'fertile_after' => 'End of fertile window',
        'post_ovulation' => 'After ovulation',
        'unknown' => 'Unknown',
    ],

    'bmi' => [
        'underweight' => ['status' => 'Underweight', 'description' => 'Your BMI is below 18.5, which means your weight is below normal. Consider seeing a doctor.'],
        'normal' => ['status' => 'Healthy weight', 'description' => 'Your weight is in the healthy range. Keep up a balanced lifestyle.'],
        'overweight' => ['status' => 'Overweight', 'description' => 'A BMI between 25 and 30 indicates overweight, which may lead to health problems later.'],
        'obese_class_1' => ['status' => 'Obesity class I', 'description' => 'Your weight is classed as obesity class I. A healthy diet is recommended.'],
        'obese_class_2' => ['status' => 'Obesity class II', 'description' => 'Moderate obesity. Consider seeing a nutritionist and following a health plan.'],
        'obese_class_3' => ['status' => 'Obesity class III', 'description' => 'Severe obesity. Please see a doctor to plan steps to improve your health.'],
        'ideal_range' => ':min - :max kg',
        'adult_only' => 'This classification is for adults (20 years and over).',
    ],

    'blood_sugar' => [
        'low' => 'Low',
        'normal' => 'Normal',
        'high' => 'High',
        'unknown' => 'Unknown',
    ],

    'blood_pressure' => [
        'hypertensive_crisis' => 'Hypertensive crisis',
        'stage_2' => 'High blood pressure (stage 2)',
        'stage_1' => 'High blood pressure (stage 1)',
        'low' => 'Low blood pressure',
        'elevated' => 'Elevated - needs further assessment',
        'normal' => 'Normal',
        'crisis_advice' => 'This reading is very high. If you have chest pain, shortness of breath, a severe headache, weakness or blurred vision, call emergency services now. Otherwise measure again in 5 minutes and contact your doctor today.',
    ],

    'pregnancy' => [
        'trimester' => [1 => 'First trimester', 2 => 'Second trimester', 3 => 'Third trimester'],
        'month_info' => 'Day :day of week :week in month :month',
        'summary' => 'You are in week :week of your pregnancy; the baby is about :size and weighs around :weight.',
        'not_available' => 'Not available',
        'future_date' => 'The last period date cannot be in the future. Please enter a valid date.',
        'ended' => 'The pregnancy should have ended by now. Please see your doctor to confirm.',
        'no_data' => 'No last period date has been entered yet.',
    ],

    'messages' => [
        'ovulation_calculated' => 'Ovulation calculated',
        'cycle_calculated' => 'Cycle calculated',
        'cycle_no_data' => 'Please enter your cycle details first',
        'bmi_calculated' => 'BMI calculated',
        'blood_sugar_saved' => 'Blood sugar reading saved',
        'blood_pressure_saved' => 'Blood pressure reading saved',
        'child_created' => 'Child and vaccination schedule created',
        'children_loaded' => 'Children and vaccinations loaded',
        'vaccine_updated' => 'Vaccination updated',
        'child_not_found' => 'Child not found',
        'child_deleted' => 'Child deleted',
        'device_registered' => 'Device registered for notifications',
        'device_removed' => 'Device unregistered',
    ],

    'medication' => [
        'future_dose' => 'A future dose cannot be updated',
        'dose_updated' => 'Dose status updated',
    ],

    'reminders' => [
        'vaccine_title' => 'Vaccination reminder',
        'vaccine_body' => ':vaccine for :child is due on :date.',
        'medication_title' => 'Medication reminder',
        'medication_body' => 'Time to take :medication (:quantity).',
    ],
];
