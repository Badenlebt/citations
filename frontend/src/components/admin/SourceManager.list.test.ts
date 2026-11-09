import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import type { App } from 'vue'
import SourceManager from './SourceManager.vue'
import { createI18n, installI18n } from '@/i18n'

// Pin the UI locale to German for deterministic labels.
const i18nPlugin = {
  install(app: App) {
    installI18n(app, createI18n('de'))
  },
}

// `@/api` is mocked because mounting SourceManager must not hit the network.
// The list itself is driven purely by the `sources` prop, so only the methods
// potentially touched by interaction are stubbed.
vi.mock('@/api', async (importActual) => {
  const actual = await importActual<typeof import('@/api')>()
  return {
    ...actual,
    api: {
      getSource: vi.fn(),
      deleteSource: vi.fn(),
      createSource: vi.fn(),
      updateSource: vi.fn(),
    },
  }
})

// Focused render test for the admin source list (Req 19.1): the manager renders
// one row per entry supplied via its `sources` prop, including the reserved
// Unknown source (isUnknown).

describe('SourceManager source list rendering (Req 19.1)', () => {
  afterEach(() => {
    vi.clearAllMocks()
  })

  it('renders one row per source, including the Unknown source', () => {
    const sources = [
      { id: 1, name: 'unbekannt', isUnknown: true },
      { id: 2, name: 'Immanuel Kant' },
      { id: 3, name: 'Jean-Paul Sartre' },
    ]

    const wrapper = mount(SourceManager, {
      props: { sources },
      global: { plugins: [i18nPlugin] },
    })

    const rows = wrapper.findAll('.source-manager__item')
    expect(rows).toHaveLength(3)

    const names = wrapper
      .findAll('.source-manager__item-name')
      .map((n) => n.text())
    expect(names).toEqual(['unbekannt', 'Immanuel Kant', 'Jean-Paul Sartre'])
  })

  it('shows the empty-state message when the sources list is empty', () => {
    const wrapper = mount(SourceManager, {
      props: { sources: [] },
      global: { plugins: [i18nPlugin] },
    })

    expect(wrapper.findAll('.source-manager__item')).toHaveLength(0)
    expect(wrapper.find('.source-manager__empty').text()).toBe('Noch keine Quellen vorhanden.')
  })
})
