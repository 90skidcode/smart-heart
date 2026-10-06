<?php

namespace App\Http\Controllers\Api;

use App\Forms\FormRegistry;
use App\Forms\Instruments\Instruments;
use App\Http\Controllers\Controller;
use App\Models\Participant;
use App\Models\SelfEntrySession;
use App\Services\FormService;
use App\Support\Audit;
use App\Support\InstrumentTexts;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Tablet self-entry. Staff open a session for ONE participant and ONE questionnaire;
 * the tablet then shows only that questionnaire (no scores, no other eCRF data) and
 * the session locks when submitted.
 */
class SelfEntryController extends Controller
{
    public function __construct(private FormService $forms) {}

    // ---------------------------------------------------------------- staff side

    public function start(Request $request, Participant $participant, string $code)
    {
        $data = $request->validate(['lang' => ['required', 'in:'.implode(',', array_keys(InstrumentTexts::LANGS))]]);
        $def = FormRegistry::exists($code) ? FormRegistry::get($code) : null;
        if (! $def || empty($def['self_entry'])) {
            return response()->json(['message' => 'This form is not a participant questionnaire.'], 422);
        }
        if ($why = $this->forms->lockReason($participant, $code)) {
            return response()->json(['message' => $why], 423);
        }
        $participant->load('forms');
        if (in_array($participant->form($code)?->status, ['complete', 'signed'], true)) {
            return response()->json(['message' => 'This questionnaire is already complete.'], 422);
        }
        if (! InstrumentTexts::ready($def['instrument'], $data['lang'])) {
            return response()->json(['message' => 'This questionnaire has no '.InstrumentTexts::LANGS[$data['lang']].' wording yet. Enter the validated text in Questionnaire texts, or choose another language.'], 422);
        }
        SelfEntrySession::where('participant_id', $participant->id)->where('form_code', $code)
            ->whereNull('completed_at')->whereNull('cancelled_at')->update(['cancelled_at' => now()]);
        $token = Str::random(40);
        $s = SelfEntrySession::create(['token_hash' => hash('sha256', $token), 'participant_id' => $participant->id, 'form_code' => $code,
            'lang' => $data['lang'], 'created_by' => $request->user()->id,
            'expires_at' => now()->addMinutes((int) config('smartheart.self_entry.minutes', 60))]);
        Audit::log('self_entry_opened', ['entity_type' => 'self_entry', 'entity_id' => $s->id, 'participant_id' => $participant->id,
            'form_code' => $code, 'meta' => ['lang' => $data['lang']]]);

        return response()->json(['token' => $token, 'path' => "/admin/entry/{$token}", 'expires_at' => $s->expires_at->toIso8601String()]);
    }

    // ---------------------------------------------------------------- tablet side (no staff login)

    private function session(string $token): SelfEntrySession
    {
        $s = SelfEntrySession::with('participant')->where('token_hash', hash('sha256', $token))->first();
        abort_if(! $s, 404, 'This questionnaire link is not valid.');
        abort_if($s->completed_at, 410, 'This questionnaire has already been completed. Thank you.');
        abort_if(! $s->isOpen(), 410, 'This questionnaire session has ended. Please ask the study team.');

        return $s;
    }

    public function show(string $token)
    {
        $s = $this->session($token);
        if (! $s->started_at) {
            $s->forceFill(['started_at' => now()])->save();
        }
        $key = FormRegistry::get($s->form_code)['instrument'];
        $inst = Instruments::get($key);
        $t = fn (string $k) => InstrumentTexts::text($key, $s->lang, $k);
        $items = [];
        foreach ($inst['items'] as $it) {
            $items[] = isset($it['scale'])
                ? ['code' => $it['code'], 'text' => $t($it['code']), 'type' => 'scale', 'min' => $it['scale'][0], 'max' => $it['scale'][1],
                    'low' => $t("{$it['code']}.low"), 'high' => $t("{$it['code']}.high"), 'required' => $it['required'] ?? true]
                : ['code' => $it['code'], 'text' => $t($it['code']), 'type' => 'choice', 'required' => $it['required'] ?? true,
                    'options' => array_map(fn ($o) => ['value' => $o['value'], 'label' => $t("{$it['code']}.{$o['key']}")], $it['options'])];
        }
        $s->participant->load('forms');

        return response()->json([
            'title' => $inst['title'], 'lang' => $s->lang, 'instructions' => $t('instructions'), 'items' => $items,
            'answers' => (object) ($s->participant->form($s->form_code)?->data ?? []),
            'expires_at' => $s->expires_at->toIso8601String(),
        ]);
    }

    /** Autosave answers so far. */
    public function save(Request $request, string $token)
    {
        $s = $this->session($token);
        $res = $this->forms->save($s->participant, $s->form_code, (array) $request->input('answers', []), [], [], "participant (self-entry #{$s->id})");

        return response()->json(['ok' => $res['ok'], 'message' => $res['message'] ?? null], $res['ok'] ? 200 : ($res['status'] ?? 422));
    }

    public function submit(Request $request, string $token)
    {
        $s = $this->session($token);
        $by = "participant (self-entry #{$s->id})";
        $res = $this->forms->save($s->participant, $s->form_code, (array) $request->input('answers', []), [], [], $by);
        if (! $res['ok']) {
            return response()->json(['message' => $res['message'] ?? 'Not saved.'], $res['status'] ?? 422);
        }
        $done = $this->forms->complete($s->participant->fresh('forms'), $s->form_code, $by);
        if (! $done['ok']) {
            return response()->json(['message' => 'Please answer every question.', 'missing' => $done['missing'] ?? []], 422);
        }
        $s->forceFill(['completed_at' => now()])->save();
        Audit::log('self_entry_completed', ['user' => null, 'entity_type' => 'self_entry', 'entity_id' => $s->id,
            'participant_id' => $s->participant_id, 'form_code' => $s->form_code]);

        // Never return scores. Show support details if PHQ-9 item 9 was positive.
        $support = null;
        if ($s->form_code === 'PRO-PHQ9' && ($done['form']->computed['PHQ9_ITEM9_POSITIVE'] ?? null) === 'Yes') {
            $support = str_replace(':phone', (string) config('smartheart.alerts.coordinator_phone', ''), config('smartheart.alerts.support_message'));
        }

        return response()->json(['done' => true, 'support_message' => $support]);
    }
}
