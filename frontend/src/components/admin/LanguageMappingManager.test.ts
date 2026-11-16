import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import type { App } from 'vue'
import type { Language } from '@/api'
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

// eslint-disable-next-line @typescript-eslint/no-explicit-any
const mockedApi = (await import('@/api')).api as any
const { ApiError } = await import('@/api')

const languages: Language[] = [
  { id: 1, name: 'Deutsch' },
  { id: 2, name: 'English' },
]

function mountManager(mappings = { de: 1, en: null as number | null }) {
  return mount(LanguageMappingManager, {
    props: { languages, mappings },
    global: { plugins: [i18nPlugin] },
  })
}

describe('LanguageMappingManager mapping (Req 9.10, 9.11)', () => {
  beforeEach(() => {
    mockedApi.setLanguageMapping.mockReset()
    mockedApi.removeLanguageMapping.mockReset()
  })

  it('saves a mapping, confirms with role=status and emits changed', async () => {
    mockedApi.setLanguageMapping.mockResolvedValue(undefined)
    const wrapper = mountManager()
    await wrapper.get('[data-testid="mapping-en"]').setValue('2')
    await flushPromises()
    expect(mockedApi.setLanguageMapping).toHaveBeenCalledWith('en', 2)
    expect(wrapper.find('[role="status"]').exists()).toBe(true)
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    expect(wrapper.emitted('changed')).toHaveLength(1)
  })

  it('removes a mapping when the empty option is chosen', async () => {
    mockedApi.removeLanguageMapping.mockResolvedValue(undefined)
    const wrapper = mountManager()
    await wrapper.get('[data-testid="mapping-de"]').setValue('')
    await flushPromises()
    expect(mockedApi.removeLanguageMapping).toHaveBeenCalledWith('de')
    expect(mockedApi.setLanguageMapping).not.toHaveBeenCalled()
    expect(wrapper.emitted('changed')).toHaveLength(1)
  })

  it('shows an alert and resets the selection to the saved value on error', async () => {
    mockedApi.setLanguageMapping.mockRejectedValue(
      new ApiError(409, 'LANGUAGE_ALREADY_MAPPED', 'x'),
    )
    const wrapper = mountManager()
    const select = wrapper.get('[data-testid="mapping-de"]')
    await select.setValue('2')
    await flushPromises()
    expect(wrapper.get('[role="alert"]').text().length).toBeGreaterThan(0)
    expect(wrapper.find('[role="status"]').exists()).toBe(false)
    expect((select.element as HTMLSelectElement).value).toBe('1')
    expect(wrapper.emitted('changed')).toBeUndefined()
  })

  it('emits unauthorized on UNAUTHORIZED without showing an alert', async () => {
    mockedApi.setLanguageMapping.mockRejectedValue(new ApiError(401, 'UNAUTHORIZED', 'x'))
    const wrapper = mountManager()
    await wrapper.get('[data-testid="mapping-en"]').setValue('1')
    await flushPromises()
    expect(wrapper.emitted('unauthorized')).toHaveLength(1)
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
  })
})
