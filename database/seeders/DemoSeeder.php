<?php

namespace Database\Seeders;

use App\Models\Draw;
use App\Models\DrawCredit;
use App\Models\GiftCardStock;
use App\Models\Prize;
use App\Models\User;
use App\Services\Credits;
use App\Services\DrawMachine;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Notification;

/** Sample data for trying the app locally: php artisan db:seed --class=DemoSeeder */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        Notification::fake();
        $this->call(DatabaseSeeder::class);

        $admin = User::create(['name' => 'Demo Admin', 'email' => 'admin@example.com', 'role' => 'admin']);
        $crew = collect(['Alex Rivera', 'Jordan Lee', 'Sam Patel', 'Taylor Brooks', 'Casey Nguyen', 'Morgan Diaz'])
            ->map(fn ($name) => User::create(['name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)).'@example.com']));

        $amounts = ['Candy' => [40, 150], 'Snack' => [40, 200], 'PTO (2 hours)' => [3, 5000], 'Stethoscope' => [2, 9000], 'Raptor shears' => [2, 6000]];
        foreach ($amounts as $name => [$qty, $cents]) {
            Prize::where('name', $name)->update(['quantity' => $qty, 'available' => $qty, 'monthly_quantity' => $qty, 'value_cents' => $cents]);
        }
        $perBrand = [500 => 4, 1000 => 3, 2000 => 2, 5000 => 1, 10000 => 1, 25000 => 0, 50000 => 0];
        foreach (Prize::where('type', 'gift_card')->get() as $prize) {
            foreach (['Amazon', 'Wawa', 'Visa'] as $brand) {
                GiftCardStock::create(['prize_id' => $prize->id, 'brand' => $brand, 'quantity' => $perBrand[$prize->value_cents]]);
            }
        }

        foreach (Prize::where('type', 'gift_card')->get() as $prize) {
            $prize->update(['available' => $prize->stocks()->sum('quantity'), 'monthly_quantity' => $prize->stocks()->sum('quantity')]);
        }

        $locations = ['Sentara Leigh', 'Chesapeake Regional', 'Bon Secours DePaul', 'Riverside Nursing', 'Lake Taylor'];
        foreach ($locations as $i => $location) {
            app(Credits::class)->creditCall(
                ['occurred_at' => now()->subDays($i), 'location' => $location, 'unit' => 'Medic '.($i + 3)],
                [$crew[$i]->id, $crew[$i + 1]->id, $admin->id],
                $admin,
            );
        }

        $machine = app(DrawMachine::class);
        foreach (DrawCredit::where('user_id', '!=', $admin->id)->take(6)->get() as $credit) {
            $draw = $machine->draw($credit);
            if ($draw->status === 'pending_brand') {
                $machine->chooseBrand($draw, $machine->brandsFor($draw)->first()->id);
            }
        }
        foreach (Draw::where('status', 'won')->take(3)->get() as $draw) {
            $draw->update(['status' => 'awarded', 'awarded_at' => now(), 'awarded_by' => $admin->id]);
        }
    }
}
