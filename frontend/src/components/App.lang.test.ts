import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount, type VueWrapper } from '@vue/test-utils'
import { createRouter, createMemoryHistory, type Router } from 'vue-router'
import fc from 'fast-check'
import App from '@/App.vue'
import { routes } from '@/router'
import { createI18n, installI18n, SUPPORTED_LOCALES, type Locale } from '@/i18n'

// In-memory localStorage stub so setLocale's persistence never throws in jsdom.
function stubStorage(): void {
  const store = new Map<string, string>()
  vi.stubGlobal('localStorage', {
    getItem: (key: string) => store.get(key) ?? null,
    setItem: (key: string, value: string) => {
      store.set(key, value)
    },
    removeItem: (key: string) => {
      store.delete(key)
    },
    clear: () => {
      store.clear()
    },
  })
}

function makeRouter(): Router {
  return createRouter({ history: createMemoryHistory(), routes })
}

describe('App lang attribute tracks the active locale (Property 13)', () => {
  beforeEach(() => {
    stubStorage()
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    document.documentElement.removeAttribute('lang')
  })

  // Feature: ui-redesign, Property 13: Lang-Attribut folgt der aktiven Locale
  // Fuer jede Folge von Locale-Wechseln ueber die verfuegbaren Locales ('de'/'en')
  // gilt: nach jedem Wechsel entspricht document.documentElement.lang exakt der
  // zuletzt gesetzten Locale (Req 9.5). App.vue traegt den lang/locale-Watcher.
  it('updates document lang to the most recently set locale after each switch', async () => {
    await fc.assert(
      fc.asyncProperty(
        fc.array(fc.constantFrom<Locale>(...SUPPORTED_LOCALES), {
          minLength: 1,
          maxLength: 8,
        }),
        async (localeSequence) => {
          const router = makeRouter()
          await router.push('/')
          await router.isReady()

          const i18n = createI18n('de')
          const wrapper: VueWrapper = mount(App, {
            global: {
              plugins: [router, (app) => installI18n(app, i18n)],
            },
          })
          // The watcher runs immediately, so lang starts at the initial locale.
          await wrapper.vm.$nextTick()

          try {
            for (const locale of localeSequence) {
              i18n.setLocale(locale)
              await wrapper.vm.$nextTick()
              expect(document.documentElement.getAttribute('lang')).toBe(locale)
            }
          } finally {
            wrapper.unmount()
            document.documentElement.removeAttribute('lang')
          }
        },
      ),
      { numRuns: 100 },
    )
  })
})
