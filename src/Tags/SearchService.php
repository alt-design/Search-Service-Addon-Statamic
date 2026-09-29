<?php

namespace AltDesign\SearchService\Tags;

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
     * the corrected query string when match is corrected and null otherwise, and the
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
        $result = Search::query($query, $this->clamp($this->params->int('limit', 10)));

        if ($result === null) {
            return [];
        }

        $as = $this->getPaginationResultsKey();

        return array_merge(
            [$as => $result['results'], 'match' => $result['match'], 'corrected' => $result['corrected']],
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

        $result = Search::query($query, $perPage, ($page - 1) * $perPage);

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

        return array_merge($output, ['match' => $result['match'], 'corrected' => $result['corrected']]);
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
