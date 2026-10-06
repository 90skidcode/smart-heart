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
        if (($arm = $request->query('arm')) && $arm !== 'all') {
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
            + ($by['consented'] ?? 0) + ($by['randomised'] ?? 0) + ($by['withdrawn'] ?? 0);

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
                'consented' => ($by['consented'] ?? 0),
                'randomised' => $by['randomised'] ?? 0,
                'intervention' => $rows->where('arm', 'intervention')->count(),
                'control' => $rows->where('arm', 'control')->count(),
                'withdrawn' => $by['withdrawn'] ?? 0,
            ],
            'screen_failure_reasons' => collect($reasons)->map(fn ($n, $r) => ['reason' => $r, 'count' => $n])->values(),
            'statuses' => Participant::STATUSES,
        ]);
    }
}
