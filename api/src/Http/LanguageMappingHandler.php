<?php

declare(strict_types=1);

namespace Citations\Http;

use Citations\ErrorCode;
use Citations\LanguageMappingService;
use Citations\Repository\UiLanguageMappingRepositoryInterface;
use Citations\UiLanguage;

/**
 * Language-mapping endpoint handler (random-quote-homepage, Requirements
 * 9.3-9.8; design "LanguageMappingHandler").
 *
 *   - GET /language-mappings (public, 9.3): `{ "de": id|null, "en": id|null }`.
 *   - PUT /language-mappings/{uiLanguage} (protected) with body `{ languageId }`:
 *     200 `{ uiLanguage, languageId }` (9.4); 400 INVALID_UI_LANGUAGE /
 *     INVALID_LANGUAGE (9.5, 9.6), 409 LANGUAGE_ALREADY_MAPPED.
 *   - DELETE /language-mappings/{uiLanguage} (protected): 200
 *     `{ uiLanguage, languageId: null }`, idempotent (9.7).
 *
 * Writes are protected by the SessionGuard BEFORE dispatch (9.8), so this
 * handler does not re-check auth.
 */
final class LanguageMappingHandler implements Handler
{
    public function __construct(
        private readonly LanguageMappingService $service,
        private readonly UiLanguageMappingRepositoryInterface $mappings,
    ) {
    }

    public function handle(Request $request, array $pathParams = []): Response
    {
        return match ($request->method) {
            'GET' => $this->list(),
            'PUT' => $this->assign($request, $pathParams),
            'DELETE' => $this->remove($pathParams),
            default => Response::error(ErrorCode::NOT_FOUND, 'Resource not found.'),
        };
    }

    private function list(): Response
    {
        $all = $this->mappings->findAll();
        $body = [];
        foreach (UiLanguage::cases() as $ui) {
            $body[$ui->value] = $all[$ui->value] ?? null;
        }

        return Response::ok($body);
    }

    /**
     * @param array<string, int|string> $pathParams
     */
    private function assign(Request $request, array $pathParams): Response
    {
        $ui = UiLanguage::tryFromInput($pathParams['uiLanguage'] ?? null);
        $value = $request->body['languageId'] ?? null;
        $languageId = is_int($value) ? $value : null;

        return $this->respond($this->service->assign($ui, $languageId));
    }

    /**
     * @param array<string, int|string> $pathParams
     */
    private function remove(array $pathParams): Response
    {
        $ui = UiLanguage::tryFromInput($pathParams['uiLanguage'] ?? null);

        return $this->respond($this->service->remove($ui));
    }

    private function respond(\Citations\LanguageMappingResult $result): Response
    {
        if (!$result->isSuccess) {
            $code = $result->error ?? ErrorCode::INTERNAL_ERROR;

            return Response::error($code, match ($code) {
                ErrorCode::INVALID_UI_LANGUAGE => 'The UI language must be "de" or "en".',
                ErrorCode::INVALID_LANGUAGE => 'The language does not exist.',
                ErrorCode::LANGUAGE_ALREADY_MAPPED => 'The language is already mapped to another UI language.',
                default => 'The request could not be processed.',
            });
        }

        return Response::ok([
            'uiLanguage' => $result->uiLanguage?->value,
            'languageId' => $result->languageId,
        ]);
    }
}
