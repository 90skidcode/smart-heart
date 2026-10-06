<?php

namespace App\Services;

use App\Models\CrfForm;
use App\Models\Participant;
use App\Models\SafetyAlert;
use App\Support\Audit;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Questionnaire safety alerts (docs/calculation-specification.md, Alert rules):
 *   PHQ9_ITEM9   item 9 > 0 at any total → critical; never auto-clears; email now, escalate after N hours unacknowledged
 *   PHQ9_GE10    total ≥ 10 → warning (clinical review)
 *   GAD7_GE10    total ≥ 10 → warning (clinical review)
 * One alert per rule per questionnaire. Acknowledging records who/when; closing needs a note.
 */
class SafetyAlertService
{
    public function checkQuestionnaire(Participant $p, CrfForm $form): void
    {
        $c = $form->computed ?? [];
        if ($form->form_code === 'PRO-PHQ9') {
            if (($c['PHQ9_ITEM9_POSITIVE'] ?? null) === 'Yes') {
                $item9 = $form->data['PHQ9_Q9'] ?? '?';
                $this->raise($p, $form, 'PHQ9_ITEM9', 'critical', "PHQ-9 item 9 answered {$item9} (thoughts of self-harm). Same-day contact required.");
            }
            if (($c['PHQ9_CLINICAL_REVIEW'] ?? null) === 'Yes') {
                $this->raise($p, $form, 'PHQ9_GE10', 'warning', "PHQ-9 total {$c['PHQ9_TOTAL']} ({$c['PHQ9_SEVERITY']}) — clinical review.");
            }
        }
        if ($form->form_code === 'PRO-GAD7' && ($c['GAD7_REVIEW_FLAG'] ?? null) === 'Yes') {
            $this->raise($p, $form, 'GAD7_GE10', 'warning', "GAD-7 total {$c['GAD7_TOTAL']} ({$c['GAD7_SEVERITY']}) — clinical review.");
        }
    }

    private function raise(Participant $p, CrfForm $form, string $rule, string $severity, string $summary): void
    {
        $alert = SafetyAlert::firstOrCreate(
            ['rule' => $rule, 'source_form_id' => $form->id],
            ['participant_id' => $p->id, 'severity' => $severity, 'summary' => $summary, 'status' => 'open', 'raised_at' => now()],
        );
        if (! $alert->wasRecentlyCreated) {
            return;
        }
        Audit::log('alert_raised', ['entity_type' => 'safety_alert', 'entity_id' => $alert->id, 'participant_id' => $p->id,
            'form_code' => $form->form_code, 'new_value' => $rule, 'meta' => ['severity' => $severity, 'summary' => $summary]]);
        if ($severity === 'critical') {
            $this->notify($alert, config('smartheart.alerts.critical_emails', []), 'notified_at', 'CRITICAL');
        }
    }

    /** Send to every address; record the time even when mail is not configured (MAIL_MAILER=log). */
    private function notify(SafetyAlert $alert, array $to, string $stamp, string $label): void
    {
        $p = $alert->participant;
        $site = rtrim((string) config('app.url'), '/').'/admin/participants/'.$p->id;
        $body = "{$label} SAFETY ALERT — SMART-HEART\n\nParticipant: {$p->study_id}\nRule: {$alert->rule}\n{$alert->summary}\n"
            ."Raised: {$alert->raised_at->format('d M Y H:i')} IST\n\nOpen the record and acknowledge: {$site}\n\n"
            .'This message contains no clinical details beyond the alert. Do not reply.';
        foreach (array_filter($to) as $addr) {
            try {
                Mail::raw($body, fn ($m) => $m->to($addr)->subject("[SMART-HEART] {$label} alert {$p->study_id}"));
            } catch (\Throwable $e) {
                Log::error("Alert email to {$addr} failed: ".$e->getMessage());
            }
        }
        $alert->forceFill([$stamp => now()])->save();
        Audit::log($stamp === 'escalated_at' ? 'alert_escalated' : 'alert_notified', ['user' => null, 'entity_type' => 'safety_alert', 'entity_id' => $alert->id,
            'participant_id' => $alert->participant_id, 'meta' => ['recipients' => array_values(array_filter($to))]]);
    }

    /** Scheduled every 5 minutes: escalate critical alerts not acknowledged within the window. */
    public function escalateOverdue(): int
    {
        $hours = (int) config('smartheart.alerts.escalate_after_hours', 2);
        $due = SafetyAlert::with('participant')->where('severity', 'critical')->where('status', 'open')
            ->whereNull('escalated_at')->where('raised_at', '<=', now()->subHours($hours))->get();
        foreach ($due as $a) {
            $this->notify($a, config('smartheart.alerts.escalation_emails', []), 'escalated_at', "ESCALATED (unacknowledged {$hours} h)");
        }

        return $due->count();
    }
}
