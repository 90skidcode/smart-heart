<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Sequential, gap-free study IDs. Must be called inside a DB transaction. */
class IdGenerator
{
    public static function next(): array
    {
        $row = DB::table('id_sequences')->where('name', 'participant')->lockForUpdate()->first();
        if (! $row) {
            DB::table('id_sequences')->insert(['name' => 'participant', 'value' => config('smartheart.id_start') - 1]);
            $row = DB::table('id_sequences')->where('name', 'participant')->lockForUpdate()->first();
        }
        $n = $row->value + 1;
        DB::table('id_sequences')->where('name', 'participant')->update(['value' => $n]);
        $num = str_pad((string) $n, config('smartheart.id_digits'), '0', STR_PAD_LEFT);

        return [
            'study_id' => config('smartheart.study_id_prefix').$num,
            'screening_id' => config('smartheart.screening_id_prefix').$num,
        ];
    }
}
