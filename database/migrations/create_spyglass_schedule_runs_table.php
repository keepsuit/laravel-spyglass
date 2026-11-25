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
        // Published by the scheduler on every tick, so the dashboard never has to boot Artisan.
        Schema::create('spyglass_schedule_tasks', function (Blueprint $table) {
            $table->char('key', 40)->primary();
            $table->string('label', 255);
            $table->text('command')->nullable();
            $table->text('description')->nullable();
            $table->text('expression');
            $table->unsignedSmallInteger('repeat_seconds')->nullable();
            $table->string('timezone', 64);
            $table->json('environments')->nullable();
            $table->dateTime('first_seen_at', 3);
            $table->dateTime('last_seen_at', 3)->index();
            $table->dateTime('last_skipped_at', 3)->nullable();
            $table->string('last_skip_reason', 32)->nullable();
        });

        Schema::create('spyglass_schedule_runs', function (Blueprint $table) {
            // Generated when the task starts, so every later write of the run is an upsert on it.
            $table->ulid('id')->primary();
            $table->char('task', 40);
            $table->dateTime('started_at', 3);
            $table->dateTime('finished_at', 3)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('status', 16);
            $table->integer('exit_code')->nullable();
            $table->text('output')->nullable();
            // Size of an appended output file when the run started: its output is what follows.
            $table->unsignedBigInteger('output_offset')->nullable();
            $table->string('host', 191);
            $table->string('scope', 191)->nullable();
            $table->json('context')->nullable();

            $table->index(['task', 'started_at', 'id']);
            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spyglass_schedule_runs');
        Schema::dropIfExists('spyglass_schedule_tasks');
    }
};
