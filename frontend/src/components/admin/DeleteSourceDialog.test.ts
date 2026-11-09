import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import type { App } from 'vue'
import DeleteSourceDialog from './DeleteSourceDialog.vue'
import SourceManager from './SourceManager.vue'
import { ApiError } from '@/api'
import { createI18n, installI18n } from '@/i18n'

// Pin the UI locale to German so the "Umhängen"/"Löschabbruch" labels are
// deterministic regardless of the test runner's browser language.
const i18nPlugin = {
  install(app: App) {
    installI18n(app, createI18n('de'))
  },
}

function mountDialog(props: { sourceName: string; busy?: boolean }) {
  return mount(DeleteSourceDialog, {
    props,
    global: { plugins: [i18nPlugin] },
  })
}

// `@/api` is mocked so we can observe which `strategy` SourceManager submits to
// the source-delete endpoint after a choice is made in the dialog.
vi.mock('@/api', async (importActual) => {
  const actual = await importActual<typeof import('@/api')>()
  return {
    ...actual,
    api: {
      deleteSource: vi.fn(),
    },
  }
})

// eslint-disable-next-line @typescript-eslint/no-explicit-any
const mockedApi = (await import('@/api')).api as any

// Component test for the delete-conflict UI (Task 15.4, Req 11.6).
//
// The dialog is shown when deleting a source returns `SOURCE_HAS_QUOTES`. It
// must present EXACTLY two choices — "Umhängen" (reassign) and "Löschabbruch"
// (cancel) — and report the picked `strategy`. Here we verify:
//   1. the dialog renders exactly two options (reassign / cancel), no third;
//   2. clicking each option emits `choose` with the matching strategy.
// A second block drives SourceManager to assert the chosen strategy is passed
// on to the source-delete API call (reassign → API delete with 'reassign';
// cancel → API delete with 'cancel' / abort, no reassign).

describe('DeleteSourceDialog', () => {
  it('renders exactly two options: reassign and cancel', () => {
    const wrapper = mountDialog({ sourceName: 'Immanuel Kant' })

    const options = wrapper.findAll('[data-strategy]')
    expect(options).toHaveLength(2)

    const strategies = options.map((o) => o.attributes('data-strategy')).sort()
    expect(strategies).toEqual(['cancel', 'reassign'])

    // No third choice sneaks in.
    expect(wrapper.find('[data-strategy="reassign"]').exists()).toBe(true)
    expect(wrapper.find('[data-strategy="cancel"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('Umhängen')
    expect(wrapper.text()).toContain('Löschabbruch')
  })

  it('shows the conflicting source name', () => {
    const wrapper = mountDialog({ sourceName: 'Immanuel Kant' })
    expect(wrapper.text()).toContain('Immanuel Kant')
  })

  it('emits choose("reassign") when Umhängen is clicked', async () => {
    const wrapper = mountDialog({ sourceName: 'Immanuel Kant' })

    await wrapper.find('[data-strategy="reassign"]').trigger('click')

    const events = wrapper.emitted('choose')
    expect(events).toHaveLength(1)
    expect(events?.[0]).toEqual(['reassign'])
  })

  it('emits choose("cancel") when Löschabbruch is clicked', async () => {
    const wrapper = mountDialog({ sourceName: 'Immanuel Kant' })

    await wrapper.find('[data-strategy="cancel"]').trigger('click')

    const events = wrapper.emitted('choose')
    expect(events).toHaveLength(1)
    expect(events?.[0]).toEqual(['cancel'])
  })

  it('disables both options while busy', () => {
    const wrapper = mountDialog({ sourceName: 'Immanuel Kant', busy: true })

    for (const option of wrapper.findAll('[data-strategy]')) {
      expect(option.attributes('disabled')).toBeDefined()
    }
  })
})

describe('delete-conflict strategy submission via SourceManager', () => {
  const source = {
    id: 42,
    name: 'Immanuel Kant',
    birthDate: null,
    deathDate: null,
    description: null,
    linkUrl: null,
  }

  beforeEach(() => {
    mockedApi.deleteSource.mockReset()
    // First (strategy-less) delete rejects with the conflict so the dialog opens.
    mockedApi.deleteSource.mockImplementation((_id: number, strategy?: string) => {
      if (strategy === undefined) {
        return Promise.reject(new ApiError(409, 'SOURCE_HAS_QUOTES', 'conflict'))
      }
      return Promise.resolve()
    })
    vi.stubGlobal('confirm', vi.fn(() => true))
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  async function openConflictDialog() {
    const wrapper = mount(SourceManager, {
      props: { sources: [source] },
      global: { plugins: [i18nPlugin] },
    })
    // The item actions are [Edit, Delete]; the delete button is the second one.
    const actionButtons = wrapper.findAll('.source-manager__item-actions button')
    const deleteButton = actionButtons[actionButtons.length - 1]
    expect(deleteButton).toBeDefined()
    await deleteButton.trigger('click')
    await flushPromises()
    expect(wrapper.findComponent(DeleteSourceDialog).exists()).toBe(true)
    return wrapper
  }

  it('submits strategy=reassign when Umhängen is chosen', async () => {
    const wrapper = await openConflictDialog()

    await wrapper.find('[data-strategy="reassign"]').trigger('click')
    await flushPromises()

    expect(mockedApi.deleteSource).toHaveBeenCalledWith(42, 'reassign')
    // Dialog closes after a successful reassign.
    expect(wrapper.findComponent(DeleteSourceDialog).exists()).toBe(false)
  })

  it('submits strategy=cancel when Löschabbruch is chosen', async () => {
    const wrapper = await openConflictDialog()

    await wrapper.find('[data-strategy="cancel"]').trigger('click')
    await flushPromises()

    expect(mockedApi.deleteSource).toHaveBeenCalledWith(42, 'cancel')
    // No reassign submission happens on cancel.
    const reassignCalls = mockedApi.deleteSource.mock.calls.filter(
      (c: unknown[]) => c[1] === 'reassign',
    )
    expect(reassignCalls).toHaveLength(0)
    // Dialog closes.
    expect(wrapper.findComponent(DeleteSourceDialog).exists()).toBe(false)
  })
})
