import { request } from './http'
import type {
  CreatedResponse,
  Language,
  LanguageInput,
  ListParams,
  LoginInput,
  QuoteDetail,
  QuoteInput,
  QuoteListResponse,
  SearchParams,
  SearchResponse,
  SourceDeleteStrategy,
  SourceDetail,
  SourceInput,
  SourceListResponse,
} from './types'

/**
 * Typed client for the citations REST API under `/api`. Every method returns a
 * promise that resolves with the parsed response or rejects with an
 * {@link ApiError} carrying the machine-readable error code.
 */
export const api = {
  // --- Public read endpoints ---------------------------------------------

  /** GET /api/search — paginated full-text search (Req 1, 2). */
  search(params: SearchParams, signal?: AbortSignal): Promise<SearchResponse> {
    return request<SearchResponse>('/search', {
      query: {
        q: params.q,
        languages: params.languages?.length ? params.languages.join(',') : undefined,
        page: params.page,
        pageSize: params.pageSize,
        sourceId: params.sourceId,
      },
      signal,
    })
  },

  /** GET /api/random — a single random quote (Req 3). */
  random(signal?: AbortSignal): Promise<QuoteDetail> {
    return request<QuoteDetail>('/random', { signal })
  },

  /** GET /api/quotes/{id} — single quote view (Req 4). */
  getQuote(id: number, signal?: AbortSignal): Promise<QuoteDetail> {
    return request<QuoteDetail>(`/quotes/${id}`, { signal })
  },

  /** GET /api/sources/{id} — single source view (Req 5). */
  getSource(id: number, signal?: AbortSignal): Promise<SourceDetail> {
    return request<SourceDetail>(`/sources/${id}`, { signal })
  },

  /** GET /api/languages — public language list (Req 12.7). */
  getLanguages(signal?: AbortSignal): Promise<Language[]> {
    return request<Language[]>('/languages', { signal })
  },

  // --- Protected admin list endpoints ------------------------------------

  /**
   * GET /api/quotes — paginated list of every quote for the admin area
   * (Req 18). Requires a valid session; rejects with an `UNAUTHORIZED`
   * {@link ApiError} otherwise. `page` defaults to 1 and `pageSize` to 20
   * (max 100) server-side when omitted.
   */
  listQuotes(params: ListParams = {}, signal?: AbortSignal): Promise<QuoteListResponse> {
    return request<QuoteListResponse>('/quotes', {
      query: { page: params.page, pageSize: params.pageSize },
      signal,
    })
  },

  /**
   * GET /api/sources — paginated list of every source for the admin area
   * (Req 19). Requires a valid session; rejects with an `UNAUTHORIZED`
   * {@link ApiError} otherwise. `page` defaults to 1 and `pageSize` to 20
   * (max 100) server-side when omitted.
   */
  listSources(params: ListParams = {}, signal?: AbortSignal): Promise<SourceListResponse> {
    return request<SourceListResponse>('/sources', {
      query: { page: params.page, pageSize: params.pageSize },
      signal,
    })
  },

  // --- Protected quote endpoints -----------------------------------------

  createQuote(input: QuoteInput): Promise<CreatedResponse> {
    return request<CreatedResponse>('/quotes', { method: 'POST', body: input })
  },

  updateQuote(id: number, input: QuoteInput): Promise<void> {
    return request<void>(`/quotes/${id}`, { method: 'PUT', body: input })
  },

  deleteQuote(id: number): Promise<void> {
    return request<void>(`/quotes/${id}`, { method: 'DELETE' })
  },

  // --- Protected source endpoints ----------------------------------------

  createSource(input: SourceInput): Promise<CreatedResponse> {
    return request<CreatedResponse>('/sources', { method: 'POST', body: input })
  },

  updateSource(id: number, input: SourceInput): Promise<void> {
    return request<void>(`/sources/${id}`, { method: 'PUT', body: input })
  },

  /**
   * DELETE /api/sources/{id} — delete a source (Req 11, 13). When the source
   * still has quotes, pass a `strategy` of `reassign` or `cancel`; omitting it
   * yields a `SOURCE_HAS_QUOTES` error so the UI can prompt for a choice (11.6).
   */
  deleteSource(id: number, strategy?: SourceDeleteStrategy): Promise<void> {
    return request<void>(`/sources/${id}`, {
      method: 'DELETE',
      query: { strategy },
    })
  },

  // --- Protected language endpoints --------------------------------------

  createLanguage(input: LanguageInput): Promise<Language> {
    return request<Language>('/languages', { method: 'POST', body: input })
  },

  // --- Auth endpoints -----------------------------------------------------

  /** POST /api/auth/login — sets an HttpOnly session cookie on success (Req 6). */
  login(input: LoginInput): Promise<void> {
    return request<void>('/auth/login', { method: 'POST', body: input })
  },

  /** POST /api/auth/logout — ends the current session (Req 6.6). */
  logout(): Promise<void> {
    return request<void>('/auth/logout', { method: 'POST' })
  },
}

export type Api = typeof api
