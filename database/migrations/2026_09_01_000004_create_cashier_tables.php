<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        /*
         * The committed test schema dump carries `subscriptions` and `subscription_items` from
         * solidtime's private Billing extension, which is built on Cashier *Paddle* - the columns
         * are paddle_id, billable_id and billable_type. That extension is not in this fork, so
         * nothing reads them and they only collide with the tables Cashier Stripe needs. Dropped
         * here rather than left to rot; a fresh database built from migrations never had them.
         */
        Schema::dropIfExists('subscription_items');
        Schema::dropIfExists('subscriptions');

        /*
         * Cashier's tables, kept to Cashier's own shape rather than this repo's UUID convention.
         * They are vendor owned - Cashier's models read and write them, nothing here joins to
         * them by key, and overriding the models to take UUIDs would be a standing upgrade risk
         * for no gain. The foreign key back to organizations is a UUID, because that side is ours.
         */
        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('organization_id');
            $table->foreign('organization_id')
                ->references('id')
                ->on('organizations')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->string('type');
            $table->string('stripe_id')->unique();
            $table->string('stripe_status');
            $table->string('stripe_price')->nullable();
            $table->integer('quantity')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'stripe_status']);
        });

        Schema::create('subscription_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('subscription_id')
                ->constrained('subscriptions')
                ->cascadeOnDelete();
            $table->string('stripe_id')->unique();
            $table->string('stripe_product');
            $table->string('stripe_price');
            $table->integer('quantity')->nullable();
            $table->string('meter_id')->nullable();
            $table->string('meter_event_name')->nullable();
            $table->timestamps();

            $table->unique(['subscription_id', 'stripe_price']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_items');
        Schema::dropIfExists('subscriptions');
    }
};
