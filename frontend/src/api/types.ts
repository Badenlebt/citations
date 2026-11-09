// Data transfer objects exchanged with the PHP REST API under `/api`.
// Shapes mirror the endpoint contracts described in design.md
// ("Components and Interfaces").

export interface Language {
  id: number
  name: string
}

/** Source as embedded in a search result item. */
export interface SearchResultSource {
  id: number
  name: string
}

/** A single search result item (GET /api/search). */
export interface QuoteListItem {
  id: number
  text: string
  languageId: number
  languageName: string
  source: SearchResultSource
}

/** Paginated search response (GET /api/search). */
export interface SearchResponse {
  items: QuoteListItem[]
  total: number
  page: number
  pageSize: number
}

export interface SearchParams {
  q: string
  languages?: number[]
  page?: number
  pageSize?: number
  sourceId?: number
}

/** Pagination parameters shared by the admin list endpoints (Req 18.1, 19.1). */
export interface ListParams {
  page?: number
  pageSize?: number
}

/**
 * Paginated response of GET /api/quotes — the admin "list all quotes" endpoint
 * (Req 18). Each item matches {@link QuoteListItem}; the shape is identical to
 * {@link SearchResponse} but kept distinct so the two endpoints can evolve
 * independently.
 */
export interface QuoteListResponse {
  items: QuoteListItem[]
  total: number
  page: number
  pageSize: number
}

/**
 * A single source entry of GET /api/sources (Req 19.2): identifier, display
 * name and a flag marking the reserved Unknown source.
 */
export interface SourceListItem {
  id: number
  name: string
  isUnknown: boolean
}

/** Paginated response of GET /api/sources — "list all sources" (Req 19). */
export interface SourceListResponse {
  items: SourceListItem[]
  total: number
  page: number
  pageSize: number
}

/** Source as embedded in a single-quote view (includes linkUrl). */
export interface QuoteDetailSource {
  id: number
  name: string
  linkUrl: string | null
}

/** Single quote view (GET /api/quotes/{id}, GET /api/random). */
export interface QuoteDetail {
  id: number
  text: string
  languageId: number
  languageName: string
  source: QuoteDetailSource
}

/** Full source detail (GET /api/sources/{id}). */
export interface SourceDetail {
  id: number
  name: string
  birthDate: string | null
  deathDate: string | null
  description: string | null
  linkUrl: string | null
}

/** Response body for create operations (201 with { id }). */
export interface CreatedResponse {
  id: number
}

export interface QuoteInput {
  text: string
  sourceId?: number
  languageId: number
}

export interface SourceInput {
  name: string
  birthDate?: string | null
  deathDate?: string | null
  description?: string | null
  linkUrl?: string | null
}

export interface LanguageInput {
  name: string
}

export interface LoginInput {
  username: string
  password: string
}

/** Strategy for deleting a source that still has quotes assigned (Req 11.6). */
export type SourceDeleteStrategy = 'reassign' | 'cancel'
