<?php

declare(strict_types=1);

use App\Dto\Pixelforge\GenerationData;
use App\Enums\FailureCode;
use App\Models\Generation;
use App\Pricing\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
| No feature test may reach the provider, whether or not its file remembers to say so:
| stray requests are refused for all of them.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(fn () => Http::preventStrayRequests())
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * The live `GET /v1/pricing` response, as recorded on 2026-11-18.
 *
 * @return array<string, mixed>
 */
function publishedPricing(): array
{
    return json_decode(file_get_contents(__DIR__.'/Fixtures/Pixelforge/pricing.json'), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * The documented generation object in the given status, with the fields that status does not
 * have cleared, then the overrides applied.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function providerGeneration(string $status = 'succeeded', array $overrides = []): array
{
    $generation = json_decode(file_get_contents(__DIR__.'/Fixtures/Pixelforge/generation.json'), true, flags: JSON_THROW_ON_ERROR);
    $generation['status'] = $status;

    if ($status !== 'succeeded') {
        $generation['image_url'] = null;
        $generation['image_url_expires_at'] = null;
        $generation['usage'] = null;
    }

    if ($status === 'queued' || $status === 'processing') {
        $generation['completed_at'] = null;
    }

    if ($status === 'failed') {
        $generation['error'] = [
            'code' => 'generation_failed',
            'message' => 'The model could not produce an image for this prompt.',
        ];
    }

    return array_replace($generation, $overrides);
}

/**
 * A webhook event body, as Pixelforge sends it.
 *
 * @param  array<string, mixed>|null  $generation  the generation object; null for `ping`
 */
function webhookEventBody(string $type, ?array $generation): string
{
    return json_encode([
        'id' => 'evt_01JC2Z9B7K2M4P6R8T0V3X5Z7A',
        'object' => 'event',
        'type' => $type,
        'created_at' => '2026-11-04T14:03:29Z',
        'data' => ['object' => $generation],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

function pixelforgeSignature(string $body, ?int $timestamp = null, string $secret = 'whsec_test'): string
{
    $timestamp ??= time();

    return "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$body}", $secret);
}

function deliverWebhook(string $body, ?string $signature): TestResponse
{
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

    if ($signature !== null) {
        $server['HTTP_PIXELFORGE_SIGNATURE'] = $signature;
    }

    return test()->call('POST', route('pixelforgeWebhook'), server: $server, content: $body);
}

/**
 * A provider state for the given local generation: its provider identifier (or a new one when it
 * has none yet) and its local identifier in the metadata.
 *
 * @param  array<string, mixed>  $overrides
 */
function providerStateFor(Generation $generation, string $status, array $overrides = []): GenerationData
{
    return GenerationData::fromArray(providerGeneration($status, [
        'id' => $generation->provider_id ?? 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K',
        'metadata' => ['local_id' => $generation->id],
        ...$overrides,
    ]));
}

/**
 * One row of the provider's usage report in the documented shape. The token counts are not
 * read by this app.
 *
 * @return array<string, mixed>
 */
function providerUsageFigures(int $generations, string $cost): array
{
    return [
        'generations' => $generations,
        'input_text_tokens' => 0,
        'input_image_tokens' => 0,
        'output_tokens' => 0,
        'reasoning_tokens' => 0,
        'cost_usd' => $cost,
    ];
}

/**
 * Fakes the provider's usage report: `[date => [generations, cost]]`, with the total they add up to.
 *
 * @param  array<string, array{int, string}>  $days
 */
function fakeUsageReport(array $days): void
{
    $rows = [];
    $generations = 0;
    $cost = Money::fromNano(0);

    foreach ($days as $date => [$dayGenerations, $dayCost]) {
        $rows[] = providerUsageFigures($dayGenerations, $dayCost) + ['date' => $date];
        $generations += $dayGenerations;
        $cost = $cost->plus(Money::fromDecimalString($dayCost));
    }

    Http::fake(['pixelforge.promptedit.dev/v1/usage*' => Http::response([
        'object' => 'usage',
        'data' => $rows,
        'total' => providerUsageFigures($generations, $cost->toDecimalString()),
    ])]);
}

/**
 * The provider's list of generations, with whatever query it is asked with.
 */
const PROVIDER_GENERATION_LIST_URL = 'pixelforge.promptedit.dev/v1/generations?*';

/**
 * A generation that failed on our side long enough ago to be checked against the provider.
 *
 * @param  array<string, mixed>  $attributes
 */
function localFailure(FailureCode $code = FailureCode::ProviderUnavailable, array $attributes = []): Generation
{
    return Generation::factory()->failedLocally($code)->create([
        'completed_at' => now()->subMinutes(Generation::CHECK_LOCAL_FAILURES_AFTER_MINUTES + 1),
        ...$attributes,
    ]);
}

/**
 * One page of the provider's list of generations, as it answers.
 *
 * @param  list<array<string, mixed>>  $generations
 * @return array<string, mixed>
 */
function providerGenerationPage(array $generations, ?string $nextCursor = null): array
{
    return ['object' => 'list', 'data' => $generations, 'has_more' => $nextCursor !== null, 'next_cursor' => $nextCursor];
}

/**
 * Fakes the provider's list of generations, one argument per page in the order they are asked
 * for. Each page but the last answers with a cursor named after the next page's number.
 *
 * @param  list<array<string, mixed>>  ...$pages
 */
function fakeProviderGenerationList(array ...$pages): void
{
    $sequence = Http::sequence();

    foreach ($pages as $number => $generations) {
        $sequence->push(providerGenerationPage($generations, $number === count($pages) - 1 ? null : 'page-'.($number + 2)));
    }

    Http::fake([PROVIDER_GENERATION_LIST_URL => $sequence]);
}
