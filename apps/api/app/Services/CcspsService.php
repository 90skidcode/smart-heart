<?php

namespace App\Services;

use App\Calc\Calc;
use App\Forms\FormContext;
use App\Models\Participant;
use App\Models\ScoringConfig;
use InvalidArgumentException;

/**
 * CCSPS composite (primary outcome), baseline visit.
 * Each domain scores 0/1/2 only under an APPROVED scoring config (scoring_configs);
 * without one — or without its input — the domain is pending, never 0
 * (docs/calculation-specification.md, CCSPS; vectors C1–C10 via Calc::ccsps).
 */
class CcspsService
{
    public const ENGINE = 'CCSPS';

    /** Domain => [label, rule type, input description]. */
    public const DOMAINS = [
        'bp' => ['Blood pressure', 'bp', 'Clinic BP mean (BL-01 M4)'],
        'ldl' => ['LDL-C', 'lower_better', 'LDL mg/dL (BL-01 M5)'],
        'hba1c' => ['HbA1c', 'hba1c', 'HbA1c % (BL-01 M5), diabetic status (SCR-01)'],
        'diet' => ['Diet quality', 'higher_better', 'Rate Your Plate – India (instrument not yet available)'],
        'physical_activity' => ['Physical activity', 'higher_better', 'Minutes/week moderate activity (BL-01 M7)'],
        'smoking' => ['Tobacco', 'smoking', 'Tobacco status and quit date (BL-01 M7)'],
        'sleep' => ['Sleep', 'higher_better', 'Hours/night (BL-01 M7)'],
        'bmi' => ['BMI', 'lower_better', 'BMI (BL-01 M3)'],
        'mental_health' => ['Mental health', 'mental', 'PHQ-9 and GAD-7 totals (worse governs)'],
        'medication_adherence' => ['Medication adherence', 'higher_better', 'MARS-5 total (PRO-01)'],
    ];

    /** Latest approved config per domain. */
    public function approved(): array
    {
        $out = [];
        foreach (ScoringConfig::where('engine', self::ENGINE)->where('status', 'approved')->orderBy('version')->get() as $c) {
            $out[$c->domain] = $c;
        }

        return $out;
    }

    /** Raw domain inputs at baseline, or null when missing. */
    public function inputs(FormContext $ctx): array
    {
        $num = fn ($v) => is_numeric($v) ? (float) $v : null;
        $sbp = $num($ctx->computed('BL-M4', 'BL_SBP_MEAN'));
        $dbp = $num($ctx->computed('BL-M4', 'BL_DBP_MEAN'));
        $smoke = $ctx->value('BL-M7', 'BL_SMOKING');
        $quit = $ctx->value('BL-M7', 'BL_QUIT_DATE');
        $phq = $ctx->computed('PRO-PHQ9', 'PHQ9_TOTAL');
        $gad = $ctx->computed('PRO-GAD7', 'GAD7_TOTAL');

        return [
            'bp' => ($sbp !== null && $dbp !== null) ? ['sbp' => $sbp, 'dbp' => $dbp] : null,
            'ldl' => $num($ctx->computed('BL-M5', 'BL_LDL_MGDL')),
            'hba1c' => $num($ctx->computed('BL-M5', 'BL_HBA1C_PCT')) === null ? null
                : ['value' => $num($ctx->computed('BL-M5', 'BL_HBA1C_PCT')), 'diabetic' => $ctx->value('SCR-01', 'SCR_T2DM') === 'Yes'],
            'diet' => null,
            'physical_activity' => $num($ctx->value('BL-M7', 'BL_PA_MIN_WEEK')),
            'smoking' => $smoke === null ? null : ['status' => strtolower($smoke),
                'months_since_quit' => ($smoke === 'Former' && $quit) ? intdiv(max(0, Calc::daysBetween($quit, $ctx->today)), 30) : null],
            'sleep' => $num($ctx->value('BL-M7', 'BL_SLEEP_HOURS')),
            'bmi' => $num($ctx->computed('BL-M3', 'BL_BMI')),
            'mental_health' => ($phq !== null && $gad !== null) ? ['phq9' => (int) $phq, 'gad7' => (int) $gad] : null,
            'medication_adherence' => $num($ctx->computed('PRO-MARS5', 'MARS5_TOTAL')),
        ];
    }

    /** Score one domain input under a rule. Returns 0|1|2. */
    public static function score(string $type, array $r, mixed $in): int
    {
        $band = fn (array $rr, float $x, bool $lower) => Calc::scoreDomain($x, $lower
            ? ['ideal' => fn ($v) => $v < $rr['ideal_below'], 'poor' => fn ($v) => $v >= $rr['poor_at_or_above']]
            : ['ideal' => fn ($v) => $v >= $rr['ideal_at_or_above'], 'poor' => fn ($v) => $v < $rr['poor_below']]);

        return match ($type) {
            'lower_better' => $band($r, $in, true),
            'higher_better' => $band($r, $in, false),
            'bp' => ($in['sbp'] >= $r['poor_sbp_at_or_above'] || $in['dbp'] >= $r['poor_dbp_at_or_above']) ? 0
                : (($in['sbp'] < $r['ideal_sbp_below'] && $in['dbp'] < $r['ideal_dbp_below']) ? 2 : 1),
            'hba1c' => $band($r[$in['diabetic'] ? 'diabetic' : 'non_diabetic'], $in['value'], true),
            'smoking' => match ($in['status']) {
                'never' => (int) $r['never'],
                'current' => (int) $r['current'],
                default => ($in['months_since_quit'] !== null && $in['months_since_quit'] < (int) $r['recent_quit_months'])
                    ? (int) $r['former_recent'] : (int) $r['former'],
            },
            'mental' => ($r['mild_from'] == 5 && $r['moderate_from'] == 10)
                ? Calc::mentalHealthDomain($in['phq9'], $in['gad7'])
                : 2 - max(...array_map(fn ($t) => $t >= $r['moderate_from'] ? 2 : ($t >= $r['mild_from'] ? 1 : 0), [$in['phq9'], $in['gad7']])),
        };
    }

    /** Required keys per rule type, used to validate a config before it is saved. */
    public static function requiredKeys(string $type): array
    {
        return match ($type) {
            'lower_better' => ['ideal_below', 'poor_at_or_above'],
            'higher_better' => ['ideal_at_or_above', 'poor_below'],
            'bp' => ['ideal_sbp_below', 'ideal_dbp_below', 'poor_sbp_at_or_above', 'poor_dbp_at_or_above'],
            'hba1c' => ['diabetic.ideal_below', 'diabetic.poor_at_or_above', 'non_diabetic.ideal_below', 'non_diabetic.poor_at_or_above'],
            'smoking' => ['never', 'former', 'former_recent', 'recent_quit_months', 'current'],
            'mental' => ['mild_from', 'moderate_from'],
        };
    }

    public static function validateRule(string $domain, array $rule): array
    {
        $type = self::DOMAINS[$domain][1] ?? throw new InvalidArgumentException('Unknown domain');
        $errors = [];
        foreach (self::requiredKeys($type) as $k) {
            $v = data_get($rule, $k);
            if (! is_numeric($v)) {
                $errors[] = "{$k} must be a number.";
            } elseif ($type === 'smoking' && $k !== 'recent_quit_months' && ! in_array((int) $v, [0, 1, 2], true)) {
                $errors[] = "{$k} must be 0, 1 or 2.";
            }
        }
        if (! $errors) {
            $pairs = ['lower_better' => [['ideal_below', 'poor_at_or_above']], 'hba1c' => [['diabetic.ideal_below', 'diabetic.poor_at_or_above'], ['non_diabetic.ideal_below', 'non_diabetic.poor_at_or_above']],
                'higher_better' => [['poor_below', 'ideal_at_or_above']], 'bp' => [['ideal_sbp_below', 'poor_sbp_at_or_above'], ['ideal_dbp_below', 'poor_dbp_at_or_above']],
                'mental' => [['mild_from', 'moderate_from']]][$type] ?? [];
            foreach ($pairs as [$a, $b]) {
                if (data_get($rule, $a) > data_get($rule, $b)) {
                    $errors[] = "{$a} must not be greater than {$b}.";
                }
            }
        }

        return $errors;
    }

    public function compute(Participant $p): array
    {
        $ctx = FormContext::for($p);
        $inputs = $this->inputs($ctx);
        $cfg = $this->approved();
        $domains = [];
        $scores = [];
        foreach (self::DOMAINS as $k => [$label, $type, $source]) {
            $c = $cfg[$k] ?? null;
            $in = $inputs[$k];
            $score = ($c && $in !== null) ? self::score($type, $c->rule, $in) : null;
            $scores[$k] = $score;
            $domains[] = [
                'domain' => $k, 'label' => $label, 'source' => $source, 'input' => $in, 'score' => $score,
                'config_version' => $c?->version,
                'pending_reason' => $score !== null ? null : (! $c ? 'No approved threshold yet' : 'Input missing'),
            ];
        }
        $total = Calc::ccsps($scores, (int) config('smartheart.ccsps.min_domains_proportional', 8));

        return ['engine' => self::ENGINE, 'visit' => 'BL', 'domains' => $domains, 'total' => $total,
            'normalisation' => config('smartheart.ccsps.normalisation', 'undecided')];
    }
}
