<?php

namespace App\Http\Controllers\Api;

use App\Forms\Instruments\Instruments;
use App\Http\Controllers\Controller;
use App\Models\Eq5dValue;
use App\Models\InstrumentText;
use App\Support\Audit;
use App\Support\InstrumentTexts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Administration → Questionnaire texts: licensed / validated wording per instrument and language. */
class InstrumentController extends Controller
{
    public function index()
    {
        $out = [];
        foreach (Instruments::all() as $key => $inst) {
            $langs = [];
            foreach (array_keys(InstrumentTexts::LANGS) as $l) {
                $missing = InstrumentTexts::missing($key, $l);
                $langs[$l] = ['ready' => ! $missing, 'missing' => count($missing)];
            }
            $out[] = ['key' => $key, 'form' => $inst['form'], 'title' => $inst['title'], 'licensed' => $inst['licensed'],
                'source' => $inst['source'], 'languages' => $langs];
        }

        return response()->json(['instruments' => $out, 'languages' => InstrumentTexts::LANGS,
            'eq5d_value_set_rows' => Eq5dValue::count()]);
    }

    /** All text keys for one instrument and language, with labels so staff know what each key is. */
    public function show(string $key, string $lang)
    {
        $inst = Instruments::get($key);
        abort_unless($inst && isset(InstrumentTexts::LANGS[$lang]), 404);
        $stored = InstrumentText::where('instrument', $key)->where('lang', $lang)->pluck('text', 'key');
        $rows = [['key' => 'instructions', 'what' => 'Instructions shown above the questions (optional)']];
        foreach ($inst['items'] as $n => $it) {
            $rows[] = ['key' => $it['code'], 'what' => 'Question '.($n + 1).' ('.$it['code'].')'];
            foreach ($it['options'] ?? [] as $o) {
                $rows[] = ['key' => "{$it['code']}.{$o['key']}", 'what' => "  Answer scored {$o['value']}"];
            }
            if (isset($it['scale'])) {
                $rows[] = ['key' => "{$it['code']}.low", 'what' => "  Label at {$it['scale'][0]}"];
                $rows[] = ['key' => "{$it['code']}.high", 'what' => "  Label at {$it['scale'][1]}"];
            }
        }
        foreach ($rows as &$r) {
            $r['text'] = $stored[$r['key']] ?? null;
            $r['builtin'] = Instruments::builtin($inst, $lang, $r['key']);
        }

        return response()->json(['key' => $key, 'title' => $inst['title'], 'lang' => $lang, 'licensed' => $inst['licensed'],
            'source' => $inst['source'], 'rows' => $rows]);
    }

    public function update(Request $request, string $key, string $lang)
    {
        $inst = Instruments::get($key);
        abort_unless($inst && isset(InstrumentTexts::LANGS[$lang]), 404);
        $data = $request->validate(['texts' => ['required', 'array'], 'texts.*' => ['nullable', 'string', 'max:2000'],
            'source_note' => ['required', 'string', 'min:5', 'max:500']]);
        $allowed = Instruments::textKeys($inst);
        $old = InstrumentText::where('instrument', $key)->where('lang', $lang)->pluck('text', 'key')->all();
        DB::transaction(function () use ($data, $allowed, $key, $lang, $request) {
            foreach ($data['texts'] as $k => $text) {
                if (! in_array($k, $allowed, true)) {
                    continue;
                }
                $text = $text === null ? '' : trim($text);
                if ($text === '') {
                    InstrumentText::where(['instrument' => $key, 'lang' => $lang, 'key' => $k])->delete();
                } else {
                    InstrumentText::updateOrCreate(['instrument' => $key, 'lang' => $lang, 'key' => $k], ['text' => $text, 'updated_by' => $request->user()->id]);
                }
            }
        });
        InstrumentTexts::flush();
        $new = InstrumentText::where('instrument', $key)->where('lang', $lang)->pluck('text', 'key')->all();
        Audit::diff('instrument_text_changed', $old, $new, ['entity_type' => 'instrument', 'form_code' => $inst['form'],
            'meta' => ['instrument' => $key, 'lang' => $lang]], array_fill_keys(array_keys($new + $old), $data['source_note']));

        return $this->show($key, $lang);
    }

    /** EQ-5D-5L value set: CSV "health_state,utility" (3,125 rows), loaded under the EuroQol licence. */
    public function uploadValueSet(Request $request)
    {
        $data = $request->validate(['csv' => ['required', 'string'], 'source_note' => ['required', 'string', 'min:5']]);
        $rows = [];
        $errors = [];
        foreach (preg_split('/\r\n|\r|\n/', trim($data['csv'])) as $i => $line) {
            $c = array_map('trim', str_getcsv($line));
            if ($i === 0 && ! is_numeric($c[1] ?? null)) {
                continue; // header
            }
            if (count($c) < 2 || ! preg_match('/^[1-5]{5}$/', $c[0]) || ! is_numeric($c[1]) || $c[1] < -1 || $c[1] > 1) {
                $errors[] = 'Line '.($i + 1).': expected 5-digit state (1–5) and utility between −1 and 1.';

                continue;
            }
            $rows[$c[0]] = ['health_state' => $c[0], 'utility' => (float) $c[1]];
        }
        if (! $errors && count($rows) !== 3125) {
            $errors[] = 'The EQ-5D-5L value set must have 3,125 health states; this file has '.count($rows).'.';
        }
        if ($errors) {
            return response()->json(['message' => 'Value set not loaded.', 'errors' => array_slice($errors, 0, 10)], 422);
        }
        DB::transaction(function () use ($rows) {
            Eq5dValue::query()->delete();
            foreach (array_chunk(array_values($rows), 500) as $chunk) {
                Eq5dValue::insert($chunk);
            }
        });
        Audit::log('eq5d_value_set_loaded', ['meta' => ['rows' => count($rows), 'source' => $data['source_note'], 'sha256' => hash('sha256', $data['csv'])]]);

        return response()->json(['ok' => true, 'rows' => count($rows)]);
    }
}
