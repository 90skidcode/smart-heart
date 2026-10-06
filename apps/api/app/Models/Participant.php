<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Participant extends Model
{
    use SoftDeletes;

    /** Identity fields: editable only with a reason, removed from de-identified exports. */
    public const IDENTITY_FIELDS = ['full_name', 'phone', 'hospital_number', 'address'];

    public const STATUSES = [
        'registered' => 'Registered',
        'screening' => 'Screening',
        'not_proceeding' => 'Not proceeding',
        'screen_failure' => 'Screen failure',
        'eligible' => 'Eligible',
        'declined_consent' => 'Declined consent',
        'consented' => 'Consented · baseline',
        'safety_deferred' => 'Safety clearance deferred',
        'ready_to_randomise' => 'Ready to randomise',
        'randomised' => 'Randomised',
        'withdrawn' => 'Withdrawn',
    ];

    protected $fillable = ['study_id', 'screening_id', 'full_name', 'phone', 'hospital_number', 'address',
        'status', 'arm', 'age_stratum', 'screen_fail_reasons', 'screen_failed_on', 'created_by',
        'randomised_at', 'randomisation_date', 'randomised_by'];

    protected function casts(): array
    {
        return ['screen_fail_reasons' => 'array', 'screen_failed_on' => 'date', 'randomised_at' => 'datetime', 'randomisation_date' => 'date'];
    }

    public function forms(): HasMany
    {
        return $this->hasMany(CrfForm::class);
    }

    public function form(string $code, string $visit = 'BL'): ?CrfForm
    {
        return $this->forms->first(fn ($f) => $f->form_code === $code && $f->visit === $visit);
    }
}
