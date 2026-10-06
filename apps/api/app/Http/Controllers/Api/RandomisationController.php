<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Participant;
use App\Services\CcspsService;
use App\Services\RandomisationService;
use Illuminate\Http\Request;

class RandomisationController extends Controller
{
    public function __construct(private RandomisationService $rand) {}

    public function status()
    {
        return response()->json($this->rand->status());
    }

    /** Body: { name, csv } — CSV columns stratum, seq_no, block_no, arm. Appends when a list is active. */
    public function upload(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:150'], 'csv' => ['required', 'string', 'max:2000000']]);
        $res = $this->rand->import($data['csv'], $data['name'], $request->user());

        return response()->json($res + $this->rand->status(), $res['ok'] ? 200 : 422);
    }

    public function check(Participant $participant)
    {
        return response()->json(['blockers' => $this->rand->blockers($participant), 'status' => $participant->status]);
    }

    public function randomise(Request $request, Participant $participant)
    {
        $request->validate(['password' => ['required', 'string']]);
        $res = $this->rand->randomise($participant, $request->user(), $request->input('password'));
        if (! $res['ok']) {
            return response()->json($res, $res['status'] ?? 422);
        }
        $p = $participant->fresh();
        $canSee = $request->user()->canScreen('view_allocation');

        return response()->json(['ok' => true, 'already' => $res['already'], 'status' => $p->status,
            'randomisation_date' => $p->randomisation_date, 'age_stratum' => $p->age_stratum, 'arm' => $canSee ? $p->arm : null]);
    }

    public function ccsps(Participant $participant, CcspsService $ccsps)
    {
        return response()->json($ccsps->compute($participant));
    }
}
