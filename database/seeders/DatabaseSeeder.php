<?php

namespace Database\Seeders;

use App\Models\Prize;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * The starting prize catalog. Quantities start at zero so supervisors set
     * the real counts on the inventory page before the first draw.
     */
    public function run(): void
    {
        $prizes = [
            ['Candy', 'item', 0, false, null, false],
            ['Snack', 'item', 0, false, null, false],
            ['PTO (2 hours)', 'pto', 0, true, 2],
            ['Stethoscope', 'item', 0, true],
            ['Raptor shears', 'item', 0, true],
        ];
        foreach ([5, 10, 20, 50, 100, 250, 500] as $dollars) {
            $prizes[] = ["\${$dollars} gift card", 'gift_card', $dollars * 100, true];
        }

        foreach ($prizes as $i => $p) {
            Prize::firstOrCreate(['name' => $p[0]], [
                'type' => $p[1],
                'value_cents' => $p[2],
                'taxable' => $p[3],
                'pto_hours' => $p[4] ?? null,
                'diminishing' => $p[5] ?? true,
                'sort' => $i,
            ]);
        }
    }
}
