import {
  computed,
  inject,
  ref,
  type App,
  type ComputedRef,
  type InjectionKey,
  type Ref,
} from 'vue'
import { DEFAULT_LOCALE, SUPPORTED_LOCALES, type Locale } from './locales'
import { messages, type MessageSchema } from './messages'
import { readStoredLocale, writeStoredLocale } from './storage'
import { resolveBrowserLocale } from './resolveLocale'

/** Dot-path keys into a catalog, e.g. `"search.heading"`. */
type Join<K, P> = K extends string
  ? P extends string
    ? `${K}.${P}`
    : never
  : never

export type MessageKey = {
  [K in keyof MessageSchema]: Join<K, keyof MessageSchema[K]>
}[keyof MessageSchema]

/** Public API exposed by the i18n composable. */
export interface I18n {
  /** The currently active UI locale (reactive). */
  readonly locale: Ref<Locale>
  /** The active catalog (reactive). */
  readonly messages: ComputedRef<MessageSchema>
  /** The locales the UI provides, for building a switcher. */
  readonly availableLocales: readonly Locale[]
  /** Translates a dot-path key to the active locale's text. */
  t(key: MessageKey): string
  /**
   * Switches the UI locale and persists the choice. Switching changes only
   * UI chrome, never quote-content language (Req 14.2, 14.4).
   */
  setLocale(locale: Locale): void
}

const INJECTION_KEY: InjectionKey<I18n> = Symbol('citations.i18n')

/**
 * Determines the locale to start with: a previously stored choice wins;
 * otherwise the browser's preferred provided locale is used, falling back to
 * German (Req 14.3).
 */
export function resolveInitialLocale(): Locale {
  return readStoredLocale() ?? resolveBrowserLocale()
}

function lookup(catalog: MessageSchema, key: MessageKey): string {
  const [section, entry] = key.split('.') as [keyof MessageSchema, string]
  const group = catalog[section] as Record<string, string> | undefined
  return group?.[entry] ?? key
}

/** Creates an i18n instance. Exported for tests that need an isolated one. */
export function createI18n(initialLocale: Locale = resolveInitialLocale()): I18n {
  const locale = ref<Locale>(initialLocale)
  const activeMessages = computed(() => messages[locale.value])

  function setLocale(next: Locale): void {
    locale.value = next
    writeStoredLocale(next)
  }

  function t(key: MessageKey): string {
    return lookup(activeMessages.value, key)
  }

  return {
    locale,
    messages: activeMessages,
    availableLocales: SUPPORTED_LOCALES,
    t,
    setLocale,
  }
}

// A shared instance so plugin-less usage (and the default export) stays simple.
let shared: I18n | null = null

/** Returns the shared i18n instance, creating it on first use. */
export function getI18n(): I18n {
  if (!shared) {
    shared = createI18n()
  }
  return shared
}

/**
 * Vue plugin registering the shared i18n instance for injection via
 * {@link useI18n}. Also exposes the active locale on `<html lang>` so the
 * document language tracks the UI language.
 */
export function installI18n(app: App, instance: I18n = getI18n()): void {
  app.provide(INJECTION_KEY, instance)
  app.config.globalProperties.$t = instance.t
}

/**
 * Composable to access i18n inside components. Uses the injected instance when
 * available (set up via {@link installI18n}), otherwise the shared instance.
 */
export function useI18n(): I18n {
  return inject(INJECTION_KEY, null) ?? getI18n()
}

export { DEFAULT_LOCALE }
