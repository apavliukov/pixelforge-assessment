<?php

declare(strict_types=1);

namespace App\Dto\Pixelforge;

use UnexpectedValueException;

/**
 * One page of `GET /v1/generations`: the provider's generations, newest first.
 */
final readonly class GenerationPageData
{
    /**
     * @param  array<string, array<string, mixed>>  $generationsByLocalId  the generations that carry a local identifier, as received and not yet validated: one malformed generation must not hide the rest of the page
     * @param  string|null  $nextCursor  what to ask the next page with; null on the last page
     */
    private function __construct(
        public array $generationsByLocalId,
        public ?string $nextCursor,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws UnexpectedValueException when the response is not a list of generations
     */
    public static function fromArray(array $payload): self
    {
        $items = $payload['data'] ?? null;

        if (! is_array($items) || ! array_is_list($items)) {
            throw new UnexpectedValueException('The list of generations has no "data" list.');
        }

        $generationsByLocalId = [];

        foreach ($items as $item) {
            $localId = is_array($item) ? ($item['metadata']['local_id'] ?? null) : null;

            if (is_string($localId)) {
                $generationsByLocalId[$localId] = $item;
            }
        }

        $nextCursor = $payload['next_cursor'] ?? null;

        return new self($generationsByLocalId, is_string($nextCursor) && $nextCursor !== '' ? $nextCursor : null);
    }
}
