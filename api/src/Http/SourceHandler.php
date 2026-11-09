<?php

declare(strict_types=1);

namespace Citations\Http;

use Citations\ErrorCode;
use Citations\Paginator;
use Citations\Repository\SourceRepositoryInterface;
use Citations\SourceCreator;
use Citations\SourceDeleter;
use Citations\SourceDeletionStrategy;
use Citations\SourceMutationResult;

/**
 * Autoren-API endpoint handler (Requirements 5.1, 5.2, 5.4, 10.1, 10.6, 11.1,
 * 11.2, 11.3, 11.4, 11.5; design "Autoren-API").
 *
 * One handler serves the whole /sources group; it branches by method and by
 * whether a `{id}` path parameter was routed in:
 *   - GET /api/sources (protected, 19) — the management list view: one page of
 *     all sources as `{ items, total, page, pageSize }` (19.1), the same
 *     envelope the search endpoint uses. `page`/`pageSize` default to 1/20 and
 *     are clamped to [1, 100] via {@see Paginator} (19.2); a page past the end
 *     yields empty `items` with unchanged `total` (19.4). The Unbekannt-Quelle
 *     always exists, so the total is at least 1 and the first page always holds
 *     it (19.5).
 *   - GET /api/sources/{id} (public, 5) — the single view
 *     `{ id, name, birthDate, deathDate, description, linkUrl }`; unset optional
 *     fields come back as null (5.1, 5.2). An unknown id is NOT_FOUND (5.4).
 *     The repository record additionally carries an `isUnknown` flag; it is
 *     intentionally stripped here so the public body matches the design single
 *     view exactly.
 *   - POST /api/sources (protected, 10) — create; 201 `{ id }` on success
 *     (10.1), else a 400/409 validation error.
 *   - PUT /api/sources/{id} (protected, 10) — overwrite; 200 on success (10.6),
 *     400/409 on invalid body (source unchanged), 404 on unknown id.
 *   - DELETE /api/sources/{id} (protected, 11) — remove; 200 confirmation on a
 *     source with no quotes (11.1); 409 SOURCE_HAS_QUOTES when the source has
 *     quotes and no strategy was chosen (11.2); with `?strategy=reassign` the
 *     quotes are reassigned to the Unbekannt-Quelle and the source removed
 *     (11.3); with `?strategy=cancel` nothing changes (11.4); deleting the
 *     Unbekannt-Quelle is 400 UNKNOWN_SOURCE_UNDELETABLE and changes nothing
 *     (11.5).
 *
 * The deletion `strategy` is read from the QUERY string (design shows
 * `?strategy=reassign`). Only the exact tokens `reassign` and `cancel` map to a
 * {@see SourceDeletionStrategy}; any other value — including a present but
 * unrecognised one — is treated as "no strategy" (null). That is the safe
 * choice: a source that still has quotes then yields the SOURCE_HAS_QUOTES
 * conflict rather than silently reassigning or deleting on a typo (11.2).
 *
 * Authentication is NOT re-checked here: the front controller wraps the
 * dispatcher in a {@see SessionGuard} (task 12.2) that rejects any
 * unauthenticated write with 401 before dispatch, so a write that reaches this
 * handler is already on the guard-allowed path. The handler is therefore a pure
 * composition layer over {@see SourceCreator} (create/edit validation,
 * not-found, reserved name), {@see SourceDeleter} (has-quotes / strategy /
 * undeletable) and the source repository's single-view read — it adds no
 * validation or persistence logic of its own, and maps each outcome's
 * {@see ErrorCode} to its HTTP status through {@see Response::error()} (15.7).
 *
 * Collaborators are injected through the constructor (no globals) so the handler
 * is driven in unit tests with the in-memory repositories.
 */
final class SourceHandler implements Handler
{
    public function __construct(
        private readonly SourceCreator $creator,
        private readonly SourceDeleter $deleter,
        private readonly SourceRepositoryInterface $repository,
    ) {
    }

    public function handle(Request $request, array $pathParams = []): Response
    {
        $id = $pathParams['id'] ?? null;

        return match ($request->method) {
            'GET' => $id === null ? $this->list($request) : $this->show($id),
            'POST' => $this->create($request),
            'PUT' => $id === null ? $this->notFound() : $this->update($id, $request),
            'DELETE' => $id === null ? $this->notFound() : $this->delete($id, $request),
            default => $this->notFound(),
        };
    }

    /**
     * GET /api/sources: return one page of all sources as the management list
     * envelope `{ items, total, page, pageSize }` (19.1) — the same shape the
     * search endpoint uses. `page`/`pageSize` are read from the query and
     * normalized through {@see Paginator} (the single source of truth for the
     * 1/20 defaults and the [1, 100] clamp, 19.2), then passed to the repository
     * already-normalized. A page past the end yields empty `items` with the
     * total unchanged (19.4); the ever-present Unbekannt-Quelle keeps the total
     * at least 1 so the first page always holds it (19.5) — all of which falls
     * out of the repository read without any special branching here.
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
     * GET /api/sources/{id}: return the public single view
     * `{ id, name, birthDate, deathDate, description, linkUrl }` with unset
     * optional fields as null (5.1, 5.2). The repository record's `isUnknown`
     * flag is stripped so the body matches the design single view exactly. An
     * unknown id is 404 NOT_FOUND (5.4).
     */
    private function show(int $id): Response
    {
        $source = $this->repository->findById($id);
        if ($source === null) {
            return $this->notFound();
        }

        return Response::ok([
            'id' => $source['id'],
            'name' => $source['name'],
            'birthDate' => $source['birthDate'],
            'deathDate' => $source['deathDate'],
            'description' => $source['description'],
            'linkUrl' => $source['linkUrl'],
        ]);
    }

    /**
     * POST /api/sources: create from `{ name, birthDate?, deathDate?,
     * description?, linkUrl? }`. Success is 201 with `{ id }` (10.1); a
     * validation failure becomes the specific 400/409 code (MISSING_NAME,
     * INPUT_TOO_LONG, INVALID_LINK_URL, UNKNOWN_SOURCE_RESERVED) via
     * {@see SourceCreator::create()}.
     */
    private function create(Request $request): Response
    {
        $body = $request->body;

        $result = $this->creator->create(
            $this->field($body, 'name') ?? '',
            $this->field($body, 'birthDate'),
            $this->field($body, 'deathDate'),
            $this->field($body, 'description'),
            $this->field($body, 'linkUrl'),
        );

        return $this->mutationResponse($result, created: true);
    }

    /**
     * PUT /api/sources/{id}: overwrite with the create-shaped body. Success is
     * 200 with `{ id }` (10.6); an invalid body is 400/409 and leaves the source
     * unchanged; an unknown id is 404. All of that is decided by
     * {@see SourceCreator::edit()}.
     */
    private function update(int $id, Request $request): Response
    {
        $body = $request->body;

        $result = $this->creator->edit(
            $id,
            $this->field($body, 'name') ?? '',
            $this->field($body, 'birthDate'),
            $this->field($body, 'deathDate'),
            $this->field($body, 'description'),
            $this->field($body, 'linkUrl'),
        );

        return $this->mutationResponse($result, created: false);
    }

    /**
     * DELETE /api/sources/{id}: remove the source, applying the query `strategy`
     * only when the source still has quotes. Outcomes map to 200 (removed or
     * cancelled, 11.1/11.4), 409 SOURCE_HAS_QUOTES (quotes present, no strategy,
     * 11.2), 400 UNKNOWN_SOURCE_UNDELETABLE (Unbekannt-Quelle, 11.5) or 404
     * (unknown id), all via {@see SourceDeleter::delete()}.
     */
    private function delete(int $id, Request $request): Response
    {
        $strategy = $this->parseStrategy($request->query);

        return $this->mutationResponse($this->deleter->delete($id, $strategy), created: false);
    }

    /**
     * Map a {@see SourceMutationResult} to an HTTP response: a successful create
     * is 201 and a successful edit/delete is 200, both echoing `{ id }`; a
     * failure derives its status (400/404/409) from the carried
     * {@see ErrorCode} via {@see Response::error()} (15.7).
     */
    private function mutationResponse(SourceMutationResult $result, bool $created): Response
    {
        if (!$result->isSuccess) {
            return Response::error($result->error, $this->message($result->error));
        }

        $body = ['id' => $result->id];

        return $created ? Response::created($body) : Response::ok($body);
    }

    /**
     * Read a body field as a trimmed-later string, or null when absent or not a
     * string. The {@see SourceCreator} performs its own trimming/normalisation,
     * so values are passed through verbatim; a non-string present value is
     * treated as absent (null) so optional fields stay optional and the name
     * falls to its MISSING_NAME path.
     *
     * @param array<string, mixed> $body
     */
    private function field(array $body, string $key): ?string
    {
        $value = $body[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * Parse the optional query `strategy` into a {@see SourceDeletionStrategy},
     * or null when absent, non-string, or not one of the exact tokens
     * `reassign`/`cancel`. A present-but-unrecognised value deliberately maps to
     * null so a source with quotes yields the SOURCE_HAS_QUOTES conflict rather
     * than acting on a malformed choice (11.2).
     *
     * @param array<string, mixed> $query
     */
    private function parseStrategy(array $query): ?SourceDeletionStrategy
    {
        $value = $query['strategy'] ?? null;
        if (!is_string($value)) {
            return null;
        }

        return SourceDeletionStrategy::tryFrom($value);
    }

    /**
     * Read a query parameter as a positive integer, or null when absent or not
     * a positive integer; {@see Paginator} then applies the `page`/`pageSize`
     * defaults and clamping (19.2). Mirrors the query parsing in
     * {@see SearchHandler}.
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

    /**
     * A human-readable, frontend-display message for a mutation failure code.
     */
    private function message(ErrorCode $code): string
    {
        return match ($code) {
            ErrorCode::MISSING_NAME => 'The source name is required.',
            ErrorCode::INPUT_TOO_LONG => 'One of the submitted fields is too long.',
            ErrorCode::INVALID_LINK_URL => 'The link URL must start with http:// or https://.',
            ErrorCode::UNKNOWN_SOURCE_RESERVED => 'The name "unbekannt" is reserved.',
            ErrorCode::SOURCE_HAS_QUOTES => 'The source still has quotes; choose reassign or cancel.',
            ErrorCode::UNKNOWN_SOURCE_UNDELETABLE => 'The unknown source cannot be deleted.',
            ErrorCode::NOT_FOUND => 'Source not found.',
            default => 'The request could not be processed.',
        };
    }

    private function notFound(): Response
    {
        return Response::error(ErrorCode::NOT_FOUND, 'Source not found.');
    }
}
