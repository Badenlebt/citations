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

<style scoped>
.delete-source-dialog__backdrop {
  position: fixed;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(0, 0, 0, 0.4);
}

.delete-source-dialog {
  background: #fff;
  color: #111;
  padding: 1.5rem;
  border-radius: 0.5rem;
  max-width: 32rem;
  width: calc(100% - 2rem);
  box-shadow: 0 8px 32px rgba(0, 0, 0, 0.25);
}

.delete-source-dialog__source {
  font-weight: 600;
}

.delete-source-dialog__actions {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  margin-top: 1rem;
}

.delete-source-dialog__option {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  text-align: left;
  padding: 0.75rem 1rem;
  cursor: pointer;
}

.delete-source-dialog__hint {
  font-size: 0.85em;
  color: #555;
}
</style>
