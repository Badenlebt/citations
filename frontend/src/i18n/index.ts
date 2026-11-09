export {
  SUPPORTED_LOCALES,
  DEFAULT_LOCALE,
  isSupportedLocale,
  type Locale,
} from './locales'
export { messages, type MessageSchema } from './messages'
export {
  resolveLocaleFromLanguages,
  readBrowserLanguages,
  resolveBrowserLocale,
} from './resolveLocale'
export {
  LOCALE_STORAGE_KEY,
  readStoredLocale,
  writeStoredLocale,
} from './storage'
export {
  createI18n,
  getI18n,
  installI18n,
  useI18n,
  resolveInitialLocale,
  type I18n,
  type MessageKey,
} from './useI18n'

import type { MessageKey } from './useI18n'

// Make `$t` available in templates with correct typing (set up in installI18n).
declare module 'vue' {
  interface ComponentCustomProperties {
    $t: (key: MessageKey) => string
  }
}
