<script setup lang="ts">
// Optional "scroll to top" control (Req 6.4, 9.3).
//
// Erscheint unten rechts, sobald die Seite ueber eine Schwelle hinaus
// gescrollt wurde, und scrollt bei Betaetigung sanft an den Seitenanfang.
// Es wird ein natives <button>-Element verwendet (nativ tastaturbedienbar);
// der Fokusindikator wird token-basiert mit ausreichendem Kontrast (>= 3:1)
// dargestellt.
//
// Keine neuen i18n-Schluessel: Der Katalog (@/i18n) enthaelt keinen passenden
// Text fuer diese optionale Steuerung. Als Beschriftung dient daher ein
// dezentes Pfeil-Zeichen; das aria-label ist technisch neutral gehalten und
// wird bewusst nicht als i18n-Schluessel gefuehrt.

import { onBeforeUnmount, onMounted, ref } from 'vue'

/** Scroll-Schwelle in Pixeln, ab der die Schaltflaeche eingeblendet wird. */
const SCROLL_THRESHOLD_PX = 300

const isVisible = ref(false)

function updateVisibility(): void {
  isVisible.value = window.scrollY > SCROLL_THRESHOLD_PX
}

function scrollToTop(): void {
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

onMounted(() => {
  // Anfangszustand setzen (z. B. bei bereits gescrollter Seite) und Listener
  // registrieren.
  updateVisibility()
  window.addEventListener('scroll', updateVisibility, { passive: true })
})

onBeforeUnmount(() => {
  window.removeEventListener('scroll', updateVisibility)
})
</script>

<template>
  <button
    v-show="isVisible"
    type="button"
    class="scroll-to-top"
    aria-label="Scroll to top"
    @click="scrollToTop"
  >
    <span class="scroll-to-top__icon" aria-hidden="true">&#9650;</span>
  </button>
</template>

<style scoped lang="scss">
@use '@/scss/variables' as tokens;
@use '@/scss/mixins' as mixins;

.scroll-to-top {
  position: fixed;
  right: tokens.$spacing-lg;
  bottom: tokens.$spacing-lg;
  z-index: 1000;

  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: tokens.$spacing-xxl;
  height: tokens.$spacing-xxl;
  padding: 0;

  color: tokens.$color-text;
  background-color: tokens.$color-purple-medium;
  border: none;
  border-radius: tokens.$radius-md;
  box-shadow: tokens.$shadow-md;
  cursor: pointer;

  font-family: tokens.$font-family-base;
  font-size: tokens.$font-size-h6;
  line-height: tokens.$line-height-heading;

  @include mixins.transition(background-color, color, box-shadow);

  &:hover {
    background-color: tokens.$color-primary;
  }

  &:focus-visible {
    // Sichtbarer Fokusindikator, Kontrast >= 3:1 (Req 6.4, 9.3):
    // Primaerfarbe #C15BEB gegen den Gunmetal-Hintergrund.
    outline: tokens.$spacing-xs solid tokens.$color-primary;
    outline-offset: tokens.$spacing-xs;
  }
}

.scroll-to-top__icon {
  display: block;
}
</style>
