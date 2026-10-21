<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lockers', function (Blueprint $table) {
            $table->id();
            $table->string('office'); // main, newport_news
            $table->unsignedInteger('number')->unique();
            $table->unsignedTinyInteger('row'); // 0 is the top row
            $table->unsignedTinyInteger('col'); // 0 is the left column
            $table->timestamps();
        });

        // One batch of prizes left in a locker for one employee.
        Schema::create('locker_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('locker_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->string('combination');
            $table->foreignId('dispensed_by')->constrained('users');
            $table->dateTime('dispensed_at');
            $table->dateTime('due_at');
            $table->dateTime('picked_up_at')->nullable();
            $table->dateTime('forfeited_at')->nullable();
            $table->dateTime('cleared_at')->nullable();
            $table->timestamps();
            $table->index(['locker_id', 'picked_up_at', 'cleared_at']);
        });

        Schema::table('draws', function (Blueprint $table) {
            $table->foreignId('locker_assignment_id')->nullable()->after('awarded_by')->constrained()->nullOnDelete();
        });

        // Main: 6 tall by 12 wide, numbered down each column from the top left.
        // Newport News: one column of 5, numbered 100 to 104 from the top.
        $now = now();
        $rows = [];
        for ($col = 0; $col < 12; $col++) {
            for ($row = 0; $row < 6; $row++) {
                $rows[] = ['office' => 'main', 'number' => $col * 6 + $row + 1, 'row' => $row, 'col' => $col, 'created_at' => $now, 'updated_at' => $now];
            }
        }
        for ($row = 0; $row < 5; $row++) {
            $rows[] = ['office' => 'newport_news', 'number' => 100 + $row, 'row' => $row, 'col' => 0, 'created_at' => $now, 'updated_at' => $now];
        }
        DB::table('lockers')->insert($rows);
    }

    public function down(): void
    {
        Schema::table('draws', function (Blueprint $table) {
            $table->dropConstrainedForeignId('locker_assignment_id');
        });
        Schema::dropIfExists('locker_assignments');
        Schema::dropIfExists('lockers');
    }
};
