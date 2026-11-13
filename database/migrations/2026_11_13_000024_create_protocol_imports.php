<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A PDF or photos uploaded to be turned into draft protocols by the Claude API.
        // The cron job reads a few pages per run and records what it made.
        Schema::create('protocol_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->json('files');
            $table->string('kind', 10); // pdf or images
            $table->unsignedInteger('page_count')->nullable();
            $table->unsignedInteger('next_page')->default(1);
            $table->string('status', 20)->default('queued');
            $table->json('protocol_ids')->nullable();
            $table->json('pending_links')->nullable(); // exit boxes to point at other protocols once every page is read
            $table->json('notes')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('protocol_imports');
    }
};
