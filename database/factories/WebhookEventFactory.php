<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\WebhookEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WebhookEvent>
 */
final class WebhookEventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $eventId = 'evt_'.Str::random(24);

        return [
            'event_id' => $eventId,
            'type' => 'ping',
            'payload' => json_encode(['id' => $eventId, 'type' => 'ping', 'data' => ['object' => null]], JSON_THROW_ON_ERROR),
        ];
    }
}
