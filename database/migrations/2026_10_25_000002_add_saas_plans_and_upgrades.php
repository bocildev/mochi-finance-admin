<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_users', function (Blueprint $table) {
            $table->string('plan_tier', 24)->default('free')->after('permissions')->index();
            $table->string('billing_cycle', 24)->nullable()->after('plan_tier');
            $table->timestamp('pro_expires_at')->nullable()->after('billing_cycle');
            $table->unsignedInteger('wallets_count')->default(0)->after('status');
            $table->unsignedInteger('transactions_count')->default(0)->after('wallets_count');
            $table->unsignedInteger('budgets_count')->default(0)->after('transactions_count');
            $table->unsignedInteger('goals_count')->default(0)->after('budgets_count');
        });

        Schema::create('saas_plans', function (Blueprint $table) {
            $table->id();
            $table->string('key', 32)->unique();
            $table->string('name');
            $table->string('badge', 64);
            $table->decimal('monthly_price', 18, 2)->default(0);
            $table->decimal('yearly_price', 18, 2)->default(0);
            $table->integer('max_wallets')->default(2); // -1 = unlimited
            $table->integer('max_budgets')->default(2);
            $table->integer('max_goals')->default(1);
            $table->boolean('allow_cloud_sync')->default(false);
            $table->boolean('allow_smart_inbox')->default(false);
            $table->boolean('allow_analytics')->default(false);
            $table->boolean('allow_csv_export')->default(false);
            $table->json('features')->nullable();
            $table->timestamps();
        });

        Schema::create('upgrade_requests', function (Blueprint $table) {
            $table->id();
            $table->string('user_sub', 64)->index();
            $table->string('user_name');
            $table->string('user_email');
            $table->string('requested_plan', 32)->default('pro_monthly');
            $table->decimal('amount', 18, 2)->default(29000);
            $table->string('payment_method', 32)->default('QRIS');
            $table->text('notes')->nullable();
            $table->string('status', 24)->default('PENDING')->index();
            $table->string('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('upgrade_requests');
        Schema::dropIfExists('saas_plans');

        Schema::table('finance_users', function (Blueprint $table) {
            $table->dropColumn([
                'plan_tier',
                'billing_cycle',
                'pro_expires_at',
                'wallets_count',
                'transactions_count',
                'budgets_count',
                'goals_count',
            ]);
        });
    }
};
