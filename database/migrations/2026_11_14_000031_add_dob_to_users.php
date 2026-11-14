<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Date of birth, encrypted with the SSN key (see App\Services\Ssn). The OIG
// exclusion list matches people on name and date of birth, then the SSN confirms.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('dob_encrypted')->nullable()->after('ssn_hash');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('dob_encrypted');
        });
    }
};
