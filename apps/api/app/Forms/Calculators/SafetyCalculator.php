<?php

namespace App\Forms\Calculators;

use App\Forms\Calculator;
use App\Forms\FormContext;
use App\Models\SafetyAlert;

/** Read-only summary pulled from BL-01 and PRO-01 for the PI's review. */
class SafetyCalculator extends Calculator
{
    public static function compute(array $data, FormContext $ctx): array
    {
        $c = fn ($form, $f) => $ctx->computed($form, $f);
        $v = fn ($form, $f) => $ctx->value($form, $f);
        $join = fn (...$x) => in_array(null, $x, true) ? null : implode(' ', $x);

        $sbp = $c('BL-M4', 'BL_SBP_MEAN');
        $phq = $c('PRO-PHQ9', 'PHQ9_TOTAL');
        $open = $ctx->participant
            ? SafetyAlert::where('participant_id', $ctx->participant->id)->where('status', '!=', 'closed')->count()
            : null;

        return [
            'SAF_SUM_BP' => $sbp === null ? null : number_format((float) $sbp, 1).'/'.number_format((float) $c('BL-M4', 'BL_DBP_MEAN'), 1).' mmHg',
            'SAF_SUM_HR' => $v('BL-M4', 'BL_HR'),
            'SAF_SUM_SPO2' => $v('BL-M4', 'BL_SPO2'),
            'SAF_SUM_LVEF' => $join($c('BL-M4', 'BL_LVEF') !== null ? $c('BL-M4', 'BL_LVEF').'%' : null, $c('BL-M4', 'BL_LVEF_BAND') ? '('.str_replace('_', ' ', $c('BL-M4', 'BL_LVEF_BAND')).')' : null),
            'SAF_SUM_EGFR' => $c('BL-M5', 'BL_EGFR_LAB') === null ? null : $c('BL-M5', 'BL_EGFR_LAB').' / '.($c('BL-M5', 'BL_EGFR_CKDEPI') ?? '—'),
            'SAF_SUM_6MWT' => $v('BL-M8', 'BL_6MWT_DONE') === 'Yes' ? $v('BL-M8', 'BL_6MWT_M').' m ('.$c('BL-M8', 'BL_6MWT_CATEGORY').')' : ($v('BL-M8', 'BL_6MWT_DONE') === 'No' ? 'Not done: '.$v('BL-M8', 'BL_6MWT_NOT_DONE_REASON') : null),
            'SAF_SUM_DASI' => $c('PRO-DASI', 'DASI_SCORE') === null ? null : $c('PRO-DASI', 'DASI_SCORE').' · '.$c('PRO-DASI', 'DASI_METS').' METs',
            'SAF_SUM_PHQ9' => $phq === null ? null : "{$phq} ({$c('PRO-PHQ9', 'PHQ9_SEVERITY')}) · item 9 ".($c('PRO-PHQ9', 'PHQ9_ITEM9_POSITIVE') === 'Yes' ? 'POSITIVE' : 'negative'),
            'SAF_SUM_GAD7' => $c('PRO-GAD7', 'GAD7_TOTAL') === null ? null : $c('PRO-GAD7', 'GAD7_TOTAL').' ('.$c('PRO-GAD7', 'GAD7_SEVERITY').')',
            'SAF_SUM_MEDS' => $c('BL-M6', 'BL_MED_COUNT') === null ? null : $c('BL-M6', 'BL_MED_COUNT').' drugs · DAPT '.$c('BL-M6', 'BL_DAPT').' · statin '.$c('BL-M6', 'BL_ON_STATIN'),
            'SAF_OPEN_ALERTS' => $open,
        ];
    }

    public static function signBlock(array $data, array $computed, FormContext $ctx): ?string
    {
        if (($data['SAF_CLEARED'] ?? null) === 'Yes' && ($computed['SAF_OPEN_ALERTS'] ?? 0) > 0
            && \App\Models\SafetyAlert::where('participant_id', $ctx->participant?->id)->where('severity', 'critical')->where('status', 'open')->exists()) {
            return 'A critical safety alert is still unacknowledged. Acknowledge it in Safety alerts before giving clearance.';
        }

        return null;
    }
}
