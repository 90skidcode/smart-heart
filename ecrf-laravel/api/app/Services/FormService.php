<?php

namespace App\Services;

use App\Forms\EligibilityEngine;
use App\Forms\FormRegistry;
use App\Forms\FormValidator;
use App\Models\CrfForm;
use App\Models\Participant;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * eCRF form workflow:  in_progress -> complete -> signed (locked)
 *                      signed -> (unlock with reason) -> complete -> signed again
 *
 * Rules
 *  - Hard validation errors block saving.
 *  - Out-of-plausible-range values need an override reason (stored + audited).
 *  - Once a form has been marked complete, changing a value needs a reason for change.
 *  - Signed forms cannot be edited.
 *  - Gating: SCR-01 needs REG-01 "potentially eligible = Yes";
 *            CON-01 needs SCR-01 signed as ELIGIBLE.
 */
class FormService
{
    public function __construct(private ?string $today = null) {}

    // ------------------------------------------------------------------ gating

    /** Returns null when the form is available, otherwise a message saying why it is locked. */
    public function lockReason(Participant $p, string $code): ?string
    {
        $p->loadMissing('forms');
        switch ($code) {
            case 'REG-01':
                return null;
            case 'SCR-01':
                $reg = $p->form('REG-01');
                if (! $reg || $reg->status === 'in_progress') {
                    return 'Complete REG-01 Registration first.';
                }
                if (($reg->data['REG_POTENTIALLY_ELIGIBLE'] ?? null) !== 'Yes') {
                    return 'REG-01 records this person as not potentially eligible.';
                }

                return null;
            case 'CON-01':
                $scr = $p->form('SCR-01');
                if (! $scr || ! $scr->isSigned()) {
                    return 'SCR-01 must be signed by the PI first.';
                }
                if (($scr->computed['ELIG_STATUS'] ?? null) !== 'ELIGIBLE') {
                    return 'Participant is not eligible (screen failure).';
                }

                return null;
        }

        return 'Form not available.';
    }

    /** Signed forms that depend on this one; they must be unlocked first. */
    private function dependents(string $code): array
    {
        return ['REG-01' => ['SCR-01', 'CON-01'], 'SCR-01' => ['CON-01'], 'CON-01' => []][$code] ?? [];
    }

    // ------------------------------------------------------------------ computing

    public function compute(string $code, array $data): array
    {
        return match ($code) {
            'SCR-01' => EligibilityEngine::evaluate($data)['computed'],
            default => [],
        };
    }

    public function check(string $code, array $data): array
    {
        $v = FormValidator::validate($code, $data, $this->today());
        $computed = $this->compute($code, $data);
        // A screen failure can be documented as soon as one exclusion is found ("stop" rule).
        if ($code === 'SCR-01' && ($computed['ELIG_STATUS'] ?? null) === 'NOT_ELIGIBLE') {
            $v['missing_allowed'] = true;
        }

        return $v + ['computed' => $computed];
    }

    // ------------------------------------------------------------------ save

    /**
     * @param  array  $input      raw field values
     * @param  array  $reasons    field => reason for change (needed when the form was already complete)
     * @param  array  $overrides  field => reason for an out-of-range value
     * @return array{ok:bool, status?:int, message?:string, errors?:array, form?:CrfForm, need_reasons?:array}
     */
    public function save(Participant $p, string $code, array $input, array $reasons, array $overrides, User $user): array
    {
        if ($why = $this->lockReason($p, $code)) {
            return ['ok' => false, 'status' => 423, 'message' => $why];
        }

        return DB::transaction(function () use ($p, $code, $input, $reasons, $overrides, $user) {
            $form = CrfForm::where('participant_id', $p->id)->where('form_code', $code)->where('visit', 'BL')->lockForUpdate()->first();
            if ($form?->isSigned()) {
                return ['ok' => false, 'status' => 423, 'message' => 'This form is signed and locked. Unlock it (with a reason) to make changes.'];
            }

            $old = $form?->data ?? [];
            $new = FormValidator::clean($code, $input);
            $check = $this->check($code, $new);
            if ($check['errors']) {
                return ['ok' => false, 'status' => 422, 'message' => 'Some values are not valid.', 'errors' => $check['errors']];
            }

            // Reason for change: required once the form has been completed at least once.
            $wasComplete = $form && $form->status === 'complete';
            $reasons = array_filter(array_map(fn ($r) => is_string($r) ? trim($r) : '', $reasons));
            if ($wasComplete) {
                $needed = [];
                foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $f) {
                    $a = $old[$f] ?? null;
                    if ($a !== null && Audit::str($a) !== Audit::str($new[$f] ?? null) && empty($reasons[$f])) {
                        $needed[] = $f;
                    }
                }
                if ($needed) {
                    return ['ok' => false, 'status' => 422, 'message' => 'A reason for change is required for edited values.', 'need_reasons' => $needed];
                }
            }

            // Override reasons are kept only for fields still out of range.
            $keptOverrides = [];
            foreach ($check['warnings'] as $f => $msg) {
                $r = trim((string) ($overrides[$f] ?? ($form->overrides[$f] ?? '')));
                if ($r !== '') {
                    $keptOverrides[$f] = $r;
                }
            }

            $isNew = ! $form;
            $form ??= new CrfForm(['participant_id' => $p->id, 'form_code' => $code, 'visit' => 'BL', 'status' => 'in_progress']);
            $oldOverrides = $form->overrides ?? [];
            $form->fill([
                'data' => $new,
                'computed' => $check['computed'],
                'overrides' => $keptOverrides ?: null,
                'updated_by' => $user->id,
            ]);
            // Editing a complete form that is no longer complete drops it back to in progress.
            if ($wasComplete && ! $this->isCompletable($check, $keptOverrides)) {
                $form->status = 'in_progress';
            }
            $form->save();

            $ctx = ['entity_type' => 'form', 'entity_id' => $form->id, 'participant_id' => $p->id, 'form_code' => $code];
            if ($isNew) {
                Audit::log('form_created', $ctx);
            }
            Audit::diff('field_changed', $old, $new, $ctx, $reasons);
            foreach ($keptOverrides as $f => $r) {
                if (($oldOverrides[$f] ?? null) !== $r) {
                    Audit::log('range_override', $ctx + ['field' => $f, 'new_value' => $new[$f] ?? null, 'reason' => $r]);
                }
            }
            if ($wasComplete && $form->status === 'in_progress') {
                Audit::log('form_status', $ctx + ['old_value' => 'complete', 'new_value' => 'in_progress']);
            }

            $this->afterChange($p, $form);

            return ['ok' => true, 'form' => $form];
        });
    }

    private function isCompletable(array $check, array $overrides): bool
    {
        $missingOk = ! empty($check['missing_allowed']) || ! $check['missing'];
        $warningsOk = ! array_diff(array_keys($check['warnings']), array_keys($overrides));

        return ! $check['errors'] && $missingOk && $warningsOk;
    }

    // ------------------------------------------------------------------ complete

    public function complete(Participant $p, string $code, User $user): array
    {
        if ($why = $this->lockReason($p, $code)) {
            return ['ok' => false, 'status' => 423, 'message' => $why];
        }
        $form = $p->form($code);
        if (! $form) {
            return ['ok' => false, 'status' => 422, 'message' => 'Save the form first.'];
        }
        if ($form->status !== 'in_progress') {
            return ['ok' => false, 'status' => 422, 'message' => 'Form is already '.$form->status.'.'];
        }
        $check = $this->check($code, $form->data ?? []);
        $overrides = $form->overrides ?? [];
        if (! $this->isCompletable($check, $overrides)) {
            return ['ok' => false, 'status' => 422, 'message' => 'The form cannot be marked complete yet.',
                'missing' => empty($check['missing_allowed']) ? $check['missing'] : [],
                'unconfirmed' => array_values(array_diff(array_keys($check['warnings']), array_keys($overrides))),
                'errors' => $check['errors']];
        }
        $form->fill(['status' => 'complete', 'completed_at' => now(), 'completed_by' => $user->id, 'computed' => $check['computed']])->save();
        Audit::log('form_completed', ['entity_type' => 'form', 'entity_id' => $form->id, 'participant_id' => $p->id, 'form_code' => $code,
            'meta' => $code === 'SCR-01' ? ['ELIG_STATUS' => $check['computed']['ELIG_STATUS']] : null]);
        $this->afterChange($p, $form);

        return ['ok' => true, 'form' => $form];
    }

    // ------------------------------------------------------------------ sign

    public function sign(Participant $p, string $code, User $user, string $password): array
    {
        $form = $p->form($code);
        $ctx = ['entity_type' => 'form', 'entity_id' => $form?->id, 'participant_id' => $p->id, 'form_code' => $code];
        if (! Hash::check($password, $user->password)) {
            Audit::log('sign_failed', $ctx + ['reason' => 'Incorrect password']);

            return ['ok' => false, 'status' => 422, 'message' => 'Password is incorrect. The form was not signed.'];
        }
        if (! $form || $form->status !== 'complete') {
            return ['ok' => false, 'status' => 422, 'message' => 'Only a complete form can be signed.'];
        }
        if ($code === 'SCR-01' && ($form->computed['ELIG_STATUS'] ?? null) === 'INCOMPLETE') {
            return ['ok' => false, 'status' => 422, 'message' => 'Eligibility is still incomplete (pending or "Unknown" answers). Resolve them before signing.'];
        }
        $meaning = config("smartheart.signature_meanings.{$code}") ?? config('smartheart.signature_meanings.default');
        $form->fill(['status' => 'signed', 'signed_at' => now(), 'signed_by' => $user->id, 'signature_meaning' => $meaning])->save();
        Audit::log('form_signed', $ctx + ['new_value' => $meaning, 'meta' => ['signer' => $user->name, 'designation' => $user->designation]]);
        $this->afterChange($p, $form);

        return ['ok' => true, 'form' => $form];
    }

    // ------------------------------------------------------------------ unlock

    public function unlock(Participant $p, string $code, User $user, string $reason): array
    {
        $form = $p->form($code);
        if (! $form || ! $form->isSigned()) {
            return ['ok' => false, 'status' => 422, 'message' => 'Form is not signed.'];
        }
        foreach ($this->dependents($code) as $dep) {
            if ($p->form($dep)?->isSigned()) {
                return ['ok' => false, 'status' => 422, 'message' => "{$dep} is signed and depends on this form. Unlock {$dep} first."];
            }
        }
        $form->fill(['status' => 'complete', 'signed_at' => null, 'signed_by' => null, 'signature_meaning' => null,
            'unlock_count' => $form->unlock_count + 1])->save();
        Audit::log('form_unlocked', ['entity_type' => 'form', 'entity_id' => $form->id, 'participant_id' => $p->id, 'form_code' => $code, 'reason' => $reason]);
        $this->afterChange($p, $form);

        return ['ok' => true, 'form' => $form];
    }

    // ------------------------------------------------------------------ participant status

    /** Derive participant status from the forms. Logged when it changes. */
    public function afterChange(Participant $p, CrfForm $changed): void
    {
        $p->load('forms');
        $reg = $p->form('REG-01');
        $scr = $p->form('SCR-01');
        $con = $p->form('CON-01');

        $status = 'registered';
        $reasons = null;
        $failedOn = null;
        $stratum = $p->age_stratum;

        if ($reg && $reg->status !== 'in_progress' && ($reg->data['REG_POTENTIALLY_ELIGIBLE'] ?? null) === 'No') {
            $status = 'not_proceeding';
        } elseif ($scr) {
            $status = 'screening';
            if ($scr->isSigned()) {
                $elig = $scr->computed['ELIG_STATUS'] ?? null;
                if ($elig === 'NOT_ELIGIBLE') {
                    $status = 'screen_failure';
                    $reasons = $scr->computed['SCR_FAIL_REASONS'] ?? [];
                    $failedOn = $scr->data['SCR_DATE'] ?? $scr->signed_at?->toDateString();
                } elseif ($elig === 'ELIGIBLE') {
                    $status = 'eligible';
                    $stratum = $scr->computed['AGE_STRATUM'] ?? null;
                    if ($con?->isSigned()) {
                        $status = ($con->data['CON_GIVEN'] ?? null) === 'Yes' ? 'consented' : 'declined_consent';
                    }
                }
            }
        }
        // Later phases (randomised / withdrawn) are never overwritten by early-form edits.
        if (in_array($p->status, ['randomised', 'withdrawn'], true)) {
            return;
        }

        $old = $p->only(['status', 'screen_fail_reasons', 'age_stratum']);
        $p->fill(['status' => $status, 'screen_fail_reasons' => $reasons, 'screen_failed_on' => $failedOn, 'age_stratum' => $stratum])->save();
        if ($old['status'] !== $status) {
            Audit::log('participant_status', ['entity_type' => 'participant', 'entity_id' => $p->id, 'participant_id' => $p->id,
                'form_code' => $changed->form_code, 'field' => 'status', 'old_value' => $old['status'], 'new_value' => $status]);
        }
    }

    private function today(): string
    {
        return $this->today ?? now()->toDateString();
    }
}
