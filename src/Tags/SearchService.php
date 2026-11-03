<?php

namespace AltDesign\SearchService\Tags;

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
            [$as => $result['results'], 'match' => $result['match'], 'corrected' => $result['corrected'], 'facets' => $result['facets']],
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

        return array_merge($output, ['match' => $result['match'], 'corrected' => $result['corrected'], 'facets' => $result['facets']]);
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
     */
    public function ask(): mixed
    {
        $query = trim((string) $this->params->get('q'));

        if ($query === '') {
            return [];
        }

        $perPage = $this->clamp($this->params->int('paginate'));

        return $this->params->has('paginate')
            ? $this->paginatedAsk($query, $perPage)
            : $this->singleAsk($query);
    }

    private function singleAsk(string $query): mixed
    {
        $result = Search::ask($query, $this->clamp($this->params->int('limit', 10)), 0, $this->criteria());

        if ($result === null) {
            return [];
        }

        $as = $this->getPaginationResultsKey();

        return array_merge(
            [
                $as => $result['results'],
                'match' => $result['match'],
                'corrected' => $result['corrected'],
                'facets' => $result['facets'],
                'dropped' => $result['dropped'],
                'intent' => $result['intent'],
            ],
            $this->extraOutput($result['results']),
        );
    }

    private function paginatedAsk(string $query, int $perPage): mixed
    {
        $page = LengthAwarePaginator::resolveCurrentPage();

        $result = Search::ask($query, $perPage, ($page - 1) * $perPage, $this->criteria());

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
            'corrected' => $result['corrected'],
            'facets' => $result['facets'],
            'dropped' => $result['dropped'],
            'intent' => $result['intent'],
        ]);
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
