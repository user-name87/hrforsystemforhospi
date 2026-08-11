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
        // Employee schedules
        Schema::create('employee_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('employee_id');
            $table->foreignId('department_id')->nullable()->constrained()->onDelete('set null');
            $table->integer('month');
            $table->integer('year');
            $table->integer('day');
            $table->string('shift_code');
            $table->timestamps();

            $table->foreign('employee_id')->references('employee_id')->on('employees')->onDelete('cascade');
            $table->index(['employee_id', 'month', 'year']);
            $table->index(['department_id', 'month', 'year']);
        });

        // Fingerprints
        Schema::create('fingerprints', function (Blueprint $table) {
            $table->id();
            $table->string('employee_id');
            $table->date('punch_date');
            $table->time('punch_time');
            $table->string('punch_type')->nullable();
            $table->string('source')->default('manual');
            $table->integer('month');
            $table->integer('year');
            $table->timestamps();

            $table->foreign('employee_id')->references('employee_id')->on('employees')->onDelete('cascade');
            $table->index(['employee_id', 'punch_date']);
            $table->index(['month', 'year']);
        });

        // Leaves
        Schema::create('leaves', function (Blueprint $table) {
            $table->id();
            $table->string('employee_id');
            $table->date('leave_date');
            $table->string('leave_type');
            $table->string('status')->default('pending_dept');
            $table->text('notes')->nullable();
            $table->string('approved_by')->nullable();
            $table->timestamps();

            $table->foreign('employee_id')->references('employee_id')->on('employees')->onDelete('cascade');
            $table->index(['employee_id', 'leave_date']);
            $table->index('status');
        });

        // Violations
        Schema::create('violations', function (Blueprint $table) {
            $table->id();
            $table->string('employee_id');
            $table->string('violation_category');
            $table->integer('violation_row');
            $table->date('incident_date');
            $table->integer('occurrence_number');
            $table->string('penalty');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('employee_id')->references('employee_id')->on('employees')->onDelete('cascade');
            $table->index(['employee_id', 'incident_date']);
            $table->index('violation_row');
        });

        // Disciplinary actions
        Schema::create('disciplinary_actions', function (Blueprint $table) {
            $table->id();
            $table->string('employee_id');
            $table->string('action_type');
            $table->string('severity');
            $table->text('note');
            $table->string('created_by')->nullable();
            $table->timestamps();

            $table->foreign('employee_id')->references('employee_id')->on('employees')->onDelete('cascade');
            $table->index(['employee_id', 'created_at']);
        });

        // Monthly schedules
        Schema::create('monthly_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained()->onDelete('cascade');
            $table->string('department_name');
            $table->integer('month');
            $table->integer('year');
            $table->string('uploaded_by')->default('manual');
            $table->timestamps();

            $table->unique(['department_id', 'month', 'year']);
            $table->index(['month', 'year']);
        });

        // Shift exceptions
        Schema::create('shift_exceptions', function (Blueprint $table) {
            $table->id();
            $table->string('employee_id');
            $table->integer('month');
            $table->integer('year');
            $table->float('original_hours');
            $table->float('exception_hours');
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->foreign('employee_id')->references('employee_id')->on('employees')->onDelete('cascade');
            $table->unique(['employee_id', 'month', 'year']);
        });

        // Duty carryover
        Schema::create('duty_carryovers', function (Blueprint $table) {
            $table->id();
            $table->string('employee_id');
            $table->integer('from_month');
            $table->integer('from_year');
            $table->integer('surplus_shifts');
            $table->integer('applied_month');
            $table->integer('applied_year');
            $table->timestamps();

            $table->foreign('employee_id')->references('employee_id')->on('employees')->onDelete('cascade');
            $table->unique(['employee_id', 'from_month', 'from_year']);
        });

        // Doctor contracts
        Schema::create('doctor_contracts', function (Blueprint $table) {
            $table->id();
            $table->string('employee_id');
            $table->string('contract_type');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->float('monthly_hours');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('employee_id')->references('employee_id')->on('employees')->onDelete('cascade');
            $table->index(['employee_id', 'start_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('doctor_contracts');
        Schema::dropIfExists('duty_carryovers');
        Schema::dropIfExists('shift_exceptions');
        Schema::dropIfExists('monthly_schedules');
        Schema::dropIfExists('disciplinary_actions');
        Schema::dropIfExists('violations');
        Schema::dropIfExists('leaves');
        Schema::dropIfExists('fingerprints');
        Schema::dropIfExists('employee_schedules');
    }
};