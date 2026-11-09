import { apiErrorFromBody, ApiError } from './ApiError'

/** Base path for all API endpoints. `.htaccess` routes `/api/*` to PHP. */
export const API_BASE = '/api'

export type HttpMethod = 'GET' | 'POST' | 'PUT' | 'DELETE'

export interface RequestOptions {
  method?: HttpMethod
  /** JSON-serialisable request body. */
  body?: unknown
  /** Query parameters; `undefined`/`null` values are omitted. */
  query?: Record<string, string | number | undefined | null>
  signal?: AbortSignal
}

function buildUrl(path: string, query?: RequestOptions['query']): string {
  const url = `${API_BASE}${path}`
  if (!query) {
    return url
  }
  const params = new URLSearchParams()
  for (const [key, value] of Object.entries(query)) {
    if (value !== undefined && value !== null) {
      params.append(key, String(value))
    }
  }
  const qs = params.toString()
  return qs ? `${url}?${qs}` : url
}

async function parseBody(response: Response): Promise<unknown> {
  // 204 No Content and empty bodies parse to undefined rather than throwing.
  const text = await response.text()
  if (text.length === 0) {
    return undefined
  }
  try {
    return JSON.parse(text)
  } catch {
    return undefined
  }
}

/**
 * Performs a request against the REST API and returns the parsed JSON body.
 *
 * On a non-2xx response the `{ error, message }` body is turned into an
 * {@link ApiError} carrying the machine-readable code. Network failures are
 * also surfaced as an {@link ApiError} with status 0 and code `null`.
 */
export async function request<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const { method = 'GET', body, query, signal } = options

  let response: Response
  try {
    response = await fetch(buildUrl(path, query), {
      method,
      // Session cookie (HttpOnly) must accompany protected requests.
      credentials: 'same-origin',
      headers: body !== undefined ? { 'Content-Type': 'application/json' } : undefined,
      body: body !== undefined ? JSON.stringify(body) : undefined,
      signal,
    })
  } catch (cause) {
    const message = cause instanceof Error ? cause.message : 'Network request failed'
    throw new ApiError(0, null, message)
  }

  const parsed = await parseBody(response)
  if (!response.ok) {
    throw apiErrorFromBody(response.status, parsed)
  }
  return parsed as T
}
