<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->string('employee_id');
            $table->string('kind');
            $table->string('title');
            $table->text('detail')->nullable();
            $table->string('status')->default('unread');
            $table->timestamps();

            $table->index(['employee_id', 'status']);
        });

        // HR audit log of every WhatsApp message the system attempts to deliver.
        Schema::create('whatsapp_logs', function (Blueprint $table) {
            $table->id();
            $table->string('employee_id');
            $table->string('phone_number')->nullable();
            $table->string('kind');
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->text('message');
            $table->string('status')->default('queued');
            $table->string('provider_message_id')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'created_at']);
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_logs');
        Schema::dropIfExists('notifications');
    }
};
