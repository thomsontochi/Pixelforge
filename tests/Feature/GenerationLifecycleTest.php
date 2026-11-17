<?php

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\CreditLedgerEntry;
use App\Models\Generation;
use App\Models\User;
use App\Services\Billing\CreditLedger;
use App\Services\Pixelforge\WebhookSignature;
use App\Support\Money;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

const WEBHOOK_SECRET = 'whsec_test';

beforeEach(function () {
    config([
        'services.pixelforge.webhook_secret' => WEBHOOK_SECRET,
        'services.pixelforge.api_key' => 'pf_test',
    ]);

    Http::fake([
        '*/v1/pricing' => Http::response(json_decode(file_get_contents(__DIR__.'/../Fixtures/pricing.json'), true)),
    ]);
});

function userWithCredits(int $dollars = 5): User
{
    $user = User::factory()->create();
    app(CreditLedger::class)->purchase($user, $dollars * Money::PICO_PER_DOLLAR);

    return $user;
}

function remoteGeneration(Generation $generation, string $status, array $overrides = []): array
{
    return array_merge([
        'id' => 'gen_test_1',
        'object' => 'generation',
        'status' => $status,
        'prompt' => $generation->prompt,
        'size' => $generation->size,
        'quality' => $generation->quality,
        'reference_images' => [],
        'metadata' => ['generation_uuid' => $generation->uuid],
        'image_url' => $status === 'succeeded' ? 'https://pixelforge.promptedit.dev/images/gen_test_1.png' : null,
        'image_url_expires_at' => $status === 'succeeded' ? now()->addDay()->toIso8601String() : null,
        'usage' => $status === 'succeeded'
            ? ['input_text_tokens' => 5, 'input_image_tokens' => 0, 'output_tokens' => 1100, 'reasoning_tokens' => 1000, 'total_tokens' => 2105]
            : null,
        'rates' => ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'],
        'error' => $status === 'failed' ? ['code' => 'generation_failed', 'message' => 'The model could not produce an image.'] : null,
        'created_at' => now()->toIso8601String(),
        'started_at' => now()->toIso8601String(),
        'completed_at' => in_array($status, ['succeeded', 'failed']) ? now()->toIso8601String() : null,
    ], $overrides);
}

function sendWebhook($test, string $eventId, string $type, array $generation)
{
    $body = json_encode(['id' => $eventId, 'object' => 'event', 'type' => $type, 'created_at' => now()->toIso8601String(), 'data' => ['object' => $generation]]);

    return $test->call('POST', '/webhooks/pixelforge', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_PIXELFORGE_SIGNATURE' => WebhookSignature::sign(WEBHOOK_SECRET, $body, time()),
        'HTTP_PIXELFORGE_EVENT_ID' => $eventId,
    ], $body);
}

function createGeneration(User $user): Generation
{
    test()->actingAs($user)->post('/generations', ['prompt' => 'A lighthouse', 'size' => '1024x1024', 'quality' => 'medium'])
        ->assertSessionHasNoErrors();

    return Generation::latest('id')->firstOrFail();
}

test('creating a generation holds the maximum price and queues the submission', function () {
    Queue::fake();
    $user = userWithCredits();

    $generation = createGeneration($user);

    expect($generation->status)->toBe(GenerationStatus::Pending)
        ->and($user->balancePico())->toBe(5 * Money::PICO_PER_DOLLAR - $generation->max_cost_pico);
    Queue::assertPushed(SubmitGeneration::class);
});

test('users who cannot afford the worst case are blocked', function () {
    Queue::fake();
    $user = User::factory()->create();

    $this->actingAs($user)->post('/generations', ['prompt' => 'A lighthouse', 'size' => '1024x1024', 'quality' => 'high'])
        ->assertSessionHasErrors('prompt');

    expect(Generation::count())->toBe(0);
});

test('a succeeded webhook charges the exact price once, even when delivered twice', function () {
    Queue::fake();
    $user = userWithCredits();
    $generation = createGeneration($user);
    $remote = remoteGeneration($generation, 'succeeded');

    sendWebhook($this, 'evt_1', 'generation.succeeded', $remote)->assertNoContent();
    sendWebhook($this, 'evt_1', 'generation.succeeded', $remote)->assertNoContent();

    $generation->refresh();
    // 5 x $5 + 1100 x $40 + 1000 x $12.50 per 1M tokens = $0.056525, plus 25% = $0.07065625
    expect(Money::toUsdString($generation->provider_cost_pico))->toBe('0.056525000000')
        ->and(Money::toUsdString($generation->charged_pico))->toBe('0.070656250000')
        ->and(CreditLedgerEntry::where('generation_id', $generation->id)->where('type', 'charge')->count())->toBe(1)
        ->and($user->balancePico())->toBe(5 * Money::PICO_PER_DOLLAR - $generation->charged_pico);
});

test('the same success arriving under a different event id is still billed once', function () {
    Queue::fake();
    $user = userWithCredits();
    $generation = createGeneration($user);

    sendWebhook($this, 'evt_a', 'generation.succeeded', remoteGeneration($generation, 'succeeded'));
    sendWebhook($this, 'evt_b', 'generation.succeeded', remoteGeneration($generation, 'succeeded'));

    expect(CreditLedgerEntry::where('generation_id', $generation->id)->where('type', 'charge')->count())->toBe(1);
});

test('a late processing event never moves a finished generation backwards', function () {
    Queue::fake();
    $generation = createGeneration(userWithCredits());

    sendWebhook($this, 'evt_done', 'generation.succeeded', remoteGeneration($generation, 'succeeded'));
    sendWebhook($this, 'evt_late', 'generation.processing', remoteGeneration($generation, 'processing'));

    expect($generation->refresh()->status)->toBe(GenerationStatus::Succeeded);
});

test('a webhook that arrives before our submit returns is matched by its metadata uuid', function () {
    Queue::fake();
    $generation = createGeneration(userWithCredits());

    expect($generation->pixelforge_id)->toBeNull();

    sendWebhook($this, 'evt_early', 'generation.processing', remoteGeneration($generation, 'processing'));

    expect($generation->refresh())
        ->status->toBe(GenerationStatus::Processing)
        ->pixelforge_id->toBe('gen_test_1');
});

test('failed generations are free and release the hold', function () {
    Queue::fake();
    $user = userWithCredits();
    $generation = createGeneration($user);

    sendWebhook($this, 'evt_fail', 'generation.failed', remoteGeneration($generation, 'failed'));

    expect($generation->refresh())
        ->status->toBe(GenerationStatus::Failed)
        ->charged_pico->toBe(0)
        ->and($user->balancePico())->toBe(5 * Money::PICO_PER_DOLLAR);
});

test('webhooks with a bad or old signature are rejected', function () {
    $body = json_encode(['id' => 'evt_x', 'type' => 'ping', 'data' => ['object' => []]]);

    $this->call('POST', '/webhooks/pixelforge', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_PIXELFORGE_SIGNATURE' => WebhookSignature::sign('wrong_secret', $body, time()),
    ], $body)->assertStatus(400);

    $this->call('POST', '/webhooks/pixelforge', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_PIXELFORGE_SIGNATURE' => WebhookSignature::sign(WEBHOOK_SECRET, $body, time() - 3600),
    ], $body)->assertStatus(400);
});

test('submitting sends an idempotency key and moves the generation to queued', function () {
    $user = userWithCredits();

    Http::fake([
        '*/v1/pricing' => Http::response(json_decode(file_get_contents(__DIR__.'/../Fixtures/pricing.json'), true)),
        '*/v1/generations' => function ($request) {
            return Http::response(remoteGeneration(Generation::firstOrFail(), 'queued'), 202);
        },
    ]);

    $generation = createGeneration($user); // queue is "sync" in tests, so the job runs now

    expect($generation->refresh()->status)->toBe(GenerationStatus::Queued);
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v1/generations')
        && $request->header('Idempotency-Key')[0] === $generation->uuid);
});

test('a content policy rejection fails the generation for free', function () {
    $user = userWithCredits();

    Http::fake([
        '*/v1/pricing' => Http::response(json_decode(file_get_contents(__DIR__.'/../Fixtures/pricing.json'), true)),
        '*/v1/generations' => Http::response(['error' => ['type' => 'invalid_request_error', 'code' => 'content_policy_violation', 'message' => 'The prompt was rejected.']], 400),
    ]);

    $generation = createGeneration($user);

    expect($generation->refresh())
        ->status->toBe(GenerationStatus::Failed)
        ->error_code->toBe('content_policy_violation')
        ->and($user->balancePico())->toBe(5 * Money::PICO_PER_DOLLAR);
});

test('a success that arrives after we gave up records the provider cost but never charges the user', function () {
    Queue::fake();
    $user = userWithCredits();
    $generation = createGeneration($user);

    app(\App\Services\GenerationUpdater::class)->reject($generation, 'gave_up', 'Gave up.');
    sendWebhook($this, 'evt_late_success', 'generation.succeeded', remoteGeneration($generation, 'succeeded'));

    expect($generation->refresh())
        ->status->toBe(GenerationStatus::Failed)
        ->charged_pico->toBe(0)
        ->provider_cost_pico->toBe(56_525_000_000)
        ->and($user->balancePico())->toBe(5 * Money::PICO_PER_DOLLAR);
});

test('an unknown status is ignored instead of crashing the webhook', function () {
    Queue::fake();
    $generation = createGeneration(userWithCredits());

    sendWebhook($this, 'evt_odd', 'generation.paused', remoteGeneration($generation, 'paused'))->assertNoContent();

    expect($generation->refresh()->status)->toBe(GenerationStatus::Pending);
});

test('a running generation Pixelforge no longer knows about is released for free', function () {
    Queue::fake();
    $user = userWithCredits();
    $generation = createGeneration($user);
    $generation->forceFill(['pixelforge_id' => 'gen_missing', 'status' => GenerationStatus::Queued])->save();

    Http::fake(['*/v1/generations/gen_missing' => Http::response(['error' => ['code' => 'not_found', 'message' => 'No such generation.']], 404)]);

    (new \App\Jobs\SyncGeneration($generation->id))->handle(app(\App\Services\Pixelforge\PixelforgeClient::class), app(\App\Services\GenerationUpdater::class));

    expect($generation->refresh())
        ->status->toBe(GenerationStatus::Failed)
        ->error_code->toBe('not_found')
        ->and($user->balancePico())->toBe(5 * Money::PICO_PER_DOLLAR);
});
