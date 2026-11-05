<?php

namespace AltDesign\SearchService;

/**
 * Where a visitor has got to in a journey, as it travels in the URL.
 *
 * The service keeps nothing, so the state comes back with every message. What
 * travels is what has been concluded, never what was said: the node they are
 * at and the answers they have settled. Carrying the messages instead would
 * mean the service re-reading all of them each turn, so turn five would cost
 * five model calls and five seconds, growing with the conversation.
 *
 * One parameter holds three things: the node, then what has been settled,
 * then the words of the conversation so far. The words are there because most
 * of what somebody says can never be an answer: a catalogue with no material
 * attribute cannot settle "metal", and without somewhere to keep it the next
 * message starts from nothing and the conversation forgets what it was about.
 *
 *     wedding-hire!range:Weddings|colour:gold!metal|chair
 *
 * Every part is encoded on the way in, and the query strings come out ready
 * for an href or a hidden input, so a template never encodes anything and
 * cannot double encode it.
 *
 * None of the three separators is a character rawurlencode leaves alone, so a
 * value holding one is escaped and can never be read as structure. A tilde
 * would have read more tidily and is unreserved, which means it would have
 * survived encoding and split a value in half.
 *
 * The page can still show the visitor what they typed. It just keeps that
 * itself, where the service does not need it.
 */
final readonly class JourneyState
{
    private const NODE = '!';

    private const PAIRS = '|';

    private const PAIR = ':';

    private const MAX_PAIRS = 20;

    private const MAX_LENGTH = 64;

    private const MAX_WANTS = 12;

    /**
     * @param  list<array{facet: string, value: string}>  $answers
     * @param  list<string>  $wants
     */
    private function __construct(
        public ?string $node,
        public array $answers,
        public array $wants = [],
    ) {}

    public static function start(): self
    {
        return new self(null, [], []);
    }

    /**
     * Read the parameter as it arrives from a link, a hidden input or a tag.
     *
     * Anything unreadable is dropped rather than refused. It arrives from a
     * URL anybody can edit, and a mangled pair should cost a visitor their
     * place in the conversation at worst, never the page.
     */
    public static function from(mixed $value): self
    {
        if (is_array($value)) {
            return new self(
                self::text($value['node'] ?? null),
                self::readPairs($value['answers'] ?? []),
                self::readWords($value['wants'] ?? []),
            );
        }

        if (! is_string($value) || trim($value) === '') {
            return self::start();
        }

        $parts = explode(self::NODE, $value, 3);

        return new self(
            self::text(rawurldecode($parts[0])),
            self::readPairs(self::splitPairs($parts[1] ?? '')),
            self::readWords(array_map('rawurldecode', array_filter(explode(self::PAIRS, $parts[2] ?? '')))),
        );
    }

    public function atStart(): bool
    {
        return $this->node === null;
    }

    /**
     * The state as the service takes it.
     *
     * @return array{node: string|null, answers: list<array{facet: string, value: string}>, wants: list<string>}
     */
    public function toQuery(): array
    {
        return ['node' => $this->node, 'answers' => $this->answers, 'wants' => $this->wants];
    }

    /**
     * The value of the state parameter, encoded.
     */
    public function parameter(): string
    {
        if ($this->node === null) {
            return '';
        }

        $pairs = implode(self::PAIRS, array_map(
            fn (array $pair): string => rawurlencode($pair['facet']).self::PAIR.rawurlencode($pair['value']),
            $this->answers,
        ));

        $wants = implode(self::PAIRS, array_map('rawurlencode', $this->wants));

        if ($pairs === '' && $wants === '') {
            return rawurlencode($this->node);
        }

        return rawurlencode($this->node).self::NODE.$pairs.($wants === '' ? '' : self::NODE.$wants);
    }

    /**
     * A whole query string for a link that asks something, ready for an href.
     *
     * Used for the options under a question, so clicking one is a link rather
     * than another thing for a template author to assemble.
     */
    public function query(string $message): string
    {
        return http_build_query(array_filter([
            'message' => $message,
            'state' => $this->parameter(),
        ], fn (string $value): bool => $value !== ''));
    }

    /**
     * The same state with one thing unsettled, which is what a visitor
     * changing their mind needs and what makes a wrong turn recoverable.
     */
    public function without(string $facet, string $value): self
    {
        return new self($this->node, array_values(array_filter(
            $this->answers,
            fn (array $pair): bool => $pair['facet'] !== $facet || $pair['value'] !== $value,
        )), $this->wants);
    }

    /**
     * @return list<string>
     */
    private static function readWords(mixed $wants): array
    {
        if (! is_array($wants)) {
            return [];
        }

        $words = [];

        foreach ($wants as $want) {
            $word = self::text($want);

            if ($word !== null && ! in_array($word, $words, true)) {
                $words[] = $word;
            }
        }

        return array_slice($words, 0, self::MAX_WANTS);
    }

    /**
     * @param  array<int|string, mixed>  $pairs
     * @return list<array{facet: string, value: string}>
     */
    private static function readPairs(mixed $pairs): array
    {
        if (! is_array($pairs)) {
            return [];
        }

        $read = [];

        foreach ($pairs as $pair) {
            if (count($read) >= self::MAX_PAIRS || ! is_array($pair)) {
                continue;
            }

            $facet = self::text($pair['facet'] ?? null);
            $value = self::text($pair['value'] ?? null);

            if ($facet !== null && $value !== null) {
                $read[] = ['facet' => $facet, 'value' => $value];
            }
        }

        return $read;
    }

    /**
     * @return list<array{facet: string, value: string}>
     */
    private static function splitPairs(string $encoded): array
    {
        if (trim($encoded) === '') {
            return [];
        }

        $pairs = [];

        foreach (explode(self::PAIRS, $encoded) as $pair) {
            $parts = explode(self::PAIR, $pair, 2);

            if (count($parts) !== 2) {
                continue;
            }

            $pairs[] = ['facet' => rawurldecode($parts[0]), 'value' => rawurldecode($parts[1])];
        }

        return $pairs;
    }

    private static function text(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, self::MAX_LENGTH);
    }
}
