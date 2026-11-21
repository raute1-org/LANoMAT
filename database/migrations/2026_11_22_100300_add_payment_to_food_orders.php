<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('food_orders', function (Blueprint $table): void {
            $table->string('payment_method', 16)->default('cash');
            $table->string('payment_link', 255)->nullable();
            $table->jsonb('included_ticket_types')->default('[]');
        });

        Schema::table('food_order_items', function (Blueprint $table): void {
            $table->boolean('included')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('food_order_items', function (Blueprint $table): void {
            $table->dropColumn('included');
        });

        Schema::table('food_orders', function (Blueprint $table): void {
            $table->dropColumn(['payment_method', 'payment_link', 'included_ticket_types']);
        });
    }
};
