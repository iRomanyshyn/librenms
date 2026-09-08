<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alerts', function (Blueprint $table): void {
            $table->unsignedInteger('mail_thread_root_id')->nullable();
            $table->text('mail_thread_subject')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('alerts', function (Blueprint $table): void {
            $table->dropColumn(['mail_thread_root_id', 'mail_thread_subject']);
        });
    }
};
