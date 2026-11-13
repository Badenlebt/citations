<script setup lang="ts">
// AppFooter — mehrspaltiger Fussbereich des UI-Redesigns (Task 4.4).
//
// Struktur: Spalten mit je einer Ueberschrift und darunter einer Liste echter
// Navigationslinks (Referenz-Design). Es werden nur so viele Spalten gebildet,
// wie inhaltlich sinnvolle Ziele existieren. Layout via CSS-Grid, mobile-first
// mit 2 Spalten; ab Breakpoint md (768px) ebenfalls 2 Spalten (nur zwei
// inhaltliche Gruppen). Alle Farben/Abstaende/Typografie stammen ausschliesslich
// aus den Design-Tokens (Req 4.6).
//
// i18n: Es werden AUSSCHLIESSLICH bereits vorhandene Schluessel verwendet; es
// werden KEINE neuen Footer-Schluessel eingefuehrt (Req 8.3). Alle Eintraege
// sind funktionierende <router-link>s: die Zitate-Spalte verweist auf die
// Suche, die Verwaltungs-Spalte per `?tab=`-Query direkt auf die jeweiligen
// Verwaltungsbereiche (deep-linkbar).
import { RouterLink } from 'vue-router'
import { useI18n } from '@/i18n'
import { RouteName } from '@/router'

const i18n = useI18n()
</script>

<template>
  <footer class="app-footer">
    <div class="app-footer__grid container">
      <!-- Spalte 1: rund um das Entdecken von Zitaten. -->
      <nav class="app-footer__col" :aria-label="i18n.t('app.title')">
        <span class="app-footer__heading">{{ i18n.t('app.title') }}</span>
        <ul class="app-footer__list">
          <li>
            <RouterLink class="app-footer__link" :to="{ name: RouteName.Search }">
              {{ i18n.t('search.heading') }}
            </RouterLink>
          </li>
          <li>
            <RouterLink class="app-footer__link" :to="{ name: RouteName.Search }">
              {{ i18n.t('search.randomQuote') }}
            </RouterLink>
          </li>
        </ul>
      </nav>

      <!-- Spalte 2: Verwaltungsbereiche, per ?tab= direkt deep-verlinkt. -->
      <nav class="app-footer__col" :aria-label="i18n.t('nav.admin')">
        <span class="app-footer__heading">{{ i18n.t('nav.admin') }}</span>
        <ul class="app-footer__list">
          <li>
            <RouterLink
              class="app-footer__link"
              :to="{ name: RouteName.Admin, query: { tab: 'quotes' } }"
            >
              {{ i18n.t('admin.tabQuotes') }}
            </RouterLink>
          </li>
          <li>
            <RouterLink
              class="app-footer__link"
              :to="{ name: RouteName.Admin, query: { tab: 'sources' } }"
            >
              {{ i18n.t('admin.tabSources') }}
            </RouterLink>
          </li>
          <li>
            <RouterLink
              class="app-footer__link"
              :to="{ name: RouteName.Admin, query: { tab: 'languages' } }"
            >
              {{ i18n.t('admin.tabLanguages') }}
            </RouterLink>
          </li>
        </ul>
      </nav>
    </div>
  </footer>
</template>

<style scoped lang="scss">
@use '../scss/variables' as tokens;
@use '../scss/mixins' as mix;

.app-footer {
  background-color: var(--color-background, #282d37);
  color: var(--color-text, #fff);
  font-family: var(--font-family-base);
  font-size: var(--font-size-base);
  line-height: var(--line-height-body);
  padding-block: var(--spacing-xl, #{tokens.$spacing-xl});
  box-shadow: var(--shadow-md);
}

.app-footer__grid {
  display: grid;
  // Mobile-first: 2 Spalten (zwei inhaltliche Gruppen, Req 4.5).
  grid-template-columns: repeat(2, 1fr);
  gap: var(--spacing-xl, #{tokens.$spacing-xl});
  align-items: start;

  @include mix.respond-to(md) {
    grid-template-columns: repeat(2, 1fr);
  }
}

// Eine Spalte: Ueberschrift oben, darunter die Linkliste.
.app-footer__col {
  display: flex;
  flex-direction: column;
  gap: var(--spacing-md, #{tokens.$spacing-md});
}

// Spaltenueberschrift: hervorgehoben, in Grossbuchstaben (Referenz-Design).
.app-footer__heading {
  font-weight: var(--font-weight-heading);
  font-size: var(--font-size-h6);
  line-height: var(--line-height-heading);
  color: var(--color-text, #fff);
  text-transform: uppercase;
}

.app-footer__list {
  display: flex;
  flex-direction: column;
  gap: var(--spacing-sm, #{tokens.$spacing-sm});
  list-style: none;
  padding: 0;
  margin: 0;
}

.app-footer__link {
  color: var(--color-purple-bright, #e49eff);
  text-decoration: none;
  @include mix.transition(color);

  &:hover,
  &:focus-visible {
    color: var(--color-primary, #c15beb);
  }

  &.router-link-active {
    color: var(--color-primary, #c15beb);
  }
}
</style>
