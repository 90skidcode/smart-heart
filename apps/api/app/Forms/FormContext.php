<?php

namespace App\Forms;

use App\Models\CrfForm;
use App\Models\Participant;

/**
 * What a form calculator can see besides its own data: the participant and the
 * participant's other forms (e.g. eGFR in BL-01 Labs needs sex from Demographics
 * and date of birth from SCR-01). Null participant = live preview without context.
 */
class FormContext
{
    public function __construct(public readonly ?Participant $participant = null, public readonly string $today = '') {}

    public static function for(?Participant $p, ?string $today = null): self
    {
        $p?->loadMissing('forms');

        return new self($p, $today ?? now()->toDateString());
    }

    public function form(string $code, string $visit = 'BL'): ?CrfForm
    {
        return $this->participant?->form($code, $visit);
    }

    public function value(string $code, string $field, string $visit = 'BL'): mixed
    {
        return $this->form($code, $visit)?->data[$field] ?? null;
    }

    public function computed(string $code, string $field, string $visit = 'BL'): mixed
    {
        return $this->form($code, $visit)?->computed[$field] ?? null;
    }

    /** True when the form is complete or signed. */
    public function done(string $code, string $visit = 'BL'): bool
    {
        return in_array($this->form($code, $visit)?->status, ['complete', 'signed'], true);
    }
}
