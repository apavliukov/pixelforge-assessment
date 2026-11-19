<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\FailureCode;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use Illuminate\Support\Facades\DB;

/**
 * Fails a generation the provider has not accepted, at no cost. A generation the provider has
 * accepted is never failed by this app: only the provider decides how it ends.
 */
final readonly class FailGenerationLocally
{
    /**
     * @param  Generation  $generation  failed only while it is waiting
     * @param  FailureCode  $code  why it failed
     * @param  string|null  $message  what went wrong, for the operator
     */
    public function __invoke(Generation $generation, FailureCode $code, ?string $message = null): Generation
    {
        return DB::transaction(static function () use ($generation, $code, $message): Generation {
            $locked = Generation::query()->whereKey($generation->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== GenerationStatus::Waiting) {
                return $locked;
            }

            $locked->markFailed($code->value, $message);
            $locked->save();

            return $locked;
        });
    }
}
