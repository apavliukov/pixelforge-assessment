<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Pricing\PricingCatalog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Tries;

/**
 * Exceptions are deliberately not caught: the job fails, Laravel logs it and records it in
 * failed_jobs, and the previous pricing stays in the cache. It is not retried, because the
 * next run is five minutes away; after an hour of failures the catalog stops using the old copy.
 */
#[Tries(1)]
final class RefreshPricingJob implements ShouldQueue
{
    use Queueable;

    public function handle(PricingCatalog $catalog): void
    {
        $catalog->refresh();
    }
}
