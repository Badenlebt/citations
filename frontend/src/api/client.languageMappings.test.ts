import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { api } from './client'

function jsonResponse(status: number, body: unknown): Response {
  return {
    ok: status >= 200 && status < 300,
    status,
    text: () => Promise.resolve(body === undefined ? '' : JSON.stringify(body)),
  } as unknown as Response
}

describe('api client: random quote and language mappings', () => {
  const fetchMock = vi.fn()

  beforeEach(() => {
    vi.stubGlobal('fetch', fetchMock)
    fetchMock.mockReset()
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('random() sends uiLanguage in the query when set (Req 6.11)', async () => {
    fetchMock.mockResolvedValue(jsonResponse(200, { id: 1 }))
    await api.random(undefined, 'en')
    const [url, init] = fetchMock.mock.calls[0]
    expect(url).toContain('/api/random?')
    expect(url).toContain('uiLanguage=en')
    expect(init.method).toBe('GET')
  })

  it('random() omits uiLanguage when not set (backward compatible)', async () => {
    fetchMock.mockResolvedValue(jsonResponse(200, { id: 1 }))
    await api.random()
    const [url] = fetchMock.mock.calls[0]
    expect(url).toContain('/api/random')
    expect(url).not.toContain('uiLanguage')
  })

  it('getLanguageMappings() performs GET /language-mappings (Req 9.10)', async () => {
    const payload = { de: 1, en: null }
    fetchMock.mockResolvedValue(jsonResponse(200, payload))
    const result = await api.getLanguageMappings()
    expect(result).toEqual(payload)
    const [url, init] = fetchMock.mock.calls[0]
    expect(url).toContain('/api/language-mappings')
    expect(init.method).toBe('GET')
  })

  it('setLanguageMapping() performs PUT with { languageId } body (Req 9.10)', async () => {
    const payload = { uiLanguage: 'de', languageId: 5 }
    fetchMock.mockResolvedValue(jsonResponse(200, payload))
    const result = await api.setLanguageMapping('de', 5)
    expect(result).toEqual(payload)
    const [url, init] = fetchMock.mock.calls[0]
    expect(url).toContain('/api/language-mappings/de')
    expect(init.method).toBe('PUT')
    expect(JSON.parse(init.body)).toEqual({ languageId: 5 })
  })

  it('removeLanguageMapping() performs DELETE (Req 9.10)', async () => {
    const payload = { uiLanguage: 'en', languageId: null }
    fetchMock.mockResolvedValue(jsonResponse(200, payload))
    const result = await api.removeLanguageMapping('en')
    expect(result).toEqual(payload)
    const [url, init] = fetchMock.mock.calls[0]
    expect(url).toContain('/api/language-mappings/en')
    expect(init.method).toBe('DELETE')
  })
})
