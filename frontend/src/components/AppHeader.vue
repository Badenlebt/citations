<script setup lang="ts">
// AppHeader / MenuBar (Task 4.1 + 4.2, Req 4.1, 4.2, 6.2, 6.3, 7.2, 7.3)
// Fixierte Menueleiste am oberen Rand mit Brand-Bereich links sowie
// Navigation und Sprachumschalter rechts. Es werden ausschliesslich die real
// vorhandenen Routen verlinkt (Search, Admin) und bestehende i18n-Schluessel
// verwendet (app.title, nav.search, nav.admin). Alle Gestaltungswerte stammen
// aus den zentralen Design-Tokens.
//
// Task 4.2 ergaenzt zwei Verhalten:
//  - Compact-on-scroll (Req 4.2): Ein passiver Scroll-Listener schaltet ab einer
//    Scroll-Schwelle die reaktive Modifikator-Klasse app-header--compact, die
//    Hoehe/Padding der Leiste reduziert.
//  - Responsiv (Req 7.2, 7.3): Ab md (768px) horizontale Navigation; unterhalb md
//    ein Hamburger-Menue (nativer <button>, tastaturbedienbar) mit Slide-in der
//    Navigation. Der Breakpoint wird ausschliesslich ueber das respond-to(md)-
//    Mixin aufgeloest.
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { useI18n } from '@/i18n'
import { RouteName } from '@/router'
import LanguageSwitcher from '@/components/LanguageSwitcher.vue'

const i18n = useI18n()

/** Scroll-Schwelle in Pixeln, ab der die Leiste kompakt dargestellt wird. */
const COMPACT_SCROLL_THRESHOLD_PX = 50

/** Reaktiver Zustand: TRUE sobald ueber die Schwelle hinaus gescrollt wurde. */
const isCompact = ref(false)

/** Reaktiver Zustand des Hamburger-Menues (unterhalb md). */
const isMenuOpen = ref(false)

function updateCompactState(): void {
  isCompact.value = window.scrollY > COMPACT_SCROLL_THRESHOLD_PX
}

function toggleMenu(): void {
  isMenuOpen.value = !isMenuOpen.value
}

function closeMenu(): void {
  isMenuOpen.value = false
}

onMounted(() => {
  // Anfangszustand setzen (z. B. bereits gescrollte Seite) und passiven
  // Scroll-Listener registrieren.
  updateCompactState()
  window.addEventListener('scroll', updateCompactState, { passive: true })
})

onBeforeUnmount(() => {
  window.removeEventListener('scroll', updateCompactState)
})
</script>

<template>
  <header
    class="app-header"
    :class="{
      'app-header--compact': isCompact,
      'app-header--menu-open': isMenuOpen,
    }"
  >
    <div class="app-header__brand">
      <strong class="app-header__title">{{ i18n.t('app.title') }}</strong>
    </div>

    <!--
      Hamburger-Toggle: nativer <button> (nativ tastaturbedienbar) mit
      technisch neutralem aria-label (kein i18n-Schluessel vorhanden, analog zur
      ScrollToTop-Loesung). Nur unterhalb md sichtbar; aria-expanded spiegelt den
      Menuezustand fuer assistive Technologien.
    -->
    <button
      type="button"
      class="app-header__toggle"
      :aria-expanded="isMenuOpen"
      aria-label="Toggle navigation menu"
      @click="toggleMenu"
    >
      <span class="app-header__toggle-icon" aria-hidden="true"></span>
    </button>

    <div class="app-header__actions">
      <nav class="app-header__nav" :aria-label="i18n.t('app.title')">
        <RouterLink
          class="app-header__nav-link"
          :to="{ name: RouteName.Search }"
          @click="closeMenu"
        >
          {{ i18n.t('nav.search') }}
        </RouterLink>
        <RouterLink
          class="app-header__nav-link"
          :to="{ name: RouteName.Admin }"
          @click="closeMenu"
        >
          {{ i18n.t('nav.admin') }}
        </RouterLink>
      </nav>

      <LanguageSwitcher />
    </div>
  </header>
</template>

<style scoped lang="scss">
@use '../scss/variables' as tokens;
@use '../scss/mixins' as mix;

// -----------------------------------------------------------------------------
// Fixierte Menueleiste (Req 4.1)
// -----------------------------------------------------------------------------
// position: fixed + z-index 1000, Hintergrund #282D37, box-shadow. Brand links,
// Navigation + Sprachumschalter rechts (space-between). Alle Werte aus Tokens.
.app-header {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  z-index: 1000;

  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--spacing-lg, 1.5rem);

  padding: var(--spacing-md, 1rem) var(--spacing-lg, 1.5rem);

  background-color: var(--color-background, #282D37);
  box-shadow: var(--shadow-md, 0 2px 8px rgba(0, 0, 0, 0.4));

  // Uebergang fuer compact-on-scroll (Req 4.2): sanftes Reduzieren von
  // Padding/Shadow beim Umschalten der Modifikator-Klasse.
  @include mix.transition(padding, box-shadow);

  // ---------------------------------------------------------------------------
  // Compact-on-scroll (Req 4.2)
  // ---------------------------------------------------------------------------
  // Reduziertes vertikales Padding (geringere Hoehe) und dezenterer Schatten,
  // sobald die reaktive Klasse app-header--compact gesetzt ist. Werte aus Tokens.
  &--compact {
    padding-block: var(--spacing-xs, 0.25rem);
    box-shadow: var(--shadow-sm, 0 1px 4px rgba(0, 0, 0, 0.3));

    .app-header__title {
      font-size: var(--font-size-h6, 1rem);
    }
  }
}

// -----------------------------------------------------------------------------
// Brand-Bereich (links)
// -----------------------------------------------------------------------------
.app-header__brand {
  display: flex;
  align-items: center;
  gap: var(--spacing-sm, 0.5rem);
}

.app-header__title {
  font-family: var(--font-family-base, sans-serif);
  font-size: var(--font-size-h5, 1.25rem);
  font-weight: var(--font-weight-heading, 700);
  line-height: var(--line-height-heading, 1.2);
  color: var(--color-text, #fff);

  // Schriftgroesse animiert im compact-Zustand mit.
  @include mix.transition(font-size);
}

// -----------------------------------------------------------------------------
// Hamburger-Toggle (Req 7.2) — nur unterhalb md sichtbar
// -----------------------------------------------------------------------------
// Nativer <button> fuer vollstaendige Tastaturbedienbarkeit; sichtbarer,
// token-basierter Fokusindikator (Kontrast >= 3:1). Ab md ausgeblendet, da dort
// die horizontale Navigation greift (Req 7.3).
.app-header__toggle {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: var(--spacing-xl, 2rem);
  height: var(--spacing-xl, 2rem);
  padding: 0;

  color: var(--color-text, #fff);
  background-color: transparent;
  border: none;
  border-radius: var(--radius-sm, 0.25rem);
  cursor: pointer;

  @include mix.transition(color);

  &:hover {
    color: var(--color-primary, #C15BEB);
  }

  &:focus-visible {
    // Sichtbarer Fokusindikator (Req 6.4): Primaerfarbe gegen Gunmetal-Grund.
    outline: var(--spacing-xs, 0.25rem) solid var(--color-primary, #C15BEB);
    outline-offset: var(--spacing-xs, 0.25rem);
  }

  // Ab md uebernimmt die horizontale Navigation; der Toggle entfaellt (Req 7.3).
  @include mix.respond-to(md) {
    display: none;
  }
}

// Hamburger-Glyphe (drei Linien) ueber ::before/::after, token-basiert.
.app-header__toggle-icon {
  position: relative;
  display: block;
  width: var(--spacing-md, 1rem);
  height: 2px;
  background-color: currentColor;

  &::before,
  &::after {
    content: '';
    position: absolute;
    left: 0;
    width: 100%;
    height: 2px;
    background-color: currentColor;
  }

  &::before {
    top: calc(-1 * var(--spacing-xs, 0.25rem));
  }

  &::after {
    top: var(--spacing-xs, 0.25rem);
  }
}

// -----------------------------------------------------------------------------
// Aktionen (rechts): Navigation + Sprachumschalter
// -----------------------------------------------------------------------------
.app-header__actions {
  display: flex;
  align-items: center;
  gap: var(--spacing-lg, 1.5rem);
}

.app-header__nav {
  display: flex;
  align-items: center;
  gap: var(--spacing-md, 1rem);
}

// -----------------------------------------------------------------------------
// Responsives Verhalten (Req 7.2, 7.3)
// -----------------------------------------------------------------------------
// Unterhalb md (mobile-first Standard): Navigation als Slide-in-Panel. Die
// Aktionen werden als vertikales Panel unter die fixierte Leiste geschoben und
// per Transform ein-/ausgeblendet (Slide-in). app-header--menu-open blendet es
// ein.
.app-header__actions {
  position: absolute;
  top: 100%;
  right: 0;
  left: 0;

  flex-direction: column;
  align-items: flex-start;
  gap: var(--spacing-md, 1rem);

  padding: var(--spacing-md, 1rem) var(--spacing-lg, 1.5rem);

  background-color: var(--color-background, #282D37);
  box-shadow: var(--shadow-md, 0 2px 8px rgba(0, 0, 0, 0.4));

  // Slide-in: standardmaessig nach oben ausgeblendet; bei geoeffnetem Menue
  // eingeblendet. Sichtbarkeit/Transform werden animiert (Req 7.2).
  transform: translateY(-100%);
  opacity: 0;
  visibility: hidden;
  pointer-events: none;

  @include mix.transition(transform, opacity, visibility);

  .app-header__nav {
    flex-direction: column;
    align-items: flex-start;
    gap: var(--spacing-sm, 0.5rem);
  }
}

// Geoeffnetes Hamburger-Menue (nur relevant unterhalb md): Panel einblenden.
.app-header--menu-open .app-header__actions {
  transform: translateY(0);
  opacity: 1;
  visibility: visible;
  pointer-events: auto;
}

// Ab md (768px): horizontale Navigationsleiste in der fixierten Leiste (Req 7.3).
// Das Slide-in-Panel wird zurueck in die statische, horizontale Darstellung
// ueberfuehrt; der Menuezustand ist hier irrelevant.
@include mix.respond-to(md) {
  .app-header__actions {
    position: static;

    flex-direction: row;
    align-items: center;
    gap: var(--spacing-lg, 1.5rem);

    padding: 0;

    background-color: transparent;
    box-shadow: none;

    transform: none;
    opacity: 1;
    visibility: visible;
    pointer-events: auto;

    .app-header__nav {
      flex-direction: row;
      align-items: center;
      gap: var(--spacing-md, 1rem);
    }
  }
}

// -----------------------------------------------------------------------------
// Navigationseintraege mit aktivem Unterstrich-Indikator (Req 6.2, 6.3)
// -----------------------------------------------------------------------------
// 2px-Unterstrich in #C15BEB fuer die aktive Route ueber router-link-active;
// Hover-Farbe #C15BEB mit einheitlichem 0.3s-ease-Uebergang.
.app-header__nav-link {
  position: relative;
  display: inline-block;

  padding-block: var(--spacing-xs, 0.25rem);

  font-family: var(--font-family-base, sans-serif);
  font-size: var(--font-size-h6, 1rem);
  color: var(--color-text, #fff);
  text-decoration: none;

  @include mix.transition(color);

  &::after {
    content: '';
    position: absolute;
    left: 0;
    bottom: 0;
    width: 100%;
    height: 2px;
    background-color: var(--color-primary, #C15BEB);
    transform: scaleX(0);
    transform-origin: left center;
    @include mix.transition(transform);
  }

  &:hover,
  &:focus-visible {
    color: var(--color-primary, #C15BEB);
    text-decoration: none;
  }

  // Aktive Route: 2px-Unterstrich in #C15BEB (Req 6.3).
  // router-link-exact-active markiert exakt die aktuelle Route und verhindert,
  // dass der Such-Link (Pfad "/") als Praefix aller Routen dauerhaft als aktiv
  // erscheint; router-link-active bleibt gemaess Task-Vorgabe beruecksichtigt.
  &.router-link-active.router-link-exact-active,
  &.router-link-exact-active {
    color: var(--color-primary, #C15BEB);

    &::after {
      transform: scaleX(1);
    }
  }
}
</style>
