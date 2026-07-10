<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bot_id')->constrained()->onDelete('cascade');
            $table->foreignId('conversation_id')->constrained()->onDelete('cascade');
            $table->foreignId('slip_verification_id')->unique()
                ->constrained('slip_verifications')->onDelete('cascade');
            // reserving|reserved|delivering|delivered|canceled|failed
            $table->string('status', 20)->default('reserving');
            $table->decimal('amount', 12, 2)->nullable();
            $table->string('confirmed_by')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('last_reminded_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('account_delivery_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_delivery_id')->constrained()->onDelete('cascade');
            $table->string('product_name');
            $table->string('stock_code', 20)->nullable();
            $table->string('kind', 20); // stock|support_link|manual
            $table->unsignedInteger('qty')->default(1);
            // id ของแถวใน mhha_acc_db (items_reserved/items_sold) — ไม่ใช่ FK ข้าม DB
            $table->integer('stock_item_id')->nullable();
            // reserved|delivered|shortage|unmapped|returned
            $table->string('status', 20);
            $table->timestamps();
        });

        Schema::table('product_stocks', function (Blueprint $table) {
            $table->string('stock_code', 20)->nullable();
            $table->string('delivery_method', 20)->default('none'); // none|stock|support_link
        });
    }

    public function down(): void
    {
        Schema::table('product_stocks', function (Blueprint $table) {
            $table->dropColumn(['stock_code', 'delivery_method']);
        });
        Schema::dropIfExists('account_delivery_items');
        Schema::dropIfExists('account_deliveries');
    }
};
