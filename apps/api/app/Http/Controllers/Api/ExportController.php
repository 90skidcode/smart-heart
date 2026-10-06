<?php

namespace App\Http\Controllers\Api;

use App\Forms\FormRegistry;
use App\Http\Controllers\Controller;
use App\Models\AppUser;
use App\Models\MedDoseLog;
use App\Models\MedSchedule;
use App\Models\Participant;
use App\Models\Reading;
use App\Support\Audit;
use Illuminate\Http\Request;

/**
 * Data export for analysis. One row per participant, one column per eCRF field
 * (same names as the eCRF), plus system columns.
 *
 * De-identified: drops name, phone, hospital number, address, date of birth and
 * free-text name fields (consent taker, witness). Uses the study ID only.
 * Arm can be exported as the real label or as blinded A/B codes.
 */
class ExportController extends Controller
{
    /** Columns removed from de-identified exports. */
    private const IDENTIFYING_FIELDS = ['SCR_DOB', 'CON_WITNESS_NAME', 'CON_TAKEN_BY'];


    public function data(Request $request)
    {
        [$type, $armCoding, $arm] = $this->options($request);
        if ($deny = $this->authorise($request, $type)) {
            return $deny;
        }

        if (in_array($request->query('dataset'), ['readings', 'doses'], true)) {
            return $this->longExport($request->query('dataset'), $type, $armCoding, $arm);
        }

        $columns = $this->columns($type);
        $q = Participant::with('forms')->orderBy('id');
        if ($arm !== 'all') {
            $q->where('arm', $arm);
        }
        $rows = $q->get();
        $codes = config('smartheart.arm_codes', ['intervention' => 'A', 'control' => 'B']);

        Audit::log('export', ['meta' => ['type' => $type, 'arm_coding' => $armCoding, 'arm_filter' => $arm,
            'rows' => $rows->count(), 'columns' => array_keys($columns)]]);

        $name = "smartheart_{$type}_".now()->format('Ymd_His').'.csv';

        return response()->streamDownload(function () use ($rows, $columns, $type, $armCoding, $codes) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_keys($columns));
            foreach ($rows as $p) {
                $line = [];
                foreach ($columns as $col => $src) {
                    $line[] = $this->value($p, $col, $src, $armCoding, $codes);
                }
                fputcsv($out, $line);
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function dictionary(Request $request)
    {
        [$type] = $this->options($request);
        if ($deny = $this->authorise($request, $type)) {
            return $deny;
        }
        Audit::log('export', ['meta' => ['type' => 'data_dictionary', 'variant' => $type]]);

        return response()->streamDownload(function () use ($type) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['variable', 'form', 'label', 'type', 'unit', 'options / coding', 'hard_range', 'plausible_range', 'shown_when', 'notes']);
            foreach ($this->columns($type) as $col => $src) {
                fputcsv($out, $this->dictRow($col, $src));
            }
            fclose($out);
        }, "smartheart_data_dictionary_{$type}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** App data in long format: one row per reading or per answered dose. */
    private function longExport(string $dataset, string $type, string $armCoding, string $arm)
    {
        $codes = config('smartheart.arm_codes', ['intervention' => 'A', 'control' => 'B']);
        $people = Participant::when($arm !== 'all', fn ($q) => $q->where('arm', $arm))->get()->keyBy('id');
        $armOf = fn ($p) => $p?->arm ? ($armCoding === 'ab' ? $codes[$p->arm] : $p->arm) : '';
        $roles = AppUser::pluck('role', 'id');
        if ($dataset === 'readings') {
            $head = ['study_id', 'arm', 'type', 'sbp', 'dbp', 'pulse', 'glucose_mg_dl', 'glucose_context', 'weight_kg', 'measured_at', 'uploaded_at', 'source', 'device', 'entered_by'];
            $rows = Reading::whereIn('participant_id', $people->keys())->orderBy('participant_id')->orderBy('measured_at')->cursor()
                ->map(fn ($r) => [$people[$r->participant_id]->study_id, $armOf($people[$r->participant_id]), $r->type, $r->values['sbp'] ?? '', $r->values['dbp'] ?? '',
                    $r->values['pulse'] ?? '', $r->values['mg_dl'] ?? '', $r->values['context'] ?? '', $r->values['kg'] ?? '',
                    $r->measured_at->toIso8601String(), $r->uploaded_at->toIso8601String(), $r->source, $r->device, $roles[$r->app_user_id] ?? '']);
        } else {
            $drugs = MedSchedule::whereIn('participant_id', $people->keys())->get()
                ->flatMap(fn ($s) => collect($s->items)->mapWithKeys(fn ($m) => ["{$s->participant_id}|{$s->version}|{$m['key']}" => $m['drug'].' '.$m['dose']]));
            $head = ['study_id', 'arm', 'schedule_version', 'med_key', 'medicine', 'dose_date', 'slot', 'status', 'answered_at', 'received_at', 'entered_by'];
            $rows = MedDoseLog::whereIn('participant_id', $people->keys())->orderBy('participant_id')->orderBy('dose_date')->cursor()
                ->map(fn ($d) => [$people[$d->participant_id]->study_id, $armOf($people[$d->participant_id]), $d->schedule_version, $d->med_key,
                    $drugs["{$d->participant_id}|{$d->schedule_version}|{$d->med_key}"] ?? '', $d->dose_date, $d->slot, $d->status,
                    $d->answered_at->toIso8601String(), $d->received_at->toIso8601String(), $roles[$d->app_user_id] ?? '']);
        }
        Audit::log('export', ['meta' => ['type' => $type, 'dataset' => $dataset, 'arm_coding' => $armCoding, 'arm_filter' => $arm]]);

        return response()->streamDownload(function () use ($head, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $head);
            foreach ($rows as $line) {
                fputcsv($out, $line);
            }
            fclose($out);
        }, "smartheart_{$dataset}_".now()->format('Ymd_His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // ------------------------------------------------------------------

    private function options(Request $request): array
    {
        $type = $request->query('type') === 'identified' ? 'identified' : 'deidentified';
        $armCoding = $request->query('arm_coding') === 'ab' ? 'ab' : 'label';
        $arm = in_array($request->query('arm'), ['intervention', 'control'], true) ? $request->query('arm') : 'all';

        return [$type, $armCoding, $arm];
    }

    private function authorise(Request $request, string $type)
    {
        $screen = $type === 'identified' ? 'export_identified' : 'export_deidentified';
        if (! $request->user()->canScreen($screen, 'read')) {
            return response()->json(['message' => 'Your role cannot download this export.'], 403);
        }

        return null;
    }

    /** column name => source descriptor */
    private function columns(string $type): array
    {
        $cols = ['study_id' => ['sys', 'study_id'], 'screening_id' => ['sys', 'screening_id']];
        if ($type === 'identified') {
            foreach (Participant::IDENTITY_FIELDS as $f) {
                $cols[$f] = ['sys', $f];
            }
        }
        $cols += ['participant_status' => ['sys', 'status'], 'arm' => ['sys', 'arm'], 'age_stratum' => ['sys', 'age_stratum'],
            'registered_on' => ['sys', 'created_at']];

        foreach (FormRegistry::codes() as $code) {
            $prefix = strtoupper(str_replace('-', '', $code));
            $cols["{$prefix}_FORM_STATUS"] = ['meta', $code, 'status'];
            $cols["{$prefix}_SIGNED_AT"] = ['meta', $code, 'signed_at'];
            foreach (FormRegistry::fields($code) as $field => $def) {
                if ($def['type'] === 'computed') {
                    continue;
                }
                if ($type === 'deidentified' && in_array($field, self::IDENTIFYING_FIELDS, true)) {
                    continue;
                }
                $cols[$field] = ['data', $code, $field];
            }
            foreach (FormRegistry::computedKeys($code) as $c) {
                $cols[$c] = ['computed', $code, $c];
            }
        }
        foreach (['RAND_DATE', 'RAND_AGE', 'RAND_STRATUM', 'RAND_SEQ_NO', 'RAND_METHOD'] as $c) {
            $cols[$c] = ['data', 'RAND-01', $c];
        }

        return $cols;
    }

    private function value(Participant $p, string $col, array $src, string $armCoding, array $codes): string
    {
        if ($src[0] === 'sys') {
            $v = $p->{$src[1]};
            if ($src[1] === 'arm' && $v && ! request()->user()?->canScreen('view_allocation')) {
                $v = 'blinded';
            } elseif ($src[1] === 'arm' && $v && $armCoding === 'ab') {
                $v = $codes[$v] ?? '?';
            }
            if ($v instanceof \DateTimeInterface) {
                $v = $v->format('Y-m-d');
            }

            return (string) ($v ?? '');
        }
        $form = $p->form($src[1]);
        if (! $form) {
            return '';
        }
        $v = match ($src[0]) {
            'meta' => $src[2] === 'signed_at' ? $form->signed_at?->format('Y-m-d H:i:s') : $form->status,
            'data' => $form->data[$src[2]] ?? null,
            'computed' => $form->computed[$src[2]] ?? null,
        };

        if (is_array($v) && array_is_list($v) && isset($v[0]) && is_array($v[0])) {
            // Table field (e.g. BL_MEDS): one "a, b, c" group per row, rows separated by " ; ".
            return implode(' ; ', array_map(fn ($row) => implode(', ', array_map(
                fn ($x) => is_array($x) ? implode('/', $x) : (string) ($x ?? ''), $row)), $v));
        }

        return is_array($v) ? implode(' | ', $v) : (string) ($v ?? '');
    }

    private function dictRow(string $col, array $src): array
    {
        if ($src[0] === 'sys') {
            $notes = [
                'study_id' => 'System-generated study ID', 'screening_id' => 'System-generated screening ID',
                'participant_status' => 'Derived: '.implode(', ', array_keys(Participant::STATUSES)),
                'arm' => 'intervention | control, or A/B when blinded coding is chosen', 'age_stratum' => 'From SCR_AGE: <60 | ≥60',
                'registered_on' => 'Date record created (YYYY-MM-DD)',
            ];

            return [$col, 'system', $col, 'text', '', '', '', '', '', $notes[$col] ?? 'Identifiable — identified export only'];
        }
        if ($src[0] === 'meta') {
            return [$col, $src[1], $src[2] === 'status' ? 'Form status' : 'PI signature timestamp (IST)', 'text', '',
                $src[2] === 'status' ? 'in_progress | complete | signed' : '', '', '', '', 'System'];
        }
        if ($src[0] === 'computed') {
            $coding = str_starts_with($col, 'ELIG_') && ! in_array($col, ['ELIG_STATUS', 'ELIG_ENGINE', 'ELIG_STOP_SCREEN'], true) ? 'pass | fail | pending | n/a | not_evaluated'
                : ($col === 'ELIG_STATUS' ? 'ELIGIBLE | NOT_ELIGIBLE | INCOMPLETE' : '');
            $label = FormRegistry::fields($src[1])[$col]['label'] ?? $col;
            $unit = FormRegistry::fields($src[1])[$col]['unit'] ?? ($col === 'SCR_AGE' ? 'years' : ($col === 'SCR_PCI_DAYS' ? 'days' : ''));

            return [$col, $src[1], $label, 'computed', $unit, $coding, '', '', '', 'System-calculated (App\\Calc\\Calc), never entered by staff'];
        }
        if ($src[1] === 'RAND-01') {
            return [$col, 'RAND-01', $col, 'system', '', $col === 'RAND_STRATUM' ? 'lt60 | ge60' : '', '', '', '', 'Written by the randomisation service'];
        }
        $d = FormRegistry::fields($src[1])[$src[2]];
        if ($d['type'] === 'table') {
            $cols = implode(', ', array_map(fn ($c) => $c['code'], $d['columns']));

            return [$col, $src[1], $d['label'], 'table', '', "One group per row: {$cols}; rows separated by ' ; '", '', '', '', ''];
        }
        if ($d['type'] === 'choice') {
            $d['options'] = array_map(fn ($o) => "{$o['value']}={$o['label']}", $d['options']);
        }
        $range = fn ($a, $b) => (isset($d[$a]) || isset($d[$b])) ? (($d[$a] ?? '') . ' – ' . ($d[$b] ?? '')) : '';
        $show = isset($d['show_if']) ? "{$d['show_if']['field']} = {$d['show_if']['equals']}" : '';

        return [$col, $src[1], $d['label'], $d['type'], $d['unit'] ?? '', implode(' | ', $d['options'] ?? []),
            $range('min', 'max'), $range('plausible_min', 'plausible_max'), $show, ! empty($d['required']) ? 'Required' : ''];
    }
}
