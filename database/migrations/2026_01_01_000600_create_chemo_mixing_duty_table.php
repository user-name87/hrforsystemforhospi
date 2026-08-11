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
        Schema::create('chemo_mixing_duties', function (Blueprint $table) {
            $table->id();
            $table->string('employee_id');
            $table->foreignId('department_id')->nullable()->constrained()->onDelete('set null');
            $table->integer('month');
            $table->integer('year');
            $table->float('reduced_hours')->nullable();
            $table->integer('reduced_days')->default(23);
            $table->boolean('confirmed')->default(false);
            $table->string('confirmed_by')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->boolean('email_sent')->default(false);
            $table->timestamp('email_sent_at')->nullable();
            $table->timestamps();

            $table->foreign('employee_id')->references('employee_id')->on('employees')->onDelete('cascade');
            $table->unique(['employee_id', 'month', 'year']);
            $table->index(['month', 'year']);
            $table->index('department_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chemo_mixing_duties');
    }
};