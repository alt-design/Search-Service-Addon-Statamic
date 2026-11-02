<?php

namespace AltDesign\SearchService;

/**
 * What a search asks for beyond the words: what to narrow it to, what order to
 * return it in, and what to count.
 *
 * One object because the four travel together through the tag, the cache key and the
 * request, and a cache key that forgot one of them would serve an unfiltered page to
 * somebody who asked for blue.
 */
final readonly class Criteria
{
    /**
     * @param  array<string, mixed>  $filter
     * @param  list<string>  $facets
     */
    public function __construct(
        public array $filter = [],
        public ?string $sort = null,
        public ?string $direction = null,
        public array $facets = [],
    ) {}

    public static function none(): self
    {
        return new self;
    }

    /**
     * Read the tag's parameters.
     *
     * Colon-separated names, the way Statamic's own tags take query conditions:
     * filter:colour="blue" narrows to blue, filter:colour="blue|grey" to either, and
     * filter:price:gte="100" sets one end of a range. Pipes separate alternatives here
     * for the same reason they do everywhere else in Antlers.
     *
     * @param  array<string, mixed>  $parameters
     */
    public static function fromParameters(array $parameters): self
    {
        $filter = [];

        foreach ($parameters as $name => $value) {
            if (! is_string($name) || ! str_starts_with($name, 'filter:')) {
                continue;
            }

            $parts = explode(':', $name);
            $field = $parts[1] ?? '';
            $bound = $parts[2] ?? null;

            if ($field === '' || ($value === null || $value === '')) {
                continue;
            }

            if ($bound === null) {
                $filter[$field] = self::list((string) $value);

                continue;
            }

            $filter[$field] = [...(array) ($filter[$field] ?? []), $bound => (string) $value];
        }

        $facets = $parameters['facets'] ?? null;

        return new self(
            $filter,
            self::text($parameters['sort'] ?? null),
            self::text($parameters['direction'] ?? null),
            $facets === null || $facets === '' ? [] : self::listOf((string) $facets),
        );
    }

    /**
     * Read a request, where filters already arrive as arrays and need no splitting.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromRequest(array $data): self
    {
        $filter = $data['filter'] ?? [];
        $facets = $data['facets'] ?? [];

        return new self(
            is_array($filter) ? $filter : [],
            self::text($data['sort'] ?? null),
            self::text($data['direction'] ?? null),
            is_array($facets) ? array_values(array_filter($facets, 'is_string')) : [],
        );
    }

    public function isEmpty(): bool
    {
        return $this->filter === [] && $this->sort === null && $this->facets === [];
    }

    /**
     * The parameters as the service takes them, with anything unset left out so a plain
     * search sends a plain request.
     *
     * @return array<string, mixed>
     */
    public function toQuery(): array
    {
        return array_filter([
            'filter' => $this->filter,
            'sort' => $this->sort,
            'direction' => $this->direction,
            'facets' => $this->facets,
        ], fn ($value): bool => $value !== null && $value !== []);
    }

    /**
     * A stable signature for the cache key. Sorted, so the same search written two ways is
     * one cache entry rather than two.
     */
    public function fingerprint(): string
    {
        $filter = $this->filter;
        ksort($filter);

        $facets = $this->facets;
        sort($facets);

        return md5(json_encode([$filter, $this->sort, $this->direction, $facets]) ?: '');
    }

    /**
     * One value, or the list a pipe separates.
     */
    private static function list(string $value): string|array
    {
        $values = self::listOf($value);

        return count($values) === 1 ? $values[0] : $values;
    }

    /**
     * @return list<string>
     */
    private static function listOf(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode('|', $value)), fn (string $part): bool => $part !== ''));
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
