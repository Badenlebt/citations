<?php

declare(strict_types=1);

namespace Citations\Http;

use Citations\ErrorCode;
use Citations\LanguageCreator;
use Citations\Repository\LanguageRepositoryInterface;

/**
 * Sprach-API endpoint handler (Requirements 12.1, 12.2, 12.3, 12.7; design
 * "Sprach-API").
 *
 * One handler serves the whole /languages group; it branches by method:
 *   - GET /api/languages (public, 12.7) — the full list of existing languages
 *     for assignment and the public filter, returned as a bare JSON array of
 *     `{ id, name }` records in the repository's stable order. This read route
 *     is reachable WITHOUT a session: the session guard forwards reads untouched
 *     and only protects the POST write before dispatch (12.2). The stock is
 *     dynamically extendable and not fixed to DE/EN, so a freshly created
 *     language appears here immediately (12.2, 12.3).
 *   - POST /api/languages (protected, 12) — create from `{ name }`. Success is
 *     201 with the stored `{ id, name }` (the trimmed display name), which is
 *     afterwards part of the available set (12.3). A validation failure becomes
 *     the specific 400/409 code: MISSING_LANGUAGE_NAME for a missing/empty name
 *     (12.4), INPUT_TOO_LONG for a name over 100 characters after trim (12.6),
 *     LANGUAGE_EXISTS for a case-insensitive duplicate (12.5).
 *
 * Authentication is NOT re-checked here: the front controller wraps the
 * dispatcher in a {@see SessionGuard} that rejects any unauthenticated write
 * with 401 before dispatch, so a POST that reaches this handler is already on
 * the guard-allowed path, while the GET is forwarded without a session (12.2).
 * The handler is therefore a pure composition layer over {@see LanguageCreator}
 * (trim, missing/too-long/duplicate validation and persistence) and the
 * repository's full-list read; it maps each failure's {@see ErrorCode} to its
 * HTTP status through {@see Response::error()} (15.7).
 *
 * Collaborators are injected through the constructor (no globals) so the handler
 * is driven in unit tests with the in-memory repository.
 */
final class LanguageHandler implements Handler
{
    public function __construct(
        private readonly LanguageCreator $creator,
        private readonly LanguageRepositoryInterface $repository,
    ) {
    }

    public function handle(Request $request, array $pathParams = []): Response
    {
        return match ($request->method) {
            'GET' => $this->list(),
            'POST' => $this->create($request),
            default => $this->notFound(),
        };
    }

    /**
     * GET /api/languages: the public list of all existing languages as a bare
     * array of `{ id, name }` in the repository's stable order (12.7). An empty
     * stock yields an empty array; a newly created language is part of the set
     * (12.2, 12.3).
     */
    private function list(): Response
    {
        return Response::ok($this->repository->findAll());
    }

    /**
     * POST /api/languages: create from `{ name }`. Success is 201 with the
     * stored `{ id, name }` (the trimmed display name), afterwards part of the
     * available set (12.3); a validation failure becomes the specific 400/409
     * code via {@see LanguageCreator::create()} (12.4/12.5/12.6).
     */
    private function create(Request $request): Response
    {
        $name = $this->name($request->body);

        $result = $this->creator->create($name);
        if (!$result->isSuccess) {
            return Response::error($result->error, $this->message($result->error));
        }

        $stored = $this->repository->findById($result->id);
        $body = $stored ?? ['id' => $result->id, 'name' => trim($name)];

        return Response::created($body);
    }

    /**
     * Read the body `name` as a string, or '' when absent or not a string. The
     * {@see LanguageCreator} performs its own trimming and rejects an empty
     * trimmed name as MISSING_LANGUAGE_NAME (12.4), so a missing or non-string
     * value is passed through as '' rather than judged here.
     *
     * @param array<string, mixed> $body
     */
    private function name(array $body): string
    {
        $value = $body['name'] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * A human-readable, frontend-display message for a create failure code.
     */
    private function message(ErrorCode $code): string
    {
        return match ($code) {
            ErrorCode::MISSING_LANGUAGE_NAME => 'The language name is required.',
            ErrorCode::INPUT_TOO_LONG => 'The language name is too long.',
            ErrorCode::LANGUAGE_EXISTS => 'A language with this name already exists.',
            default => 'The request could not be processed.',
        };
    }

    private function notFound(): Response
    {
        return Response::error(ErrorCode::NOT_FOUND, 'Resource not found.');
    }
}
