<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\ApplyProviderState;
use App\Dto\Pixelforge\GenerationData;
use App\Models\WebhookEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Tries;

/**
 * Applies the generation carried by one stored webhook event. If it keeps failing it ends in
 * `failed_jobs` and the event stays unprocessed: it can be retried with `queue:retry` after a
 * fix, and polling brings the generation up to date meanwhile.
 */
#[Tries(3)]
#[Backoff(10)]
#[Queue('webhooks')]
final class ApplyWebhookEventJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public WebhookEvent $webhookEvent) {}

    public function handle(ApplyProviderState $applyProviderState): void
    {
        $webhookEvent = $this->webhookEvent;

        if ($webhookEvent->processed_at !== null) {
            return;
        }

        $event = json_decode($webhookEvent->payload, true, flags: JSON_THROW_ON_ERROR);

        if (str_starts_with($webhookEvent->type, 'generation.')) {
            $applyProviderState(GenerationData::fromArray($event['data']['object'] ?? []));
        }

        $webhookEvent->processed_at = now();
        $webhookEvent->save();
    }
}
