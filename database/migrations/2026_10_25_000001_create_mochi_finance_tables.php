<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_users', function (Blueprint $table) {
            $table->id();
            $table->string('sub', 64)->unique();
            $table->string('name');
            $table->string('email')->index();
            $table->json('roles')->nullable();
            $table->json('permissions')->nullable();
            $table->string('tenant_id', 64)->nullable();
            $table->string('status', 24)->default('active');
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->timestamps();
        });

        Schema::create('finance_wallets', function (Blueprint $table) {
            $table->id();
            $table->string('user_sub', 64)->index();
            $table->string('client_id', 100);
            $table->string('name');
            $table->string('type', 32)->default('bank');
            $table->decimal('balance', 18, 2)->default(0);
            $table->string('account_number', 64)->nullable();
            $table->string('icon', 64)->nullable();
            $table->string('color', 32)->nullable();
            $table->unsignedBigInteger('created_ms')->default(0);
            $table->unsignedBigInteger('updated_ms')->default(0);
            $table->timestamps();

            $table->unique(['user_sub', 'client_id']);
        });

        Schema::create('finance_categories', function (Blueprint $table) {
            $table->id();
            $table->string('user_sub', 64)->index();
            $table->string('client_id', 100);
            $table->string('name');
            $table->string('type', 24)->default('expense');
            $table->string('icon', 64)->nullable();
            $table->string('color', 32)->nullable();
            $table->timestamps();

            $table->unique(['user_sub', 'client_id']);
        });

        Schema::create('finance_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('user_sub', 64)->index();
            $table->string('client_id', 100);
            $table->string('wallet_id', 100);
            $table->string('target_wallet_id', 100)->nullable();
            $table->decimal('amount', 18, 2)->default(0);
            $table->string('type', 24)->default('expense');
            $table->string('category_id', 100);
            $table->text('notes')->nullable();
            $table->string('tx_date', 40);
            $table->longText('receipt_image')->nullable();
            $table->json('tags')->nullable();
            $table->string('inbox_ref_id', 100)->nullable();
            $table->unsignedBigInteger('created_ms')->default(0);
            $table->timestamps();

            $table->unique(['user_sub', 'client_id']);
        });

        Schema::create('finance_budgets', function (Blueprint $table) {
            $table->id();
            $table->string('user_sub', 64)->index();
            $table->string('client_id', 100);
            $table->string('category_id', 100);
            $table->decimal('monthly_limit', 18, 2)->default(0);
            $table->string('month', 16);
            $table->timestamps();

            $table->unique(['user_sub', 'client_id']);
        });

        Schema::create('finance_saving_goals', function (Blueprint $table) {
            $table->id();
            $table->string('user_sub', 64)->index();
            $table->string('client_id', 100);
            $table->string('name');
            $table->decimal('target_amount', 18, 2)->default(0);
            $table->decimal('current_amount', 18, 2)->default(0);
            $table->string('target_date', 24)->nullable();
            $table->string('icon', 64)->nullable();
            $table->string('color', 32)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_ms')->default(0);
            $table->timestamps();

            $table->unique(['user_sub', 'client_id']);
        });

        Schema::create('finance_recurring_bills', function (Blueprint $table) {
            $table->id();
            $table->string('user_sub', 64)->index();
            $table->string('client_id', 100);
            $table->string('name');
            $table->decimal('amount', 18, 2)->default(0);
            $table->string('category_id', 100);
            $table->string('wallet_id', 100);
            $table->unsignedTinyInteger('due_day')->default(1);
            $table->string('billing_cycle', 24)->default('monthly');
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->string('last_paid_date', 24)->nullable();
            $table->unsignedBigInteger('created_ms')->default(0);
            $table->timestamps();

            $table->unique(['user_sub', 'client_id']);
        });

        Schema::create('finance_inbox_items', function (Blueprint $table) {
            $table->id();
            $table->string('user_sub', 64)->index();
            $table->string('client_id', 100);
            $table->string('source', 32)->default('MANUAL');
            $table->text('raw_message');
            $table->decimal('detected_amount', 18, 2)->default(0);
            $table->string('detected_type', 24)->default('expense');
            $table->string('detected_merchant')->nullable();
            $table->string('detected_date', 40);
            $table->string('suggested_wallet_id', 100)->nullable();
            $table->string('suggested_category_id', 100)->nullable();
            $table->string('status', 24)->default('PENDING');
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_ms')->default(0);
            $table->timestamps();

            $table->unique(['user_sub', 'client_id']);
        });

        Schema::create('iam_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id', 100)->unique();
            $table->string('event_type', 64)->index();
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('iam_webhook_events');
        Schema::dropIfExists('finance_inbox_items');
        Schema::dropIfExists('finance_recurring_bills');
        Schema::dropIfExists('finance_saving_goals');
        Schema::dropIfExists('finance_budgets');
        Schema::dropIfExists('finance_transactions');
        Schema::dropIfExists('finance_categories');
        Schema::dropIfExists('finance_wallets');
        Schema::dropIfExists('finance_users');
    }
};
