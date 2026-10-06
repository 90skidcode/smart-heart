<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SafetyAlert;
use App\Support\Audit;
use Illuminate\Http\Request;

class AlertController extends Controller
{
    public function index(Request $request)
    {
        $q = SafetyAlert::with(['participant:id,study_id,full_name', 'acknowledger:id,name', 'closer:id,name'])->orderByRaw("CASE status WHEN 'open' THEN 0 WHEN 'acknowledged' THEN 1 ELSE 2 END")
            ->orderByDesc('raised_at');
        if ($s = $request->query('status')) {
            $s === 'active' ? $q->where('status', '!=', 'closed') : $q->where('status', $s);
        }
        if ($pid = $request->query('participant_id')) {
            $q->where('participant_id', $pid);
        }

        return response()->json([
            'data' => $q->limit(300)->get()->map(fn (SafetyAlert $a) => $this->row($a)),
            'counts' => [
                'critical_open' => SafetyAlert::where('severity', 'critical')->where('status', 'open')->count(),
                'active' => SafetyAlert::where('status', '!=', 'closed')->count(),
            ],
        ]);
    }

    /** Small count for the header badge, polled by the admin panel. */
    public function summary()
    {
        return response()->json([
            'critical_open' => SafetyAlert::where('severity', 'critical')->where('status', 'open')->count(),
            'active' => SafetyAlert::where('status', '!=', 'closed')->count(),
        ]);
    }

    public function acknowledge(Request $request, SafetyAlert $alert)
    {
        if ($alert->status !== 'open') {
            return response()->json(['message' => 'Alert is already '.$alert->status.'.'], 422);
        }
        $alert->forceFill(['status' => 'acknowledged', 'acknowledged_at' => now(), 'acknowledged_by' => $request->user()->id])->save();
        Audit::log('alert_acknowledged', ['entity_type' => 'safety_alert', 'entity_id' => $alert->id, 'participant_id' => $alert->participant_id, 'new_value' => $alert->rule]);

        return response()->json($this->row($alert->fresh(['participant', 'acknowledger', 'closer'])));
    }

    public function close(Request $request, SafetyAlert $alert)
    {
        $data = $request->validate(['note' => ['required', 'string', 'min:10', 'max:2000']]);
        if ($alert->status === 'closed') {
            return response()->json(['message' => 'Alert is already closed.'], 422);
        }
        $alert->forceFill([
            'status' => 'closed', 'closed_at' => now(), 'closed_by' => $request->user()->id, 'close_note' => $data['note'],
            'acknowledged_at' => $alert->acknowledged_at ?? now(), 'acknowledged_by' => $alert->acknowledged_by ?? $request->user()->id,
        ])->save();
        Audit::log('alert_closed', ['entity_type' => 'safety_alert', 'entity_id' => $alert->id, 'participant_id' => $alert->participant_id,
            'new_value' => $alert->rule, 'reason' => $data['note']]);

        return response()->json($this->row($alert->fresh(['participant', 'acknowledger', 'closer'])));
    }

    private function row(SafetyAlert $a): array
    {
        return [
            'id' => $a->id, 'rule' => $a->rule, 'severity' => $a->severity, 'status' => $a->status, 'summary' => $a->summary,
            'participant_id' => $a->participant_id, 'study_id' => $a->participant?->study_id, 'full_name' => $a->participant?->full_name,
            'raised_at' => $a->raised_at?->toIso8601String(), 'notified_at' => $a->notified_at?->toIso8601String(),
            'escalated_at' => $a->escalated_at?->toIso8601String(),
            'acknowledged_at' => $a->acknowledged_at?->toIso8601String(), 'acknowledged_by' => $a->acknowledger?->name,
            'closed_at' => $a->closed_at?->toIso8601String(), 'closed_by' => $a->closer?->name, 'close_note' => $a->close_note,
        ];
    }
}
