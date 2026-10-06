<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Participant;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $q = Participant::query();
        $unblinded = $request->user()->canScreen('view_allocation');
        if (($arm = $request->query('arm')) && $arm !== 'all' && $unblinded) {
            $arm === 'unassigned' ? $q->whereNull('arm') : $q->where('arm', $arm);
        }
        $rows = $q->get(['id', 'status', 'arm', 'screen_fail_reasons']);
        $by = $rows->countBy('status');

        $reasons = [];
        foreach ($rows->where('status', 'screen_failure') as $p) {
            foreach ($p->screen_fail_reasons ?? [] as $r) {
                $key = preg_replace('/\s*\(.*\)$/', '', $r); // group by criterion, drop the detail
                $reasons[$key] = ($reasons[$key] ?? 0) + 1;
            }
        }
        arsort($reasons);

        $screened = ($by['screen_failure'] ?? 0) + ($by['eligible'] ?? 0) + ($by['declined_consent'] ?? 0)
            + ($by['consented'] ?? 0) + ($by['safety_deferred'] ?? 0) + ($by['ready_to_randomise'] ?? 0)
            + ($by['randomised'] ?? 0) + ($by['withdrawn'] ?? 0);

        return response()->json([
            'target' => 240,
            'counts' => [
                'registered_total' => $rows->count(),
                'in_screening' => ($by['registered'] ?? 0) + ($by['screening'] ?? 0),
                'not_proceeding' => $by['not_proceeding'] ?? 0,
                'screened' => $screened,
                'screen_failure' => $by['screen_failure'] ?? 0,
                'eligible_awaiting_consent' => $by['eligible'] ?? 0,
                'declined_consent' => $by['declined_consent'] ?? 0,
                'consented' => ($by['consented'] ?? 0) + ($by['safety_deferred'] ?? 0) + ($by['ready_to_randomise'] ?? 0),
                'in_baseline' => ($by['consented'] ?? 0),
                'safety_deferred' => $by['safety_deferred'] ?? 0,
                'ready_to_randomise' => $by['ready_to_randomise'] ?? 0,
                'randomised' => $by['randomised'] ?? 0,
                'intervention' => $unblinded ? $rows->where('arm', 'intervention')->count() : null,
                'control' => $unblinded ? $rows->where('arm', 'control')->count() : null,
                'withdrawn' => $by['withdrawn'] ?? 0,
            ],
            'screen_failure_reasons' => collect($reasons)->map(fn ($n, $r) => ['reason' => $r, 'count' => $n])->values(),
            'statuses' => Participant::STATUSES,
            'unblinded' => $unblinded,
            'alerts' => [
                'critical_open' => \App\Models\SafetyAlert::where('severity', 'critical')->where('status', 'open')->count(),
                'active' => \App\Models\SafetyAlert::where('status', '!=', 'closed')->count(),
            ],
        ]);
    }
}
