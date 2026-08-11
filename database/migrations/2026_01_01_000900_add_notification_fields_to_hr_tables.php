<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('email')->nullable()->after('name');
        });

        Schema::table('disciplinary_actions', function (Blueprint $table) {
            $table->string('penalty')->nullable()->after('note');
            $table->timestamp('notified_at')->nullable()->after('penalty');
        });
    }

    public function down(): void
    {
        Schema::table('disciplinary_actions', function (Blueprint $table) {
            $table->dropColumn(['penalty', 'notified_at']);
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('email');
        });
    }
};
