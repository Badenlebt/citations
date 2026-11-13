<script setup lang="ts">
import { watch } from 'vue'
import { RouterView } from 'vue-router'
import { useI18n } from '@/i18n'
import AppHeader from '@/components/AppHeader.vue'
import AppFooter from '@/components/AppFooter.vue'
import ScrollToTop from '@/components/ScrollToTop.vue'

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
  <!-- Fixierte Menueleiste (Brand + Navigation + Sprachumschalter), Req 4.1. -->
  <AppHeader />

  <!-- Inhaltsbereich mit oberem Abstand unterhalb der fixierten Leiste
       (padding-top 96px, Req 4.3). Struktur und Routen bleiben unveraendert. -->
  <main class="app-main">
    <div class="app-main__container container">
      <RouterView />
    </div>
  </main>

  <!-- Mehrspaltiger Fussbereich (Req 4.5). -->
  <AppFooter />

  <!-- Optionale Schaltflaeche zum Seitenanfang (Req 6.4, 9.3). -->
  <ScrollToTop />
</template>

<style scoped lang="scss">
@use '@/scss/variables' as tokens;

// Inhaltsbereich beginnt mit 96px oberem Abstand unterhalb der fixierten
// Menueleiste, sodass kein Inhalt verdeckt wird (Req 4.3). Der 96px-Wert ist
// durch die Spezifikation fest vorgegeben; alle uebrigen Gestaltungswerte
// stammen aus den zentralen Design-Tokens.
.app-main {
  padding-top: 96px;
  // Der Inhaltsbereich fuellt mindestens die Resthoehe, damit der Footer bei
  // kurzen Seiten nicht nach oben rutscht (ausgewogene Gesamtstruktur).
  min-height: calc(100vh - 96px);
}

// Horizontale Begrenzung + Zentrierung (uebernimmt .container) sowie
// vertikaler Rhythmus des Seiteninhalts. Ohne diesen Wrapper klebte der
// Inhalt am linken Rand, waehrend der Footer bereits zentriert war.
.app-main__container {
  padding-block: var(--spacing-xl, #{tokens.$spacing-xl});
}
</style>
