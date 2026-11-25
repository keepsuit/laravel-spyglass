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
        Schema::create('spyglass_schedule_runs', function (Blueprint $table) {
            // Generated when the task starts, so every later write of the run is an upsert on it.
            $table->ulid('id')->primary();
            $table->string('task', 191);
            $table->text('command')->nullable();
            $table->string('description', 255)->nullable();
            $table->string('expression', 64);
            $table->string('timezone', 64)->nullable();
            $table->dateTime('started_at', 3);
            $table->dateTime('finished_at', 3)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('status', 16);
            $table->integer('exit_code')->nullable();
            $table->text('output')->nullable();
            $table->string('host', 191);
            // What started the run. Only the scheduler today.
            $table->string('trigger', 16)->default('schedule');
            $table->string('scope', 191)->nullable();
            $table->json('context')->nullable();

            // Serves both the last run of each task and the history of a task.
            $table->index(['task', 'started_at']);
            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spyglass_schedule_runs');
    }
};
