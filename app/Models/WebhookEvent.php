<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\WebhookEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One webhook event received from the provider, stored once however often it is delivered.
 *
 * @property int $id
 * @property string $event_id the provider's `evt_…` identifier
 * @property string $type
 * @property string $payload the raw request body, exactly as signed
 * @property CarbonImmutable|null $processed_at
 * @property CarbonImmutable|null $created_at
 *
 * @mixin IdeHelperWebhookEvent
 */
#[Fillable([
    'event_id',
    'type',
    'payload',
])]
final class WebhookEvent extends Model
{
    /** @use HasFactory<WebhookEventFactory> */
    use HasFactory;

    public const null UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'processed_at' => 'datetime',
        ];
    }
}
