<?php

namespace AltDesign\SearchService\Tags;

use AltDesign\SearchService\Chosen;
use AltDesign\SearchService\JourneyState;
use AltDesign\SearchService\Criteria;
use AltDesign\SearchService\Search;
use Statamic\Extensions\Pagination\LengthAwarePaginator;
use Statamic\Tags\Concerns\OutputsItems;
use Statamic\Tags\Tags;

class SearchService extends Tags
{
    use OutputsItems;

    protected $defaultAsKey = 'results';

    /**
     * {{ search_service:results q="" limit="10" }} ... {{ /search_service:results }}
     *
     * The entries always live in {{ results }}, alongside {{ match }} reporting which tier
     * answered the query (exact, prefix, corrected, partial or none), {{ corrected }} giving
     * the corrected query string when match is corrected and null otherwise,
     * {{ facets }} holding each value of every attribute named in facets="" with a {{ total }}
     * of how many matched it (not {{ count }}, which Antlers reserves for the row's
     * position in the loop), and the
     * {{ no_results }} and {{ total_results }} variables the core collection tag also
     * exposes. With paginate="10" a {{ paginate }} array appears as well, which is how the
     * core collection tag behaves. The page comes from ?page= in the query string.
     */
    public function results(): mixed
    {
        $query = trim((string) $this->params->get('q'));

        if ($query === '') {
            return [];
        }

        $perPage = $this->clamp($this->params->int('paginate'));

        return $this->params->has('paginate')
            ? $this->paginated($query, $perPage)
            : $this->single($query);
    }

    private function single(string $query): mixed
    {
        $result = Search::query($query, $this->clamp($this->params->int('limit', 10)), 0, $this->criteria());

        if ($result === null) {
            return [];
        }

        $as = $this->getPaginationResultsKey();

        return array_merge(
            [$as => $result['results'], 'match' => $result['match'], 'corrected' => $this->safe($result['corrected']), 'facets' => $this->safe($result['facets'])],
            $this->extraOutput($result['results']),
        );
    }

    /**
     * Statamic's paginator rather than Laravel's, because the {{ paginate }} array the
     * core tags expose includes links rendered by methods only Statamic's subclass has.
     */
    private function paginated(string $query, int $perPage): mixed
    {
        $page = LengthAwarePaginator::resolveCurrentPage();

        $result = Search::query($query, $perPage, ($page - 1) * $perPage, $this->criteria());

        if ($result === null) {
            return [];
        }

        $output = $this->output(new LengthAwarePaginator(
            $result['results'],
            $result['total'],
            $perPage,
            $page,
            ['path' => LengthAwarePaginator::resolveCurrentPath()],
        ));

        return array_merge($output, ['match' => $result['match'], 'corrected' => $this->safe($result['corrected']), 'facets' => $this->safe($result['facets'])]);
    }

    /**
     * {{ search_service:ask q="" limit="10" }} ... {{ /search_service:ask }}
     *
     * As {{ search_service:results }}, but understands plain language rather than
     * matching words: the entries live in {{ results }} alongside {{ match }},
     * {{ corrected }}, {{ no_results }} and {{ total_results }}, with {{ paginate }}
     * appearing too when paginate="" is given. {{ intent }} carries what the service
     * understood: {{ intent:source }} says whether that came from the site's own
     * vocabulary, a cache, the language model or a fallback, {{ intent:terms }} the
     * words it kept, and {{ intent:concepts }} and {{ intent:unmatched }} the concepts
     * it matched and the ones the site has nothing for, so a template can say "we do
     * not stock red" rather than showing an empty list. {{ dropped }} carries
     * {{ dropped:facet }} and {{ dropped:value }} when nothing satisfied the whole query
     * and one part of it had to be given up to answer at all.
     *
     * Several turns make a conversation. {{ question }} is the one thing worth asking
     * next, with {{ question:facet }} and {{ question:options }}, each option carrying
     * {{ value }}, {{ total }} and {{ link }}, a query string ready for an href.
     * {{ chosen }} is what the visitor has settled, each with a {{ link }} that
     * unsettles it. The state travels in the URL rather than a session, so a narrowed
     * search can be shared and the back button works.
     */
    public function ask(): mixed
    {
        $query = trim((string) $this->params->get('q'));

        if ($query === '') {
            return [];
        }

        $perPage = $this->clamp($this->params->int('paginate'));
        $chosen = Chosen::from($this->params->get('chosen', request()->input('chosen')));

        return $this->params->has('paginate')
            ? $this->paginatedAsk($query, $perPage, $chosen)
            : $this->singleAsk($query, $chosen);
    }

    private function singleAsk(string $query, Chosen $chosen): mixed
    {
        $result = Search::ask($query, $this->clamp($this->params->int('limit', 10)), 0, $this->criteria(), $chosen);

        if ($result === null) {
            return [];
        }

        $as = $this->getPaginationResultsKey();

        return array_merge(
            [
                $as => $result['results'],
                'match' => $result['match'],
                'corrected' => $this->safe($result['corrected']),
                'facets' => $this->safe($result['facets']),
                'dropped' => $this->safe($result['dropped']),
                'intent' => $this->safe($result['intent']),
            ],
            $this->conversation($query, $result),
            $this->extraOutput($result['results']),
        );
    }

    private function paginatedAsk(string $query, int $perPage, Chosen $chosen): mixed
    {
        $page = LengthAwarePaginator::resolveCurrentPage();

        $result = Search::ask($query, $perPage, ($page - 1) * $perPage, $this->criteria(), $chosen);

        if ($result === null) {
            return [];
        }

        $output = $this->output(new LengthAwarePaginator(
            $result['results'],
            $result['total'],
            $perPage,
            $page,
            ['path' => LengthAwarePaginator::resolveCurrentPath()],
        ));

        return array_merge($output, [
            'match' => $result['match'],
            'corrected' => $this->safe($result['corrected']),
            'facets' => $this->safe($result['facets']),
            'dropped' => $this->safe($result['dropped']),
            'intent' => $this->safe($result['intent']),
        ], $this->conversation($query, $result));
    }

    /**
     * One turn of a guided conversation, named by the key a client gave it.
     *
     * {{ search_service:journey key="hire-enquiry" }}
     *
     * The message comes from the request, so the page is a form posting to
     * itself and the tag needs telling nothing. The state comes back ready for
     * a hidden input, so the next turn carries on where this one stopped
     * without a template assembling anything.
     *
     * An empty message opens the journey and returns its greeting, which is
     * also what a visitor arriving on the page for the first time gets.
     *
     * "failed" is true when the service could not answer. That is not the same
     * as being sent off path, and a template should say so and leave the
     * visitor where they are rather than send them away over a rate limit.
     */
    public function journey(): mixed
    {
        $key = trim((string) $this->params->get('key'));

        if ($key === '') {
            return ['failed' => true, 'handoff' => null, 'question' => null, 'results' => []];
        }

        $state = JourneyState::from($this->params->get('state', request()->input('state')));
        $message = trim((string) $this->params->get('message', request()->input('message')));

        $turn = Search::journey($key, $message, $state, $this->clamp($this->params->int('limit')));

        if ($turn === null) {
            return ['failed' => true, 'handoff' => null, 'question' => null, 'results' => []];
        }

        $next = JourneyState::from($turn['state']);

        return [
            'failed' => false,
            'key' => e($turn['key']),
            'name' => e($turn['name']),
            'prompt' => $turn['prompt'] === null ? null : e($turn['prompt']),
            'node' => $turn['node'] === null ? null : e($turn['node']),
            'message' => e($message),
            'state' => $next->parameter(),
            'settled' => $this->settled($next),
            'question' => $this->asked($turn['question'], $next),
            'sorted' => $this->safe($turn['sorted']),
            'handoff' => $this->safe($turn['handoff']),
            'total' => $turn['total'],
            'facets' => $this->safe($turn['facets']),
            'results' => $turn['results'],
        ];
    }

    /**
     * What the visitor has settled, each with the link that unsettles it, so a
     * wrong turn is one click to undo.
     *
     * @return array<int, array{facet: string, value: string, link: string}>
     */
    private function settled(JourneyState $state): array
    {
        return array_map(fn (array $pair): array => [
            'facet' => e($pair['facet']),
            'value' => e($pair['value']),
            'link' => $state->without($pair['facet'], $pair['value'])->query(''),
        ], $state->answers);
    }

    /**
     * The next question, each option carrying the link that answers it.
     *
     * The option is sent as the message, because that is what a visitor typing
     * the same thing would send: clicking "gold" and typing "gold" take the
     * same route through the service rather than two that can disagree.
     *
     * @param  array{facet: string, options: array<int, array{value: string, total: int}>}|null  $question
     * @return array<string, mixed>|null
     */
    private function asked(?array $question, JourneyState $state): ?array
    {
        if ($question === null) {
            return null;
        }

        return [
            'facet' => e($question['facet']),
            'options' => array_map(fn (array $option): array => [
                'value' => e($option['value']),
                'total' => (int) $option['total'],
                'link' => $state->query((string) $option['value']),
            ], $question['options']),
        ];
    }

    /**
     * Every string the service sent, escaped for a template.
     *
     * Antlers does not escape {{ }}, and all of this is the client's own
     * content arriving over HTTP: a facet name, a value, a corrected spelling.
     * None of it is ever markup, so escaping costs nothing and leaving it raw
     * puts a stored script one indexed field away from every search page.
     *
     * Array keys are left alone, since those are the names a template looks a
     * facet up by.
     */
    private function safe(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->safe($item), $value);
        }

        return is_string($value) ? e($value) : $value;
    }

    /**
     * What the conversation has settled and what to ask next, each carrying the
     * link that acts on it, so a template writes an href and nothing else.
     *
     * Built from what the service sent back rather than from what arrived, so a
     * pair it discarded leaves the page too.
     *
     * Facet names and values are escaped here rather than left to the template.
     * Antlers does not escape {{ }}, and these are the client's own index
     * values arriving over HTTP: a value is a label, never markup, so there is
     * nothing to lose by escaping it and a stored script to gain by not. The
     * links are built from the raw values and encoded by http_build_query.
     *
     * @param  array{chosen: array<int, array{facet: string, value: string}>, question: array{facet: string, options: array<int, array{value: string, total: int}>}|null}  $result
     * @return array<string, mixed>
     */
    private function conversation(string $query, array $result): array
    {
        $chosen = Chosen::from($result['chosen']);
        $question = $result['question'];

        return [
            'chosen' => array_map(fn (array $pair): array => [
                'facet' => e($pair['facet']),
                'value' => e($pair['value']),
                'link' => $chosen->without($pair['facet'], $pair['value'])->query($query),
            ], $chosen->toQuery()),
            'question' => $question === null ? null : [
                'facet' => e($question['facet']),
                'options' => array_map(fn (array $option): array => [
                    'value' => e($option['value']),
                    'total' => (int) $option['total'],
                    'link' => $chosen->with($question['facet'], $option['value'])->query($query),
                ], $question['options']),
            ],
        ];
    }

    /**
     * What this tag was asked to narrow, order and count by.
     *
     * Parameters are colon-separated the way Statamic's own tags take query conditions:
     * filter:colour="blue", filter:colour="blue|grey" for either, filter:price:gte="100"
     * for one end of a range, sort and direction for the order, and facets="colour|brand"
     * for what to count.
     */
    private function criteria(): Criteria
    {
        return Criteria::fromParameters($this->params->all());
    }

    /**
     * Template authors can pass a limit from anywhere, including a variable, so it is
     * clamped here as well as validated by the service.
     */
    private function clamp(int $value): int
    {
        return max(1, min(100, $value === 0 ? 10 : $value));
    }
}
