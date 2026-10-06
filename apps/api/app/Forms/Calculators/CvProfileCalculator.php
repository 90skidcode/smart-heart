<?php

namespace App\Forms\Calculators;

use App\Forms\Calculator;
use App\Forms\FormContext;

class CvProfileCalculator extends Calculator
{
    public static function compute(array $data, FormContext $ctx): array
    {
        $dx = $ctx->value('SCR-01', 'SCR_DIAGNOSIS');
        $sub = $ctx->value('SCR-01', 'SCR_ACS_SUBTYPE');

        return [
            'BL_INDEX_DX' => $dx ? ($dx.($sub ? " — {$sub}" : '')) : null,
            'BL_PCI_DATE' => $ctx->value('SCR-01', 'SCR_PCI_DATE'),
            'BL_T2DM' => $ctx->value('SCR-01', 'SCR_T2DM'),
        ];
    }

    public static function validate(array $data, FormContext $ctx): array
    {
        $e = [];
        $admit = $data['BL_ADMIT_DATE'] ?? null;
        $disc = $data['BL_DISCHARGE_DATE'] ?? null;
        $pci = $ctx->value('SCR-01', 'SCR_PCI_DATE');
        if ($admit && $disc && $disc < $admit) {
            $e['BL_DISCHARGE_DATE'] = 'Discharge date is before the admission date.';
        }
        if ($admit && $pci && $pci < $admit) {
            $e['BL_ADMIT_DATE'] = "Admission date is after the PCI date ({$pci}).";
        }
        if ($disc && $pci && $disc < $pci) {
            $e['BL_DISCHARGE_DATE'] = "Discharge date is before the PCI date ({$pci}).";
        }

        return $e;
    }
}
