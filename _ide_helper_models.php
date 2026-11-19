<?php

// @formatter:off
// phpcs:ignoreFile
/**
 * A helper file for your Eloquent Models
 * Copy the phpDocs from this file to the correct Model,
 * And remove them from this file, to prevent double declarations.
 *
 * @author Barry vd. Heuvel <barryvdh@gmail.com>
 */


namespace App\Models{
/**
 * One user's request for one image, from submission to its final status. Only the request is
 * mass assignable; everything the provider reports is set by `ApplyProviderState`.
 *
 * @property string $id
 * @property int $user_id
 * @property string|null $provider_id
 * @property string $prompt
 * @property ImageSize $size
 * @property ImageQuality $quality
 * @property list<string> $reference_images
 * @property GenerationStatus $status
 * @property int $price_multiplier_basis_points the provider cost multiplier at submission, 12500 = ×1.25
 * @property Money $estimate_min
 * @property Money $estimate_max
 * @property int|null $input_text_tokens
 * @property int|null $input_image_tokens
 * @property int|null $output_tokens
 * @property int|null $reasoning_tokens
 * @property array<string, string>|null $rates the provider's rates per 1M tokens, as received
 * @property Money|null $provider_cost null until the generation is finished
 * @property Money|null $user_charge null until the generation is finished
 * @property string|null $error_code
 * @property string|null $error_message the provider's message, for the operator
 * @property string|null $image_url
 * @property CarbonImmutable|null $image_url_expires_at
 * @property CarbonImmutable|null $provider_synced_at the last time the provider reported on it or was asked
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable $created_at the submission time
 * @property CarbonImmutable $updated_at
 * @property-read \App\Models\User $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Generation abandonedWhileWaiting()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Generation dueForPolling()
 * @method static \Database\Factories\GenerationFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Generation newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Generation newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Generation query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Generation unfinished()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Generation whereCompletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Generation whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Generation whereErrorCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Generation whereErrorMessage($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Generation whereEstimateMax($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Generation whereEstimateMin($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Generation whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Generation whereImageUrl($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Generation whereImageUrlExpiresAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Generation whereInputImageTokens($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Generation whereInputTextTokens($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Generation whereOutputTokens($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Generation wherePriceMultiplierBasisPoints($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Generation wherePrompt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Generation whereProviderCost($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Generation whereProviderId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Generation whereProviderSyncedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Generation whereQuality($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Generation whereRates($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Generation whereReasoningTokens($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Generation whereReferenceImages($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Generation whereSize($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Generation whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Generation whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Generation whereUserCharge($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Generation whereUserId($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	final class IdeHelperGeneration {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Generation> $generations
 * @property-read int|null $generations_count
 * @property-read \Illuminate\Notifications\DatabaseNotificationCollection<int, \Illuminate\Notifications\DatabaseNotification> $notifications
 * @property-read int|null $notifications_count
 * @method static \Database\Factories\UserFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereEmailVerifiedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User wherePassword($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereRememberToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereUpdatedAt($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	final class IdeHelperUser {}
}

namespace App\Models{
/**
 * One webhook event received from the provider, stored once however often it is delivered.
 *
 * @property int $id
 * @property string $event_id the provider's `evt_…` identifier
 * @property string $type
 * @property string $payload the raw request body, exactly as signed
 * @property CarbonImmutable|null $processed_at
 * @property CarbonImmutable|null $created_at
 * @method static \Database\Factories\WebhookEventFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookEvent newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookEvent newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookEvent query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookEvent whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookEvent whereEventId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookEvent whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookEvent wherePayload($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookEvent whereProcessedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookEvent whereType($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	final class IdeHelperWebhookEvent {}
}

