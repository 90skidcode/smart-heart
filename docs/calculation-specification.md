# SMART-HEART Calculation Specification

Oct 3, 2026 · @Someone

## At a glance

No, the first plan checked only the headline rules. This line-by-line audit of all three prototypes found 67 calculations: 14 correct, 21 with errors, 18 with no written rule and 14 where the prototypes contradict each other. The most serious problems are in the primary outcome (the CCSPS composite cannot be measured the same way in both arms) and in safety alerts (a positive PHQ-9 item 9, or severe chest pain on its own, goes unflagged).

Change a row's status as its rule is written and approved. The detail for each row is in the section and test vectors named beside it; the defect list and decision checklist further down carry the actions.

### Screening and baseline

| # | Calculation | Where | Status | Section · vectors |
| --- | --- | --- | --- | --- |
| 1 | Age from date of birth | eCRF | Correct | Ground rules · G3–G7 |
| 2 | Days from PCI to screening | eCRF | Correct | Screening · G2, E5, E6 |
| 3 | Eligibility decision | eCRF | Error found | Screening · E1–E15 |
| 4 | Age stratum | eCRF | Rule undefined | Randomisation · R1 |
| 5 | BMI and its category | eCRF, app, portal | Conflicting | Baseline · B1–B3 |
| 6 | Clinic BP mean | eCRF | Correct | Baseline · B4 |
| 7 | Lab unit conversions | eCRF, app | Conflicting | Baseline · B5–B7 |
| 8 | LDL-C value | eCRF | Error found | Baseline · B8–B10 |
| 9 | eGFR | eCRF | Error found | Baseline · B11–B13 |
| 10 | 6MWT category | Portal | Rule undefined | Baseline · B14 |
| 11 | LVEF band | Portal | Correct | Baseline · B15 |
| 12 | Risk tier | Portal | Rule undefined | Baseline |

### Questionnaires

| # | Calculation | Where | Status | Section · vectors |
| --- | --- | --- | --- | --- |
| 13 | PHQ-9 total and bands | App, portal | Error found | Questionnaires · Q1–Q5 |
| 14 | PHQ-9 item 9 safety flag | App | Error found | Questionnaires · Q2 |
| 15 | GAD-7 total and bands | App, portal | Error found | Questionnaires · Q6, Q7 |
| 16 | DASI score | App | Correct | Questionnaires · Q8–Q11 |
| 17 | DASI VO₂peak and METs | App, eCRF | Error found | Questionnaires · Q8–Q10 |
| 18 | DASI bands | App | Error found | Questionnaires · Q10 |
| 19 | EQ-5D-5L state and VAS | App | Error found | Questionnaires · Q12, Q13 |
| 20 | EQ-5D-5L utility | eCRF | Rule undefined | Questionnaires |
| 21 | DHR readiness (app) | App | Error found | Questionnaires |
| 22 | DHRx (eCRF) | eCRF | Rule undefined | Questionnaires |
| 23 | BRiDgE | App | Correct | Questionnaires · Q14 |
| 24 | Rate Your Plate – India | App | Rule undefined | Questionnaires |

### CCSPS composite

| # | Calculation | Where | Status | Section · vectors |
| --- | --- | --- | --- | --- |
| 25 | Mental-health domain | All three | Error found | CCSPS · C1–C5 |
| 26 | Blood-pressure domain | All three | Conflicting | CCSPS |
| 27 | LDL-C domain | All three | Rule undefined | CCSPS |
| 28 | HbA1c domain | All three | Conflicting | CCSPS |
| 29 | Diet domain | App | Rule undefined | CCSPS |
| 30 | Physical-activity domain | App, portal | Conflicting | CCSPS |
| 31 | Smoking domain | All three | Rule undefined | CCSPS |
| 32 | Sleep domain | All three | Conflicting | CCSPS |
| 33 | BMI domain | App, portal | Conflicting | CCSPS |
| 34 | Medication-adherence domain | App, portal | Rule undefined | CCSPS |
| 35 | Composite total | All three | Error found | CCSPS · C6–C10 |
| 36 | 0–100 normalisation | eCRF, portal | Rule undefined | CCSPS |

### Time anchors and exercise

| # | Calculation | Where | Status | Section · vectors |
| --- | --- | --- | --- | --- |
| 37 | Rehab phase | All three | Conflicting | Time anchors · T1, T2 |
| 38 | Day counter ("Day 14") | App | Rule undefined | Time anchors |
| 39 | Visit windows and status | eCRF, app | Conflicting | Time anchors · T3, T4 |
| 40 | HRmax | App | Correct | Exercise · X9 |
| 41 | Karvonen target zone | App | Error found | Exercise · X1–X8 |
| 42 | Intensity table | App | Rule undefined | Exercise · X3, X4 |
| 43 | Zones displayed | App, portal | Conflicting | Exercise · X1, X3–X5 |
| 44 | Time in zone | Portal | Rule undefined | Exercise · X10, X11 |
| 45 | Session zone label | Portal | Correct | Exercise · X12 |
| 46 | Weekly session completion | Portal | Correct | Exercise |
| 47 | RPE scale | App, portal | Conflicting | Exercise |

### Monitoring, adherence, alerts, randomisation

| # | Calculation | Where | Status | Section · vectors |
| --- | --- | --- | --- | --- |
| 48 | Steps 7-day average | Portal | Error found | Monitoring · M2 |
| 49 | Steps status against goal | Portal | Correct | Monitoring · M11 |
| 50 | Trend arrows | Portal | Error found | Monitoring · M8, M9 |
| 51 | Sleep bands | Portal, eCRF | Conflicting | Monitoring · M10 |
| 52 | Vital tile statuses | Portal | Rule undefined | Monitoring |
| 53 | Home BP average | Portal | Rule undefined | Monitoring · M3 |
| 54 | Cohort counters | Portal | Rule undefined | Monitoring |
| 55 | Daily dose adherence | App | Error found | Adherence · A1, A2 |
| 56 | Overall medication adherence | Portal | Rule undefined | Adherence |
| 57 | DAPT below-target flag | Portal | Correct | Adherence · A3, A4 |
| 58 | DHI Adherence Meter | Portal | Error found | Adherence · A5 |
| 59 | Education completion | Portal | Conflicting | Adherence · A6 |
| 60 | Nutrition, meal and task percentages | App | Correct | Adherence |
| 61 | Fluid goal | App | Conflicting | Adherence · A7 |
| 62 | SBP > 160 alert | Portal | Correct | Alerts |
| 63 | Resting HR > 100 alert | Portal | Correct | Alerts · M4, M5 |
| 64 | Step-decline alert | Portal | Error found | Alerts · M1 |
| 65 | Symptom-cluster alert | Portal | Error found | Alerts · L1, L2 |
| 66 | Randomisation blocks | eCRF | Error found | Randomisation · R2 |
| 67 | Change arrows (▲) | Portal | Error found | Randomisation · R3 |

## Ground rules for every calculation

Every number in this document is computed by one versioned PHP function on the server. The web app and the mobile app only display results; they never recompute them.

| Topic | Rule |
| --- | --- |
| Time zone | All day arithmetic uses Asia/Kolkata calendar dates. Timestamps are stored in UTC, but a reading taken at 00:30 IST belongs to that IST date. Days start at midnight IST. |
| Day counting | Day 0 is the anchor date itself. days = date − anchor date, in calendar days. Anchors: PCI date (rehab day and phase), randomisation date (study day and visits), screening date (PCI window). |
| Age | Completed years on the reference date: subtract 1 if the birthday has not yet been reached. A 29 February birthday counts as reached on 1 March in non-leap years. Reference date: screening date for eligibility, randomisation date for the age stratum. |
| "Last 7 days" | The 7 complete IST calendar days ending yesterday. Today is never included, because a partial day always looks low (steps, doses). |
| Rounding | Round half away from zero (PHP `round()`), to the precision listed for each measure. Round first, then classify, so a BMI shown as 23.0 is never labelled "below 23". |
| Units | One canonical unit per measure: mg/dL for lipids, glucose and creatinine; % for HbA1c; mmHg; bpm; kg; cm; minutes. The value and unit as entered are kept beside the converted value. |
| Plausibility | Values outside the plausible range are rejected at entry, not silently stored (ranges in each section). |
| Missing data | Missing is never zero and never "poor". A score whose required input is missing is stored as null with status pending. Prorating is allowed only where the statistical analysis plan says so. |
| Silent defaults | No clinical input ever falls back to a default value. The app's THR calculator currently assumes age 55 and resting HR 70 when they are missing; that pattern is not allowed. |
| Versioning | Each engine carries a version (ELIG-1.0, PHQ9-1.0, CCSPS-1.0 …). Thresholds live in a versioned `scoring_configs` table, approved by the PI and statistician. A threshold change never rewrites stored results unless an audited re-score is run. |
| Trace | Every stored result keeps a trace: the input values with their record ids, each intermediate step, and the output. A monitor must be able to re-derive any number from the trace alone. |
| Participant display | The app never shows PHQ-9, GAD-7, EQ-5D-5L or DASI scores or bands. The recovery score is shown only as coaching bands, with the mental-health domain hidden. |
| Numerals | Tamil numerals in the app (௧௨௦/௮௦) are display only. Inputs accept digits 0–9 only. |

## Screening and eligibility (SCR-01)

The engine (ELIG-1.0) checks the eight screens in order and stops at the first exclusion. An "unknown" answer never counts as absent: it holds the decision at pending until the PI resolves it. Test vectors E1–E15 and G2–G7 cover every boundary below.

**Derived fields**

| Field | Formula | Precision | Verified example |
| --- | --- | --- | --- |
| Age | Completed years from date of birth to screening date | Whole years | 15 May 1971 → 4 Aug 2026 = 55 (G3) |
| PCI timing | Screening date − PCI date, in IST calendar days | Whole days | 3 Aug → 4 Aug = 1 day (G2) |
| Age stratum | Age on the randomisation date: < 60 or ≥ 60 | — | 59 → under 60, 60 → 60 and over (R1) |

**Decision rules**

| Screen | Criterion | Passes | Excludes | Held as pending | Boundary tests |
| --- | --- | --- | --- | --- | --- |
| 1 | Age | ≥ 18 | < 18 | Date of birth missing | 17 excluded, 18 passes (E15, G6, G7) |
| 2 | Diagnosis | ACS with subtype (STEMI / NSTEMI / UA), or stable IHD | "Other" | ACS subtype missing | — |
| 3 | PCI | Done, 0–30 days before screening | No PCI, or > 30 days | Date missing. A PCI date after the screening date is rejected as a data error | 30 days passes, 31 excluded (E5, E6) |
| 4 | CABG | None | Previous or current | Missing | — |
| 5 | LVEF | ≥ 40% | < 40% | Missing | 39 excluded, 40 passes (E3, E4) |
| 5 | Cardiac arrest, complex ventricular arrhythmia, cardiogenic shock | No | Yes | Unknown or missing | E13 |
| 6 | Retinopathy, neuropathy, foot ulcer | No (or not diabetic → not applicable) | Yes | Unknown or missing | E1, E14 |
| 6 | eGFR | ≥ 45 | < 45 | Missing | 44 excluded, 45 passes (E7, E8) |
| 6 | Blood pressure | SBP < 160 and DBP < 100 | SBP ≥ 160 or DBP ≥ 100 while on treatment | High but untreated, or treatment status missing → PI review | E9–E12 |
| 7 | Visual, hearing, cognitive | No, or yes with aids / caregiver making app use safe | Unsafe for app use | Missing | — |
| 8 | Digital access | Android or iOS smartphone (own or household) | No smartphone | Phone OS missing | — |

**Decision:** any exclusion → ineligible, with the first failing screen recorded and later criteria shown as "not evaluated". Otherwise any pending item → pending. Otherwise eligible. The PI confirms with an e-signature; overriding the engine requires a written reason.

**Problems found in the eCRF prototype**

- **No foot-ulcer question.** The summary table lists "retinopathy / neuropathy / foot ulcer" but the form asks only the first two. Built as drawn, a diabetic participant can never reach "eligible" (E1). Add the field.
- **DBP is ignored in the message.** The BP alert checks only "SBP 138 below 160"; DBP ≥ 100 also excludes (E11).
- **"Despite treatment" has no input.** The rule depends on antihypertensive use, but the form never asks for it.
- **One BP reading.** Define whether "uncontrolled" uses a single reading or the mean of two.
- **eGFR is typed in, not derived.** The eCRF shows eGFR 68 with creatinine 1.1 mg/dL; the CKD-EPI 2021 equation gives 79 for a 55-year-old man (B11). Labs using older equations report lower values, which matters near the 45 cut-off. Decide whether eligibility uses the lab's printed eGFR or a value computed centrally from creatinine.
- **No LVEF date.** Define which echo counts (for example, the index admission) and store its date.
- **"Other" diagnosis.** Confirm it means "not eligible" rather than PI discretion.

## Baseline clinical calculations (BL-01)

The formulas here are standard; the risk is in units, rounding and which version of a formula is used. Vectors B1–B15.

| Calculation | Formula | Inputs, units, plausible range | Stored precision | Verified example |
| --- | --- | --- | --- | --- |
| BMI | weight ÷ height², with height in metres | Height 120–220 cm, weight 30–250 kg | 1 dp | 72 kg, 165 cm → 26.4 (B1) |
| BMI category | < 18.5 underweight · 18.5–22.9 normal · 23.0–24.9 overweight · ≥ 25.0 obese (Asian / Indian cut-offs, PROPOSED) | BMI after rounding | — | 26.4 → obese (B2); 66.4 kg, 170 cm → 23.0 → overweight, not normal (B3) |
| Clinic BP | Mean of 2 readings, SBP and DBP separately | SBP 60–260, DBP 30–160 mmHg, DBP below SBP | 1 dp | 138/86 and 136/84 → 137.0/85.0 (B4) |
| Lipids to mg/dL | mmol/L × 38.67 (LDL, total, HDL); × 88.57 (triglycerides) | As entered + unit | 1 dp | LDL 2.6 mmol/L → 100.5 mg/dL (B5) |
| HbA1c to % | % = 0.09148 × mmol/mol + 2.152 (NGSP master equation) | 3.5–18 % | 2 dp | 53 mmol/mol → 7.0 % (B6) |
| Creatinine to mg/dL | µmol/L ÷ 88.42 | 0.2–15 mg/dL | 2 dp | 97.2 µmol/L → 1.10 mg/dL (B7) |
| Glucose to mg/dL | mmol/L × 18.016 | 40–700 mg/dL | 1 dp | — |
| Friedewald LDL | Total − HDL − TG ÷ 5 (mg/dL); not valid when TG ≥ 400 | All three from the same sample | 1 dp | 184 − 42 − 162 ÷ 5 = 109.6 (B8); TG 400 → not computed (B9) |
| Non-HDL cholesterol | Total − HDL | mg/dL | 1 dp | 184 − 42 = 142 (B10) |
| eGFR (CKD-EPI 2021) | 142 × min(Scr/κ, 1)^α × max(Scr/κ, 1)^−1.200 × 0.9938^age × 1.012 if female; κ = 0.7 F / 0.9 M; α = −0.241 F / −0.302 M | Creatinine mg/dL, age in years, sex | Whole number | Man 55, Cr 1.1 → 79 (B11); woman 55, Cr 1.1 → 59 (B12) |
| eGFR consistency check | abs(entered − computed) ÷ computed × 100; flag above 15 % (PROPOSED) | Lab eGFR and CKD-EPI value | 1 dp | 68 vs 79 → 13.9 %, not flagged (B13) |
| 6MWT category | < 350 poor · 350–500 intermediate · > 500 ideal | Metres | — | 349 / 350 / 500 / 501 → poor / intermediate / intermediate / ideal (B14) |
| 6MWT change | latest − baseline, shown with its sign | Metres | Whole | — |
| LVEF band | ≤ 40 reduced · 41–49 mildly reduced · ≥ 50 preserved | % | — | 40 / 41 / 49 / 50 (B15) |

**Problems found**

- **The eCRF's own lipids disagree.** LDL is entered as 98 mg/dL, but its total cholesterol, HDL and triglycerides give a Friedewald LDL of 109.6. Store whether each LDL is measured directly or calculated, and by which method.
- **The schema could not convert HbA1c.** IFCC → % needs an offset, not just a multiplier. Fixed: `lab_tests` now has `alt_to_canonical_offset`.
- **BMI targets conflict.** The app says "< 25", the clinician portal says "< 23 (Asian, provisional)". One table of cut-offs must be approved and used everywhere.
- **Risk tier is not calculated anywhere.** The portal shows Low / Low–Moderate / Moderate / High from hard-coded text. Either pick a method (for example AACVPR exercise risk stratification) or make it a clinician-entered field.
- **6MWT cut-offs have no stated source.** Confirm them, and whether 500 m is intermediate or ideal.
- **Portal sample participant 014 could not be in the trial.** LVEF 38 % fails screen 5. The data also raises a real question: what happens if LVEF falls below 40 % after randomisation?
- **No date window for baseline labs.** Define how old a lab result may be at randomisation (for example ≤ 30 days).

## Questionnaires (PRO-01 and the app)

The scores themselves are simple sums. The prototype errors are in one wrong formula (DASI METs), missing-item handling, the PHQ-9 safety flag and what participants are shown. Vectors Q1–Q14.

| Instrument | Items and values | Score | Bands and flags | Missing items | Shown to participant | Tests |
| --- | --- | --- | --- | --- | --- | --- |
| PHQ-9 | 9 items, 0–3; plus the difficulty item, stored but never added | Sum, 0–27 | 0–4 minimal · 5–9 mild · 10–14 moderate · 15–19 moderately severe · 20–27 severe. Item 9 > 0 → critical alert at any total. Total ≥ 10 → clinical review | Total = null (PROPOSED). Option for the SAP: prorate when ≤ 2 missing (sum × 9 ÷ items answered). Item 9 is always evaluated on its own | No | Q1–Q5 |
| GAD-7 | 7 items, 0–3; plus difficulty item | Sum, 0–21 | 0–4 minimal · 5–9 mild · 10–14 moderate · 15–21 severe. ≥ 10 → clinical review | Same as PHQ-9 | No | Q6, Q7 |
| DASI | 12 yes/no items with weights 2.75, 1.75, 2.75, 5.50, 8.00, 2.70, 3.50, 8.00, 4.50, 5.25, 6.00, 7.50 | Sum of "yes" weights, 0–58.2 (2 dp). VO₂peak = 0.43 × DASI + 9.6 mL/kg/min (1 dp). METs = VO₂peak ÷ 3.5 (1 dp) | No bands defined in the protocol | All 12 required | No | Q8–Q11 |
| EQ-5D-5L | 5 dimensions, levels 1–5; VAS 0–100 | Health state (5 digits), VAS, utility from the India value set (Jyani 2022; range −0.923 to 1) | — | All 5 dimensions required; VAS must be set by the participant | No | Q12, Q13 |
| DHRx (eCRF) | 10 items: Q1–Q7 scored, C1–C3 unscored | Reported as /21, continuous | None | Unknown | — | Needs scoring manual |
| BRiDgE (app) | 8 items, 1–4 | Sum, 8–32 | ≥ 26 high · 18–25 moderate · ≤ 17 low | All required | Yes (support level) | Q14 |
| Rate Your Plate – India | Not in any prototype | App shows "62/100" | Unknown | — | — | Needs scoring manual |

Verified worked examples: PHQ-9 answers 1,1,2,1,1,1,1,1,0 → 9, mild, item 9 negative (Q1). GAD-7 answers 2,1,2,1,1,2,1 → 10, moderate, review (Q6). DASI items 1–4 and 7–10 → 34.0 → VO₂peak 24.2 → 6.9 METs (Q10). DASI all "no" → 0 → 9.6 → 2.7 METs (Q9).

**Problems found**

1. **The app calls VO₂peak "METs".** It shows 0.43 × DASI + 9.6 as METs; that value is VO₂peak in mL/kg/min. METs needs ÷ 3.5.
2. **The app's DASI bands are mislabelled.** It labels DASI ≥ 34 as "> 10 METs" and 16–33 as "5–10 METs". DASI 34 is 6.9 METs, and the maximum possible (58.2) is 9.9 METs, so "> 10 METs" can never happen. 5 METs needs DASI ≥ 18.4.
3. **The eCRF's DASI example is impossible.** No combination of answers gives 28.4 (nearest: 28.2, 28.25, 28.45, 28.5). And 28.4 would be 6.2 METs, not the "\~5.2" shown.
4. **SAF-01's "functional capacity ≥ 1 MET" check is always true.** The lowest possible DASI result is 2.7 METs. Define a real threshold if one is intended.
5. **The app scores unanswered items as 0** in PHQ-9, GAD-7, DHR and BRiDgE, and as "no" in DASI. An unfinished questionnaire gets a score.
6. **The app shows scores and bands to participants** (PHQ-9, GAD-7, DASI, EQ-5D). The eCRF says they must be hidden.
7. **Item 9 is only acted on when the total is ≥ 10.** In the app, a participant who answers item 9 = 1 with a total of 3 triggers nothing (Q2). The server must raise a critical alert on any item 9 > 0. The participant must see the coordinator's number, Tele-MANAS (14416 or 1800-891-4416, 24×7) and 104.
8. **The app has no difficulty item** for PHQ-9 or GAD-7, though the eCRF stores PHQ9\_FUNCTION and GAD7\_FUNCTION.
9. **EQ-5D-5L defaults.** The VAS starts at 50, so saving without touching it records 50 (Q13). The pain VAS on the Exercise tab starts at 3 for the same reason. The app's EQ-5D wording is paraphrased; only the licensed official Tamil and English versions may be used.
10. **DHR scoring cannot reach its stated maximum, or exceeds it.** The app's 6-item DHR has a real range of 6–23 but displays "/21", so a participant can score 23/21. The eCRF's DHRx example uses a 1–5 scale, which cannot produce a /21 total from 7 items. The scoring manual is needed.
11. **The clinician portal flattens severity.** It labels any PHQ-9 ≥ 10 and any GAD-7 ≥ 10 as "MODERATE", so 15–27 shows as moderate.
12. **Translations.** PHQ-9, GAD-7 and DASI need their validated Tamil versions, not the app's own translations.

## CCSPS composite (primary outcome)

The composite cannot be computed the same way in both arms as specified, and only the mental-health domain has a written scoring rule. This is the most important finding in the audit. Vectors C1–C10.

&#91;embedded content: CCSPS domains and their data sources by arm\]

The eCRF says follow-up composites come from app data (BP averages, step counts, sleep, adherence, PROs). Control participants never get the app or the wearable, so five domains have no follow-up value for them. Comparing arms on a score built from different data is measurement bias. The PI and statistician need to choose arm-neutral sources: clinic BP at every visit, questionnaire-based activity, sleep, diet and adherence, or a wearable worn by controls without feedback.

**Formula.** Total = sum of the domains that have a score (0, 1 or 2 each). A domain with missing input is pending; it is never scored 0. Normalisation is a statistical analysis plan (SAP) decision:

- Option A, complete case: total ÷ 20 × 100, only when all 10 domains are present.
- Option B, proportional: total ÷ (2 × domains scored) × 100, when at least 8 domains are present (C10: 10 from 8 domains → 62.5).

**Domain rules.** Only mental health has a written rule. The rest must come from CCSPS\_Specification.docx; the prototypes imply the following, and some of it contradicts itself.

| Domain | Input | What the prototypes score | Rule status |
| --- | --- | --- | --- |
| Mental health | PHQ-9 and GAD-7 totals | eCRF: 9 + 10 → 0. Portal: 11 + 9 → 1 | Worse of the two governs: both 0–4 → 2, worst 5–9 → 1, worst ≥ 10 → 0 (the mild = 1 step is PROPOSED). Portal participant 014 should score 0 (C3) |
| Blood pressure | Clinic mean at baseline; home mean at follow-up | eCRF 138/86 → 1; app 120/80 → 2; portal 124/78 → 2, 168/96 → 0; portal target < 130/80 | Not defined. Needs cut-offs and a rule for treated patients |
| LDL-C | Lab, mg/dL | Portal 78 → 1, 70 → 1, 124 → 0; eCRF 98 → 0; portal target < 55 | Not defined. Implied "poor" cut-off lies between 79 and 98, which matches no common guideline cut-point (55, 70, 100) |
| HbA1c | Lab, % | Portal 6.4 → 2 but 5.8 → 1; 8.1 → 0; eCRF 7.4 → 1; app 7.2 → 1 | Not defined. A lower HbA1c scoring worse means the rule differs for diabetic and non-diabetic participants; write both rules |
| Physical activity | Steps/day (wearable) | App: 6,200 → 1. Portal: the same 6,200 average → 2 | Contradictory. Define the measure (steps or minutes per week) and cut-offs |
| Sleep | Hours/night | eCRF 6.0 h → 0; app 6.5 h → 1; portal 6.8 → 1, 5.1 → 0, 7.4 → 2 | eCRF conflicts with the portal's own bands (6–7 h = short, not poor). Baseline is self-report, follow-up is wearable: different measures |
| BMI | kg/m² | App 26.4 → 1 (target < 25); portal 25.8 → 1, 23.1 → 1, 29.4 → 0 (target < 23) | Not defined; two different targets in use |
| Smoking | Status, quit date, tobacco type | eCRF current → 0; app never → 2; portal 014 → 1 | Not defined. Needs a rule for recent quitters and for bidi and smokeless tobacco |
| Diet | Rate Your Plate – India, 0–100 | App 62 → 1 | Not defined, and the instrument's scoring is not in any prototype |
| Medication adherence | % of doses taken | App "3/4 doses today" → 1; portal means 88.8 → 2, 59.2 → 1, 95.2 → 2 | Not defined. Needs a window (7 or 30 days) and cut-offs. No baseline measure exists |

**Totals that do not add up**

- **eCRF box** shows raw score 9 from "6 domains scored". The seven listed domains add up to 3 (C6).
- **Clinician portal gauges** show 68, 44 and 79 for participants 001, 014 and 009. Their own domain pips add up to 16, 4 and 17 of 20, which is 80, 20 and 85 on a 0–100 scale (C7–C9).
- **App Assess tab** shows 14/20 (70 %), hard-coded. The app's own calculation gives 11, and it scores the missing LDL as 0; done correctly it is 10 from 8 domains (C10).
- **App labels** any total under 16 as "Moderate — keep going", including 0/20.
- **Four names** for one score: CCSPS, Composite CAD Prevention Score, Recovery Score and Secondary Prevention Composite. Use one.

## Time anchors: rehab phase, study day, visit windows

Two clocks run side by side, and the prototypes mix them. Days since PCI drive the rehab phase; days since randomisation drive the visit schedule. Vectors T1–T4.

| Clock | Day 0 | Drives | Formula |
| --- | --- | --- | --- |
| Rehab day | PCI date | Rehab phase, care-plan filtering, education recommendations, app greeting | IST date − PCI date |
| Study day | Randomisation date | Visit targets and windows, follow-up questionnaires | IST date − randomisation date |
| Screening day | Screening date | PCI ≤ 30-day eligibility window | Screening date − PCI date |

**Rehab phase (PROPOSED boundaries).** Phase I: day 0–7 · Phase II: day 8–30 · Phase III: day 31–90 · Phase IV: day 91 onwards. Days 7, 8, 30, 31, 90, 91 → I, II, II, III, III, IV (T1). The portal's examples (day 47, 21, 96 → III, II, IV) agree (T2).

**Visit windows** (targets from the eCRF; windows to confirm against the protocol):

| Visit | Target study day | Window in schema seed | App prototype window |
| --- | --- | --- | --- |
| Baseline | Before randomisation | Up to 7 days before | Day 0–7 after enrolment |
| D14 | 14 | 12–17 | 14–17 |
| D30 | 30 | 25–35 | 30–35 |
| D60 | 60 | 55–65 | 60–65 |
| D90 (primary endpoint) | 90 | 83–97 | 90–97 |
| D180 | 180 | 166–194 | Not in the app |

**Visit status:** before the window opens → scheduled; inside the window → open; past the window and not complete → missed; done → complete (T4). Day 30 from a 4 August randomisation: target 3 September, window 29 August to 8 September (T3).

**Problems found**

- **Overlapping phase labels.** "Days 0–7" and "Days 7–30", "30–90" and "> 90" leave days 7, 30 and 90 ambiguous. The boundaries above resolve this; the PI must confirm them.
- **The app lets participants choose their own phase** from a Phase 1–4 dropdown in the profile. Phase must be computed from the PCI date.
- **"Day 14 of Recovery"** on the app home screen does not say which clock it counts. The success screen shows "Phase 2 · Day 1" for the same person. Label it "Day 14 since your procedure".
- **The app's windows only open on or after the target day**, so a participant can never complete a visit early. The eCRF's schedule has early windows.
- **The app's baseline questionnaires fall after enrolment**, but PRO-01 is a gate that must be complete before randomisation, and the app only opens after randomisation. Baseline PROs belong in the eCRF.
- **Our own schema view used `UTC_DATE()`.** Between midnight and 05:30 IST that returns yesterday, so phase changes would fire 5½ hours late. Fixed: the view now uses the IST date and the boundaries above.

## Exercise: heart-rate zones, time in zone, RPE

The app codes the Karvonen formula correctly, but it feeds it a fixed resting heart rate, and its intensity table and the zones shown elsewhere disagree. The same participant gets up to four different zones. Vectors X1–X12.

**Formulas**

- HRmax = 220 − age (the prototype's choice). Alternative: 207 − 0.7 × age (Gellish 2007), which gives 169 instead of 165 at age 55 (X9). The app's sidebar also shows "192 − 0.007 × age²" with no source; the code does not use it.
- Target zone (Karvonen) = (HRmax − resting HR) × intensity % + resting HR, for the low and high % of heart-rate reserve, each rounded to a whole bpm.
- Resting HR = mean of the wearable's daily resting HR over the last 7 complete days. Never a constant.
- Time in zone = minutes with HR inside \[low, high\] ÷ minutes with any HR reading × 100. Not computed when HR covers < 50 % of the session (X10, X11).
- Session label by average HR: above zone, in zone or below zone (X12). Kept as a secondary label only.
- Weekly completion = sessions done ÷ sessions prescribed × 100; the portal flags below 60 %.

**Intensity table** (% of heart-rate reserve, copied from the app; needs cardiologist and physiotherapist sign-off):

| NYHA class | Phase II | Phase III | Phase IV |
| --- | --- | --- | --- |
| I | 45–54 | 60–69 | 80–89 |
| II | 40–45 | 55–60 | 70–80 |
| III | 35–40 | 45–54 | 45–54 |
| IV | No zone (rest / ADL only) | No zone | No zone |

Phase I has no zone (ADL only). No zone is produced when resting HR is missing or ≥ 100 bpm. For participants on a beta-blocker, RPE 11–13 on the Borg 6–20 scale is the primary guide and the HR zone is advisory. A clinician override is stored with its reason.

**The same people, four answers**

| Case | Inputs | Karvonen result | What the prototype shows |
| --- | --- | --- | --- |
| App Exercise tab | 55 y, resting HR 70, NYHA I, Phase II | 113–121 bpm (X1) | "93–102 bpm · 45–54 % MHR", which matches neither Karvonen nor 45–54 % of HRmax (74–89) |
| Same, measured resting HR | Resting HR 74 | 115–123 bpm (X2) | — |
| Portal participant 001 | 58 y, 68, NYHA I, Phase III | 124–133 bpm (X3) | 95–115 |
| Portal participant 009 | 52 y, 64, NYHA I, Phase IV | 147–157 bpm (X4) | 105–125 |
| Portal participant 014 | 64 y, 104, NYHA III, Phase II | No zone (X5); the formula would give 122–125 | 85–100 |
| Any Phase I participant | — | No zone (X6) | App falls back to 45–54 % (113–121 bpm at age 55) |

**Problems found**

- **Fixed inputs.** The app assumes resting HR 70 for everyone and age 55 when age is blank.
- **Wrong label.** "45–54 % MHR" describes a % of maximum HR; Karvonen uses % of heart-rate reserve.
- **Phase IV intensity is high.** 80–89 % of reserve gives 147–157 bpm for a 52-year-old after PCI. That needs explicit clinical approval, and probably an exercise test.
- **High resting HR breaks the formula.** With resting HR 104, Karvonen returns a zone above an already tachycardic heart rate. The guard above prevents this.
- **Beta-blockers.** All three portal participants take one, which blunts the heart-rate response, so age-predicted HRmax overstates the zone.
- **Two RPE scales.** The app asks 1–5 ("Very easy" to "Very hard"); care plans and the portal use Borg 6–20 (RPE 11–13; sessions logged at 12–16). The data cannot be compared. Use Borg 6–20 in the app.
- **Prescription mismatch.** The app shows "4–7×/wk, 15–20 min"; the Phase II care plan says "15–20 min × 5/week".
- **Wrong message.** The Vitals tab says resting HR is "within THR target". Resting HR is never compared with the exercise zone.
- **No exercise safety alert.** Portal participant 014 exercised at an average 112 bpm against an 85–100 zone, at RPE 16, and nothing fired. PROPOSED rule: average HR > zone high + 10 bpm, or Borg RPE ≥ 15, raises a warning.

## Monitoring metrics (portal tiles and app vitals)

The portal's tiles are hard-coded sample values with no stated rule, and some contradict their own charts. These rules define each one. Every window uses complete IST days, excluding today. Vectors M1–M11.

| Metric | Formula | Window, minimum data | Status bands | Check |
| --- | --- | --- | --- | --- |
| Home BP | Mean of all home readings, SBP and DBP separately, whole mmHg | 7 days; at least 6 readings, ideally 2 each morning and evening (PROPOSED) | Not defined; portal target < 130/80 | M3 |
| Resting HR | Mean of the wearable's daily resting HR | 7 days; at least 4 days | Not defined | — |
| HRV (SDNN) | Mean of device-reported daily values | 7 days | Not defined. Confirm the watch exports HRV at all | — |
| SpO₂ | Mean of spot checks | 7 days | Not defined; portal shows 95 % as "watch" | — |
| Weight | Latest value, plus change over time | Any 3-day span | Weight alone has no ideal band. PROPOSED flag: gain > 2 kg within 3 days | M6, M7 |
| Steps, 7-day average | Mean over valid days only; a valid day has ≥ 600 minutes of wear | 7 days; at least 4 valid days | ≥ goal on target · ≥ 60 % of goal below goal · lower is low | M2, M11 |
| METs | Device-reported average | 7 days | Not defined; no source named | — |
| Sleep | Total sleep minutes (awake time excluded) of the session that ends on that IST date; stage % = stage minutes ÷ total sleep | 7 days; at least 4 nights | ≥ 7 h ideal · 6 to < 7 h short · < 6 h poor | M10 |
| Trend arrow | PROPOSED: mean of the last 3 days − mean of the first 3, against a minimum change per metric (e.g. 5 mmHg, 5 bpm, 1 kg, 2 % SpO₂) | 7 days | Improving / stable / worsening | M9 |
| Last sync | Now − time of the newest record received | — | — | — |

**Problems found**

- **The step averages contradict their own bar charts.** The tiles show 6,200, 2,400 and 8,400; the last 7 bars average 6,486, 2,357 and 8,500.
- **The trend rule ignores units.** The portal compares only the first and last values and calls any change ≥ 0.5 a trend, whether mmHg, kg, % or ms. So SpO₂ 96 → 97 shows as "improving" (M8).
- **Status chips have no rules.** Weight 88.4 kg is "watch" and SpO₂ 95 % is "watch"; nothing says why.
- **Cohort counters are undefined.** "Flagged today: 3" counts three alerts for one participant; define it as participants with an open alert. "Wearable wear-time 91 %" needs a formula, for example valid days ÷ days since activation, averaged over participants.
- **The app says "Normal" for every BP.** Participants need defined messages for very high BP, low BP with symptoms and low SpO₂. The PI must set those numbers; none exist today.
- **App vitals are hard-coded and disagree.** Resting HR is 74 on Home and 72 on Vitals.

## Adherence and engagement

Adherence feeds a CCSPS domain and the DHI Adherence Meter, so every numerator and denominator must be exact. Vectors A1–A8.

| Measure | Formula | Window | Check |
| --- | --- | --- | --- |
| Dose adherence, per drug | Doses marked taken ÷ doses due × 100. "Due" = scheduled doses whose time slot has ended, excluding PRN drugs and doses after a stop date. Not marked by the end of the slot + 2 h (PROPOSED grace) = missed | Today, 7 days, 30 days | 2 of 3 morning doses at 9 AM → 67 (A2) |
| Overall medication adherence | Either dose-weighted (all doses taken ÷ all due) or drug-weighted (mean of per-drug %). The portal uses drug-weighted: 88.8, 59.2 and 95.2 for its three participants. Pick one | 7 or 30 days | — |
| DAPT below target | Any DAPT drug (aspirin or the P2Y12 inhibitor) < 70 % → critical alert | 7 days | 70 and 58 → fires; 70 and 71 → no (A3, A4) |
| DHI Adherence Meter | Mean of the 7 sub-domains below (unweighted, as in the portal) | 7 days | 88 / 61 / 93 for the portal's three participants (A5) |
| Education completion | Modules completed ÷ modules in the library (22) × 100 | Since activation | 14 of 22 → 64 (A6) |
| Nutrition adherence (app) | Guidelines met ÷ 5 × 100 | Day | — |
| Meals logged (app) | Meals logged ÷ 5 | Day | — |
| Daily task completion | Tasks ticked ÷ 7 × 100 | Day | — |
| Walk progress | Minutes done ÷ target minutes | Session | 13 ÷ 20 = 65 % (correct in the app) |
| Fluid | Glasses × mL per glass, compared with a minimum target or, for heart-failure patients, a maximum limit | Day | 8 × 250 mL = 2.0 L: meets a 1.5 L target but breaks a 1.5 L limit (A7) |
| Salt to sodium | Sodium g = salt g ÷ 2.54 | — | 5 g salt = 1.97 g sodium, consistent with the "< 2 g sodium" care plan (A8) |

**DHI meter sub-domains** (none is defined in the prototypes; PROPOSED definitions):

| Sub-domain | Numerator ÷ denominator |
| --- | --- |
| App usage frequency | Days with ≥ 1 app open ÷ days in window |
| Wearable device use | Valid wear days (≥ 600 min) ÷ days in window |
| Self-report data entry | Entries made ÷ entries expected (BP readings, symptom diary, meals) |
| Teleconsult attendance | Attended ÷ scheduled in window; excluded when none was scheduled |
| Education modules | Completed ÷ assigned and phase-recommended (or ÷ all 22: decide) |
| Caregiver engagement | Days with a caregiver view ÷ days in window; excluded when caregiver mode is off |
| Symptom / med reporting | Days with a symptom log or dose marks ÷ days in window |

**Problems found**

- **The app counts doses not yet due.** At 9 AM it shows 40 % (2 of 5 for the whole day) when the participant is fully up to date on morning doses (A1 vs A2).
- **Two adherence figures disagree.** The app's composite says "3/4 doses taken today"; its Meds tab shows 2 of 5.
- **The app's medicine list is clinically wrong.** It schedules aspirin 75 mg morning and evening (it is once daily) and has no P2Y12 inhibitor, so DAPT adherence cannot be measured. For the same participant ID the portal lists ticagrelor, metoprolol 50 mg and ramipril 5 mg against the app's metoprolol 25 mg and ramipril 2.5 mg. The list must come from the eCRF, verified by staff.
- **Caregiver engagement is 78 % for a participant whose caregiver mode is off** (participant 009).
- **The education sub-domain disagrees with the library.** It shows 71 % and 38 %, but completed modules give 64 % and 27 %.
- **One meter value is wrong.** Participant 009 shows 94; the mean of its sub-domains is 93.1.
- **The fluid goal contradicts itself.** "8 glasses" is 2.0 L at 250 mL a glass, while the target says 1.5 L. For participant 014 (EF 38 %, on furosemide) a fluid goal should be a limit, not a target.

## Alert rules

Every rule below is written precisely enough to code and test. Two prototype alerts disagree with their own sample data, and a severe single symptom triggers nothing at all.

| Rule | Exact condition | Window | Severity | Prototype check |
| --- | --- | --- | --- | --- |
| SBP\_GT\_160 | Any home SBP reading > 160 mmHg (PROPOSED: confirmed by a repeat reading within 15 min) | Per reading | Critical | 014: 168 → fires |
| RHR\_GT\_100 | Daily resting HR > 100 bpm on each of the last 3 complete days | 3 days | Critical | 014: 101, 103, 104 → fires (M4) |
| STEP\_DECLINE\_50 | (prior 7-day mean − last 7-day mean) ÷ prior 7-day mean × 100 > 50, valid days only | 14 complete days | Warning | 014: 45.2 % → does not fire (M1). The portal says "down 54 %" |
| SYMPTOM\_CLUSTER | In the last 7 logged days, ≥ 2 days on which ≥ 2 of chest pain, breathlessness and ankle swelling are ≥ 2 (moderate) | 7 days | Warning; PROPOSED critical when any of the three is 3 | 014: 7 of 7 days → fires (L1) |
| RED\_FLAG\_SYMPTOM (new, PROPOSED) | Chest pain ≥ 2, or any symptom = 3, on a single day | Day | Critical, same-day call | L2, L3 |
| PHQ9\_ITEM9 | PHQ-9 item 9 > 0, at any total | Per questionnaire | Critical, same day; never auto-clears | Q2 |
| PHQ9\_GE10 / GAD7\_GE10 | Total ≥ 10 | Per questionnaire | Warning | eCRF GAD-7 10 → fires |
| DAPT\_ADH\_LT70 | Any DAPT drug < 70 % | 7 days | Critical | A3 |
| EXERCISE\_ABOVE\_ZONE (new, PROPOSED) | Session average HR > zone high + 10 bpm, or Borg RPE ≥ 15 | Session | Warning | 014: 112 bpm at RPE 16 |
| WEIGHT\_GAIN (new, PROPOSED, heart-failure risk) | Gain > 2 kg within 3 days | 3 days | Warning | M6 |
| NO\_SYNC\_72H | No data received for 72 h | 72 h | Info | — |

**How alerts behave.** Rules run every 5 minutes on new data and nightly for windowed rules. One open alert per rule, participant and IST date (de-duplication key). An alert clears automatically once its condition has been false for 3 consecutive days, except PHQ9\_ITEM9 and urgent care plans, which need a clinician's acknowledgement. Acknowledging records who and when; resolving requires a note.

**Problems found**

- **The step-decline alert disagrees with its own data.** The portal says "down 54 %"; the 14-day series gives 45.2 % on a 7-day vs 7-day comparison. Comparing the last day with the first gives 59.6 %. The rule was never written down.
- **Severe chest pain alone never alerts.** The cluster rule needs two symptoms on two days, so chest pain at "severe" every day for a week fires nothing (L2).
- **The cluster is only a warning.** For participant 014 (EF 38 %, severe breathlessness and ankle swelling) it should be critical.
- **The app and portal use different symptom lists.** The app asks yes/no for chest pain, breathlessness, fever > 38 °C, rapid heartbeat, extreme fatigue and cough. The portal's diary and cluster rule expect 0–3 severity for chest pain, breathlessness, palpitations, dizziness, ankle swelling and fatigue. The app never asks about ankle swelling or dizziness, so the cluster rule could never fire on real app data. Use one list, the union of both, each scored 0–3.
- **The app promises a response but sends nothing.** It tells the participant to "inform your care team immediately" when any symptom is ticked; nothing reaches the care team.

## Randomisation (RAND-01)

The allocation arithmetic is simple. The risks are a predictable block size and a baseline gate that can never pass as specified. Vectors R1–R3.

| Item | Rule | Check |
| --- | --- | --- |
| Ratio and target | 1:1, 240 participants (120 per arm). The eCRF's "499 screened" estimate implies 48.1 % of those screened are randomised (240 ÷ 499) | — |
| Stratum | Age on the randomisation date, in completed years: < 60 or ≥ 60 | 59 → under 60, 60 → 60 and over (R1) |
| Allocation | Next unused row in the statistician's list for site + stratum, taken under a row lock in the same transaction that writes RAND-01. Irreversible; retries return the same result | — |
| Gates | SCR-01 eligible and signed, consent recorded, BL-01 complete, PRO-01 complete, SAF-01 cleared | — |
| List import check | Every block balanced 1:1, sequence numbers without gaps, both strata present, no row used twice | Unbalanced block found (R2) |

**Problems found**

- **Fixed block size of 4.** Site staff are not blinded to allocation, so with a known block of 4 they can predict the last allocation of every block, and often the last two. PROPOSED: random permuted blocks of 2 and 4 (or 4 and 6), with sizes known only to the statistician.
- **Which age sets the stratum** is not stated. Someone who turns 60 between screening and randomisation changes stratum; use the randomisation date.
- **The baseline gate can never pass as specified.** RAND-01 requires BL-01 "11/11 complete", including module 10 (CCSPS). That module cannot complete while BL-01 has no diet, physical-activity or adherence inputs (see CCSPS).
- **Envelopes or software?** The eCRF says "Record sealed · SNOSE protocol" (sequentially numbered, opaque, sealed envelopes), a paper method, next to an electronic allocation. Decide which is primary; envelopes can be the documented backup for system downtime.
- **Arm counts are visible to everyone.** The eCRF dashboard shows "Intervention 2 · Control 2" to all users; blinded roles must not see the split.

## Defects found, by severity

The audit found 45 defects: 9 critical, 17 high, 15 medium and 4 low. Critical means a wrong primary outcome, a missed safety signal or a participant wrongly excluded. Update Status as each is fixed in the build or the protocol.

### Critical (9)

| # | Defect | Where | Fix | Status |
| --- | --- | --- | --- | --- |
| D1 | Control arm has no follow-up source for 5 CCSPS domains (BP, activity, sleep, adherence, diet) | eCRF App Integration Map | Arm-neutral sources, decided by PI + statistician | Open |
| D2 | Baseline CCSPS cannot complete: BL-01 has no diet, activity or adherence input, so the RAND-01 gate can never pass | eCRF BL-01 M7, M10 | Add instruments and fields | Open |
| D3 | 9 of 10 CCSPS domains have no written thresholds, and the prototypes contradict each other | All three | Approve one threshold table | Open |
| D4 | Composite totals wrong: eCRF 9 (actual 3), portal 68/44/79 (actual 80/20/85), app 14 (actual 11) | All three | Server computes; clients display | Open |
| D5 | PHQ-9 item 9 > 0 with total < 10 triggers nothing | App | Critical alert on any item 9 > 0 | Open |
| D6 | Severe chest pain alone never alerts | Portal cluster rule | Add RED\_FLAG\_SYMPTOM | Open |
| D7 | App symptom list (yes/no) lacks ankle swelling and dizziness, so the cluster rule cannot fire on real data | App vs portal | One list, each 0–3 | Open |
| D8 | Missing input scored as 0 (LDL "lab result needed" → 0/2) | App | Pending, never 0 | Open |
| D9 | No foot-ulcer question, so a diabetic participant can never be eligible | eCRF SCR-01 | Add field | Open |

### High (17)

| # | Defect | Where | Fix | Status |
| --- | --- | --- | --- | --- |
| D10 | DASI VO₂peak shown as METs | App | Divide by 3.5 | Open |
| D11 | DASI bands mislabelled; "> 10 METs" is impossible | App | Correct or remove bands | Open |
| D12 | Mental-health domain = 2 whenever PHQ-9 is done | App | Precedence rule | Open |
| D13 | Mental-health domain 1 for PHQ-9 = 11 (should be 0) | Portal | Precedence rule | Open |
| D14 | THR uses resting HR 70 and age 55 as defaults | App | Measured inputs, no defaults | Open |
| D15 | Phase I gets a 45–54 % zone instead of none | App | No zone in Phase I | Open |
| D16 | Exercise-tab zone 93–102 matches no formula; portal zones differ from Karvonen | App, portal | One server-computed zone | Open |
| D17 | RPE 1–5 in the app vs Borg 6–20 everywhere else | App | Borg 6–20 | Open |
| D18 | Step-decline alert says 54 %; its data gives 45.2 % | Portal | Written rule | Open |
| D19 | Daily adherence counts doses not yet due | App | Due-so-far denominator | Open |
| D20 | Medicine list: aspirin twice daily, no P2Y12 inhibitor, doses differ from portal | App | eCRF-sourced, verified list | Open |
| D21 | Fixed block size 4 is predictable | eCRF RAND-01 | Variable block sizes | Open |
| D22 | PRO scores and bands shown to participants | App | Hide | Open |
| D23 | Unanswered items scored as 0 | App | Null, or SAP prorating rule | Open |
| D24 | EQ-5D VAS pre-set to 50; pain VAS pre-set to 3 | App | No default value | Open |
| D25 | DHR shows /21 but can reach 23; eCRF DHRx scale cannot total 21 | App, eCRF | Obtain scoring manual | Open |
| D26 | Fluid: 8 glasses ≠ 1.5 L; no limit for heart-failure patients | App | Per-participant target or limit | Open |

### Medium (15)

| # | Defect | Where | Fix | Status |
| --- | --- | --- | --- | --- |
| D27 | eCRF DASI example 28.4 is unreachable; 5.2 METs is wrong | eCRF PRO-01 | Correct example | Open |
| D28 | SAF-01 "≥ 1 MET" check is always true | eCRF SAF-01 | Real threshold or remove | Open |
| D29 | eGFR 68 typed in; creatinine gives 79 (CKD-EPI 2021) | eCRF | Decide the eligibility source | Open |
| D30 | LDL 98 entered; lipids give 109.6 (Friedewald); method not stored | eCRF | Store LDL method | Open |
| D31 | BP exclusion ignores DBP; no "on treatment" question | eCRF SCR-01 | Add both | Open |
| D32 | Rehab phase boundaries overlap at days 7, 30, 90 | All three | Approve boundaries | Open |
| D33 | Participant chooses own phase; "Day 14" does not say which clock | App | Compute and label | Open |
| D34 | Every PHQ-9 / GAD-7 ≥ 10 labelled "MODERATE" | Portal | Full severity bands | Open |
| D35 | Step tiles contradict their bar charts | Portal | Compute from data | Open |
| D36 | Trend rule uses ±0.5 for every unit | Portal | Per-metric minimum change | Open |
| D37 | Changes always shown with ▲, even when negative | Portal | Signed change | Open |
| D38 | DHI meter 94 vs 93.1; education 71/38 vs 64/27; caregiver 78 % with caregiver mode off | Portal | Defined sub-domains | Open |
| D39 | BMI target < 25 in app, < 23 in portal | App, portal | One cut-off table | Open |
| D40 | Risk tier hard-coded, no method | Portal | Choose a method or make it entered | Open |
| D41 | Sample participant 014 (LVEF 38 %) fails eligibility | Portal | Fix test data; define post-randomisation LVEF rule | Open |

### Low (4)

| # | Defect | Where | Fix | Status |
| --- | --- | --- | --- | --- |
| D42 | Four names for the composite | All three | One name | Open |
| D43 | "Flagged today" counts alerts; wear-time % undefined | Portal | Define both | Open |
| D44 | Resting HR described as "within THR target" | App | Fix wording | Open |
| D45 | Our schema: phase view used the UTC date; HbA1c conversion needed an offset | schema.sql | Fixed in this release | Fixed |

## Decisions needed before the engines are frozen

23 decisions block the calculation engines. Everything marked PROPOSED in this document is a default for these discussions, not a decision. Tick each one when the decision is written into the protocol, the SAP or the CCSPS specification.

**PI and statistician (primary outcome)**

- [ ] Choose arm-neutral data sources for BP, physical activity, sleep, medication adherence and diet at every visit (D1)
- [ ] Add baseline instruments for diet, activity and adherence, or change the randomisation gate (D2)
- [ ] Approve the threshold table for all 10 CCSPS domains, with separate HbA1c rules for diabetic and non-diabetic participants and a rule for treated BP (D3)
- [ ] Confirm the mental-health mapping: both minimal → 2, worst mild → 1, worst moderate or above → 0
- [ ] Choose normalisation: complete case (all 10 domains) or proportional (at least 8)
- [ ] Decide whether PHQ-9 and GAD-7 totals may be prorated when 1–2 items are missing
- [ ] Define medication adherence: dose- or drug-weighted, 7- or 30-day window, and its cut-offs
- [ ] Approve variable block sizes and stratification by age on the randomisation date
- [ ] Confirm every visit window, D14 to D180

**PI (clinical rules)**

- [ ] eGFR for eligibility: the lab's printed value or CKD-EPI 2021 from creatinine; tolerance for the consistency check
- [ ] "Uncontrolled BP" at screening: one reading or the mean of two; how treatment status is recorded
- [ ] Which echo provides LVEF, and what happens if LVEF falls below 40 % after randomisation
- [ ] Rehab phase day boundaries (0–7, 8–30, 31–90, 91+)
- [ ] Exercise intensity table and the Phase IV ceiling, with the physiotherapist; whether an exercise test is required
- [ ] Alert thresholds: SBP confirmation reading, single-symptom red flag, cluster severity, weight gain, exercise above zone
- [ ] Messages and numbers for participants: very high BP, low BP with symptoms, low SpO₂
- [ ] Fluid: which participants get a limit rather than a target, and the volumes
- [ ] Method for the risk tier, BMI cut-offs and 6MWT categories

**Instruments and licences**

- [ ] Obtain the DHRx and Rate Your Plate – India scoring manuals
- [ ] Register for the EQ-5D-5L digital licence and the India value set
- [ ] Obtain validated Tamil versions of PHQ-9, GAD-7, DASI and EQ-5D-5L

**Data management**

- [ ] Store the LDL method and the lab's eGFR equation with each result
- [ ] Adopt one symptom list (union of app and portal lists, each scored 0–3) and one name for the composite

## Verification: reference code and test vectors

Every worked example in this document comes from a reference PHP library, checked by 102 automated test vectors; all 102 pass on PHP 8.3.6. CI must re-run them on PHP 8.5, the production version. The vector ids (G3, E1, Q10 …) are quoted beside each example above.

| File | What it is |
| --- | --- |
| calculations/Calc.php | Reference library: one pure function per calculation (eligibility, BMI, lab conversions, eGFR, PHQ-9, GAD-7, DASI, EQ-5D-5L, BRiDgE, CCSPS, rehab phase, visit windows, THR, time in zone, monitoring, adherence, alerts, randomisation checks). No dependencies. |
| calculations/run\_tests.php | The 102 vectors, each with its expected value and where that expectation comes from |
| calculations/test\_vectors.json | The same vectors as JSON, so the web and mobile teams can test their display code against them |
| schema.sql | Updated: `scoring_configs` table, lab conversion offset and method, eGFR and LVEF fields, fluid target or limit, IST-based phase view, three new alert rules, draft parameters for every engine |
| openapi.yaml | Updated: Calculations endpoints (evaluate an engine, manage and approve scoring configs) and a step-by-step trace on every score |

| Area | Vectors | Count |
| --- | --- | --- |
| Ground rules (dates, age) | G1–G7 | 7 |
| Eligibility | E1–E15 | 15 |
| Baseline clinical | B1–B15 | 15 |
| Questionnaires | Q1–Q14 | 14 |
| CCSPS | C1–C10 | 10 |
| Time anchors | T1–T4 | 4 |
| Exercise | X1–X12 | 12 |
| Monitoring | M1–M11 | 11 |
| Adherence | A1–A8 | 8 |
| Alerts | L1–L3 | 3 |
| Randomisation | R1–R3 | 3 |

**The tests were checked too.** Five deliberate bugs were injected one at a time: a phase boundary of `< 7` instead of `≤ 7`, a DASI intercept of 9.0 instead of 9.6, a wrong mental-health mapping, LVEF `≤ 40` instead of `< 40`, and truncation instead of rounding. Each was caught, failing between 1 and 13 vectors.

**How the build uses them**

1. The production engines in `api/src/Domain` must pass the same vectors in CI; a failing vector blocks the merge.
2. A threshold change means a new `scoring_configs` version, updated vectors, a full re-run, and PI and statistician sign-off.
3. The web and mobile apps never compute; the JSON vectors test their display formatting only.
4. Validation documents (operational qualification) cite vector ids as evidence.

**What the vectors cannot prove yet.** Anything marked PROPOSED, the nine undefined CCSPS domain cut-offs, DHRx and Rate Your Plate scoring, and EQ-5D-5L utilities have no vectors until their rules are approved. Each decision in the checklist above should add its own vectors.

## Sources

Audited files: SMART\_HEART\_eCRF.html, smart\_heart\_clinician\_dashboard.html and smart\_heart\_idhayam.html, every script line and every displayed number.

Formulas and numbers checked against these pages:

- [CKD-EPI creatinine equation (2021)](https://www.kidney.org/professionals/ckd-epi-creatinine-equation-2021), National Kidney Foundation: constant, κ, α and age factor.
- [IFCC standardisation of HbA1c](https://ngsp.org/ifccngsp.asp), NGSP: master equation % = 0.09148 × mmol/mol + 2.152.
- [DASI calculator](https://www.omnicalculator.com/health/dasi): the 12 item weights, maximum 58.2, VO₂peak = 0.43 × DASI + 9.6, METs = VO₂peak ÷ 3.5.
- [Indian EQ-5D-5L value set (DEVINE study)](<https://www.ispor.org/publications/journals/value-in-health/abstract/Volume-25--Issue-7/Development-of-an-EQ-5D-Value-Set-for-India-Using-an-Extended-Design-(DEVINE)-Study--The-Indian-5-Level-Version-EQ-5D-Value-Set>), Jyani et al., Value in Health 2022: values range from −0.923 to 1.
- [Longitudinal modelling of age and maximal heart rate](https://pubmed.ncbi.nlm.nih.gov/17468581/), Gellish et al., Med Sci Sports Exerc 2007: HRmax = 207 − 0.7 × age.
- [Tele-MANAS](https://iitk.ac.in/counsel/TeleManas.php): 14416 or 1800-891-4416, 24×7.

Taken from standard published guidance and not re-fetched for this audit: PHQ-9 bands (Kroenke 2001), GAD-7 bands (Spitzer 2006), Friedewald LDL, LVEF bands (universal definition of heart failure 2021) and Asian BMI cut-offs (WHO expert consultation 2004).
