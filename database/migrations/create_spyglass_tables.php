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
        // Exception occurrences and log entries, both throttled: `spyglass_groups` keeps their exact counts.
        Schema::create('spyglass_entries', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 16);
            // The exception fingerprint, or the digest of a log level and message.
            $table->char('signature', 40);
            $table->string('level', 16)->nullable();
            $table->dateTime('recorded_at', 3);
            $table->text('message');
            // The trace or the log context. Not JSON: truncation can cut the log context short. Medium: an
            // escaped trace can exceed MySQL's 64 KB TEXT.
            $table->mediumText('body')->nullable();
            // The innermost `getPrevious()`: what a wrapping exception hides.
            $table->string('cause_class', 255)->nullable();
            $table->text('cause_message')->nullable();
            $table->string('source_type', 16)->nullable();
            $table->text('source')->nullable();
            // The scheduled task run or the job uuid.
            $table->string('source_id', 64)->nullable();
            $table->string('route', 255)->nullable();
            $table->string('user_id', 191)->nullable();
            $table->string('scope', 191)->nullable();
            $table->json('context')->nullable();

            $table->index(['kind', 'recorded_at']);
            $table->index(['kind', 'signature', 'recorded_at']);
            $table->index(['kind', 'level', 'recorded_at']);
            $table->index(['kind', 'scope', 'recorded_at']);
            $table->index(['kind', 'scope', 'signature', 'recorded_at']);
            $table->index(['source_type', 'source_id']);
        });

        // One total row per exception group (`source_type` ''), and one per source of an exception group or log message.
        Schema::create('spyglass_groups', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 16);
            $table->char('signature', 40);
            $table->string('source_type', 16)->default('');
            // The key, as `source` can be longer than an index allows. '' on the total row.
            $table->char('source_hash', 40)->default('');
            $table->text('source')->nullable();
            $table->string('class', 255)->nullable();
            $table->text('message')->nullable();
            $table->text('file')->nullable();
            $table->unsignedInteger('line')->nullable();
            $table->unsignedBigInteger('count')->default(0);
            $table->dateTime('first_seen_at', 3)->nullable();
            // Milliseconds: an occurrence in the same second as the resolution must still reopen the group.
            $table->dateTime('last_seen_at', 3);
            $table->dateTime('resolved_at', 3)->nullable();

            $table->unique(['kind', 'signature', 'source_type', 'source_hash'], 'spyglass_groups_key');
            $table->index(['kind', 'source_type', 'last_seen_at']);
            $table->index(['kind', 'source_type', 'class', 'last_seen_at']);
            $table->index(['kind', 'last_seen_at']);
        });

        Schema::create('spyglass_queue_aggregates', function (Blueprint $table) {
            $table->id();
            $table->dateTime('bucket');
            // Lengths keep the unique index under MySQL's 3072-byte limit with utf8mb4.
            $table->string('connection', 64);
            $table->string('queue', 191);
            $table->string('job', 255);
            // '' is the total of every scope. Empty instead of NULL: NULLs never match in a unique index.
            $table->string('scope', 191)->default('');
            $table->unsignedInteger('queued')->default(0);
            $table->unsignedInteger('processed')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->unsignedInteger('released')->default(0);
            $table->unsignedInteger('timed_out')->default(0);
            $table->unsignedBigInteger('duration_sum')->default(0);
            $table->unsignedInteger('duration_max')->default(0);

            $table->unique(['bucket', 'connection', 'queue', 'job', 'scope'], 'spyglass_queue_aggregates_key');
            $table->index(['scope', 'bucket']);
        });

        Schema::create('spyglass_queue_failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 36)->unique();
            $table->string('connection', 64);
            $table->string('queue', 191);
            $table->string('job', 255);
            $table->unsignedInteger('attempts');
            $table->string('exception_class', 255);
            $table->char('fingerprint', 40);
            $table->string('scope', 191)->nullable();
            $table->json('context')->nullable();
            $table->dateTime('failed_at');

            $table->index('failed_at');
            $table->index(['scope', 'failed_at']);
            $table->index(['queue', 'failed_at']);
            $table->index(['job', 'failed_at']);
        });
        // Published by the scheduler on every tick, so the dashboard never has to boot Artisan.
        Schema::create('spyglass_schedule_tasks', function (Blueprint $table) {
            $table->char('key', 40)->primary();
            $table->string('label', 255);
            $table->string('type', 16);
            // Constructor arguments of a job.
            $table->string('detail', 255)->nullable();
            $table->text('command')->nullable();
            $table->text('description')->nullable();
            // One per time the task is registered at: {expression, timezone, repeat_seconds}.
            $table->json('schedules');
            $table->json('environments')->nullable();
            $table->boolean('without_overlapping')->default(false);
            $table->dateTime('first_seen_at', 3);
            $table->dateTime('last_seen_at', 3)->index();
            $table->dateTime('last_skipped_at', 3)->nullable();
            $table->string('last_skip_reason', 32)->nullable();
            // Successful runs of sub-minute tasks are not rows: only the last one, here.
            $table->dateTime('last_run_at', 3)->nullable();
            $table->unsignedInteger('last_duration_ms')->nullable();
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
            $table->string('exception_class', 255)->nullable();
            $table->char('fingerprint', 40)->nullable();
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
        Schema::dropIfExists('spyglass_entries');
        Schema::dropIfExists('spyglass_groups');
        Schema::dropIfExists('spyglass_queue_aggregates');
        Schema::dropIfExists('spyglass_queue_failed_jobs');
        Schema::dropIfExists('spyglass_schedule_tasks');
        Schema::dropIfExists('spyglass_schedule_runs');
    }
};
