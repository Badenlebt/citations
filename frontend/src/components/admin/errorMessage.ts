// Maps a thrown API error to a localised, user-facing message for the admin UI.
//
// The REST API returns a stable machine-readable `error` code (see
// design.md error table); the `errors.<CODE>` i18n keys mirror those codes.
// Unknown or network errors fall back to a generic message so the UI never
// surfaces raw internal details.

import { ApiError } from '@/api'
import type { I18n, MessageKey } from '@/i18n'

/** Error codes that have a dedicated `errors.<CODE>` translation key. */
const TRANSLATED_CODES = new Set<string>([
  'MISSING_TEXT',
  'INVALID_SOURCE',
  'INVALID_LANGUAGE',
  'MISSING_NAME',
  'INVALID_LINK_URL',
  'INPUT_TOO_LONG',
  'MISSING_LANGUAGE_NAME',
  'LANGUAGE_EXISTS',
  'UNKNOWN_SOURCE_RESERVED',
  'UNKNOWN_SOURCE_UNDELETABLE',
  'MISSING_CREDENTIALS',
  'INVALID_CREDENTIALS',
  'UNAUTHORIZED',
  'NOT_FOUND',
  'RATE_LIMITED',
  'INTERNAL_ERROR',
])

/**
 * Resolves a localised message for an error raised by an API call. Known error
 * codes map to their `errors.<CODE>` translation; everything else uses the
 * generic `errors.unknown` fallback.
 */
export function apiErrorMessage(i18n: I18n, error: unknown): string {
  if (error instanceof ApiError && error.code && TRANSLATED_CODES.has(error.code)) {
    return i18n.t(`errors.${error.code}` as MessageKey)
  }
  return i18n.t('errors.unknown')
}
