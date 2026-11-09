// Maximum input lengths for all length-limited fields (Req 14.5, 14.6).
//
// These values MUST match the server-side limits documented in design.md
// ("Längenbegrenzte Eingabefelder", API contracts and DB schema):
//   - Sucheingabe (search query)        1–200   → INPUT via QUERY_TOO_LONG (1.13)
//   - Zitattext (quote text)            1–5000   → INPUT_TOO_LONG (8.6, 9.6)
//   - Quellenname (source name)         1–200    → INPUT_TOO_LONG (10.7)
//   - Optionale Quellenfelder           0–500    → INPUT_TOO_LONG (10.7)
//     (Geburtsdatum, Sterbedatum, Kurzbeschreibung)
//   - Link-URL                          0–2000   → INPUT_TOO_LONG (10.7)
//   - Sprachbezeichnung (language name) 1–100    → INPUT_TOO_LONG (12.6)
//
// The frontend enforces these maxima on input (14.5) and displays the current
// character count alongside the maximum (14.6). Server-side validation remains
// the source of truth; these constants only improve the client experience.

export const MAX_SEARCH_QUERY_LENGTH = 200
export const MAX_QUOTE_TEXT_LENGTH = 5000
export const MAX_SOURCE_NAME_LENGTH = 200
export const MAX_SOURCE_OPTIONAL_FIELD_LENGTH = 500
export const MAX_LINK_URL_LENGTH = 2000
export const MAX_LANGUAGE_NAME_LENGTH = 100

/** All field length limits keyed by a stable identifier. */
export const FIELD_LIMITS = {
  searchQuery: MAX_SEARCH_QUERY_LENGTH,
  quoteText: MAX_QUOTE_TEXT_LENGTH,
  sourceName: MAX_SOURCE_NAME_LENGTH,
  sourceOptionalField: MAX_SOURCE_OPTIONAL_FIELD_LENGTH,
  linkUrl: MAX_LINK_URL_LENGTH,
  languageName: MAX_LANGUAGE_NAME_LENGTH,
} as const

export type FieldLimitKey = keyof typeof FIELD_LIMITS
