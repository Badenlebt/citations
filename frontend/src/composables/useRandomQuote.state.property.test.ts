import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import fc from 'fast-check'
import { toRaw } from 'vue'
import { ApiError, type QuoteDetail } from '@/api'
import { useRandomQuote, type RandomQuoteStatus } from './useRandomQuote'

// Feature: random-quote-homepage, Property 8: Zustandsautomat der Startseite
// **Validates: Requirements 2.3, 2.5, 3.3, 3.4, 4.4, 4.5, 4.6**

type EventKind = 'reload' | 'resolve' | 'noQuotes' | 'otherError' | 'timeout'

const TIMEOUT_MS = 10_000

function makeQuote(id: number): QuoteDetail {
  return {
    id,
    text: `Zitat ${id}`,
    languageId: 1,
    languageName: 'Deutsch',
    source: { id: 1, name: 'Quelle', linkUrl: null },
  } as unknown as QuoteDetail
}

interface Model {
  status: RandomQuoteStatus
  quote: QuoteDetail | null
  errorVisible: boolean
  pending: boolean
}

describe('useRandomQuote state machine (property)', () => {
  beforeEach(() => {
    vi.useFakeTimers()
  })
  afterEach(() => {
    vi.useRealTimers()
  })

  it('Property 8: Zustandsautomat der Startseite', async () => {
    const eventArb = fc.constantFrom<EventKind>('reload', 'resolve', 'noQuotes', 'otherError', 'timeout')

    await fc.assert(
      fc.asyncProperty(fc.array(eventArb, { minLength: 1, maxLength: 30 }), async (events) => {
        // Controllable client double: each call yields a promise we settle by hand.
        const settlers: Array<{
          resolve: (q: QuoteDetail) => void
          reject: (e: unknown) => void
        }> = []
        let calls = 0
        let running = 0
        let maxRunning = 0
        const client = {
          random: () => {
            calls++
            running++
            maxRunning = Math.max(maxRunning, running)
            return new Promise<QuoteDetail>((resolve, reject) => {
              settlers.push({
                resolve: (q) => {
                  running--
                  resolve(q)
                },
                reject: (e) => {
                  running--
                  reject(e)
                },
              })
            })
          },
        }
        const rq = useRandomQuote({ client, locale: () => 'de', timeoutMs: TIMEOUT_MS })

        const model: Model = { status: 'idle', quote: null, errorVisible: false, pending: false }
        let expectedCalls = 0
        let nextId = 1
        let hadQuoteEver = false

        const settle = async () => {
          await vi.advanceTimersByTimeAsync(0)
        }
        const failModel = () => {
          if (model.quote === null) model.status = 'error'
          model.errorVisible = true
          model.pending = false
        }

        for (const ev of events) {
          const wasPending = model.pending
          const before = { quote: model.quote, errorVisible: model.errorVisible }

          if (ev === 'reload') {
            const p = rq.reload()
            if (!model.pending) {
              expectedCalls++
              model.pending = true
              if (model.status === 'idle') model.status = 'loading'
            }
            await settle()
            void p
          } else if (!model.pending) {
            // Settling events without a running request are no-ops.
            await settle()
          } else {
            const s = settlers[settlers.length - 1]
            if (ev === 'resolve') {
              const q = makeQuote(nextId++)
              s.resolve(q)
              model.quote = q
              model.status = 'ready'
              model.errorVisible = false
              model.pending = false
              hadQuoteEver = true
            } else if (ev === 'noQuotes') {
              s.reject(new ApiError(404, 'NO_QUOTES', 'none'))
              model.quote = null
              model.status = 'empty'
              model.errorVisible = false
              model.pending = false
            } else if (ev === 'otherError') {
              s.reject(new ApiError(500, 'INTERNAL_ERROR', 'boom'))
              failModel()
            } else {
              // timeout: the request never settles on its own
              await vi.advanceTimersByTimeAsync(TIMEOUT_MS)
              running--
              failModel()
            }
            await settle()
          }

          // (a) at most one request at a time; reload while pending starts none
          expect(maxRunning).toBeLessThanOrEqual(1)
          expect(calls).toBe(expectedCalls)
          expect(rq.pending.value).toBe(model.pending)

          // Model conformance
          expect(rq.status.value).toBe(model.status)
          expect(rq.errorVisible.value).toBe(model.errorVisible)
          expect(rq.quote.value).toEqual(model.quote)

          // (b) first request: loading visible, no quote, no error
          if (!hadQuoteEver && model.status === 'loading') {
            expect(rq.initialLoading.value).toBe(true)
            expect(rq.quote.value).toBeNull()
            expect(rq.errorVisible.value).toBe(false)
          }
          // (c) no loading once a result/error arrived
          if (!model.pending) expect(rq.initialLoading.value).toBe(false)
          if (model.status !== 'loading') expect(rq.initialLoading.value).toBe(false)

          // (d) failed reload keeps the shown quote unchanged and shows the error
          if ((ev === 'otherError' || ev === 'timeout') && wasPending && before.quote !== null) {
            expect(toRaw(rq.quote.value)).toStrictEqual(before.quote)
            expect(rq.errorVisible.value).toBe(true)
          }

          // (e) retry after error: message stays while pending, reload is blocked
          if (ev === 'reload' && !wasPending && before.errorVisible && model.pending) {
            expect(rq.errorVisible.value).toBe(true)
            expect(rq.pending.value).toBe(true)
          }
          if (ev === 'resolve' && wasPending) expect(rq.errorVisible.value).toBe(false)

          // (f) never quote and empty state together
          expect(rq.quote.value !== null && rq.status.value === 'empty').toBe(false)
        }
        rq.dispose()
      }),
      { numRuns: 150 },
    )
  })
})
