<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_recurring_bills', function (Blueprint $table) {
            $table->string('bill_type', 24)->default('subscription')->after('billing_cycle');
            $table->unsignedInteger('total_tenor')->nullable()->after('bill_type');
            $table->unsignedInteger('paid_count')->default(0)->after('total_tenor');
        });
    }

    public function down(): void
    {
        Schema::table('finance_recurring_bills', function (Blueprint $table) {
            $table->dropColumn(['bill_type', 'total_tenor', 'paid_count']);
        });
    }
};
