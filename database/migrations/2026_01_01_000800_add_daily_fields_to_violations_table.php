<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('violations', function (Blueprint $table) {
            // 'late' | 'early_leave' | 'absent' | 'disruption' | 'abandoning' | 'disciplinary'
            $table->string('violation_type')->default('late')->after('violation_category');
            // Minutes of lateness / early leave that produced the violation.
            $table->integer('minutes')->default(0)->after('violation_type');
            // 'attendance_engine' | 'cctv' | 'manual'
            $table->string('source')->default('manual')->after('minutes');
            // Violations wait for HR before the employee is notified (flow: HR review → notify).
            $table->string('status')->default('pending_review')->after('penalty');
            $table->timestamp('notified_at')->nullable()->after('status');

            $table->unique(['employee_id', 'incident_date', 'violation_type'], 'violations_daily_unique');
        });

        // Absence has no row in the lateness table, so the row may be empty.
        Schema::table('violations', function (Blueprint $table) {
            $table->integer('violation_row')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('violations', function (Blueprint $table) {
            $table->dropUnique('violations_daily_unique');
            $table->dropColumn(['violation_type', 'minutes', 'source', 'status', 'notified_at']);
        });
    }
};
