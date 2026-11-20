<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\ApplyWebhookEventJob;
use App\Models\WebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Receives an event whose signature has already been verified, stores it once and answers at
 * once; a queued job applies it. The event row and the job are written in one transaction (the
 * queue is in the same database), so an acknowledged event is never lost. A repeated delivery
 * of a stored event is acknowledged and ignored.
 */
final class PixelforgeWebhookController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $eventId = $request->json('id');
        $type = $request->json('type');

        if (! is_string($eventId) || ! is_string($type)) {
            abort(400, 'Malformed Pixelforge event.');
        }

        if ($type === 'ping') {
            return response()->noContent(200);
        }

        DB::transaction(function () use ($eventId, $type, $request): void {
            $webhookEvent = WebhookEvent::query()->createOrFirst(
                ['event_id' => $eventId],
                ['type' => $type, 'payload' => $request->getContent()],
            );

            if ($webhookEvent->wasRecentlyCreated) {
                dispatch(new ApplyWebhookEventJob($webhookEvent));
            }
        });

        return response()->noContent(200);
    }
}
