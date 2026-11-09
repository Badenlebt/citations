// Machine-readable error codes returned by the PHP REST API.
// The API error response format is `{ error: <CODE>, message: <text> }`
// (see design.md "Fehlerantwort-Format" / error table). The `error` code is
// stable and used by the frontend to drive localisation and UI behaviour,
// while `message` is a human-readable fallback.

export const ERROR_CODES = [
  'MISSING_QUERY',
  'QUERY_TOO_LONG',
  'MISSING_TEXT',
  'INVALID_SOURCE',
  'INVALID_LANGUAGE',
  'MISSING_NAME',
  'INVALID_LINK_URL',
  'INPUT_TOO_LONG',
  'MISSING_LANGUAGE_NAME',
  'UNKNOWN_SOURCE_UNDELETABLE',
  'MISSING_CREDENTIALS',
  'INVALID_CREDENTIALS',
  'UNAUTHORIZED',
  'NOT_FOUND',
  'NO_QUOTES',
  'SOURCE_HAS_QUOTES',
  'UNKNOWN_SOURCE_RESERVED',
  'LANGUAGE_EXISTS',
  'RATE_LIMITED',
  'INTERNAL_ERROR',
] as const

export type ErrorCode = (typeof ERROR_CODES)[number]

const ERROR_CODE_SET = new Set<string>(ERROR_CODES)

/** Narrows an arbitrary string to a known `ErrorCode`, if it is one. */
export function isKnownErrorCode(code: unknown): code is ErrorCode {
  return typeof code === 'string' && ERROR_CODE_SET.has(code)
}
