import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import { mount } from '@vue/test-utils'
import LanguageSwitcher from './LanguageSwitcher.vue'
import { createI18n, installI18n, useI18n, type I18n } from '@/i18n'

// In-memory localStorage stub: setLocale persists through storage, which must
// not throw during the test run.
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

// Mounts a component with an isolated i18n instance provided via the plugin,
// so each test controls the starting locale and shares one reactive instance.
function mountWith(component: Parameters<typeof mount>[0], i18n: I18n) {
  return mount(component, {
    global: {
      plugins: [(app) => installI18n(app, i18n)],
    },
  })
}

describe('LanguageSwitcher', () => {
  beforeEach(() => {
    stubStorage()
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  // Language switch renders the chosen language (Req 14.2).
  it('renders the switcher label and options in the active locale', () => {
    const i18n = createI18n('de')
    const wrapper = mountWith(LanguageSwitcher, i18n)

    expect(wrapper.text()).toContain('Sprache der Oberfläche')
    const optionTexts = wrapper.findAll('option').map((o) => o.text())
    expect(optionTexts).toEqual(['Deutsch', 'Englisch'])
  })

  it('re-renders texts in the chosen catalog after setLocale', async () => {
    const i18n = createI18n('de')
    const wrapper = mountWith(LanguageSwitcher, i18n)

    i18n.setLocale('en')
    await wrapper.vm.$nextTick()

    expect(wrapper.text()).toContain('Interface language')
    expect(wrapper.findAll('option').map((o) => o.text())).toEqual([
      'German',
      'English',
    ])

    i18n.setLocale('de')
    await wrapper.vm.$nextTick()
    expect(wrapper.text()).toContain('Sprache der Oberfläche')
  })

  it('updates the selected option to reflect the active locale', async () => {
    const i18n = createI18n('de')
    const wrapper = mountWith(LanguageSwitcher, i18n)
    const select = wrapper.get('select')

    expect((select.element as HTMLSelectElement).value).toBe('de')

    i18n.setLocale('en')
    await wrapper.vm.$nextTick()
    expect((select.element as HTMLSelectElement).value).toBe('en')
  })

  it('switches the locale when the user picks an option', async () => {
    const i18n = createI18n('de')
    const wrapper = mountWith(LanguageSwitcher, i18n)

    await wrapper.get('select').setValue('en')

    expect(i18n.locale.value).toBe('en')
  })
})

// UI/quote-language decoupling (Req 14.4). A quote carries its own `language`
// data attribute that must stay fixed no matter which UI locale is active.
const QuoteCard = defineComponent({
  name: 'QuoteCard',
  props: {
    text: { type: String, required: true },
    language: { type: String, required: true },
  },
  setup(props) {
    const i18n = useI18n()
    // The label ("Sprache"/"Language") is UI chrome; the value is quote data.
    return () =>
      h('article', [
        h('p', { class: 'quote-text' }, props.text),
        h('span', { class: 'quote-language-label' }, i18n.t('quote.language')),
        h('span', { class: 'quote-language-value' }, props.language),
      ])
  },
})

describe('UI/quote language decoupling', () => {
  beforeEach(() => {
    stubStorage()
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('keeps a quote language value unchanged while the UI locale switches', async () => {
    const i18n = createI18n('de')
    const wrapper = mount(QuoteCard, {
      props: { text: 'Je pense, donc je suis.', language: 'Französisch' },
      global: { plugins: [(app) => installI18n(app, i18n)] },
    })

    const quoteLanguage = () => wrapper.get('.quote-language-value').text()
    const uiLabel = () => wrapper.get('.quote-language-label').text()

    // German UI: label is German, quote language is its own data value.
    expect(uiLabel()).toBe('Sprache')
    expect(quoteLanguage()).toBe('Französisch')

    i18n.setLocale('en')
    await wrapper.vm.$nextTick()

    // The UI label switched, but the quote's language data did not.
    expect(uiLabel()).toBe('Language')
    expect(quoteLanguage()).toBe('Französisch')
  })
})
