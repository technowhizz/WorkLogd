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
        Schema::create('organization_subscriptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // One subscription per organization - the record is the organization's current
            // billing arrangement, not a history of them.
            $table->uuid('organization_id')->unique();
            $table->foreign('organization_id')
                ->references('id')
                ->on('organizations')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->string('plan')->default('free');
            $table->string('status')->default('active');
            $table->dateTime('trial_ends_at')->nullable();
            $table->dateTime('starts_at')->nullable();
            // When the current period runs out. Null means it renews until someone says otherwise.
            $table->dateTime('ends_at')->nullable();
            // How many non-placeholder members the organization has paid for. Null means no cap.
            $table->integer('seats')->nullable();
            // What the organization is charged each interval, in the minor unit of the currency.
            $table->integer('price')->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('billing_interval')->nullable();
            // Whatever identifies the arrangement in the system that actually takes the money -
            // a payment provider's id, or an invoice reference for organizations billed by hand.
            $table->string('external_reference')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organization_subscriptions');
    }
};
