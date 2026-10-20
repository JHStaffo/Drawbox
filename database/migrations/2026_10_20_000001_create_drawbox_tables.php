<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('value');
            $table->timestamps();
        });

        // A successfully solicited call, entered by dispatch.
        Schema::create('calls', function (Blueprint $table) {
            $table->id();
            $table->dateTime('occurred_at');
            $table->string('location');
            $table->string('unit')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });

        // One draw opportunity for one crew member on a call.
        Schema::create('draw_credits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('call_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->dateTime('expires_at');
            $table->string('status')->default('open'); // open, drawn, expired
            $table->timestamp('reminded_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'expires_at']);
        });

        // The prize catalog. Gift cards have one row per dollar value; their
        // envelopes are the brand stock in gift_card_stocks.
        Schema::create('prizes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type'); // item, pto, constant, gift_card
            $table->unsignedInteger('value_cents')->default(0);
            $table->decimal('pto_hours', 5, 2)->nullable();
            $table->boolean('taxable')->default(true);
            $table->unsignedInteger('quantity')->default(0);
            $table->unsignedInteger('monthly_quantity')->default(0);
            $table->boolean('active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('gift_card_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prize_id')->constrained()->cascadeOnDelete();
            $table->string('brand');
            $table->unsignedInteger('quantity')->default(0);
            $table->unsignedInteger('monthly_quantity')->default(0);
            $table->timestamps();
            $table->unique(['prize_id', 'brand']);
        });

        // A prize won. Name, value, hours and taxable are copied at draw time
        // so later catalog edits never change history.
        Schema::create('draws', function (Blueprint $table) {
            $table->id();
            $table->foreignId('draw_credit_id')->unique()->constrained();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('prize_id')->constrained();
            $table->string('prize_name');
            $table->string('prize_type');
            $table->unsignedInteger('value_cents');
            $table->decimal('pto_hours', 5, 2)->nullable();
            $table->boolean('taxable');
            $table->foreignId('gift_card_stock_id')->nullable()->constrained()->nullOnDelete();
            $table->string('brand')->nullable();
            $table->string('status'); // pending_brand, won, awarded
            $table->dateTime('drawn_at');
            $table->dateTime('awarded_at')->nullable();
            $table->foreignId('awarded_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->index(['status', 'awarded_at']);
        });

        Schema::create('inventory_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained();
            $table->string('item');
            $table->integer('change');
            $table->integer('quantity_after');
            $table->string('reason');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_logs');
        Schema::dropIfExists('draws');
        Schema::dropIfExists('gift_card_stocks');
        Schema::dropIfExists('prizes');
        Schema::dropIfExists('draw_credits');
        Schema::dropIfExists('calls');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('login_codes');
    }
};
