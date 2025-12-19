<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('chore_completion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chore_id')->constrained(table: 'chores')->noActionOnUpdate()->onDelete('cascade');
            $table->foreignId('family_id')->constrained(table: 'families')->noActionOnUpdate()->onDelete('cascade');
            $table->foreignId('completed_by')->constrained(table: 'users')->noActionOnUpdate()->onDelete('cascade');
            $table->string('period_key', 50)->nullable()->comment('something like 2025-12-15_week');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('table_chore_completion');
    }
};
