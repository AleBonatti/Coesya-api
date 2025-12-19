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
        Schema::create('users_families', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained(table: 'users')->noActionOnUpdate()->onDelete('cascade');
            $table->foreignId('family_id')->constrained(table: 'families')->noActionOnUpdate()->onDelete('cascade');
            $table->unsignedTinyInteger('current')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users_families');
    }
};
