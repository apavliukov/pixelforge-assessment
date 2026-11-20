<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Dto\Pixelforge\GenerationData;
use App\Dto\Pixelforge\TokenUsage;
use App\Enums\FailureCode;
use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Pricing\Money;
use Carbon\CarbonImmutable;
use Database\Factories\GenerationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
 *
 * @mixin IdeHelperGeneration
 */
#[Fillable([
    'prompt',
    'size',
    'quality',
    'reference_images',
    'status',
    'price_multiplier_basis_points',
    'estimate_min',
    'estimate_max',
])]
final class Generation extends Model
{
    /** @use HasFactory<GenerationFactory> */
    use HasFactory;
    use HasUuids;

    /**
     * How long after submission a generation counts as young and is polled more often.
     */
    public const int YOUNG_FOR_MINUTES = 10;

    /**
     * How long a young accepted generation may go without news before it is polled.
     */
    public const int POLL_YOUNG_AFTER_MINUTES = 1;

    /**
     * How long an older accepted generation may go without news before it is polled.
     */
    public const int POLL_OLD_AFTER_MINUTES = 10;

    /**
     * How long a generation may wait for the provider to accept it, from submission.
     */
    public const int WAIT_LIMIT_MINUTES = 10;

    /**
     * A generation still waiting this long was lost by its send job, which gives up at the wait
     * limit; the margin leaves a send in progress time to finish.
     */
    public const int ABANDONED_AFTER_MINUTES = self::WAIT_LIMIT_MINUTES + 5;

    /**
     * How far back a local failure is still checked against the provider's list of generations.
     */
    public const int VERIFY_LOCAL_FAILURES_FOR_HOURS = 24;

    /**
     * How long after a local failure the provider's list is read. A request we gave up on may
     * still be on its way; asking at once could miss it, and the check is made only once.
     */
    public const int VERIFY_LOCAL_FAILURES_AFTER_MINUTES = 2;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * A failure this app decided before the provider accepted the generation. It is provisional:
     * any state the provider reports for the generation replaces it.
     */
    public function isLocalFailure(): bool
    {
        return $this->status === GenerationStatus::Failed && $this->provider_id === null;
    }

    public function hasWaitedTooLong(): bool
    {
        return $this->created_at->addMinutes(self::WAIT_LIMIT_MINUTES)->isPast();
    }

    /**
     * Records a success with the money the provider billed for it. Not saved.
     *
     * @param  TokenUsage  $usage  the tokens the provider counted
     * @param  Money  $providerCost  what the provider billed
     * @param  Money  $userCharge  what the user pays
     * @param  GenerationData  $state  the succeeded provider state, for the image and the completion
     *                                 time, which decides the day the provider bills it on
     */
    public function markSucceeded(TokenUsage $usage, Money $providerCost, Money $userCharge, GenerationData $state): void
    {
        $this->status = GenerationStatus::Succeeded;
        $this->input_text_tokens = $usage->inputText;
        $this->input_image_tokens = $usage->inputImage;
        $this->output_tokens = $usage->output;
        $this->reasoning_tokens = $usage->reasoning;
        $this->provider_cost = $providerCost;
        $this->user_charge = $userCharge;
        $this->image_url = $state->imageUrl;
        $this->image_url_expires_at = $state->imageUrlExpiresAt;
        $this->completed_at = $state->completedAt;
    }

    /**
     * Records a failure: a failed generation is never charged. Not saved.
     *
     * @param  string  $code  a `FailureCode` value or the provider's error code
     * @param  string|null  $message  what went wrong, for the operator
     * @param  CarbonImmutable|null  $completedAt  when it failed; now if not given
     */
    public function markFailed(string $code, ?string $message, ?CarbonImmutable $completedAt = null): void
    {
        $this->status = GenerationStatus::Failed;
        $this->error_code = $code;
        $this->error_message = $message;
        $this->provider_cost = Money::fromNano(0);
        $this->user_charge = Money::fromNano(0);
        $this->completed_at = $completedAt ?? now();
    }

    /**
     * Undoes `markFailed()`, for a local failure the provider's report replaces. Not saved.
     */
    public function clearFailure(): void
    {
        $this->error_code = null;
        $this->error_message = null;
        $this->provider_cost = null;
        $this->user_charge = null;
        $this->completed_at = null;
    }

    /**
     * Accepted, unfinished generations the provider has not reported on recently: within a
     * minute while they are young, within ten minutes after that. Least recently checked first.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function dueForPolling(Builder $query): void
    {
        $youngSince = now()->subMinutes(self::YOUNG_FOR_MINUTES);

        $query->whereIn('status', [GenerationStatus::Queued, GenerationStatus::Processing])
            ->whereNotNull('provider_id')
            ->where(fn (Builder $dueQuery): Builder => $dueQuery
                ->where(fn (Builder $youngQuery): Builder => $youngQuery
                    ->where('created_at', '>=', $youngSince)
                    ->where('provider_synced_at', '<', now()->subMinutes(self::POLL_YOUNG_AFTER_MINUTES)))
                ->orWhere(fn (Builder $oldQuery): Builder => $oldQuery
                    ->where('created_at', '<', $youngSince)
                    ->where('provider_synced_at', '<', now()->subMinutes(self::POLL_OLD_AFTER_MINUTES))))
            ->oldest('provider_synced_at');
    }

    /**
     * Local failures of the last day, but not of the last two minutes, after which the provider
     * may hold the generation all the same, and about which it has not been asked yet. A failure
     * the provider decided itself is not among them.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function unverifiedLocalFailures(Builder $query): void
    {
        $query->where('status', GenerationStatus::Failed)
            ->whereNull('provider_id')
            ->whereNull('provider_synced_at')
            ->whereIn('error_code', [FailureCode::ProviderUnavailable, FailureCode::WaitTimeout, FailureCode::SubmissionRejected])
            ->where('completed_at', '>=', now()->subHours(self::VERIFY_LOCAL_FAILURES_FOR_HOURS))
            ->where('completed_at', '<=', now()->subMinutes(self::VERIFY_LOCAL_FAILURES_AFTER_MINUTES));
    }

    /**
     * Waiting, queued or processing.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function unfinished(Builder $query): void
    {
        $query->whereNotIn('status', array_filter(
            GenerationStatus::cases(),
            static fn (GenerationStatus $status): bool => $status->isFinal(),
        ));
    }

    /**
     * Generations still waiting long after every send attempt should have finished: the job that
     * sends them was lost.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function abandonedWhileWaiting(Builder $query): void
    {
        $query->where('status', GenerationStatus::Waiting)
            ->where('created_at', '<', now()->subMinutes(self::ABANDONED_AFTER_MINUTES));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => ImageSize::class,
            'quality' => ImageQuality::class,
            'reference_images' => 'array',
            'status' => GenerationStatus::class,
            'estimate_min' => MoneyCast::class,
            'estimate_max' => MoneyCast::class,
            'rates' => 'array',
            'provider_cost' => MoneyCast::class,
            'user_charge' => MoneyCast::class,
            'image_url_expires_at' => 'datetime',
            'provider_synced_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
