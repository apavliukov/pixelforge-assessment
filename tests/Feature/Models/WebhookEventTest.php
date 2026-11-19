<?php

declare(strict_types=1);

use App\Models\WebhookEvent;
use Illuminate\Database\UniqueConstraintViolationException;

it('stores an event identifier only once', function (): void {
    WebhookEvent::factory()->create(['event_id' => 'evt_1']);

    WebhookEvent::factory()->create(['event_id' => 'evt_1']);
})->throws(UniqueConstraintViolationException::class);
