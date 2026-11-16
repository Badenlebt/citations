import { afterEach, describe, expect, it, vi } from 'vitest'
import fc from 'fast-check'
import { flushPromises, mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import { createMemoryHistory, createRouter } from 'vue-router'

vi.mock('@/api', async () => {
  const actual = await vi.importActual<typeof import('@/api')>('@/api')
  return { ...actual, api: { random: vi.fn() } }
})

import { api, ApiError, type QuoteDetail } from '@/api'
import { createI18n, installI18n, messages, SUPPORTED_LOCALES, type Locale } from '@/i18n'
import { routes } from '@/router'
import HomeView from './HomeView.vue'

// Feature: random-quote-homepage, Property 12: Der Sprachwechsel verändert den Zustand der Startseite nicht
// **Validates: Requirements 6.2, 6.3, 6.4**

type StateKind = 'loading' | 'quote' | 'empty' | 'error'

// Ohne führende/mehrfache Leerzeichen, damit text() dem Rohwert entspricht.
const wordsArb = fc.stringMatching(/^[A-Za-z0-9ÄÖÜäöüß.,!?-]{1,15}( [A-Za-z0-9]{1,10}){0,3}$/)

const quoteArb: fc.Arbitrary<QuoteDetail> = fc.record({
  id: fc.integer({ min: 1, max: 10_000 }),
  text: wordsArb,
  languageId: fc.integer({ min: 1, max: 100 }),
  languageName: wordsArb,
  source: fc.record({
    id: fc.integer({ min: 1, max: 10_000 }),
    name: wordsArb,
    linkUrl: fc.constant(null),
  }),
})

const stateArb = fc.constantFrom<StateKind>('loading', 'quote', 'empty', 'error')
const localeArb = fc.constantFrom<Locale>(...SUPPORTED_LOCALES)

const randomMock = vi.mocked(api.random)

describe('HomeView Property 12: Sprachwechsel verändert den Zustand nicht', () => {
  afterEach(() => {
    vi.clearAllMocks()
  })

  it('Zustand, Zitatdaten und Anfragezahl bleiben erhalten, statische Texte folgen der Sprache', async () => {
    await fc.assert(
      fc.asyncProperty(
        stateArb,
        quoteArb,
        localeArb,
        fc.array(localeArb, { minLength: 1, maxLength: 4 }),
        async (state, quote, startLocale, switches) => {
          randomMock.mockReset()
          if (state === 'loading') randomMock.mockImplementation(() => new Promise(() => undefined))
          else if (state === 'quote') randomMock.mockResolvedValue(quote)
          else if (state === 'empty') randomMock.mockRejectedValue(new ApiError(404, 'NO_QUOTES', 'x'))
          else randomMock.mockRejectedValue(new ApiError(500, 'INTERNAL_ERROR', 'x'))

          const i18n = createI18n(startLocale)
          const router = createRouter({ history: createMemoryHistory(), routes })
          await router.push('/')
          await router.isReady()
          const wrapper = mount(HomeView, {
            global: { plugins: [router, { install: (app) => installI18n(app, i18n) }] },
          })
          await flushPromises()

          const snapshot = () => ({
            alerts: wrapper.findAll('[role="alert"]').length,
            statuses: wrapper.findAll('.home-view__status').length,
            figure: wrapper.find('figure').exists(),
            reload: wrapper.find('button.home-view__reload').exists(),
            text: wrapper.find('blockquote').exists() ? wrapper.find('blockquote').text() : null,
            source: wrapper.find('.home-view__link').exists() && wrapper.find('figure').exists()
              ? wrapper.find('figure .home-view__link').text()
              : null,
            language: wrapper.find('.home-view__language').exists()
              ? wrapper.find('.home-view__language').text()
              : null,
          })

          const before = snapshot()
          const calls = randomMock.mock.calls.length
          expect(calls).toBe(1)
          if (state === 'quote') {
            expect(before.text).toBe(quote.text)
            expect(before.source).toBe(quote.source.name)
            expect(before.language).toBe(quote.languageName)
          }

          for (const next of switches) {
            i18n.setLocale(next)
            await nextTick()
            await flushPromises()
            expect(wrapper.find('h1').text()).toBe(messages[next].home.heading)
            expect(snapshot()).toEqual(before)
            expect(randomMock.mock.calls.length).toBe(calls)
          }

          wrapper.unmount()
        },
      ),
      { numRuns: 100 },
    )
  })
})
