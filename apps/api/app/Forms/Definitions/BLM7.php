<?php

/* BL-01 Module 7 · Lifestyle. Self-report at baseline; wearable sleep/activity starts after randomisation. */
return [
    'code' => 'BL-M7',
    'title' => 'Lifestyle',
    'eyebrow' => 'BL-01 · Module 7',
    'description' => 'Tobacco, alcohol, physical activity and sleep, as reported by the participant.',
    'group' => 'Baseline',
    'gate' => ['consented'],
    'sections' => [
        ['title' => 'Tobacco', 'entered_by' => 'PI Enters', 'fields' => [
            ['code' => 'BL_SMOKING', 'label' => 'Tobacco use', 'type' => 'radio', 'required' => true, 'options' => ['Never', 'Former', 'Current']],
            ['code' => 'BL_TOBACCO_TYPE', 'label' => 'Type of tobacco', 'type' => 'checkboxes', 'required' => true,
                'options' => ['Cigarettes', 'Bidi', 'Smokeless (chewing / snuff)', 'Other'], 'show_if' => ['field' => 'BL_SMOKING', 'equals' => 'Current']],
            ['code' => 'BL_TOBACCO_PER_DAY', 'label' => 'Cigarettes / bidis / uses per day', 'type' => 'number', 'integer' => true, 'required' => true,
                'min' => 0, 'max' => 100, 'plausible_max' => 60, 'show_if' => ['field' => 'BL_SMOKING', 'equals' => 'Current']],
            ['code' => 'BL_TOBACCO_YEARS', 'label' => 'Years of use', 'type' => 'number', 'integer' => true, 'required' => true,
                'min' => 0, 'max' => 90, 'show_if' => ['field' => 'BL_SMOKING', 'equals' => 'Current']],
            ['code' => 'BL_QUIT_DATE', 'label' => 'Date stopped (approximate)', 'type' => 'date', 'required' => true, 'not_future' => true,
                'show_if' => ['field' => 'BL_SMOKING', 'equals' => 'Former']],
        ]],
        ['title' => 'Alcohol', 'entered_by' => 'PI Enters', 'fields' => [
            ['code' => 'BL_ALCOHOL', 'label' => 'Alcohol use', 'type' => 'radio', 'required' => true, 'options' => ['None', 'Occasional', 'Regular']],
            ['code' => 'BL_ALCOHOL_UNITS', 'label' => 'Standard drinks per week', 'type' => 'number', 'required' => true, 'min' => 0, 'max' => 200,
                'show_if' => ['field' => 'BL_ALCOHOL', 'equals' => 'Regular']],
        ]],
        ['title' => 'Physical activity and sleep (self-report)', 'entered_by' => 'PI Enters', 'fields' => [
            ['code' => 'BL_PA_DAYS', 'label' => 'Days per week with at least 10 minutes of moderate activity (before the index event)', 'type' => 'number', 'integer' => true,
                'required' => true, 'min' => 0, 'max' => 7],
            ['code' => 'BL_PA_MIN_WEEK', 'label' => 'Total minutes of moderate activity per week', 'type' => 'number', 'integer' => true,
                'unit' => 'min', 'required' => true, 'min' => 0, 'max' => 3000, 'plausible_max' => 1200],
            ['code' => 'BL_SLEEP_HOURS', 'label' => 'Average sleep per night', 'type' => 'number', 'unit' => 'hours', 'required' => true, 'min' => 0, 'max' => 16, 'plausible_min' => 3, 'plausible_max' => 12],
        ]],
    ],
];
