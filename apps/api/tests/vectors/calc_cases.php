<?php

/*
 * The 102 test vectors from docs/calculation-specification.md, ported unchanged from
 * archive/core-php-api/api/tests/scripts/calc_vectors.php. Each case:
 * [id, section, what, fn() => actual, expected, source]. Used by Tests\Unit\CalcVectorsTest.
 */

declare(strict_types=1);

use App\Calc\Calc;

$eCRF = [ // SCR-01 example participant SH-0031 as shown in the eCRF prototype
    'dob' => '1971-05-15', 'screening_date' => '2026-08-04', 'index_diagnosis' => 'acs', 'acs_subtype' => 'nstemi',
    'pci_done' => true, 'pci_date' => '2026-08-03', 'cabg_history' => false, 'lvef_pct' => 52,
    'prior_cardiac_arrest' => 'no', 'complex_vent_arrhythmia' => 'no', 'cardiogenic_shock' => 'no',
    't2dm' => true, 'retinopathy' => 'no', 'peripheral_neuropathy' => 'no', /* foot ulcer: no field in eCRF */
    'egfr' => 68, 'sbp' => 138, 'dbp' => 86, 'visual_impairment' => 'no', 'hearing_impairment' => 'no',
    'cognitive_impairment' => 'no', 'smartphone_access' => true, 'phone_os' => 'android',
];
$elig = fn(array $over) => Calc::eligibility(array_merge($eCRF, ['diabetic_foot_ulcer' => 'no'], $over));
$pick = fn(array $r, array $keys) => array_intersect_key($r, array_flip($keys));
$phq = fn(array $a) => array_combine(array_map('strval', range(1, count($a))), $a);
// $spread(total, items, min, max): item values (each min..max) that add up to total, filled left to right.
$spread = function (int $total, int $items, int $min, int $max): array {
    $v = array_fill(0, $items, $min);
    $left = $total - $min * $items;
    for ($i = 0; $i < $items && $left > 0; $i++) {
        $add = min($max - $min, $left);
        $v[$i] += $add;
        $left -= $add;
    }
    if ($left !== 0 || $total < $min * $items) {
        throw new RuntimeException("Cannot spread $total over $items items");
    }
    return $v;
};
$dasiYes = fn(array $items) => array_map(fn($i) => in_array($i, $items, true), range(1, 12));
$sym = fn(array $c, array $d, array $e) => array_map(fn($i) => ['chest_pain' => $c[$i], 'breathlessness' => $d[$i], 'ankle_swelling' => $e[$i]], range(0, count($c) - 1));

$cases = [
    // ── Ground rules
    ['G1', 'Ground rules', 'UTC 19:00 on 2 Oct is 00:30 IST on 3 Oct', fn() => Calc::localDate('2026-10-02T19:00:00Z'), '2026-10-03', 'IST date rule'],
    ['G2', 'Ground rules', 'PCI 3 Aug → screening 4 Aug is 1 day', fn() => Calc::daysBetween('2026-08-03', '2026-08-04'), 1, 'eCRF SCR-01'],
    ['G3', 'Ground rules', 'Age of SH-0031 on screening date', fn() => Calc::ageYears('1971-05-15', '2026-08-04'), 55, 'eCRF SCR-01 shows 55'],
    ['G4', 'Ground rules', '29 Feb birthday, day before 1 Mar', fn() => Calc::ageYears('2000-02-29', '2026-02-28'), 25, 'completed-years rule'],
    ['G5', 'Ground rules', '29 Feb birthday reached on 1 Mar', fn() => Calc::ageYears('2000-02-29', '2026-03-01'), 26, 'completed-years rule'],
    ['G6', 'Ground rules', 'Day before 18th birthday', fn() => Calc::ageYears('2008-08-05', '2026-08-04'), 17, 'boundary'],
    ['G7', 'Ground rules', 'On 18th birthday', fn() => Calc::ageYears('2008-08-04', '2026-08-04'), 18, 'boundary'],

    // ── Eligibility
    ['E1', 'Eligibility', 'eCRF example as built (no foot-ulcer field) cannot be eligible', fn() => $pick(Calc::eligibility($eCRF), ['result', 'pending']), ['result' => 'pending', 'pending' => ['DIABETIC_FOOT_ULCER']], 'eCRF summary lists foot ulcer but form has no field'],
    ['E2', 'Eligibility', 'eCRF example with foot ulcer answered "no"', fn() => $pick($elig([]), ['result', 'stop_at_screen']), ['result' => 'eligible', 'stop_at_screen' => null], 'eCRF shows ELIGIBLE'],
    ['E3', 'Eligibility', 'LVEF 39 excludes and stops at screen 5', fn() => $pick($elig(['lvef_pct' => 39]), ['result', 'stop_at_screen', 'failures']), ['result' => 'ineligible', 'stop_at_screen' => 5, 'failures' => ['LVEF_GE_40']], 'boundary'],
    ['E4', 'Eligibility', 'LVEF 40 passes', fn() => $elig(['lvef_pct' => 40])['result'], 'eligible', 'boundary (<40 excludes)'],
    ['E5', 'Eligibility', 'PCI 30 days before screening passes', fn() => $elig(['pci_date' => '2026-07-05'])['result'], 'eligible', 'boundary (≤30)'],
    ['E6', 'Eligibility', 'PCI 31 days before excludes at screen 3; later screens not evaluated', fn() => [$elig(['pci_date' => '2026-07-04'])['stop_at_screen'], $elig(['pci_date' => '2026-07-04'])['criteria'][4]['status']], [3, 'not_evaluated'], 'stop rule'],
    ['E7', 'Eligibility', 'eGFR 44 excludes', fn() => $elig(['egfr' => 44])['result'], 'ineligible', 'boundary (<45)'],
    ['E8', 'Eligibility', 'eGFR 45 passes', fn() => $elig(['egfr' => 45])['result'], 'eligible', 'boundary'],
    ['E9', 'Eligibility', 'SBP 160 on treatment excludes', fn() => $elig(['sbp' => 160, 'on_antihypertensive' => true])['result'], 'ineligible', 'boundary (≥160)'],
    ['E10', 'Eligibility', 'BP 159/99 passes', fn() => $elig(['sbp' => 159, 'dbp' => 99])['result'], 'eligible', 'boundary'],
    ['E11', 'Eligibility', 'DBP 100 on treatment excludes (eCRF message checks SBP only)', fn() => $elig(['dbp' => 100, 'on_antihypertensive' => true])['result'], 'ineligible', 'DBP arm of rule'],
    ['E12', 'Eligibility', 'SBP 165 untreated is held for PI review', fn() => $elig(['sbp' => 165, 'on_antihypertensive' => false])['result'], 'pending', '"despite treatment"'],
    ['E13', 'Eligibility', 'Cardiac arrest "unknown" holds result', fn() => $elig(['prior_cardiac_arrest' => 'unknown'])['result'], 'pending', 'unknown ≠ absent'],
    ['E14', 'Eligibility', 'Non-diabetic: complications not applicable', fn() => $elig(['t2dm' => false, 'retinopathy' => null])['result'], 'eligible', 'n/a rule'],
    ['E15', 'Eligibility', 'Age 17 excludes at screen 1', fn() => $elig(['dob' => '2008-08-05'])['stop_at_screen'], 1, 'boundary'],

    // ── Baseline clinical
    ['B1', 'Baseline', 'BMI 72 kg, 165 cm', fn() => Calc::bmi(165, 72), 26.4, 'kg/m²'],
    ['B2', 'Baseline', 'BMI 26.4 under Asian cut-offs', fn() => Calc::bmiCategoryAsian(26.4), 'obese', 'app target <25, portal target <23'],
    ['B3', 'Baseline', 'Round before classify: 66.4 kg, 170 cm → 23.0 is overweight', fn() => [Calc::bmi(170, 66.4), Calc::bmiCategoryAsian(Calc::bmi(170, 66.4))], [23.0, 'overweight'], 'rounding rule'],
    ['B4', 'Baseline', 'Clinic BP mean of 138/86 and 136/84', fn() => Calc::meanBp([[138, 86], [136, 84]]), ['sbp' => 137.0, 'dbp' => 85.0], 'BL-01 M4'],
    ['B5', 'Baseline', 'LDL 2.6 mmol/L → mg/dL', fn() => Calc::convertLab('LDL', 2.6, 'mmol/L')['value'], 100.5, '× 38.67'],
    ['B6', 'Baseline', 'HbA1c 53 mmol/mol → %', fn() => Calc::convertLab('HBA1C', 53, 'mmol/mol')['value'], 7.0, 'NGSP master equation'],
    ['B7', 'Baseline', 'Creatinine 97.2 µmol/L → mg/dL', fn() => Calc::convertLab('CREAT', 97.2, 'µmol/L')['value'], 1.1, '÷ 88.42'],
    ['B8', 'Baseline', 'Friedewald LDL from eCRF lipids (TC 184, HDL 42, TG 162)', fn() => Calc::ldlFriedewald(184, 42, 162), 109.6, 'eCRF LDL entered as 98'],
    ['B9', 'Baseline', 'Friedewald not valid at TG 400', fn() => Calc::ldlFriedewald(184, 42, 400), null, 'method limit'],
    ['B10', 'Baseline', 'Non-HDL cholesterol', fn() => Calc::nonHdl(184, 42), 142.0, 'TC − HDL'],
    ['B11', 'Baseline', 'CKD-EPI 2021, male 55, creatinine 1.1 mg/dL', fn() => Calc::egfrCkdEpi2021(1.1, 55, 'M'), 79, 'eCRF eGFR entered as 68'],
    ['B12', 'Baseline', 'CKD-EPI 2021, female 55, creatinine 1.1 mg/dL', fn() => Calc::egfrCkdEpi2021(1.1, 55, 'F'), 59, 'female coefficients'],
    ['B13', 'Baseline', 'Entered 68 vs computed 79', fn() => Calc::egfrConsistency(68, 79), ['difference_pct' => 13.9, 'flag' => false], '15 % tolerance (PROPOSED)'],
    ['B14', 'Baseline', '6MWT 349 / 350 / 500 / 501 m', fn() => array_map([Calc::class, 'sixMwtCategory'], [349, 350, 500, 501]), ['poor', 'intermediate', 'intermediate', 'ideal'], 'portal bands'],
    ['B15', 'Baseline', 'LVEF 40 / 41 / 49 / 50', fn() => array_map([Calc::class, 'efBand'], [40, 41, 49, 50]), ['reduced', 'mildly_reduced', 'mildly_reduced', 'preserved'], 'HF universal definition'],

    // ── Questionnaires
    ['Q1', 'Questionnaires', 'PHQ-9 eCRF example total 9', fn() => $pick(Calc::phq9($phq([1, 1, 2, 1, 1, 1, 1, 1, 0])), ['total', 'severity', 'item9_positive', 'clinical_review']), ['total' => 9, 'severity' => 'mild', 'item9_positive' => false, 'clinical_review' => false], 'eCRF PRO-01'],
    ['Q2', 'Questionnaires', 'PHQ-9 total 3 with item 9 = 1 still flags safety', fn() => $pick(Calc::phq9($phq([1, 0, 1, 0, 0, 0, 0, 0, 1])), ['total', 'severity', 'item9_positive']), ['total' => 3, 'severity' => 'minimal', 'item9_positive' => true], 'app only notifies at ≥ 10'],
    ['Q3', 'Questionnaires', 'PHQ-9 with item 3 missing, no prorating', fn() => $pick(Calc::phq9(['1' => 1, '2' => 1, '4' => 1, '5' => 1, '6' => 1, '7' => 1, '8' => 1, '9' => 1]), ['total', 'missing', 'item9_positive']), ['total' => null, 'missing' => 1, 'item9_positive' => true], 'app counts missing as 0'],
    ['Q4', 'Questionnaires', 'PHQ-9 with 1 missing, prorating allowed (sum 8 of 8 items)', fn() => Calc::phq9(['1' => 1, '2' => 1, '4' => 1, '5' => 1, '6' => 1, '7' => 1, '8' => 1, '9' => 1], 2)['total'], 9, '8 × 9 ÷ 8'],
    ['Q5', 'Questionnaires', 'PHQ-9 band edges 4/5/9/10/14/15/19/20', fn() => array_map(fn($t) => Calc::phq9($phq($spread($t, 9, 0, 3)))['severity'], [4, 5, 9, 10, 14, 15, 19, 20]), ['minimal', 'mild', 'mild', 'moderate', 'moderate', 'moderately_severe', 'moderately_severe', 'severe'], 'Kroenke 2001'],
    ['Q6', 'Questionnaires', 'GAD-7 eCRF example total 10', fn() => $pick(Calc::gad7($phq([2, 1, 2, 1, 1, 2, 1])), ['total', 'severity', 'clinical_review']), ['total' => 10, 'severity' => 'moderate', 'clinical_review' => true], 'eCRF PRO-01'],
    ['Q7', 'Questionnaires', 'GAD-7 total 15 is severe', fn() => Calc::gad7($phq([3, 3, 3, 2, 2, 1, 1]))['severity'], 'severe', 'Spitzer 2006'],
    ['Q8', 'Questionnaires', 'DASI all yes', fn() => $pick(Calc::dasi(array_fill(0, 12, true)), ['score', 'vo2peak', 'mets']), ['score' => 58.2, 'vo2peak' => 34.6, 'mets' => 9.9], 'max possible'],
    ['Q9', 'Questionnaires', 'DASI all no', fn() => $pick(Calc::dasi(array_fill(0, 12, false)), ['score', 'vo2peak', 'mets']), ['score' => 0.0, 'vo2peak' => 9.6, 'mets' => 2.7], 'minimum; "≥ 1 MET" check in SAF-01 is always true'],
    ['Q10', 'Questionnaires', 'DASI 34 (items 1–4, 7–10)', fn() => $pick(Calc::dasi($dasiYes([1, 2, 3, 4, 7, 8, 9, 10])), ['score', 'vo2peak', 'mets']), ['score' => 34.0, 'vo2peak' => 24.2, 'mets' => 6.9], 'app labels ≥ 34 as "> 10 METs"'],
    ['Q11', 'Questionnaires', 'DASI with one item missing', fn() => Calc::dasi(array_merge(array_fill(0, 11, true), [null]))['score'], null, 'no prorating'],
    ['Q12', 'Questionnaires', 'EQ-5D-5L state and VAS', fn() => $pick(Calc::eq5d5l([2, 1, 1, 1, 1], 70), ['health_state', 'vas', 'utility']), ['health_state' => '21111', 'vas' => 70, 'utility' => null], 'utility needs licensed value set'],
    ['Q13', 'Questionnaires', 'EQ-5D-5L VAS not touched stays empty', fn() => Calc::eq5d5l([1, 1, 1, 1, 1], null)['vas'], null, 'app pre-sets 50'],
    ['Q14', 'Questionnaires', 'BRiDgE bands at 17 / 18 / 25 / 26', fn() => array_map(fn($t) => Calc::bridge($spread($t, 8, 1, 4))['band'], [17, 18, 25, 26]), ['low', 'moderate', 'moderate', 'high'], 'app thresholds'],

    // ── CCSPS
    ['C1', 'CCSPS', 'Mental domain PHQ 9 + GAD 10', fn() => Calc::mentalHealthDomain(9, 10), 0, 'eCRF precedence example'],
    ['C2', 'CCSPS', 'Mental domain PHQ 4 + GAD 3', fn() => Calc::mentalHealthDomain(4, 3), 2, 'portal participant 001'],
    ['C3', 'CCSPS', 'Mental domain PHQ 11 + GAD 9', fn() => Calc::mentalHealthDomain(11, 9), 0, 'portal shows 1 for participant 014'],
    ['C4', 'CCSPS', 'Mental domain PHQ 5 + GAD 4', fn() => Calc::mentalHealthDomain(5, 4), 1, 'PROPOSED mild = 1'],
    ['C5', 'CCSPS', 'Mental domain with GAD-7 missing', fn() => Calc::mentalHealthDomain(4, null), null, 'app gives 2 once PHQ-9 is done'],
    ['C6', 'CCSPS', 'eCRF composite example (7 domains scored)', fn() => $pick(Calc::ccsps(['bp' => 1, 'ldl' => 0, 'hba1c' => 1, 'smoking' => 0, 'sleep' => 0, 'bmi' => 1, 'mental_health' => 0]), ['raw', 'domains_scored', 'normalised_complete_case', 'normalised_proportional']), ['raw' => 3, 'domains_scored' => 7, 'normalised_complete_case' => null, 'normalised_proportional' => null], 'eCRF box shows raw 9, "6 domains"'],
    ['C7', 'CCSPS', 'Portal participant 001 domain pips', fn() => $pick(Calc::ccsps(['diet' => 1, 'physical_activity' => 2, 'smoking' => 2, 'sleep' => 1, 'bmi' => 1, 'ldl' => 1, 'hba1c' => 2, 'bp' => 2, 'medication_adherence' => 2, 'mental_health' => 2]), ['raw', 'normalised_complete_case']), ['raw' => 16, 'normalised_complete_case' => 80.0], 'portal gauge shows 68'],
    ['C8', 'CCSPS', 'Portal participant 014 domain pips', fn() => Calc::ccsps(['diet' => 1, 'physical_activity' => 0, 'smoking' => 1, 'sleep' => 0, 'bmi' => 0, 'ldl' => 0, 'hba1c' => 0, 'bp' => 0, 'medication_adherence' => 1, 'mental_health' => 1])['normalised_complete_case'], 20.0, 'portal gauge shows 44'],
    ['C9', 'CCSPS', 'Portal participant 009 domain pips', fn() => Calc::ccsps(['diet' => 2, 'physical_activity' => 2, 'smoking' => 2, 'sleep' => 2, 'bmi' => 1, 'ldl' => 1, 'hba1c' => 1, 'bp' => 2, 'medication_adherence' => 2, 'mental_health' => 2])['normalised_complete_case'], 85.0, 'portal gauge shows 79'],
    ['C10', 'CCSPS', 'App example with LDL missing and PHQ-9 not done', fn() => $pick(Calc::ccsps(['hba1c' => 1, 'bp' => 2, 'diet' => 1, 'physical_activity' => 1, 'smoking' => 2, 'sleep' => 1, 'bmi' => 1, 'medication_adherence' => 1]), ['raw', 'domains_scored', 'normalised_proportional']), ['raw' => 10, 'domains_scored' => 8, 'normalised_proportional' => 62.5], 'app scores 11 and shows 14'],

    // ── Time anchors
    ['T1', 'Time anchors', 'Rehab phase on days 7 / 8 / 30 / 31 / 90 / 91', fn() => array_map(fn($d) => Calc::rehabPhase('2026-08-03', date('Y-m-d', strtotime("2026-08-03 +$d days"))), [7, 8, 30, 31, 90, 91]), [1, 2, 2, 3, 3, 4], 'PROPOSED boundaries'],
    ['T2', 'Time anchors', 'Portal examples: day 47 / 21 / 96', fn() => array_map(fn($d) => Calc::rehabPhase('2026-08-03', date('Y-m-d', strtotime("2026-08-03 +$d days"))), [47, 21, 96]), [3, 2, 4], 'portal shows III / II / IV'],
    ['T3', 'Time anchors', 'Day 30 visit window (−5 / +5) from randomisation 4 Aug', fn() => Calc::visitWindow('2026-08-04', 30, 5, 5), ['target' => '2026-09-03', 'start' => '2026-08-29', 'end' => '2026-09-08'], 'visit schedule'],
    ['T4', 'Time anchors', 'Visit status before / inside / after window', fn() => array_map(fn($d) => Calc::visitStatus(['start' => '2026-08-29', 'end' => '2026-09-08'], $d, false), ['2026-08-28', '2026-09-03', '2026-09-09']), ['scheduled', 'open', 'missed'], 'window rule'],

    // ── Exercise
    ['X1', 'Exercise', 'THR age 55, RHR 70, NYHA I, phase 2', fn() => Calc::thr(55, 70, 'I', 2)['zone'], ['low' => 113, 'high' => 121], 'app calcTHR gives the same; Exercise tab shows 93–102'],
    ['X2', 'Exercise', 'THR age 55, measured RHR 74', fn() => Calc::thr(55, 74, 'I', 2)['zone'], ['low' => 115, 'high' => 123], 'measured RHR instead of fixed 70'],
    ['X3', 'Exercise', 'Portal participant 001 (58 y, RHR 68, NYHA I, phase 3)', fn() => Calc::thr(58, 68, 'I', 3)['zone'], ['low' => 124, 'high' => 133], 'portal zone 95–115'],
    ['X4', 'Exercise', 'Portal participant 009 (52 y, RHR 64, NYHA I, phase 4)', fn() => Calc::thr(52, 64, 'I', 4)['zone'], ['low' => 147, 'high' => 157], 'portal zone 105–125'],
    ['X5', 'Exercise', 'Portal participant 014 (RHR 104) gets no zone', fn() => Calc::thr(64, 104, 'III', 2)['zone'], null, 'Karvonen would give 122–125'],
    ['X6', 'Exercise', 'Phase I gets no zone', fn() => Calc::thr(55, 70, 'I', 1)['zone'], null, 'app falls back to 45–54 %'],
    ['X7', 'Exercise', 'NYHA IV gets no zone', fn() => Calc::thr(55, 70, 'IV', 2)['zone'], null, 'rest / ADL only'],
    ['X8', 'Exercise', 'Missing RHR gets no zone', fn() => Calc::thr(55, null, 'I', 2)['zone'], null, 'app assumes 70'],
    ['X9', 'Exercise', 'HRmax 220 − age vs Gellish at 55', fn() => [Calc::hrMax(55), Calc::hrMax(55, 'gellish')], [165, 169], '207 − 0.7 × age'],
    ['X10', 'Exercise', 'Time in zone: 24 of 30 minutes', fn() => Calc::timeInZone(array_merge(array_fill(0, 24, 100), array_fill(0, 6, 130)), 95, 115, 30), 80, '1-minute samples'],
    ['X11', 'Exercise', 'Time in zone with HR on only 10 of 30 minutes', fn() => Calc::timeInZone(array_fill(0, 10, 100), 95, 115, 30), null, 'insufficient data'],
    ['X12', 'Exercise', 'Session avg HR 112 vs zone 85–100', fn() => Calc::sessionZoneStatus(112, 85, 100), 'above_zone', 'portal participant 014'],

    // ── Monitoring
    ['M1', 'Monitoring', 'Step decline for participant 014 (14 days)', fn() => Calc::stepDecline([5200, 5400, 5100, 4800, 3600, 3100, 2900, 2600, 2400, 2200, 2500, 2300, 2400, 2100]), 45.2, 'portal alert says 54 %'],
    ['M2', 'Monitoring', 'Steps mean over valid days only (≥ 600 min wear, ≥ 4 days)', fn() => Calc::validDayMean([['value' => 6000, 'wear_min' => 800], ['value' => 300, 'wear_min' => 90], ['value' => 7000, 'wear_min' => 700], ['value' => 6500, 'wear_min' => 650], ['value' => 6800, 'wear_min' => 900]]), 6575.0, 'low-wear day excluded'],
    ['M3', 'Monitoring', 'Home BP needs ≥ 6 readings', fn() => [Calc::homeBp([[130, 80], [128, 82], [132, 84], [126, 78], [130, 80]]), Calc::homeBp([[130, 80], [128, 82], [132, 84], [126, 78], [130, 80], [134, 86]])], [null, ['sbp' => 130.0, 'dbp' => 82.0, 'n' => 6]], 'PROPOSED minimum'],
    ['M4', 'Monitoring', 'RHR > 100 on last 3 days (participant 014)', fn() => Calc::sustainedAbove([92, 95, 98, 99, 101, 103, 104], 100, 3), true, 'portal alert'],
    ['M5', 'Monitoring', 'RHR not sustained when one day is 99', fn() => Calc::sustainedAbove([101, 103, 99, 104], 100, 3), false, 'rule'],
    ['M6', 'Monitoring', 'Weight +2.2 kg in 2 days flags', fn() => Calc::weightGainFlag(['2026-10-01' => 88.0, '2026-10-03' => 90.2]), true, 'PROPOSED HF rule'],
    ['M7', 'Monitoring', 'Participant 014 weight 87.1 → 88.4 over 7 days', fn() => Calc::weightGainFlag(['2026-09-19' => 87.1, '2026-09-22' => 88.0, '2026-09-25' => 88.4]), false, 'PROPOSED HF rule'],
    ['M8', 'Monitoring', 'Prototype trend: SpO₂ 96 → 97 counts as improving', fn() => Calc::trendPrototype([96, 97, 96, 97, 97, 98, 97], false), 'improving', 'first vs last value'],
    ['M9', 'Monitoring', 'Proposed trend: weight, 3-day means, 1 kg minimum', fn() => Calc::trend([78, 77.4, 76.5, 75.6, 75, 74.6, 74.2], true, 1.0), 'improving', 'participant 001'],
    ['M10', 'Monitoring', 'Sleep bands 5.9 / 6.0 / 7.0 h', fn() => array_map([Calc::class, 'sleepBand'], [5.9, 6.0, 7.0]), ['poor', 'short', 'ideal'], 'eCRF scores 6.0 h as 0'],
    ['M11', 'Monitoring', 'Steps status 2400/6000, 6200/7000, 8400/8000', fn() => [Calc::stepsStatus(2400, 6000), Calc::stepsStatus(6200, 7000), Calc::stepsStatus(8400, 8000)], ['low', 'below_goal', 'on_target'], 'portal rule'],

    // ── Adherence
    ['A1', 'Adherence', 'App meds today: 2 taken of 5 scheduled', fn() => Calc::doseAdherence(2, 5), 40, 'composite note says 3/4'],
    ['A2', 'Adherence', 'At 9 AM only morning doses are due: 2 of 3', fn() => Calc::doseAdherence(2, 3), 67, 'due-so-far rule'],
    ['A3', 'Adherence', 'DAPT below target (aspirin 70, clopidogrel 58)', fn() => Calc::daptBelowTarget([70, 58]), true, 'portal participant 014'],
    ['A4', 'Adherence', 'DAPT at 70 and 71 is not below target', fn() => Calc::daptBelowTarget([70, 71]), false, '< 70 rule'],
    ['A5', 'Adherence', 'DHI meter 001 / 014 / 009', fn() => [Calc::dhiMeter([94, 96, 82, 100, 71, 90, 83]), Calc::dhiMeter([58, 74, 44, 50, 38, 81, 82]), Calc::dhiMeter([97, 98, 91, 100, 95, 78, 93])], [88, 61, 93], 'portal shows 88 / 61 / 94'],
    ['A6', 'Adherence', 'Education completion 14 of 22 modules', fn() => Calc::pct(14, 22), 64, 'portal sub-domain shows 71'],
    ['A7', 'Adherence', '8 glasses × 250 mL against a 1.5 L target and a 1.5 L limit', fn() => [Calc::fluid(8, 250, 1500)['met'], Calc::fluid(8, 250, 1500, 'limit')['met']], [true, false], 'HF needs a limit'],
    ['A8', 'Adherence', '5 g salt in sodium', fn() => Calc::saltToSodium(5), 1.97, '÷ 2.54'],

    // ── Alerts
    ['L1', 'Alerts', 'Symptom cluster for participant 014', fn() => Calc::symptomCluster($sym([0, 0, 1, 1, 2, 2, 1, 2, 2, 2], [1, 1, 1, 2, 2, 2, 3, 2, 3, 2], [1, 1, 1, 2, 2, 2, 2, 3, 2, 3])), ['days_flagged' => 7, 'window' => 7, 'fires' => true], 'portal fires'],
    ['L2', 'Alerts', 'Severe chest pain alone never fires the cluster rule', fn() => Calc::symptomCluster($sym([3, 3, 3, 3, 3, 3, 3], [0, 0, 0, 0, 0, 0, 0], [0, 0, 0, 0, 0, 0, 0]))['fires'], false, 'safety gap'],
    ['L3', 'Alerts', 'Single-day red flag: chest pain 2 / fatigue 2 / breathlessness 3', fn() => [Calc::singleDayRedFlag(['chest_pain' => 2]), Calc::singleDayRedFlag(['fatigue' => 2]), Calc::singleDayRedFlag(['breathlessness' => 3])], [true, false, true], 'PROPOSED rule'],

    // ── Randomisation
    ['R1', 'Randomisation', 'Age stratum at 59 and 60', fn() => [Calc::ageStratum(59), Calc::ageStratum(60)], ['lt60', 'ge60'], 'eCRF RAND-01'],
    ['R2', 'Randomisation', 'Sequence import check finds an unbalanced block', fn() => Calc::validateSequence([['stratum' => 'lt60', 'block_no' => 1, 'arm' => 'intervention'], ['stratum' => 'lt60', 'block_no' => 1, 'arm' => 'control'], ['stratum' => 'lt60', 'block_no' => 2, 'arm' => 'intervention'], ['stratum' => 'lt60', 'block_no' => 2, 'arm' => 'intervention']]), ['blocks' => 2, 'unbalanced' => ['lt60#2']], 'import validation'],
    ['R3', 'Randomisation', 'Signed change for display', fn() => [Calc::signedDelta(44, 38), Calc::signedDelta(38, 44), Calc::signedDelta(50, 50)], ['+6', '−6', '0'], 'portal prints ▲ for negative changes'],
];

return $cases;
