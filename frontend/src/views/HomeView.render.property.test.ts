import { afterEach, describe, expect, it, vi } from 'vitest'
import fc from 'fast-check'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'

vi.mock('@/api', async () => {
  const actual = await vi.importActual<typeof import('@/api')>('@/api')
  return { ...actual, api: { random: vi.fn() } }
})

import { api } from '@/api'
import type { QuoteDetail } from '@/api'
import { routes } from '@/router'
import HomeView from './HomeView.vue'

// Feature: random-quote-homepage, Property 10: Vollständige Darstellung und Verknüpfungen eines Zitats
// **Validates: Requirements 1.4, 1.5, 1.6**

// Beliebiger Text inkl. HTML-Sonderzeichen und langer Texte.
const textArb = fc.oneof(
  fc.string({ minLength: 1, maxLength: 200 }),
  fc.string({ minLength: 1000, maxLength: 3000 }),
  fc.constantFrom(
    '<script>alert(1)</script>',
    '<b>fett</b> & "quotes" \'x\'',
    '<img src=x onerror=alert(1)>',
    '&amp; &lt;div&gt;',
  ),
)

// Namen: nicht nur Whitespace, damit die Textprüfung nach Trim eindeutig ist.
const nameArb = textArb.filter((s) => s.trim().length > 0)

const quoteArb: fc.Arbitrary<QuoteDetail> = fc.record({
  id: fc.integer({ min: 1, max: 2_000_000_000 }),
  text: nameArb,
  languageId: fc.integer({ min: 1, max: 1_000_000 }),
  languageName: nameArb,
  source: fc.record({
    id: fc.integer({ min: 1, max: 2_000_000_000 }),
    name: nameArb,
    linkUrl: fc.constant(null),
  }),
})

const norm = (s: string) => s.replace(/\s+/g, ' ').trim()

const randomMock = vi.mocked(api.random)

describe('HomeView Property 10: Vollständige Darstellung und Verknüpfungen', () => {
  afterEach(() => {
    vi.clearAllMocks()
  })

  it('zeigt Text, Quellenname, Sprachname als Text und verknüpft Quelle und Zitat', async () => {
    await fc.assert(
      fc.asyncProperty(quoteArb, async (quote) => {
        randomMock.mockReset()
        randomMock.mockResolvedValue(quote)

        const router = createRouter({ history: createMemoryHistory(), routes })
        await router.push('/')
        await router.isReady()
        const wrapper = mount(HomeView, { global: { plugins: [router] } })
        await flushPromises()

        const block = wrapper.find('blockquote')
        expect(block.exists()).toBe(true)
        // Als Text, nicht als HTML interpretiert (Req 1.4).
        expect(norm(block.text())).toBe(norm(quote.text))
        expect(block.element.children.length).toBe(0)

        const caption = wrapper.find('figcaption')
        expect(caption.element.querySelectorAll('script, img, b').length).toBe(0)

        // Sprachname (Req 1.5).
        expect(norm(wrapper.find('.home-view__language').text())).toBe(norm(quote.languageName))

        // Quellenname verknüpft mit /sources/{id} (Req 1.5).
        const links = wrapper.findAll('figcaption a')
        const sourceLink = links.find((a) => a.attributes('href') === `/sources/${quote.source.id}`)
        expect(sourceLink).toBeDefined()
        expect(norm(sourceLink!.text())).toBe(norm(quote.source.name))

        // Verknüpfung auf das Einzelzitat (Req 1.6).
        expect(links.some((a) => a.attributes('href') === `/quotes/${quote.id}`)).toBe(true)

        wrapper.unmount()
      }),
      { numRuns: 100 },
    )
  })
})
