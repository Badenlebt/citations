<script setup lang="ts">
// UI language switcher (Req 14.2). Changing the selection updates only the
// interface texts and persists the choice; it never affects the language of
// individual quotes, which is a separate data attribute (Req 14.4).
import { useI18n, type Locale } from '@/i18n'

const i18n = useI18n()

function onChange(event: Event): void {
  const value = (event.target as HTMLSelectElement).value as Locale
  i18n.setLocale(value)
}
</script>

<template>
  <label class="language-switcher">
    <span class="language-switcher__label">{{ i18n.t('language.label') }}</span>
    <select
      class="language-switcher__select"
      :value="i18n.locale.value"
      @change="onChange"
    >
      <option
        v-for="locale in i18n.availableLocales"
        :key="locale"
        :value="locale"
      >
        {{ i18n.t(`language.${locale}`) }}
      </option>
    </select>
  </label>
</template>
