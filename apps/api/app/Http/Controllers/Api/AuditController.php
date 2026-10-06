<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Participant;
use App\Support\Audit;
use Illuminate\Http\Request;

class AuditController extends Controller
{
    private function query(Request $request)
    {
        $q = AuditLog::query()->orderByDesc('id');
        if ($pid = $request->query('participant_id')) {
            $q->where('participant_id', $pid);
        }
        if ($study = trim((string) $request->query('study_id'))) {
            $ids = Participant::withTrashed()->where('study_id', 'like', "%{$study}%")->pluck('id');
            $q->whereIn('participant_id', $ids);
        }
        foreach (['action', 'form_code', 'user_id'] as $k) {
            if ($v = $request->query($k)) {
                $q->where($k, $v);
            }
        }
        if ($f = $request->query('from')) {
            $q->where('created_at', '>=', $f.' 00:00:00');
        }
        if ($t = $request->query('to')) {
            $q->where('created_at', '<=', $t.' 23:59:59');
        }

        return $q;
    }

    public function index(Request $request)
    {
        $page = $this->query($request)->paginate(50);
        $studyIds = Participant::withTrashed()->whereIn('id', $page->pluck('participant_id')->filter()->unique())->pluck('study_id', 'id');

        return response()->json([
            'data' => $page->getCollection()->map(fn (AuditLog $a) => $a->toArray() + ['study_id' => $studyIds[$a->participant_id] ?? null]),
            'total' => $page->total(),
            'page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'actions' => AuditLog::query()->distinct()->orderBy('action')->pluck('action'),
        ]);
    }

    public function download(Request $request)
    {
        $rows = $this->query($request)->limit(100000)->get();
        $studyIds = Participant::withTrashed()->pluck('study_id', 'id');
        Audit::log('export', ['meta' => ['type' => 'audit_trail', 'rows' => $rows->count(), 'filters' => $request->query()]]);

        return response()->streamDownload(function () use ($rows, $studyIds) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['id', 'timestamp_ist', 'user', 'action', 'study_id', 'form', 'field', 'old_value', 'new_value', 'reason', 'ip_address', 'meta']);
            foreach ($rows as $a) {
                fputcsv($out, [$a->id, $a->created_at?->format('Y-m-d H:i:s'), $a->user_name, $a->action, $studyIds[$a->participant_id] ?? '',
                    $a->form_code, $a->field, $a->old_value, $a->new_value, $a->reason, $a->ip_address, $a->meta ? json_encode($a->meta, JSON_UNESCAPED_UNICODE) : '']);
            }
            fclose($out);
        }, 'audit_trail_'.now()->format('Ymd_His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
