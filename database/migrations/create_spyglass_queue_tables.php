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
        Schema::create('spyglass_queue_aggregates', function (Blueprint $table) {
            $table->id();
            $table->dateTime('bucket');
            // Lengths keep the unique index under MySQL's 3072-byte limit with utf8mb4.
            $table->string('connection', 64);
            $table->string('queue', 191);
            $table->string('job', 255);
            // Empty instead of NULL: NULLs never match in a unique index, so upserts would duplicate rows.
            $table->string('scope', 191)->default('');
            $table->unsignedInteger('queued')->default(0);
            $table->unsignedInteger('processed')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->unsignedInteger('released')->default(0);
            $table->unsignedInteger('timed_out')->default(0);
            $table->unsignedBigInteger('duration_sum')->default(0);
            $table->unsignedInteger('duration_max')->default(0);

            $table->unique(['bucket', 'connection', 'queue', 'job', 'scope'], 'spyglass_queue_aggregates_key');
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
    }

    public function down(): void
    {
        Schema::dropIfExists('spyglass_queue_aggregates');
        Schema::dropIfExists('spyglass_queue_failed_jobs');
    }
};
