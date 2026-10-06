<?php

namespace App\Services;

use App\Calc\Calc;
use App\Forms\FormContext;
use App\Models\CrfForm;
use App\Models\Participant;
use App\Models\RandomisationList;
use App\Models\RandomisationSlot;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * RAND-01 (docs/calculation-specification.md, Randomisation; vectors R1–R2).
 *  - Stratum = age in completed years on the randomisation date: lt60 | ge60.
 *  - Allocation = next unused row of the active list for that stratum, taken under a row
 *    lock in the same transaction that writes RAND-01. Irreversible; a retry returns the same arm.
 *  - Gates: SCR-01 eligible + signed, consent signed, BL-01 + required PROs complete, SAF-01 signed with clearance.
 *  - Arms are never listed; users without 'view_allocation' never see a participant's arm.
 */
class RandomisationService
{
    public const ARMS = ['intervention', 'control'];

    public const STRATA = ['lt60', 'ge60'];

    /** Parse and check a CSV list. Returns ['rows' => [...], 'errors' => [...]]. */
    public function parse(string $csv, int $startAfter = 0, array $existingMax = []): array
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', trim($csv)))));
        $errors = [];
        $rows = [];
        if (! $lines) {
            return ['rows' => [], 'errors' => ['The file is empty.']];
        }
        $head = array_map(fn ($h) => strtolower(trim($h, " \t\"\xEF\xBB\xBF")), str_getcsv(array_shift($lines)));
        foreach (['stratum', 'seq_no', 'block_no', 'arm'] as $col) {
            if (! in_array($col, $head, true)) {
                $errors[] = "Missing column '{$col}'. Required columns: stratum, seq_no, block_no, arm.";
            }
        }
        if ($errors) {
            return ['rows' => [], 'errors' => $errors];
        }
        foreach ($lines as $n => $line) {
            $r = @array_combine($head, array_map('trim', str_getcsv($line)));
            if ($r === false) {
                $errors[] = 'Line '.($n + 2).': wrong number of columns.';

                continue;
            }
            $stratum = strtolower($r['stratum']);
            $arm = strtolower($r['arm']);
            if (! in_array($stratum, self::STRATA, true)) {
                $errors[] = 'Line '.($n + 2).": stratum must be lt60 or ge60, got '{$r['stratum']}'.";
            }
            if (! in_array($arm, self::ARMS, true)) {
                $errors[] = 'Line '.($n + 2).": arm must be intervention or control, got '{$r['arm']}'.";
            }
            if (! ctype_digit($r['seq_no']) || ! ctype_digit($r['block_no'])) {
                $errors[] = 'Line '.($n + 2).': seq_no and block_no must be whole numbers.';

                continue;
            }
            $rows[] = ['stratum' => $stratum, 'seq_no' => (int) $r['seq_no'], 'block_no' => (int) $r['block_no'], 'arm' => $arm];
        }
        if ($errors) {
            return ['rows' => [], 'errors' => array_slice($errors, 0, 20)];
        }

        // Sequence numbers: unique and gap-free per stratum, continuing after any existing rows.
        foreach (self::STRATA as $s) {
            $seq = array_column(array_filter($rows, fn ($r) => $r['stratum'] === $s), 'seq_no');
            sort($seq);
            if (! $seq && ! $existingMax) {
                $errors[] = "No rows for stratum {$s}. Both strata are required.";

                continue;
            }
            $from = ($existingMax[$s] ?? 0) + 1;
            if ($seq && $seq !== range($from, $from + count($seq) - 1)) {
                $errors[] = "Stratum {$s}: seq_no must run {$from}, ".($from + 1).', … with no gaps or repeats.';
            }
        }
        $check = Calc::validateSequence($rows);
        foreach ($check['unbalanced'] as $b) {
            $errors[] = "Block {$b} is not balanced 1:1.";
        }

        return ['rows' => $errors ? [] : $rows, 'errors' => $errors, 'blocks' => $check['blocks']];
    }

    public function import(string $csv, string $name, User $user): array
    {
        return DB::transaction(function () use ($csv, $name, $user) {
            $active = RandomisationList::where('status', 'active')->lockForUpdate()->first();
            $max = $active ? RandomisationSlot::where('list_id', $active->id)->groupBy('stratum')
                ->selectRaw('stratum, MAX(seq_no) as m')->pluck('m', 'stratum')->map(fn ($v) => (int) $v)->all() : [];
            $parsed = $this->parse($csv, 0, $max);
            if ($parsed['errors']) {
                return ['ok' => false, 'errors' => $parsed['errors']];
            }
            $hash = hash('sha256', $csv);
            $list = $active ?? RandomisationList::create(['name' => $name, 'file_sha256' => $hash, 'status' => 'active', 'row_count' => 0, 'uploaded_by' => $user->id]);
            foreach (array_chunk($parsed['rows'], 200) as $chunk) {
                RandomisationSlot::insert(array_map(fn ($r) => $r + ['list_id' => $list->id], $chunk));
            }
            $list->forceFill(['row_count' => $list->row_count + count($parsed['rows'])])->save();
            Audit::log('randomisation_list_imported', ['entity_type' => 'randomisation_list', 'entity_id' => $list->id,
                'meta' => ['name' => $name, 'sha256' => $hash, 'rows' => count($parsed['rows']), 'mode' => $active ? 'append' : 'new']]);

            return ['ok' => true, 'rows' => count($parsed['rows']), 'list_id' => $list->id];
        });
    }

    /** Remaining capacity per stratum. Never reveals arms. */
    public function status(): array
    {
        $list = RandomisationList::where('status', 'active')->first();
        $out = ['list' => $list ? ['name' => $list->name, 'rows' => $list->row_count, 'sha256' => $list->file_sha256, 'uploaded_at' => $list->created_at?->toIso8601String()] : null, 'strata' => []];
        foreach (self::STRATA as $s) {
            $q = RandomisationSlot::where('list_id', $list?->id)->where('stratum', $s);
            $total = (clone $q)->count();
            $used = (clone $q)->whereNotNull('participant_id')->count();
            $out['strata'][$s] = ['total' => $total, 'used' => $used, 'remaining' => $total - $used];
        }

        return $out;
    }

    /** Gate check. Returns a list of reasons; empty = can randomise. */
    public function blockers(Participant $p): array
    {
        $p->load('forms');
        $ctx = FormContext::for($p);
        $out = [];
        if ($p->status === 'randomised') {
            return [];
        }
        if (! $ctx->form('SCR-01')?->isSigned() || $ctx->computed('SCR-01', 'ELIG_STATUS') !== 'ELIGIBLE') {
            $out[] = 'SCR-01 must be signed as eligible.';
        }
        if (! $ctx->form('CON-01')?->isSigned() || $ctx->value('CON-01', 'CON_GIVEN') !== 'Yes') {
            $out[] = 'Consent (CON-01) must be signed.';
        }
        foreach (app(FormService::class)->baselineForms() as $c) {
            if (! $ctx->done($c)) {
                $out[] = "{$c} is not complete.";
            }
        }
        if (! $ctx->form('SAF-01')?->isSigned() || $ctx->value('SAF-01', 'SAF_CLEARED') !== 'Yes') {
            $out[] = 'SAF-01 must be signed with safety clearance = Yes.';
        }
        if (! RandomisationList::where('status', 'active')->exists()) {
            $out[] = 'No randomisation list has been uploaded.';
        }
        if ($p->status === 'withdrawn') {
            $out[] = 'Participant has withdrawn.';
        }

        return $out;
    }

    public function randomise(Participant $p, User $user, string $password, ?string $today = null): array
    {
        if (! Hash::check($password, $user->password)) {
            Audit::log('randomise_failed', ['participant_id' => $p->id, 'reason' => 'Incorrect password']);

            return ['ok' => false, 'status' => 422, 'message' => 'Password is incorrect.'];
        }
        if ($p->status === 'randomised') {
            return ['ok' => true, 'already' => true];
        }
        if ($why = $this->blockers($p)) {
            return ['ok' => false, 'status' => 422, 'message' => 'Randomisation is not yet possible.', 'blockers' => $why];
        }
        $today ??= now()->toDateString();
        $dob = FormContext::for($p)->value('SCR-01', 'SCR_DOB');
        $age = Calc::ageYears($dob, $today);
        $stratum = Calc::ageStratum($age);

        return DB::transaction(function () use ($p, $user, $today, $age, $stratum) {
            $p = Participant::whereKey($p->id)->lockForUpdate()->first();
            if ($p->status === 'randomised') {
                return ['ok' => true, 'already' => true];
            }
            $slot = RandomisationSlot::query()
                ->whereIn('list_id', RandomisationList::where('status', 'active')->pluck('id'))
                ->where('stratum', $stratum)->whereNull('participant_id')
                ->orderBy('seq_no')->lockForUpdate()->first();
            if (! $slot) {
                return ['ok' => false, 'status' => 422, 'message' => "The randomisation list has no rows left for stratum {$stratum}. Ask the statistician for an extension."];
            }
            $slot->forceFill(['participant_id' => $p->id, 'used_at' => now()])->save();
            $old = $p->status;
            $p->forceFill(['arm' => $slot->arm, 'age_stratum' => $stratum, 'status' => 'randomised', 'randomised_at' => now(),
                'randomisation_date' => $today, 'randomised_by' => $user->id])->save();
            $meaning = 'I confirm that all randomisation gates were met and that this participant was allocated by the system.';
            $form = CrfForm::create(['participant_id' => $p->id, 'form_code' => 'RAND-01', 'visit' => 'BL', 'status' => 'signed',
                'data' => ['RAND_DATE' => $today, 'RAND_AGE' => $age, 'RAND_STRATUM' => $stratum, 'RAND_SEQ_NO' => $slot->seq_no,
                    'RAND_BLOCK_NO' => $slot->block_no, 'RAND_ARM' => $slot->arm, 'RAND_METHOD' => 'System (active list)'],
                'computed' => [], 'completed_at' => now(), 'completed_by' => $user->id, 'signed_at' => now(), 'signed_by' => $user->id,
                'signature_meaning' => $meaning, 'updated_by' => $user->id]);
            // The audit entry records the slot, not the arm, so audit readers stay blinded.
            Audit::log('randomised', ['entity_type' => 'form', 'entity_id' => $form->id, 'participant_id' => $p->id, 'form_code' => 'RAND-01',
                'meta' => ['stratum' => $stratum, 'age' => $age, 'seq_no' => $slot->seq_no, 'slot_id' => $slot->id]]);
            Audit::log('participant_status', ['entity_type' => 'participant', 'entity_id' => $p->id, 'participant_id' => $p->id,
                'form_code' => 'RAND-01', 'field' => 'status', 'old_value' => $old, 'new_value' => 'randomised']);

            return ['ok' => true, 'already' => false];
        });
    }
}
