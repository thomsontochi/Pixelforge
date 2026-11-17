<?php

namespace App\Http\Controllers;

use App\Models\WebhookEvent;
use App\Services\GenerationUpdater;
use App\Services\Pixelforge\WebhookSignature;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PixelforgeWebhookController extends Controller
{
    public function __invoke(Request $request, WebhookSignature $signature, GenerationUpdater $updater): Response
    {
        // Verify against the raw body, before any JSON decoding.
        $payload = $request->getContent();

        if (! $signature->isValid($request->header('Pixelforge-Signature'), $payload)) {
            Log::warning('Pixelforge webhook rejected: bad signature', ['event' => $request->header('Pixelforge-Event-Id')]);

            abort(400, 'Invalid Pixelforge signature.');
        }

        $event = json_decode($payload, true);
        $remote = $event['data']['object'] ?? null;

        if (! isset($event['id'], $event['type']) || $event['type'] === 'ping' || ! is_array($remote) || ($remote['object'] ?? null) !== 'generation') {
            return response()->noContent();
        }

        // Record the event and apply it in one transaction. If applying fails, the event row is
        // rolled back too, Pixelforge gets a 500 and retries, and nothing is half-done.
        DB::transaction(function () use ($event, $remote, $request, $updater) {
            $inserted = WebhookEvent::query()->insertOrIgnore([
                'event_id' => $event['id'],
                'type' => $event['type'],
                'pixelforge_generation_id' => $remote['id'] ?? null,
                'delivery_attempt' => (int) $request->header('Pixelforge-Delivery-Attempt', '1'),
                'payload' => json_encode($event),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($inserted === 0) {
                return; // Duplicate delivery of an event we already handled.
            }

            $generation = $updater->findFor($remote);

            if ($generation === null) {
                Log::warning('Pixelforge webhook for an unknown generation', ['event' => $event['id'], 'generation' => $remote['id'] ?? null]);

                return;
            }

            $updater->apply($generation, $remote);
        });

        return response()->noContent();
    }
}
