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
        Schema::create('cctv_violations', function (Blueprint $table) {
            $table->id();
            $table->string('employee_id');
            $table->date('violation_date');
            $table->string('violation_type');
            $table->text('description');
            $table->integer('penalty_days')->default(0);
            $table->text('notes')->nullable();
            $table->integer('month');
            $table->integer('year');
            $table->string('recorded_by')->default('manual');
            $table->timestamps();

            $table->foreign('employee_id')->references('employee_id')->on('employees')->onDelete('cascade');
            $table->index(['employee_id', 'violation_date']);
            $table->index(['month', 'year']);
            $table->index('violation_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cctv_violations');
    }
};