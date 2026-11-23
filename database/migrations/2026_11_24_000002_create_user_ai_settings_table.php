<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('finance_user_ai_settings')) {
            Schema::create('finance_user_ai_settings', function (Blueprint $table) {
                $table->id();
                $table->string('user_sub', 100)->unique();
                $table->text('google_api_key_encrypted')->nullable();
                $table->string('default_model', 64)->default('gemini-3.5-flash-lite');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_user_ai_settings');
    }
};
