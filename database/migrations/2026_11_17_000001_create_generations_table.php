<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Our own id. Sent to Pixelforge as the Idempotency-Key and in metadata,
            // so retries never create a second generation and early webhooks can be matched.
            $table->uuid('uuid')->unique();
            // Pixelforge's id (gen_...), known once Pixelforge accepts the request.
            $table->string('pixelforge_id')->nullable()->unique();
            $table->string('status')->index();

            $table->text('prompt');
            $table->string('size');
            $table->string('quality');
            $table->json('reference_images');

            // All money columns are integer picodollars (1 USD = 10^12). See App\Support\Money.
            $table->bigInteger('estimated_cost_pico');
            $table->bigInteger('max_cost_pico');
            $table->bigInteger('provider_cost_pico')->nullable();
            $table->bigInteger('charged_pico')->nullable();

            $table->json('rates')->nullable();
            $table->json('usage')->nullable();
            $table->text('image_url')->nullable();
            $table->timestamp('image_url_expires_at')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->text('notice')->nullable();

            $table->unsignedInteger('submit_attempts')->default(0);
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('credit_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('generation_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->bigInteger('amount_pico');
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            // The database itself guarantees one hold, one release and one charge per generation,
            // so a duplicated webhook can never bill twice, even under a race.
            $table->unique(['generation_id', 'type']);
        });

        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            // Pixelforge's evt_ id. Unique, so the same event is only ever handled once.
            $table->string('event_id')->unique();
            $table->string('type');
            $table->string('pixelforge_generation_id')->nullable()->index();
            $table->unsignedInteger('delivery_attempt')->default(1);
            $table->json('payload');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('credit_ledger');
        Schema::dropIfExists('generations');
    }
};
