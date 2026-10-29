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
        Schema::create('incident_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained('incidents')->cascadeOnDelete();
            $table->string('provider');
            $table->string('model');
            $table->string('status')->default('completed');
            $table->string('threat_level')->nullable();
            $table->boolean('is_false_positive')->default(false);
            $table->float('confidence_score')->nullable();
            $table->text('summary')->nullable();
            $table->text('detailed_analysis')->nullable();
            $table->json('recommendations')->nullable();
            $table->text('prompt')->nullable();
            $table->json('raw_response')->nullable();
            $table->integer('tokens_used')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('incident_analyses');
    }
};
