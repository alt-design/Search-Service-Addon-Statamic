<?php

namespace AltDesign\SearchService;

/**
 * What a visitor has settled so far in a conversation, and the links that
 * change it.
 *
 * The service stores nothing, so this travels in the URL: one "chosen"
 * parameter holding facet and value pairs. A URL carries the whole state,
 * which means a narrowed search can be bookmarked, shared and reached by the
 * back button, and a visitor who returns tomorrow is where they left off.
 *
 * Each pair is encoded on the way in and the query strings come out ready to
 * drop into an href, so a template never has to encode anything and cannot
 * double encode it.
 */
final readonly class Chosen
{
    private const PAIRS = '|';

    private const PAIR = ':';

    private const MAX_PAIRS = 20;

    /**
     * @param  list<array{facet: string, value: string}>  $pairs
     */
    private function __construct(public array $pairs) {}

    public static function none(): self
    {
        return new self([]);
    }

    /**
     * Read the parameter as it arrives from a link or a tag.
     *
     * Anything unreadable is dropped rather than rejected, since it arrives
     * from a URL somebody may have edited, and a mangled pair should narrow
     * nothing rather than break the page.
     */
    public static function from(mixed $value): self
    {
        if (is_array($value)) {
            return new self(self::readPairs($value));
        }

        if (! is_string($value) || trim($value) === '') {
            return self::none();
        }

        $pairs = [];

        foreach (explode(self::PAIRS, $value) as $pair) {
            $parts = explode(self::PAIR, $pair, 2);

            if (count($parts) !== 2) {
                continue;
            }

            $pairs[] = ['facet' => rawurldecode($parts[0]), 'value' => rawurldecode($parts[1])];
        }

        return new self(self::readPairs($pairs));
    }

    public function isEmpty(): bool
    {
        return $this->pairs === [];
    }

    /**
     * The pairs as the service takes them.
     *
     * @return list<array{facet: string, value: string}>
     */
    public function toQuery(): array
    {
        return $this->pairs;
    }

    /**
     * A stable signature for the cache key, so a narrowed conversation is
     * never served the answer to a wider one.
     */
    public function fingerprint(): string
    {
        $pairs = $this->pairs;
        usort($pairs, fn (array $a, array $b): int => [$a['facet'], $a['value']] <=> [$b['facet'], $b['value']]);

        return md5(json_encode($pairs) ?: '');
    }

    /**
     * The same conversation with one more thing settled, replacing whatever
     * was settled for that facet before.
     */
    public function with(string $facet, string $value): self
    {
        $kept = array_values(array_filter($this->pairs, fn (array $pair): bool => $pair['facet'] !== $facet));

        return new self([...$kept, ['facet' => $facet, 'value' => $value]]);
    }

    /**
     * The same conversation with one thing unsettled, which is what a visitor
     * changing their mind needs and what makes a wrong turn recoverable.
     */
    public function without(string $facet, string $value): self
    {
        return new self(array_values(array_filter(
            $this->pairs,
            fn (array $pair): bool => $pair['facet'] !== $facet || $pair['value'] !== $value,
        )));
    }

    /**
     * The value of the chosen parameter, encoded.
     */
    public function parameter(): string
    {
        return implode(self::PAIRS, array_map(
            fn (array $pair): string => rawurlencode($pair['facet']).self::PAIR.rawurlencode($pair['value']),
            $this->pairs,
        ));
    }

    /**
     * A whole query string for a link, ready for an href with no filter.
     */
    public function query(string $search): string
    {
        return http_build_query(array_filter([
            'q' => $search,
            'chosen' => $this->parameter(),
        ], fn (string $value): bool => $value !== ''));
    }

    /**
     * @param  array<int|string, mixed>  $pairs
     * @return list<array{facet: string, value: string}>
     */
    private static function readPairs(array $pairs): array
    {
        $read = [];

        foreach ($pairs as $pair) {
            if (count($read) >= self::MAX_PAIRS || ! is_array($pair)) {
                continue;
            }

            $facet = trim((string) ($pair['facet'] ?? ''));
            $value = trim((string) ($pair['value'] ?? ''));

            if ($facet !== '' && $value !== '') {
                $read[] = ['facet' => $facet, 'value' => $value];
            }
        }

        return $read;
    }
}
