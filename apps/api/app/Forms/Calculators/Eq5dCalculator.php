<?php

namespace App\Forms\Calculators;

use App\Calc\Calc;
use App\Forms\Calculator;
use App\Forms\FormContext;
use App\Models\Eq5dValue;

/** EQ-5D-5L via Calc::eq5d5l (vectors Q12–Q13). Utility needs the licensed India value set, loaded by the study team. */
class Eq5dCalculator extends Calculator
{
    public static function compute(array $data, FormContext $ctx): array
    {
        $levels = array_map(fn ($d) => $data["EQ5D_{$d}"] ?? null, ['MO', 'SC', 'UA', 'PD', 'AD']);
        $vas = $data['EQ5D_VAS'] ?? null;
        $state = in_array(null, $levels, true) ? null : implode('', $levels);
        $utility = $state ? Eq5dValue::where('health_state', $state)->value('utility') : null;
        $r = Calc::eq5d5l($levels, $vas, $utility !== null ? [$state => (float) $utility] : null);

        return [
            'EQ5D_STATE' => $r['health_state'],
            'EQ5D_VAS_SCORE' => $r['vas'],
            'EQ5D_UTILITY' => $r['utility'],
            'EQ5D_VALUESET' => $utility !== null ? config('smartheart.scoring.eq5d_value_set', 'India (Jyani 2022)') : null,
        ];
    }
}
