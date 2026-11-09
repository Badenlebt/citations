import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { api } from './client'
import { ApiError } from './ApiError'

// Helper to build a fetch Response-like stub.
function jsonResponse(status: number, body: unknown): Response {
  return {
    ok: status >= 200 && status < 300,
    status,
    text: () => Promise.resolve(body === undefined ? '' : JSON.stringify(body)),
  } as unknown as Response
}

describe('api client', () => {
  const fetchMock = vi.fn()

  beforeEach(() => {
    vi.stubGlobal('fetch', fetchMock)
    fetchMock.mockReset()
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('performs a GET search with query params and returns the parsed body', async () => {
    const payload = { items: [], total: 0, page: 1, pageSize: 20 }
    fetchMock.mockResolvedValue(jsonResponse(200, payload))

    const result = await api.search({ q: 'kant', languages: [1, 2], page: 2, sourceId: 7 })

    expect(result).toEqual(payload)
    const [url, init] = fetchMock.mock.calls[0]
    expect(url).toContain('/api/search?')
    expect(url).toContain('q=kant')
    expect(url).toContain('languages=1%2C2')
    expect(url).toContain('page=2')
    expect(url).toContain('sourceId=7')
    expect(init.method).toBe('GET')
  })

  it('omits undefined query params', async () => {
    fetchMock.mockResolvedValue(jsonResponse(200, { items: [], total: 0, page: 1, pageSize: 20 }))

    await api.search({ q: 'x' })

    const [url] = fetchMock.mock.calls[0]
    expect(url).not.toContain('languages=')
    expect(url).not.toContain('sourceId=')
    expect(url).not.toContain('page=')
  })

  it('maps a 400 { error, message } body to a known ApiError code', async () => {
    fetchMock.mockResolvedValue(
      jsonResponse(400, { error: 'MISSING_QUERY', message: 'Suche fehlt' }),
    )

    await expect(api.search({ q: '' })).rejects.toMatchObject({
      status: 400,
      code: 'MISSING_QUERY',
      message: 'Suche fehlt',
    })
  })

  it('flags UNAUTHORIZED errors via isUnauthorized', async () => {
    fetchMock.mockResolvedValue(
      jsonResponse(401, { error: 'UNAUTHORIZED', message: 'Nicht autorisiert' }),
    )

    const error = await api
      .createQuote({ text: 'x', languageId: 1 })
      .catch((e: unknown) => e)

    expect(error).toBeInstanceOf(ApiError)
    expect((error as ApiError).isUnauthorized).toBe(true)
    expect((error as ApiError).code).toBe('UNAUTHORIZED')
  })

  it('keeps an unknown error code as rawCode with a null typed code', async () => {
    fetchMock.mockResolvedValue(jsonResponse(500, { error: 'MYSTERY', message: 'boom' }))

    const error = (await api.getLanguages().catch((e: unknown) => e)) as ApiError

    expect(error.code).toBeNull()
    expect(error.rawCode).toBe('MYSTERY')
    expect(error.status).toBe(500)
  })

  it('tolerates a malformed error body', async () => {
    fetchMock.mockResolvedValue(jsonResponse(500, undefined))

    const error = (await api.getLanguages().catch((e: unknown) => e)) as ApiError

    expect(error.code).toBeNull()
    expect(error.status).toBe(500)
    expect(error.message).toContain('500')
  })

  it('surfaces network failures as an ApiError with status 0', async () => {
    fetchMock.mockRejectedValue(new TypeError('Failed to fetch'))

    const error = (await api.random().catch((e: unknown) => e)) as ApiError

    expect(error).toBeInstanceOf(ApiError)
    expect(error.status).toBe(0)
    expect(error.code).toBeNull()
  })

  it('serialises a JSON body and sets the content-type for writes', async () => {
    fetchMock.mockResolvedValue(jsonResponse(201, { id: 42 }))

    const result = await api.createSource({ name: 'Kant' })

    expect(result).toEqual({ id: 42 })
    const [url, init] = fetchMock.mock.calls[0]
    expect(url).toBe('/api/sources')
    expect(init.method).toBe('POST')
    expect(init.headers['Content-Type']).toBe('application/json')
    expect(JSON.parse(init.body)).toEqual({ name: 'Kant' })
    expect(init.credentials).toBe('same-origin')
  })

  it('passes the source delete strategy as a query param', async () => {
    fetchMock.mockResolvedValue(jsonResponse(204, undefined))

    await api.deleteSource(3, 'reassign')

    const [url, init] = fetchMock.mock.calls[0]
    expect(url).toBe('/api/sources/3?strategy=reassign')
    expect(init.method).toBe('DELETE')
  })
})
