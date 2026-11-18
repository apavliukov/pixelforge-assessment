<?php

declare(strict_types=1);

use App\Jobs\RefreshPricingJob;
use App\Pricing\PricingCatalog;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;

it('stores the published pricing for later estimates', function (): void {
    Http::preventStrayRequests();
    Http::fake(['pixelforge.promptedit.dev/v1/pricing' => Http::response(publishedPricing())]);

    dispatch_sync(new RefreshPricingJob());

    expect(resolve(PricingCatalog::class)->current()->payload)->toBe(publishedPricing());
    Http::assertSentCount(1);
});

it('is scheduled every five minutes', function (): void {
    $events = collect(resolve(Schedule::class)->events())
        ->filter(fn (Event $event): bool => $event->description === RefreshPricingJob::class);

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('*/5 * * * *');
});
