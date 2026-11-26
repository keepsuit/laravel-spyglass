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
        // Where each exception group and log message came from, with exact counts: occurrences and log entries are throttled.
        Schema::create('spyglass_sources', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 16);
            // The exception fingerprint, or the digest of a log level and message.
            $table->char('signature', 40);
            $table->string('source_type', 16);
            // The key, as `source` can be longer than an index allows.
            $table->char('source_hash', 40);
            $table->text('source');
            $table->unsignedBigInteger('count')->default(0);
            $table->dateTime('last_seen_at', 3)->index();

            $table->unique(['kind', 'signature', 'source_type', 'source_hash'], 'spyglass_sources_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spyglass_sources');
    }
};
