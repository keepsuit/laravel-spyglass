<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Keepsuit\Spyglass\Facades\Spyglass;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return Spyglass::connection()->getName();
    }

    public function up(): void
    {
        Schema::create('spyglass_log_entries', function (Blueprint $table) {
            $table->id();
            $table->string('level', 16);
            $table->text('message');
            // Text, not JSON: truncation can cut the JSON short.
            $table->text('log_context')->nullable();
            $table->dateTime('logged_at')->index();
            $table->string('source_type', 16)->nullable();
            $table->text('source')->nullable();
            // The scheduled task run or the job uuid.
            $table->string('source_id', 64)->nullable();
            $table->string('scope', 191)->nullable();
            $table->json('context')->nullable();

            $table->index(['level', 'logged_at']);
            $table->index(['scope', 'logged_at']);
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spyglass_log_entries');
    }
};
