import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import fc from 'fast-check'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'

vi.mock('@/api', async () => {
  const actual = await vi.importActual<typeof import('@/api')>('@/api')
  return { ...actual, api: { random: vi.fn() } }
})

import { api, ApiError } from '@/api'
import { routes } from '@/router'
import HomeView from './HomeView.vue'

// Feature: random-quote-homepage, Property 11: Jeder Fehler außer NO_QUOTES führt zur Fehleranzeige mit Wiederholungsmöglichkeit
// **Validates: Requirements 4.2, 4.3**

type FailureKind =
  | { kind: 'api'; status: number; code: string | null }
  | { kind: 'network'; message: string }
  | { kind: 'timeout' }

const failureArb: fc.Arbitrary<FailureKind> = fc.oneof(
  fc.record({
    kind: fc.constant('api' as const),
    status: fc.integer({ min: 400, max: 599 }),
    code: fc.option(
      fc
        .oneof(
          fc.constantFrom('INTERNAL_ERROR', 'UNAUTHORIZED', 'NOT_FOUND', 'INVALID_UI_LANGUAGE'),
          fc.string({ maxLength: 20 }),
        )
        .filter((c) => c !== 'NO_QUOTES'),
      { nil: null },
    ),
  }),
  fc.record({ kind: fc.constant('network' as const), message: fc.string({ maxLength: 30 }) }),
  fc.constant<FailureKind>({ kind: 'timeout' }),
)

const randomMock = vi.mocked(api.random)

describe('HomeView Property 11: Fehleranzeige mit Wiederholungsmöglichkeit', () => {
  beforeEach(() => {
    vi.useFakeTimers()
  })
  afterEach(() => {
    vi.useRealTimers()
    vi.clearAllMocks()
  })

  it('jeder Fehler außer NO_QUOTES zeigt genau einen Alert, kein Zitat und eine aktive Nachladen-Schaltfläche', async () => {
    await fc.assert(
      fc.asyncProperty(failureArb, async (failure) => {
        randomMock.mockReset()
        if (failure.kind === 'timeout') {
          randomMock.mockImplementation(() => new Promise(() => undefined))
        } else if (failure.kind === 'network') {
          randomMock.mockRejectedValue(new TypeError(failure.message))
        } else {
          randomMock.mockRejectedValue(new ApiError(failure.status, failure.code, 'boom'))
        }

        const router = createRouter({ history: createMemoryHistory(), routes })
        await router.push('/')
        await router.isReady()
        const wrapper = mount(HomeView, { global: { plugins: [router] } })

        if (failure.kind === 'timeout') {
          await vi.advanceTimersByTimeAsync(10_000)
        }
        await flushPromises()

        expect(wrapper.findAll('[role="alert"]')).toHaveLength(1)
        expect(wrapper.find('figure').exists()).toBe(false)
        expect(wrapper.find('blockquote').exists()).toBe(false)

        const button = wrapper.find('button.home-view__reload')
        expect(button.exists()).toBe(true)
        expect(button.attributes('disabled')).toBeUndefined()

        // Aktivierbar: ein Klick löst eine weitere Anfrage aus.
        const before = randomMock.mock.calls.length
        await button.trigger('click')
        expect(randomMock.mock.calls.length).toBe(before + 1)

        wrapper.unmount()
      }),
      { numRuns: 100 },
    )
  })
})
