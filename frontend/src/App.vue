<script setup lang="ts">
import { watch } from 'vue'
import { RouterView } from 'vue-router'
import { useI18n } from '@/i18n'
import LanguageSwitcher from '@/components/LanguageSwitcher.vue'

const i18n = useI18n()

// Keep the document language in sync with the active UI locale so assistive
// technologies announce interface chrome in the right language (Req 14.2).
watch(
  i18n.locale,
  (locale) => {
    if (typeof document !== 'undefined') {
      document.documentElement.setAttribute('lang', locale)
    }
  },
  { immediate: true },
)
</script>

<template>
  <header class="app-header">
    <strong class="app-header__title">{{ i18n.t('app.title') }}</strong>
    <LanguageSwitcher />
  </header>
  <main>
    <RouterView />
  </main>
</template>
