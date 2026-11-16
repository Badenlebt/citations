import { computed, ref, type ComputedRef, type Ref } from 'vue'
import { ApiError, type Api, type QuoteDetail } from '@/api'
import type { Locale } from '@/i18n'

/** Default request timeout (Req 1.8, 2.5, 4.2). */
export const RANDOM_QUOTE_TIMEOUT_MS = 10_000

export type RandomQuoteStatus = 'idle' | 'loading' | 'ready' | 'empty' | 'error'

export interface UseRandomQuote {
  quote: Ref<QuoteDetail | null>
  status: Ref<RandomQuoteStatus>
  /** First request is running and there is no result yet. */
  initialLoading: ComputedRef<boolean>
  errorVisible: Ref<boolean>
  /** A request is in flight (disable the reload button). */
  pending: Ref<boolean>
  reload(): Promise<void>
  /** Abort on unmount: no state change, no message. */
  dispose(): void
}

export interface UseRandomQuoteDeps {
  client: Pick<Api, 'random'>
  /** Read freshly on every call (Req 6.11, 6.12). */
  locale: () => Locale
  timeoutMs?: number
}

export function useRandomQuote(deps: UseRandomQuoteDeps): UseRandomQuote {
  const timeoutMs = deps.timeoutMs ?? RANDOM_QUOTE_TIMEOUT_MS
  const quote = ref<QuoteDetail | null>(null) as Ref<QuoteDetail | null>
  const status = ref<RandomQuoteStatus>('idle')
  const errorVisible = ref(false)
  const pending = ref(false)
  const initialLoading = computed(() => status.value === 'loading')

  let sequence = 0
  let disposed = false
  let controller: AbortController | null = null

  async function reload(): Promise<void> {
    if (disposed || pending.value) return

    const mySequence = ++sequence
    const ctrl = new AbortController()
    controller = ctrl
    let timedOut = false
    pending.value = true
    // Only the very first request shows the loading state; later ones keep
    // the current state (quote / error / empty) until a result arrives.
    if (status.value === 'idle') status.value = 'loading'

    const timer = setTimeout(() => {
      timedOut = true
      ctrl.abort()
    }, timeoutMs)

    // Rejects on abort even if the client ignores the signal.
    const aborted = new Promise<never>((_, reject) => {
      ctrl.signal.addEventListener('abort', () =>
        reject(new DOMException('Aborted', 'AbortError')),
      )
    })
    aborted.catch(() => undefined)

    try {
      const result = await Promise.race([deps.client.random(ctrl.signal, deps.locale()), aborted])
      if (disposed || mySequence !== sequence) return
      quote.value = result
      status.value = 'ready'
      errorVisible.value = false
    } catch (err) {
      if (disposed || mySequence !== sequence) return
      if (ctrl.signal.aborted && !timedOut) return // aborted by dispose
      if (!timedOut && err instanceof ApiError && err.code === 'NO_QUOTES') {
        quote.value = null
        status.value = 'empty'
        errorVisible.value = false
      } else {
        // Keep an already shown quote; otherwise show the error state.
        if (quote.value === null) status.value = 'error'
        errorVisible.value = true
      }
    } finally {
      clearTimeout(timer)
      if (mySequence === sequence && !disposed) pending.value = false
      if (controller === ctrl) controller = null
    }
  }

  function dispose(): void {
    disposed = true
    sequence++
    controller?.abort()
    controller = null
  }

  return { quote, status, initialLoading, errorVisible, pending, reload, dispose }
}
