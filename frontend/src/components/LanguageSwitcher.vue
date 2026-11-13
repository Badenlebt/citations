<script setup lang="ts">
// UI language switcher (Req 14.2). Changing the selection updates only the
// interface texts and persists the choice; it never affects the language of
// individual quotes, which is a separate data attribute (Req 14.4).
//
// Rendered as a small set of segmented toggle buttons (one per available
// locale). The active locale is marked with aria-pressed="true" so assistive
// technology announces the current selection; the accessible group name comes
// from the existing `language.label` i18n key.
import { useI18n, type Locale } from '@/i18n'

const i18n = useI18n()

function select(locale: Locale): void {
  i18n.setLocale(locale)
}

/** Short, uppercase label for a locale toggle (e.g. "DE", "EN"). */
function shortLabel(locale: Locale): string {
  return locale.toUpperCase()
}
</script>

<template>
  <div
    class="language-switcher"
    role="group"
    :aria-label="i18n.t('language.label')"
  >
    <button
      v-for="locale in i18n.availableLocales"
      :key="locale"
      type="button"
      class="language-switcher__option"
      :class="{
        'language-switcher__option--active': locale === i18n.locale.value,
      }"
      :aria-pressed="locale === i18n.locale.value"
      :aria-label="i18n.t(`language.${locale}`)"
      @click="select(locale)"
    >
      {{ shortLabel(locale) }}
    </button>
  </div>
</template>

<style scoped lang="scss">
// Token-based styling for the UI language switcher (Task 4.6, Feinschliff).
// Segmented DE/EN toggles: the active locale is highlighted with the accent
// colour, inactive locales read as plain text. Logic and i18n keys are
// unchanged; all presentation values come from design tokens — build-checked
// SCSS variables (Req 1.5) or :root-mirrored custom properties (Req 1.4); no
// hardcoded colors/spacing (Req 1.3, 6.1, 8.3, 8.4). Transitions use the
// shared transition() mixin (Req 6.2).
@use '@/scss/variables' as tokens;
@use '@/scss/mixins' as mix;

.language-switcher {
  display: inline-flex;
  align-items: center;
  gap: var(--spacing-xs, #{tokens.$spacing-xs});
  font-family: var(--font-family-base, #{tokens.$font-family-base});
  font-size: var(--font-size-h6, #{tokens.$font-size-h6});
  line-height: var(--line-height-body, #{tokens.$line-height-body});
}

.language-switcher__option {
  padding: var(--spacing-xs, #{tokens.$spacing-xs})
    var(--spacing-sm, #{tokens.$spacing-sm});

  color: var(--color-text, #{tokens.$color-text});
  background-color: transparent;
  border: 1px solid transparent;
  border-radius: var(--radius-sm, #{tokens.$radius-sm});
  font-weight: var(--font-weight-heading, #{tokens.$font-weight-heading});
  cursor: pointer;

  @include mix.transition(color, background-color, border-color);

  // Inaktive Locale: schlichter Text; beim Hover dezent zur Akzentfarbe.
  &:hover {
    color: var(--color-primary, #{tokens.$color-primary});
  }

  // Sichtbarer, token-basierter Fokusindikator (Kontrast >= 3:1, Req 9.4):
  // Primaerfarbe (#C15BEB) gegen den Gunmetal-Hintergrund.
  &:focus-visible {
    outline: 2px solid var(--color-primary, #{tokens.$color-primary});
    outline-offset: 2px;
  }

  // Aktive Locale: Akzent-Rahmen + leicht hervorgehobene Flaeche, wie im
  // Referenz-Design (DE hervorgehoben, EN schlicht daneben).
  &--active {
    color: var(--color-primary, #{tokens.$color-primary});
    border-color: var(--color-primary, #{tokens.$color-primary});

    &:hover {
      color: var(--color-primary, #{tokens.$color-primary});
    }
  }
}
</style>
