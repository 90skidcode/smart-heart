<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ScoringConfig;
use App\Services\CcspsService;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/** CCSPS domain thresholds: draft → approved (PI e-signature). Approving retires the previous version. */
class ScoringConfigController extends Controller
{
    public function index()
    {
        $all = ScoringConfig::with('approver:id,name')->where('engine', CcspsService::ENGINE)->orderBy('domain')->orderByDesc('version')->get();
        $domains = [];
        foreach (CcspsService::DOMAINS as $k => [$label, $type, $source]) {
            $rows = $all->where('domain', $k)->values();
            $domains[] = ['domain' => $k, 'label' => $label, 'type' => $type, 'source' => $source,
                'required_keys' => CcspsService::requiredKeys($type),
                'approved' => $this->row($rows->firstWhere('status', 'approved')),
                'draft' => $this->row($rows->firstWhere('status', 'draft')),
                'history' => $rows->map(fn ($r) => $this->row($r))];
        }

        return response()->json(['domains' => $domains, 'normalisation' => config('smartheart.ccsps.normalisation')]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['domain' => ['required', 'string'], 'rule' => ['required', 'array'], 'note' => ['required', 'string', 'min:5', 'max:1000']]);
        abort_unless(isset(CcspsService::DOMAINS[$data['domain']]), 422, 'Unknown domain');
        if ($errors = CcspsService::validateRule($data['domain'], $data['rule'])) {
            return response()->json(['message' => 'The thresholds are not valid.', 'errors' => ['rule' => $errors]], 422);
        }
        $version = (int) ScoringConfig::where('engine', CcspsService::ENGINE)->where('domain', $data['domain'])->max('version') + 1;
        ScoringConfig::where('engine', CcspsService::ENGINE)->where('domain', $data['domain'])->where('status', 'draft')->update(['status' => 'retired']);
        $c = ScoringConfig::create(['engine' => CcspsService::ENGINE, 'domain' => $data['domain'], 'version' => $version, 'rule' => $data['rule'],
            'status' => 'draft', 'note' => $data['note'], 'created_by' => $request->user()->id]);
        Audit::log('scoring_config_drafted', ['entity_type' => 'scoring_config', 'entity_id' => $c->id, 'field' => $data['domain'],
            'new_value' => $data['rule'], 'reason' => $data['note'], 'meta' => ['version' => $version]]);

        return response()->json($this->row($c), 201);
    }

    public function approve(Request $request, ScoringConfig $config)
    {
        $request->validate(['password' => ['required', 'string']]);
        if (! Hash::check($request->input('password'), $request->user()->password)) {
            return response()->json(['message' => 'Password is incorrect.'], 422);
        }
        if ($config->status !== 'draft') {
            return response()->json(['message' => 'Only a draft can be approved.'], 422);
        }
        DB::transaction(function () use ($config, $request) {
            ScoringConfig::where('engine', $config->engine)->where('domain', $config->domain)->where('status', 'approved')->update(['status' => 'retired']);
            $config->forceFill(['status' => 'approved', 'approved_at' => now(), 'approved_by' => $request->user()->id])->save();
        });
        Audit::log('scoring_config_approved', ['entity_type' => 'scoring_config', 'entity_id' => $config->id, 'field' => $config->domain,
            'new_value' => $config->rule, 'meta' => ['version' => $config->version]]);

        return response()->json($this->row($config->fresh('approver')));
    }

    private function row(?ScoringConfig $c): ?array
    {
        return $c ? ['id' => $c->id, 'version' => $c->version, 'rule' => $c->rule, 'status' => $c->status, 'note' => $c->note,
            'approved_at' => $c->approved_at?->toIso8601String(), 'approved_by' => $c->approver?->name, 'created_at' => $c->created_at?->toIso8601String()] : null;
    }
}
