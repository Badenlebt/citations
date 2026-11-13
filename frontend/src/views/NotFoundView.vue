<script setup lang="ts">
// Catch-all 404 view for client-side routing.
</script>

<template>
  <section class="not-found-view">
    <h1>Nicht gefunden</h1>
  </section>
</template>

<style scoped lang="scss">
// Token-based styling for the 404 view (Task 6.7).
// Markup, logic and i18n stay unchanged; the shared header/navigation is
// provided globally via AppHeader in App.vue, so this view owns no header
// logic. All presentation values come from design tokens — either via
// build-checked SCSS variables (Req 1.5) or the :root-mirrored custom
// properties (Req 1.4); no hardcoded colors/spacing (Req 5.6, 8.1).
@use '@/scss/variables' as tokens;

.not-found-view {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: var(--spacing-md, #{tokens.$spacing-md});
  padding-block: var(--spacing-xl, #{tokens.$spacing-xl});
  text-align: center;
}
</style>
