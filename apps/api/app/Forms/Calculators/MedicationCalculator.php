<?php

namespace App\Forms\Calculators;

use App\Forms\Calculator;
use App\Forms\FormContext;

class MedicationCalculator extends Calculator
{
    public static function compute(array $data, FormContext $ctx): array
    {
        $rows = $data['BL_MEDS'] ?? [];
        $classes = array_column($rows, 'class');
        $has = fn (string $c) => in_array($c, $classes, true);

        return [
            'BL_DAPT' => $rows ? ($has('Antiplatelet — aspirin') && $has('Antiplatelet — P2Y12 inhibitor') ? 'Yes' : 'No') : null,
            'BL_ON_STATIN' => $rows ? ($has('Statin') ? 'Yes' : 'No') : null,
            'BL_ON_BETABLOCKER' => $rows ? ($has('Beta-blocker') ? 'Yes' : 'No') : null,
            'BL_MED_COUNT' => $rows ? count($rows) : null,
        ];
    }

    public static function validate(array $data, FormContext $ctx): array
    {
        foreach ($data['BL_MEDS'] ?? [] as $i => $r) {
            $f = $r['frequency'] ?? null;
            $n = count($r['times'] ?? []);
            $need = ['Once daily' => 1, 'At night' => 1, 'Twice daily' => 2, 'Three times daily' => 3, 'Four times daily' => 4][$f] ?? null;
            if ($need !== null && $n > 0 && $n !== $need) {
                return ['BL_MEDS' => 'Row '.($i + 1).": {$f} needs {$need} time(s) ticked, {$n} ticked."];
            }
        }

        return [];
    }
}
