import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import LanguageSwitcher from './LanguageSwitcher.vue'
import {
  createI18n,
  installI18n,
  LOCALE_STORAGE_KEY,
  type I18n,
} from '@/i18n'

// Behavioural guard for Req 8.4: after the token-based restyling of the
// LanguageSwitcher (Task 4.6), the *observable* behaviour of switching the UI
// language must be identical to before the redesign. The existing
// LanguageSwitcher.test.ts already covers label/option rendering and single
// selection; this file deliberately avoids duplicating those and instead
// pins down the observable effects the restyling must not alter:
//   - picking an option persists the choice in localStorage under the
//     stable key, and
//   - driving the <select> across several switches keeps the active locale,
//     the visible selected option and the persisted value in lockstep.

// In-memory localStorage stub, mirroring the existing test setup so that
// setLocale's persistence is observable and never throws during the run.
function stubStorage(): Map<string, string> {
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
  return store
}

// Mounts with an isolated i18n instance via the plugin, matching the existing
// mount/i18n pattern so each test controls the starting locale.
function mountWith(i18n: I18n) {
  return mount(LanguageSwitcher, {
    global: {
      plugins: [(app) => installI18n(app, i18n)],
    },
  })
}

// Clicks the locale toggle whose short label matches (e.g. 'DE', 'EN'). The
// switcher now renders segmented toggle buttons instead of a <select>; the
// observable switching behaviour is otherwise identical.
async function pickLocale(
  wrapper: ReturnType<typeof mountWith>,
  short: string,
): Promise<void> {
  const button = wrapper
    .findAll('.language-switcher__option')
    .find((b) => b.text() === short)
  if (!button) {
    throw new Error(`no locale toggle labelled ${short}`)
  }
  await button.trigger('click')
}

describe('LanguageSwitcher behaviour unchanged after restyling (Req 8.4)', () => {
  let store: Map<string, string>

  beforeEach(() => {
    store = stubStorage()
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('persists the chosen locale to localStorage when the user picks an option', async () => {
    const i18n = createI18n('de')
    const wrapper = mountWith(i18n)

    await pickLocale(wrapper, 'EN')

    // The observable persistence contract: the choice is written under the
    // stable storage key so it survives across visits.
    expect(store.get(LOCALE_STORAGE_KEY)).toBe('en')
    expect(i18n.locale.value).toBe('en')
  })

  it('keeps active locale, visible selection and persisted value in lockstep across repeated switches', async () => {
    const i18n = createI18n('de')
    const wrapper = mountWith(i18n)
    // The active toggle (aria-pressed="true") reflects the visible selection.
    const activeLocaleShort = () =>
      wrapper
        .findAll('.language-switcher__option')
        .find((b) => b.attributes('aria-pressed') === 'true')
        ?.text()

    // Initial state reflects the starting locale, nothing persisted yet.
    expect(activeLocaleShort()).toBe('DE')
    expect(store.get(LOCALE_STORAGE_KEY)).toBeUndefined()

    // Walk a sequence of user-driven switches and assert the three observable
    // facets stay consistent after each step.
    for (const next of ['en', 'de', 'en'] as const) {
      await pickLocale(wrapper, next.toUpperCase())

      expect(i18n.locale.value).toBe(next)
      expect(activeLocaleShort()).toBe(next.toUpperCase())
      expect(store.get(LOCALE_STORAGE_KEY)).toBe(next)
    }
  })
})
