<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Everything people do in the app, including sign-ins and failed sign-ins.
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email')->nullable(); // who tried to sign in, when there's no user
            $table->string('action', 100)->index();
            $table->text('description');
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_login_at')->nullable()->after('active');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('last_login_at'));
        Schema::dropIfExists('audit_logs');
    }
};
