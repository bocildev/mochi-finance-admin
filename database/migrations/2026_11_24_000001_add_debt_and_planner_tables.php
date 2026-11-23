<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_recurring_bills', function (Blueprint $table) {
            $table->string('target_month', 7)->nullable()->after('paid_count')->index();
            $table->string('creditor_name', 120)->nullable()->after('target_month');
            $table->decimal('paid_amount', 18, 2)->default(0)->after('creditor_name');
        });

        Schema::create('finance_monthly_plans', function (Blueprint $table) {
            $table->id();
            $table->string('user_sub', 64)->index();
            $table->string('month', 7);
            $table->decimal('monthly_salary', 18, 2)->default(0);
            $table->text('strategy_notes')->nullable();
            $table->string('preferred_model', 64)->default('gemini-3.5-flash-lite');
            $table->longText('ai_analysis')->nullable();
            $table->timestamp('last_analyzed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_sub', 'month']);
        });

        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->longText('value')->nullable();
            $table->boolean('is_encrypted')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_settings');
        Schema::dropIfExists('finance_monthly_plans');

        Schema::table('finance_recurring_bills', function (Blueprint $table) {
            $table->dropColumn(['target_month', 'creditor_name', 'paid_amount']);
        });
    }
};
