import { describe, it, expect } from 'vitest'
import fc from 'fast-check'
import type { QuoteDetail } from '@/api'
import type { Locale } from '@/i18n'
import { useRandomQuote } from './useRandomQuote'

// Feature: random-quote-homepage, Property 13: Die Anfrage trägt die aktuelle Oberflächensprache
// **Validates: Requirements 6.11, 6.12**

type Step = { kind: 'switch'; locale: Locale } | { kind: 'reload' }

const stepArb: fc.Arbitrary<Step> = fc.oneof(
  fc.constantFrom<Locale>('de', 'en').map((locale): Step => ({ kind: 'switch', locale })),
  fc.constant<Step>({ kind: 'reload' }),
)

describe('useRandomQuote Property 13: Anfrage trägt aktuelle Oberflächensprache', () => {
  it('jeder random()-Aufruf trägt die aktuelle Sprache; Sprachwechsel allein löst keinen Aufruf aus', async () => {
    await fc.assert(
      fc.asyncProperty(
        fc.constantFrom<Locale>('de', 'en'),
        fc.array(stepArb, { maxLength: 30 }),
        async (initial, steps) => {
          let current: Locale = initial
          const calls: Array<Locale | undefined> = []
          const client = {
            random: async (_signal?: AbortSignal, uiLanguage?: Locale) => {
              calls.push(uiLanguage)
              return { id: 1 } as unknown as QuoteDetail
            },
          }
          const rq = useRandomQuote({ client: client as never, locale: () => current })

          const expected: Locale[] = []
          for (const step of steps) {
            if (step.kind === 'switch') {
              current = step.locale
              expect(calls).toEqual(expected) // Sprachwechsel allein: kein Aufruf
            } else {
              expected.push(current)
              await rq.reload()
              expect(calls).toEqual(expected)
            }
          }
          rq.dispose()
        },
      ),
      { numRuns: 100 },
    )
  })
})
