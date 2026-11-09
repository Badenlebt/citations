<?php

declare(strict_types=1);

namespace Citations\Http;

use Citations\ErrorCode;
use Citations\LanguageFilter;
use Citations\Paginator;
use Citations\QuoteMatcher;
use Citations\RandomQuoteSelector;
use Citations\RelevanceScorer;
use Citations\Repository\LanguageRepositoryInterface;
use Citations\Repository\QuoteRepositoryInterface;
use Citations\ResultSorter;
use Citations\SearchQueryValidator;
use Citations\SearchTokenizer;

/**
 * Search-API endpoint handler (Requirements 1, 2, 3, 5.3; design "Such-API").
 *
 * Both `GET /api/search` and `GET /api/random` route to the SEARCH handler key
 * (see {@see Dispatcher::match()}), so this one handler distinguishes the two
 * by the first path segment: `random` yields one random quote, anything else
 * (i.e. `search`) runs the full search pipeline.
 *
 * It is a pure composition layer over the existing side-effect-free search
 * components and the quote/language repositories — it adds no matching,
 * scoring, ordering or pagination logic of its own:
 *
 *   validate (1.9/1.13) -> tokenize -> per-quote match (1.1–1.4) + language
 *   filter (1.10–1.12) -> relevance score (2.1–2.6) -> deterministic order
 *   (1.5/2.7) -> paginate (1.6–1.8) -> shape the response.
 *
 * All collaborators are injected through the constructor (no globals), so the
 * handler is driven in unit tests with the in-memory repositories and a fixed
 * random generator.
 */
final class SearchHandler implements Handler
{
    public function __construct(
        private readonly QuoteRepositoryInterface $quotes,
        private readonly LanguageRepositoryInterface $languages,
        private readonly RandomQuoteSelector $randomQuoteSelector,
    ) {
    }

    public function handle(Request $request, array $pathParams = []): Response
    {
        // Both endpoints share the SEARCH handler key; the first path segment
        // selects the behaviour (design "GET /search und GET /random ... per
        // Pfadsegment unterscheiden").
        $segment = $request->segments[0] ?? 'search';

        if ($segment === 'random') {
            return $this->handleRandom();
        }

        return $this->handleSearch($request);
    }

    /**
     * `GET /api/random`: return exactly one random quote (3.1) or NO_QUOTES
     * when the stock is empty (3.3). The uniform, fully-reachable selection
     * (3.2) lives in the injected {@see RandomQuoteSelector}.
     */
    private function handleRandom(): Response
    {
        $quote = $this->randomQuoteSelector->pick();

        if ($quote === null) {
            return Response::error(ErrorCode::NO_QUOTES, 'No quotes are available.');
        }

        return Response::ok($this->shapeQuote($quote));
    }

    /**
     * `GET /api/search`: run the full search pipeline and return the paginated,
     * relevance-ordered result envelope `{ items, total, page, pageSize }`.
     */
    private function handleSearch(Request $request): Response
    {
        $rawQuery = $this->stringParam($request->query, 'q') ?? '';

        // Gate the raw input BEFORE any search work: over-long -> QUERY_TOO_LONG
        // (1.13), empty/whitespace -> MISSING_QUERY (1.9). Both short-circuit so
        // no tokenization or scoring happens on a rejected query.
        $validationError = SearchQueryValidator::validate($rawQuery);
        if ($validationError !== null) {
            return Response::error(
                $validationError,
                $validationError === ErrorCode::QUERY_TOO_LONG
                    ? 'The search query is too long.'
                    : 'A search query is required.',
            );
        }

        $terms = SearchTokenizer::tokenize($rawQuery);

        $filterLanguageIds = $this->parseLanguageIds($request->query);
        $existingLanguageIds = $this->existingLanguageIds();
        $sourceId = $this->positiveIntParam($request->query, 'sourceId');

        // Candidate set from the repository (optionally restricted to one
        // source, 5.3), scored and ordered purely in the application layer.
        $candidates = $this->quotes->findAllForSearch($sourceId);

        /** @var array<int, float> $relevanceById */
        $relevanceById = [];
        /** @var array<int, array<string, mixed>> $recordById */
        $recordById = [];

        foreach ($candidates as $quote) {
            $sourceName = $quote['source']['name'];

            // 1.1–1.4: match only against quote text and source name.
            if (!QuoteMatcher::matches($quote['text'], $sourceName, $terms)) {
                continue;
            }

            // 1.10–1.12: optional language filter; neutral when absent/empty.
            if (!LanguageFilter::includes($quote['languageId'], $filterLanguageIds, $existingLanguageIds)) {
                continue;
            }

            // 2.1: exactly one relevance value per matched quote.
            $relevanceById[$quote['id']] = RelevanceScorer::score($quote['text'], $sourceName, $terms);
            $recordById[$quote['id']] = $quote;
        }

        // 1.5/2.1/2.7: order descending by relevance with a stable id tie-break.
        $orderedIds = ResultSorter::sortIds($relevanceById);

        $orderedRecords = [];
        foreach ($orderedIds as $id) {
            $orderedRecords[] = $recordById[$id];
        }

        // 1.6/1.7/1.8: cut the requested page out of the ordered total list;
        // the total stays the full match count regardless of the page window.
        $page = $this->positiveIntParam($request->query, 'page');
        $pageSize = $this->positiveIntParam($request->query, 'pageSize');
        $paginated = Paginator::paginate($orderedRecords, $page, $pageSize);

        return Response::ok([
            'items' => array_map([$this, 'shapeQuote'], $paginated['items']),
            'total' => $paginated['total'],
            'page' => $paginated['page'],
            'pageSize' => $paginated['pageSize'],
        ]);
    }

    /**
     * Project a joined quote record onto the public search/random item shape
     * `{ id, text, languageId, languageName, source: { id, name } }`
     * (design "Antwort"). The source linkUrl is intentionally not part of the
     * search item shape.
     *
     * @param array<string, mixed> $quote
     *
     * @return array{id: int, text: string, languageId: int, languageName: string, source: array{id: int, name: string}}
     */
    private function shapeQuote(array $quote): array
    {
        return [
            'id' => $quote['id'],
            'text' => $quote['text'],
            'languageId' => $quote['languageId'],
            'languageName' => $quote['languageName'],
            'source' => [
                'id' => $quote['source']['id'],
                'name' => $quote['source']['name'],
            ],
        ];
    }

    /**
     * Parse the optional `languages` query parameter (a comma-separated list of
     * language ids) into a list of positive ints. Missing/empty/garbage ids are
     * dropped; an absent or fully-empty parameter yields the empty (neutral)
     * filter (1.11).
     *
     * @param array<string, mixed> $query
     *
     * @return list<int>
     */
    private function parseLanguageIds(array $query): array
    {
        $raw = $query['languages'] ?? null;
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $ids = [];
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if ($part === '' || !ctype_digit($part)) {
                continue;
            }
            $id = (int) $part;
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * The ids of all currently existing languages, so {@see LanguageFilter}
     * can drop filter ids that reference no existing language (1.12).
     *
     * @return list<int>
     */
    private function existingLanguageIds(): array
    {
        $ids = [];
        foreach ($this->languages->findAll() as $language) {
            $ids[] = $language['id'];
        }

        return $ids;
    }

    /**
     * Read a query parameter as a trimmed string, or null when absent/non-string.
     *
     * @param array<string, mixed> $query
     */
    private function stringParam(array $query, string $key): ?string
    {
        $value = $query[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * Read a query parameter as a positive integer, or null when absent or not a
     * positive integer. {@see Paginator} applies its own defaults/clamping for
     * `page`/`pageSize` when given null.
     *
     * @param array<string, mixed> $query
     */
    private function positiveIntParam(array $query, string $key): ?int
    {
        $value = $query[$key] ?? null;
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_string($value) && ctype_digit(trim($value))) {
            $int = (int) trim($value);

            return $int > 0 ? $int : null;
        }

        return null;
    }
}
