<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('oc_hyperpay_payment', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('order_id')->nullable()->index();
            $table->string('checkout_id', 100)->nullable();      // from /v1/checkouts
            $table->string('payment_id', 100)->nullable();       // from /v1/payments (PA, DB, etc.)
            $table->string('payment_type', 10);                  // DB, PA, RB, RV, RF
            $table->decimal('amount', 15, 4);
            $table->string('currency', 3);
            $table->string('result_code', 20)->nullable();       // e.g., "000.100.112"
            $table->text('result_description')->nullable();
            $table->string('brand', 20)->nullable();             // VISA, MASTERCARD
            $table->string('card_bin', 10)->nullable();
            $table->string('card_last4', 4)->nullable();
            $table->string('status', 20)->default('pending');    // pending, success, failed, refunded, reversed
            $table->json('raw_response')->nullable();            // full HyperPay response
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('oc_hyperpay_payment');
    }
};