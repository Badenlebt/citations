import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import fc from 'fast-check'
import type { App } from 'vue'
import LanguageMappingManager from './LanguageMappingManager.vue'
import { createI18n, installI18n } from '@/i18n'

const i18nPlugin = {
  install(app: App) {
    installI18n(app, createI18n('de'))
  },
}

vi.mock('@/api', async (importActual) => {
  const actual = await importActual<typeof import('@/api')>()
  return {
    ...actual,
    api: {
      createLanguage: vi.fn(),
      setLanguageMapping: vi.fn(),
      removeLanguageMapping: vi.fn(),
    },
  }
})

// Feature: random-quote-homepage, Property 16: Auswahl der Sprachverwaltung
// **Validates: Requirements 9.9**
describe('LanguageMappingManager mapping selects (Property 16)', () => {
  it('offers all languages plus "keine Zuordnung" and preselects the saved mapping', () => {
    const scenario = fc
      .uniqueArray(fc.integer({ min: 1, max: 10000 }), { maxLength: 8 })
      .chain((ids) => {
        const choice = fc.constantFrom<number | null>(null, ...ids)
        return fc.record({
          ids: fc.constant(ids),
          names: fc.array(fc.string({ minLength: 1, maxLength: 20 }), {
            minLength: ids.length,
            maxLength: ids.length,
          }),
          de: choice,
          en: choice,
        })
      })
    fc.assert(
      fc.property(scenario, ({ ids, names, de, en }) => {
        const languages = ids.map((id, i) => ({ id, name: names[i] }))
        const wrapper = mount(LanguageMappingManager, {
          props: { languages, mappings: { de, en } },
          global: { plugins: [i18nPlugin] },
        })
        for (const [locale, saved] of [
          ['de', de],
          ['en', en],
        ] as const) {
          const select = wrapper.find(`select[data-testid="mapping-${locale}"]`)
          expect(select.exists()).toBe(true)
          const options = select.findAll('option')
          expect(options.map((o) => (o.element as HTMLOptionElement).value)).toEqual([
            '',
            ...ids.map(String),
          ])
          expect((select.element as HTMLSelectElement).value).toBe(
            saved === null ? '' : String(saved),
          )
        }
        wrapper.unmount()
      }),
      { numRuns: 100 },
    )
  })
})
