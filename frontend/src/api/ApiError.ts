import { isKnownErrorCode, type ErrorCode } from './errorCodes'

/**
 * Error thrown when an API request fails. Carries both the HTTP status and the
 * machine-readable `error` code from the `{ error, message }` body, so callers
 * can branch on a stable code (e.g. MISSING_QUERY, UNAUTHORIZED) rather than on
 * localised message text.
 */
export class ApiError extends Error {
  readonly status: number
  /** Known API error code, or `null` when the code was missing/unrecognised. */
  readonly code: ErrorCode | null
  /** Raw `error` field as received, even if not a known code. */
  readonly rawCode: string | null

  constructor(status: number, rawCode: string | null, message: string) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.rawCode = rawCode
    this.code = isKnownErrorCode(rawCode) ? rawCode : null
  }

  /** True when the server rejected the request as unauthenticated. */
  get isUnauthorized(): boolean {
    return this.code === 'UNAUTHORIZED' || this.status === 401
  }

  /** True when the resource was not found. */
  get isNotFound(): boolean {
    return this.code === 'NOT_FOUND' || this.status === 404
  }
}

interface ErrorBody {
  error?: unknown
  message?: unknown
}

/**
 * Builds an {@link ApiError} from a failed response body. Tolerates malformed
 * or empty bodies: falls back to the HTTP status for the message and a null
 * code, so a broken error payload never masks the underlying failure.
 */
export function apiErrorFromBody(status: number, body: unknown): ApiError {
  const parsed = (body ?? {}) as ErrorBody
  const rawCode = typeof parsed.error === 'string' ? parsed.error : null
  const message =
    typeof parsed.message === 'string' && parsed.message.length > 0
      ? parsed.message
      : `Request failed with status ${status}`
  return new ApiError(status, rawCode, message)
}
