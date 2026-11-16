// Feature: random-quote-homepage, Property 9: Das angezeigte Zitat stammt aus der letzten Antwort
// **Validates: Requirements 1.7**
import { describe, it, expect } from 'vitest'
import fc from 'fast-check'
import type { QuoteDetail } from '@/api'
import { useRandomQuote } from './useRandomQuote'

const quoteArb: fc.Arbitrary<QuoteDetail> = fc.record({
  id: fc.integer({ min: 1, max: 1_000_000 }),
  text: fc.string({ minLength: 1, maxLength: 200 }),
  languageId: fc.integer({ min: 1, max: 1000 }),
  languageName: fc.string({ minLength: 1, maxLength: 30 }),
  source: fc.record({
    id: fc.integer({ min: 1, max: 1_000_000 }),
    name: fc.string({ minLength: 1, maxLength: 50 }),
    linkUrl: fc.option(fc.webUrl(), { nil: null }),
  }),
})

describe('useRandomQuote latest response (Property 9)', () => {
  it('shows exactly the quote of the latest successful response after each reload', async () => {
    await fc.assert(
      fc.asyncProperty(fc.array(quoteArb, { minLength: 1, maxLength: 10 }), async (responses) => {
        let call = 0
        const client = {
          random: async () => responses[call++]!,
        }
        const rq = useRandomQuote({ client: client as never, locale: () => 'de' })
        for (const expected of responses) {
          await rq.reload()
          expect(rq.quote.value).toEqual(expected)
          expect(rq.status.value).toBe('ready')
          expect(rq.errorVisible.value).toBe(false)
        }
        expect(call).toBe(responses.length)
        rq.dispose()
      }),
      { numRuns: 100 },
    )
  })
})
