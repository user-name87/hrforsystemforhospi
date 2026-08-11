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
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('employee_id')->unique();
            $table->string('name');
            $table->foreignId('department_id')->nullable()->constrained()->onDelete('set null');
            $table->string('job_title')->nullable();
            $table->string('shift_type')->nullable();
            $table->string('nationality')->nullable();
            $table->string('phone_number')->nullable();
            $table->decimal('basic_salary', 10, 2)->nullable();
            $table->string('full_or_part')->nullable();
            $table->string('classification')->nullable();
            $table->integer('duty_quota')->nullable();
            $table->timestamps();

            $table->index('employee_id');
            $table->index('department_id');
            $table->index('classification');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};