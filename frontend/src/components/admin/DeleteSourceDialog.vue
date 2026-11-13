<script setup lang="ts">
// Delete-decision dialog (Req 11.6). Shown when deleting a source returns the
// `SOURCE_HAS_QUOTES` conflict because quotes are still assigned. It offers
// EXACTLY two choices and reports the picked `strategy` to the parent, which
// re-issues the delete against the author API:
//   - "Umhängen"     → strategy `reassign`: quotes move to the Unknown source,
//                      then the source is deleted (11.3).
//   - "Löschabbruch" → strategy `cancel`: nothing changes (11.4).
import { useI18n } from '@/i18n'
import type { SourceDeleteStrategy } from '@/api'

defineProps<{
  /** Name of the source whose deletion triggered the conflict. */
  sourceName: string
  /** Disables the choices while the follow-up request is in flight. */
  busy?: boolean
}>()

const emit = defineEmits<{
  (e: 'choose', strategy: SourceDeleteStrategy): void
}>()

const i18n = useI18n()

function choose(strategy: SourceDeleteStrategy): void {
  emit('choose', strategy)
}
</script>

<template>
  <div class="delete-source-dialog__backdrop">
    <div
      class="delete-source-dialog"
      role="dialog"
      aria-modal="true"
      aria-labelledby="delete-source-dialog-title"
    >
      <h2 id="delete-source-dialog-title" class="delete-source-dialog__heading">
        {{ i18n.t('admin.deleteConflictHeading') }}
      </h2>

      <p class="delete-source-dialog__message">
        {{ i18n.t('admin.deleteConflictMessage') }}
      </p>
      <p class="delete-source-dialog__source">{{ sourceName }}</p>

      <!-- Exactly two options, per Req 11.6. -->
      <div class="delete-source-dialog__actions">
        <button
          type="button"
          class="delete-source-dialog__option"
          data-strategy="reassign"
          :disabled="busy"
          @click="choose('reassign')"
        >
          <strong>{{ i18n.t('admin.deleteReassign') }}</strong>
          <span class="delete-source-dialog__hint">{{
            i18n.t('admin.deleteReassignHint')
          }}</span>
        </button>

        <button
          type="button"
          class="delete-source-dialog__option"
          data-strategy="cancel"
          :disabled="busy"
          @click="choose('cancel')"
        >
          <strong>{{ i18n.t('admin.deleteCancel') }}</strong>
          <span class="delete-source-dialog__hint">{{
            i18n.t('admin.deleteCancelHint')
          }}</span>
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped lang="scss">
// Token-based styling for the delete-decision dialog (Task 6.6).
// Markup, BEM class names, props, logic, routes and i18n keys remain
// unchanged; only the presentation is driven by design tokens. The modal
// surface, elevation, spacing and the muted hint colour are sourced from the
// token palette — either via build-checked SCSS variables (Req 1.5) or the
// :root-mirrored custom properties (Req 1.4); no hardcoded colors/spacing
// (Req 2.2, 4.6, 5.5, 6.1, 6.6, 8.3). The dialog adopts the app's dark surface
// (background token) with the text token, replacing the former light #fff/#111
// surface and the #555 hint gray. The backdrop scrim keeps a translucent black
// overlay — the established convention also used by the shadow tokens — as no
// dedicated scrim token exists.
@use '@/scss/variables' as tokens;

.delete-source-dialog__backdrop {
  position: fixed;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(0, 0, 0, 0.4);
}

.delete-source-dialog {
  background: var(--color-background, #{tokens.$color-background});
  color: var(--color-text, #{tokens.$color-text});
  padding: var(--spacing-lg, #{tokens.$spacing-lg});
  border-radius: var(--radius-md, #{tokens.$radius-md});
  max-width: 32rem;
  width: calc(100% - var(--spacing-xl, #{tokens.$spacing-xl}));
  box-shadow: var(--shadow-md, #{tokens.$shadow-md});
}

.delete-source-dialog__source {
  font-weight: var(--font-weight-heading, #{tokens.$font-weight-heading});
}

.delete-source-dialog__actions {
  display: flex;
  flex-direction: column;
  // 0.75rem has no single token; composed from the spacing scale (sm + xs).
  gap: calc(
    var(--spacing-sm, #{tokens.$spacing-sm}) +
      var(--spacing-xs, #{tokens.$spacing-xs})
  );
  margin-top: var(--spacing-md, #{tokens.$spacing-md});
}

.delete-source-dialog__option {
  display: flex;
  flex-direction: column;
  gap: var(--spacing-xs, #{tokens.$spacing-xs});
  text-align: left;
  // 0.75rem has no single token; composed from the spacing scale (sm + xs).
  padding: calc(
      var(--spacing-sm, #{tokens.$spacing-sm}) +
        var(--spacing-xs, #{tokens.$spacing-xs})
    )
    var(--spacing-md, #{tokens.$spacing-md});
  cursor: pointer;
}

.delete-source-dialog__hint {
  font-size: 0.85em;
  // Muted secondary text via the purple-bright token (replaces #555).
  color: var(--color-purple-bright, #{tokens.$color-purple-bright});
}
</style>
