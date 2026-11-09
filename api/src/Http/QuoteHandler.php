<?php

declare(strict_types=1);

namespace Citations\Http;

use Citations\ErrorCode;
use Citations\Paginator;
use Citations\QuoteCreator;
use Citations\QuoteMutationResult;
use Citations\Repository\QuoteRepositoryInterface;

/**
 * Zitat-API endpoint handler (Requirements 4.1, 4.5, 7.4, 8.1, 9.1, 9.4, 9.5;
 * design "Zitat-API").
 *
 * One handler serves the whole /quotes group; it branches by method and by
 * whether a `{id}` path parameter was routed in:
 *   - GET /api/quotes (protected, 18) — the management list view: one page of
 *     the whole quote stock as `{ items, total, page, pageSize }` (18.1), the
 *     same envelope the search endpoint uses. `page`/`pageSize` default to 1/20
 *     and are clamped to [1, 100] via {@see Paginator} (18.2); a page past the
 *     end yields empty `items` with unchanged `total` (18.4) and an empty stock
 *     yields empty `items` with `total` 0 (18.5).
 *   - GET /api/quotes/{id} (public, 4) — the single view, including
 *     `source.linkUrl` (4.1); an unknown id is NOT_FOUND (4.5).
 *   - POST /api/quotes (protected, 7/8) — create; 201 `{ id }` on success
 *     (8.1), else a 400 validation error.
 *   - PUT /api/quotes/{id} (protected, 9) — overwrite; 200 on success (9.1),
 *     400 on invalid body (quote unchanged), 404 on unknown id (9.5).
 *   - DELETE /api/quotes/{id} (protected, 9) — remove; 200 confirmation (9.4),
 *     404 on unknown id (9.5).
 *
 * Authentication is NOT re-checked here: the front controller wraps the
 * dispatcher in a {@see SessionGuard} (task 12.2) that rejects any
 * unauthenticated write with 401 before dispatch, so a write that reaches this
 * handler is already on the guard-allowed path (7.4). The handler is therefore
 * a pure composition layer over {@see QuoteCreator} (validation, Unbekannt
 * fallback, not-found) and the quote repository's single-view read — it adds no
 * validation or persistence logic of its own, and maps each outcome's
 * {@see ErrorCode} to its HTTP status through {@see Response::error()}.
 *
 * Collaborators are injected through the constructor (no globals) so the
 * handler is driven in unit tests with the in-memory repositories.
 */
final class QuoteHandler implements Handler
{
    public function __construct(
        private readonly QuoteCreator $quotes,
        private readonly QuoteRepositoryInterface $repository,
    ) {
    }

    public function handle(Request $request, array $pathParams = []): Response
    {
        $id = $pathParams['id'] ?? null;

        return match ($request->method) {
            'GET' => $id === null ? $this->list($request) : $this->show($id),
            'POST' => $this->create($request),
            'PUT' => $id === null ? $this->notFound() : $this->update($id, $request),
            'DELETE' => $id === null ? $this->notFound() : $this->delete($id),
            default => $this->notFound(),
        };
    }

    /**
     * GET /api/quotes: return one page of the whole quote stock as the
     * management list envelope `{ items, total, page, pageSize }` (18.1) — the
     * same shape the search endpoint uses. `page`/`pageSize` are read from the
     * query and normalized through {@see Paginator} (the single source of truth
     * for the 1/20 defaults and the [1, 100] clamp, 18.2), then passed to the
     * repository already-normalized. A page past the end yields empty `items`
     * with the total unchanged (18.4) and an empty stock yields empty `items`
     * with total 0 (18.5) — both fall out of the repository read without any
     * special branching here.
     */
    private function list(Request $request): Response
    {
        $page = Paginator::normalizePage($this->positiveIntParam($request->query, 'page'));
        $pageSize = Paginator::normalizePageSize($this->positiveIntParam($request->query, 'pageSize'));

        $result = $this->repository->listAll($page, $pageSize);

        return Response::ok([
            'items' => $result['items'],
            'total' => $result['total'],
            'page' => $page,
            'pageSize' => $pageSize,
        ]);
    }

    /**
     * GET /api/quotes/{id}: return the single view
     * `{ id, text, languageId, languageName, source: { id, name, linkUrl } }`
     * (4.1), which is exactly the repository's joined record shape — so it is
     * returned as-is. An unknown id yields 404 NOT_FOUND (4.5).
     */
    private function show(int $id): Response
    {
        $quote = $this->repository->findById($id);
        if ($quote === null) {
            return $this->notFound();
        }

        return Response::ok($quote);
    }

    /**
     * POST /api/quotes: create from `{ text, sourceId?, languageId }`. Success
     * is 201 with `{ id }` (8.1); a validation failure becomes a 400 with the
     * specific code (MISSING_TEXT / INPUT_TOO_LONG / INVALID_SOURCE /
     * INVALID_LANGUAGE). An absent/null sourceId falls back to the
     * Unbekannt-Quelle inside {@see QuoteCreator} (13.3).
     */
    private function create(Request $request): Response
    {
        $sourceId = $this->parseSourceId($request->body);
        if ($sourceId instanceof Response) {
            return $sourceId;
        }

        $languageId = $this->parseLanguageId($request->body);
        if ($languageId instanceof Response) {
            return $languageId;
        }

        $result = $this->quotes->create($this->text($request->body), $sourceId, $languageId);

        return $this->mutationResponse($result, created: true);
    }

    /**
     * PUT /api/quotes/{id}: overwrite with the create-shaped body. Success is
     * 200 with `{ id }` (9.1); an invalid body is 400 and leaves the quote
     * unchanged (9.2/9.3/9.6); an unknown id is 404 (9.5). All of that is
     * decided by {@see QuoteCreator::edit()}.
     */
    private function update(int $id, Request $request): Response
    {
        $sourceId = $this->parseSourceId($request->body);
        if ($sourceId instanceof Response) {
            return $sourceId;
        }

        $languageId = $this->parseLanguageId($request->body);
        if ($languageId instanceof Response) {
            return $languageId;
        }

        $result = $this->quotes->edit($id, $this->text($request->body), $sourceId, $languageId);

        return $this->mutationResponse($result, created: false);
    }

    /**
     * DELETE /api/quotes/{id}: remove the quote. An existing quote is removed
     * and the removal confirmed with 200 `{ id }` (9.4); an unknown id is 404
     * NOT_FOUND and nothing changes (9.5).
     */
    private function delete(int $id): Response
    {
        return $this->mutationResponse($this->quotes->delete($id), created: false);
    }

    /**
     * Map a {@see QuoteMutationResult} to an HTTP response: a successful create
     * is 201 and a successful edit/delete is 200, both echoing `{ id }`; a
     * failure derives its status (400 for validation codes, 404 for NOT_FOUND)
     * from the carried {@see ErrorCode} via {@see Response::error()} (15.7).
     */
    private function mutationResponse(QuoteMutationResult $result, bool $created): Response
    {
        if (!$result->isSuccess) {
            return Response::error($result->error, $this->message($result->error));
        }

        $body = ['id' => $result->id];

        return $created ? Response::created($body) : Response::ok($body);
    }

    /**
     * Read the raw `text` field as a string, defaulting to the empty string
     * when absent or non-string so {@see QuoteCreator} rejects it uniformly as
     * MISSING_TEXT (8.2/9.2) after its own trim.
     *
     * @param array<string, mixed> $body
     */
    private function text(array $body): string
    {
        $value = $body['text'] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * Parse the optional `sourceId`: absent or null yields null (Unbekannt
     * fallback, 13.3); a positive int (or its numeric-string form) is passed
     * through for {@see QuoteCreator} to verify it exists; any other present
     * value is a malformed reference and is rejected immediately with 400
     * INVALID_SOURCE (8.3/9.3).
     *
     * @param array<string, mixed> $body
     */
    private function parseSourceId(array $body): int|null|Response
    {
        if (!array_key_exists('sourceId', $body) || $body['sourceId'] === null) {
            return null;
        }

        $id = $this->positiveInt($body['sourceId']);
        if ($id === null) {
            return Response::error(ErrorCode::INVALID_SOURCE, 'The chosen source does not exist.');
        }

        return $id;
    }

    /**
     * Parse the required `languageId`: a positive int (or its numeric-string
     * form) is passed through for {@see QuoteCreator} to verify it exists; an
     * absent/null/malformed value cannot reference an existing language and is
     * rejected with 400 INVALID_LANGUAGE (8.4/9.3).
     *
     * @param array<string, mixed> $body
     */
    private function parseLanguageId(array $body): int|Response
    {
        $id = $this->positiveInt($body['languageId'] ?? null);
        if ($id === null) {
            return Response::error(ErrorCode::INVALID_LANGUAGE, 'The chosen language does not exist.');
        }

        return $id;
    }

    /**
     * Interpret a JSON value as a positive integer id, accepting both a native
     * int and a digit-only string; anything else is null.
     */
    private function positiveInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_string($value) && ctype_digit(trim($value))) {
            $int = (int) trim($value);

            return $int > 0 ? $int : null;
        }

        return null;
    }

    /**
     * Read a query parameter as a positive integer, or null when absent or not
     * a positive integer; {@see Paginator} then applies the `page`/`pageSize`
     * defaults and clamping (18.2). Mirrors the query parsing in
     * {@see SearchHandler}.
     *
     * @param array<string, mixed> $query
     */
    private function positiveIntParam(array $query, string $key): ?int
    {
        return $this->positiveInt($query[$key] ?? null);
    }

    /**
     * A human-readable, frontend-display message for a mutation failure code.
     */
    private function message(ErrorCode $code): string
    {
        return match ($code) {
            ErrorCode::MISSING_TEXT => 'The quote text is required.',
            ErrorCode::INPUT_TOO_LONG => 'The quote text is too long.',
            ErrorCode::INVALID_SOURCE => 'The chosen source does not exist.',
            ErrorCode::INVALID_LANGUAGE => 'The chosen language does not exist.',
            ErrorCode::NOT_FOUND => 'Quote not found.',
            default => 'The request could not be processed.',
        };
    }

    private function notFound(): Response
    {
        return Response::error(ErrorCode::NOT_FOUND, 'Quote not found.');
    }
}
