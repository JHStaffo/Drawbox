<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Each prize gets a count of draws left, which sets the odds, separate
     * from how many are on hand. The monthly amount moves from each brand or
     * choice up to the prize, and is what Reset sets draws left back to.
     */
    public function up(): void
    {
        Schema::table('prizes', function (Blueprint $table) {
            $table->unsignedInteger('available')->default(0)->after('quantity');
        });

        // Brand and choice monthly amounts add up to their prize's, and a prize
        // without one gets what's on hand. Every prize starts with its monthly
        // amount of draws left.
        foreach (DB::table('prizes')->get() as $prize) {
            $stocks = DB::table('gift_card_stocks')->where('prize_id', $prize->id)->where('active', true);
            $fromChoices = $prize->type === 'gift_card' || $stocks->exists();
            if ($fromChoices) {
                $monthly = (int) (clone $stocks)->sum('monthly_quantity');
                $pending = DB::table('draws')->where('prize_id', $prize->id)->where('status', 'pending_brand')->count();
                $onHand = max(0, (int) (clone $stocks)->sum('quantity') - $pending);
            } else {
                $monthly = (int) $prize->monthly_quantity;
                $onHand = (int) $prize->quantity;
            }
            $monthly = $monthly > 0 ? $monthly : $onHand;
            DB::table('prizes')->where('id', $prize->id)->update(['monthly_quantity' => $monthly, 'available' => $monthly]);
        }

        Schema::table('gift_card_stocks', fn (Blueprint $table) => $table->dropColumn('monthly_quantity'));
    }

    public function down(): void
    {
        Schema::table('gift_card_stocks', fn (Blueprint $table) => $table->unsignedInteger('monthly_quantity')->default(0)->after('quantity'));
        Schema::table('prizes', fn (Blueprint $table) => $table->dropColumn('available'));
    }
};
