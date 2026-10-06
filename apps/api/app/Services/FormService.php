<?php

namespace App\Services;

use App\Forms\FormContext;
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
 *  - Gating comes from each definition's 'gate' list (see GATES).
 */
class FormService
{
    /** Gate name => [description shown when locked, forms that must be signed/done for it]. */
    public const GATES = [
        'reg_yes' => ['REG-01'],
        'eligible_signed' => ['SCR-01'],
        'consented' => ['CON-01'],
        'baseline_ready' => [],   // BL modules + required PROs (dynamic)
    ];

    /** Gates whose upstream form cannot be unlocked while a dependent form is signed. */
    private const STRICT_GATES = ['reg_yes', 'eligible_signed', 'consented'];

    public function __construct(private ?string $today = null) {}

    // ------------------------------------------------------------------ gating

    /** Returns null when the form is available, otherwise a message saying why it is locked. */
    public function lockReason(Participant $p, string $code): ?string
    {
        if (! FormRegistry::exists($code)) {
            return 'Form not available.';
        }
        $p->loadMissing('forms');
        if ($p->status === 'withdrawn' && $code !== 'WD-01') {
            return 'Participant has withdrawn. No further data entry.';
        }
        $ctx = FormContext::for($p, $this->today());
        foreach (FormRegistry::get($code)['gate'] ?? [] as $gate) {
            if ($why = $this->gateBlock($gate, $ctx)) {
                return $why;
            }
        }

        return null;
    }

    private function gateBlock(string $gate, FormContext $ctx): ?string
    {
        switch ($gate) {
            case 'reg_yes':
                if (! $ctx->done('REG-01')) {
                    return 'Complete REG-01 Registration first.';
                }

                return $ctx->value('REG-01', 'REG_POTENTIALLY_ELIGIBLE') === 'Yes' ? null : 'REG-01 records this person as not potentially eligible.';
            case 'eligible_signed':
                if (! $ctx->form('SCR-01')?->isSigned()) {
                    return 'SCR-01 must be signed by the PI first.';
                }

                return $ctx->computed('SCR-01', 'ELIG_STATUS') === 'ELIGIBLE' ? null : 'Participant is not eligible (screen failure).';
            case 'consented':
                if (! $ctx->form('CON-01')?->isSigned()) {
                    return 'Record and sign consent (CON-01) first.';
                }

                return $ctx->value('CON-01', 'CON_GIVEN') === 'Yes' ? null : 'Participant did not give consent.';
            case 'baseline_ready':
                $missing = array_values(array_filter($this->baselineForms(), fn ($c) => ! $ctx->done($c)));

                return $missing ? 'Complete these first: '.implode(', ', $missing).'.' : null;
        }
        if (str_starts_with($gate, 'instrument_en:')) {
            $key = substr($gate, 14);

            return \App\Support\InstrumentTexts::ready($key, 'en') ? null
                : 'The licensed English wording for this questionnaire has not been entered yet (Administration → Questionnaire texts).';
        }

        return null;
    }

    /** Forms that must be complete before SAF-01: every BL module plus the required baseline PROs. */
    public function baselineForms(): array
    {
        $bl = array_values(array_filter(FormRegistry::codes(), fn ($c) => str_starts_with($c, 'BL-')));

        return array_merge($bl, array_values(array_intersect(config('smartheart.baseline.required_pros', []), FormRegistry::codes())));
    }

    /** Signed forms that depend on this one through a strict gate; they must be unlocked first. */
    private function dependents(string $code): array
    {
        $out = [];
        foreach (FormRegistry::codes() as $c) {
            foreach (FormRegistry::get($c)['gate'] ?? [] as $gate) {
                if (in_array($gate, self::STRICT_GATES, true) && in_array($code, self::GATES[$gate], true)) {
                    $out[] = $c;
                }
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------ computing

    private function calc(string $code): ?string
    {
        return FormRegistry::get($code)['calculator'] ?? null;
    }

    public function check(string $code, array $data, ?Participant $p = null): array
    {
        $ctx = FormContext::for($p, $this->today());
        $v = FormValidator::validate($code, $data, $this->today());
        $calc = $this->calc($code);
        $computed = $calc ? $calc::compute($data, $ctx) : [];
        if ($calc) {
            foreach ($calc::validate($data, $ctx) as $f => $msg) {
                $v['errors'][$f] ??= $msg;
            }
            if ($calc::missingAllowed($computed)) {
                $v['missing_allowed'] = true;
            }
        }

        return $v + ['computed' => $computed];
    }

    // ------------------------------------------------------------------ save

    /**
     * @param  array  $input      raw field values
     * @param  array  $reasons    field => reason for change (needed when the form was already complete)
     * @param  array  $overrides  field => reason for an out-of-range value
     */
    public function save(Participant $p, string $code, array $input, array $reasons, array $overrides, User|string $by, string $visit = 'BL'): array
    {
        if ($why = $this->lockReason($p, $code)) {
            return ['ok' => false, 'status' => 423, 'message' => $why];
        }

        return DB::transaction(function () use ($p, $code, $input, $reasons, $overrides, $by, $visit) {
            $form = CrfForm::where('participant_id', $p->id)->where('form_code', $code)->where('visit', $visit)->lockForUpdate()->first();
            if ($form?->isSigned()) {
                return ['ok' => false, 'status' => 423, 'message' => 'This form is signed and locked. Unlock it (with a reason) to make changes.'];
            }

            $old = $form?->data ?? [];
            $new = FormValidator::clean($code, $input);
            $check = $this->check($code, $new, $p);
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
                $r = trim((string) ($overrides[$f] ?? ($form?->overrides[$f] ?? '')));
                if ($r !== '') {
                    $keptOverrides[$f] = $r;
                }
            }

            $isNew = ! $form;
            $form ??= new CrfForm(['participant_id' => $p->id, 'form_code' => $code, 'visit' => $visit, 'status' => 'in_progress']);
            $oldOverrides = $form->overrides ?? [];
            $form->fill([
                'data' => $new,
                'computed' => $check['computed'],
                'overrides' => $keptOverrides ?: null,
                'updated_by' => $by instanceof User ? $by->id : null,
            ]);
            if ($wasComplete && ! $this->isCompletable($check, $keptOverrides)) {
                $form->status = 'in_progress';
            }
            $form->save();

            $ctx = ['entity_type' => 'form', 'entity_id' => $form->id, 'participant_id' => $p->id, 'form_code' => $code]
                + ($by instanceof User ? [] : ['user' => null, 'meta' => ['entered_by' => $by]]);
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

    public function complete(Participant $p, string $code, User|string $by, string $visit = 'BL'): array
    {
        if ($why = $this->lockReason($p, $code)) {
            return ['ok' => false, 'status' => 423, 'message' => $why];
        }
        $p->load('forms');
        $form = $p->form($code, $visit);
        if (! $form) {
            return ['ok' => false, 'status' => 422, 'message' => 'Save the form first.'];
        }
        if ($form->status !== 'in_progress') {
            return ['ok' => false, 'status' => 422, 'message' => 'Form is already '.$form->status.'.'];
        }
        $check = $this->check($code, $form->data ?? [], $p);
        $overrides = $form->overrides ?? [];
        if (! $this->isCompletable($check, $overrides)) {
            return ['ok' => false, 'status' => 422, 'message' => 'The form cannot be marked complete yet.',
                'missing' => empty($check['missing_allowed']) ? $check['missing'] : [],
                'unconfirmed' => array_values(array_diff(array_keys($check['warnings']), array_keys($overrides))),
                'errors' => $check['errors']];
        }
        $form->fill(['status' => 'complete', 'completed_at' => now(), 'completed_by' => $by instanceof User ? $by->id : null,
            'computed' => $check['computed']])->save();
        Audit::log('form_completed', ['entity_type' => 'form', 'entity_id' => $form->id, 'participant_id' => $p->id, 'form_code' => $code,
            'meta' => array_filter(['ELIG_STATUS' => $check['computed']['ELIG_STATUS'] ?? null, 'entered_by' => is_string($by) ? $by : null])]
            + ($by instanceof User ? [] : ['user' => null]));
        $this->afterChange($p, $form);

        return ['ok' => true, 'form' => $form];
    }

    // ------------------------------------------------------------------ sign

    public function sign(Participant $p, string $code, User $user, string $password): array
    {
        $p->load('forms');
        $form = $p->form($code);
        $ctx = ['entity_type' => 'form', 'entity_id' => $form?->id, 'participant_id' => $p->id, 'form_code' => $code];
        if (! Hash::check($password, $user->password)) {
            Audit::log('sign_failed', $ctx + ['reason' => 'Incorrect password']);

            return ['ok' => false, 'status' => 422, 'message' => 'Password is incorrect. The form was not signed.'];
        }
        if (! $form || $form->status !== 'complete') {
            return ['ok' => false, 'status' => 422, 'message' => 'Only a complete form can be signed.'];
        }
        $calc = $this->calc($code);
        if ($calc && ($why = $calc::signBlock($form->data ?? [], $form->computed ?? [], FormContext::for($p, $this->today())))) {
            return ['ok' => false, 'status' => 422, 'message' => $why];
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
        $p->load('forms');
        $form = $p->form($code);
        if (! $form || ! $form->isSigned()) {
            return ['ok' => false, 'status' => 422, 'message' => 'Form is not signed.'];
        }
        if ($p->status === 'randomised' && in_array($code, ['SCR-01', 'CON-01'], true)) {
            return ['ok' => false, 'status' => 422, 'message' => 'The participant is randomised. Eligibility and consent records can no longer be unlocked; record a note-to-file instead.'];
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
        $saf = $p->form('SAF-01');
        $wd = $p->form('WD-01');

        if ($wd?->isSigned()) {
            $status = 'withdrawn';
        } elseif (in_array($p->status, ['randomised'], true)) {
            return; // randomisation is final; later forms never move the status back
        } else {
            $status = 'registered';
            if ($reg && $reg->status !== 'in_progress' && ($reg->data['REG_POTENTIALLY_ELIGIBLE'] ?? null) === 'No') {
                $status = 'not_proceeding';
            } elseif ($scr) {
                $status = 'screening';
                if ($scr->isSigned()) {
                    $elig = $scr->computed['ELIG_STATUS'] ?? null;
                    if ($elig === 'NOT_ELIGIBLE') {
                        $status = 'screen_failure';
                    } elseif ($elig === 'ELIGIBLE') {
                        $status = 'eligible';
                        if ($con?->isSigned()) {
                            $status = ($con->data['CON_GIVEN'] ?? null) === 'Yes' ? 'consented' : 'declined_consent';
                            if ($status === 'consented' && $saf?->isSigned()) {
                                $status = ($saf->data['SAF_CLEARED'] ?? null) === 'Yes' ? 'ready_to_randomise' : 'safety_deferred';
                            }
                        }
                    }
                }
            }
        }

        $reasons = $status === 'screen_failure' ? ($scr->computed['SCR_FAIL_REASONS'] ?? []) : null;
        $failedOn = $status === 'screen_failure' ? ($scr->data['SCR_DATE'] ?? $scr->signed_at?->toDateString()) : null;

        $oldStatus = $p->status;
        $p->fill(['status' => $status, 'screen_fail_reasons' => $reasons, 'screen_failed_on' => $failedOn])->save();
        if ($oldStatus !== $status) {
            Audit::log('participant_status', ['entity_type' => 'participant', 'entity_id' => $p->id, 'participant_id' => $p->id,
                'form_code' => $changed->form_code, 'field' => 'status', 'old_value' => $oldStatus, 'new_value' => $status]);
        }
        if (in_array($changed->form_code, ['PRO-PHQ9', 'PRO-GAD7'], true)) {
            app(SafetyAlertService::class)->checkQuestionnaire($p, $changed);
        }
    }

    private function today(): string
    {
        return $this->today ?? now()->toDateString();
    }
}
