<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class BarangCodeGenerator
{
    public function next(): string
    {
        DB::table('number_sequences')
            ->where('name', 'barang')
            ->update([
                'current_value' => DB::raw('LAST_INSERT_ID(current_value + 1)'),
                'updated_at' => now(),
            ]);

        $number = (int) (DB::selectOne('SELECT LAST_INSERT_ID() AS value')->value ?? 0);

        return 'BRG-'.str_pad((string) $number, 3, '0', STR_PAD_LEFT);
    }
}
