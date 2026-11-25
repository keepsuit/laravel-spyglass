<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spyglass_test_entries', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('scope')->nullable()->index();
            $table->json('context')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('spyglass_test_counters', function (Blueprint $table) {
            $table->string('bucket');
            $table->string('name');
            $table->string('label')->nullable();
            $table->unsignedInteger('count')->default(0);
            $table->unsignedInteger('duration_max')->default(0);
            $table->unsignedInteger('total')->nullable();
            $table->unsignedInteger('peak')->nullable();
            $table->unique(['bucket', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spyglass_test_entries');
        Schema::dropIfExists('spyglass_test_counters');
    }
};
