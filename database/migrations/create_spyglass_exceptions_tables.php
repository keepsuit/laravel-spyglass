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
        Schema::create('spyglass_exception_groups', function (Blueprint $table) {
            $table->id();
            $table->char('fingerprint', 40)->unique();
            $table->string('class', 255);
            $table->text('message');
            $table->text('file');
            $table->unsignedInteger('line');
            $table->unsignedBigInteger('count')->default(0);
            // Milliseconds: an occurrence in the same second as the resolution must still reopen the group.
            $table->dateTime('first_seen_at', 3);
            $table->dateTime('last_seen_at', 3)->index();
            $table->dateTime('resolved_at', 3)->nullable();

            $table->index(['class', 'last_seen_at']);
        });

        Schema::create('spyglass_exception_occurrences', function (Blueprint $table) {
            $table->id();
            $table->char('fingerprint', 40);
            $table->dateTime('occurred_at', 3)->index();
            $table->text('message');
            $table->json('trace');
            $table->string('source_type', 16);
            $table->text('source')->nullable();
            $table->string('route', 255)->nullable();
            $table->string('user_id', 191)->nullable();
            $table->string('scope', 191)->nullable();
            $table->json('context')->nullable();

            $table->index(['fingerprint', 'occurred_at']);
            $table->index(['scope', 'fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spyglass_exception_groups');
        Schema::dropIfExists('spyglass_exception_occurrences');
    }
};
