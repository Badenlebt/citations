<script setup lang="ts">
// Source management (Req 10, 11, 13): create, edit and delete sources. All
// length-limited fields use the shared input with the matching constants.
//
// Deletion (Req 11, 11.6): a plain DELETE is attempted first. If the source
// still has quotes, the API answers `SOURCE_HAS_QUOTES`; we then show the
// delete-decision dialog with exactly two options and re-issue the DELETE with
// the chosen `strategy` (reassign → quotes move to the Unknown source then the
// source is removed, 11.3; cancel → nothing changes, 11.4).
import { computed, reactive, ref } from 'vue'
import { api, ApiError } from '@/api'
import type { SourceDeleteStrategy, SourceInput } from '@/api'
import { useI18n } from '@/i18n'
import {
  MAX_SOURCE_NAME_LENGTH,
  MAX_SOURCE_OPTIONAL_FIELD_LENGTH,
  MAX_LINK_URL_LENGTH,
} from '@/constants/fieldLimits'
import LengthLimitedInput from '@/components/LengthLimitedInput.vue'
import DeleteSourceDialog from './DeleteSourceDialog.vue'
import { apiErrorMessage } from './errorMessage'

/**
 * Source row shown in the management list (Req 19.2). The admin list endpoint
 * returns only id, name and the Unknown-source flag; the full detail needed for
 * editing is fetched lazily when a row is edited. `isUnknown` is optional so
 * callers that only have an id/name reference stay compatible.
 */
interface SourceRow {
  id: number
  name: string
  isUnknown?: boolean
}

const props = defineProps<{ sources: SourceRow[] }>()

const emit = defineEmits<{
  (e: 'changed'): void
  (e: 'unauthorized'): void
}>()

const i18n = useI18n()

const formError = ref<string | null>(null)
const generalError = ref<string | null>(null)
const submitting = ref(false)
const deletingId = ref<number | null>(null)
const editingId = ref<number | null>(null)

/** The source awaiting a reassign/cancel decision, or null when none. */
const conflictSource = ref<SourceRow | null>(null)

const form = reactive<{
  name: string
  birthDate: string
  deathDate: string
  description: string
  linkUrl: string
}>({
  name: '',
  birthDate: '',
  deathDate: '',
  description: '',
  linkUrl: '',
})

const isEditing = computed(() => editingId.value !== null)

function resetForm(): void {
  form.name = ''
  form.birthDate = ''
  form.deathDate = ''
  form.description = ''
  form.linkUrl = ''
  editingId.value = null
  formError.value = null
}

function startCreate(): void {
  resetForm()
}

/**
 * Loads the full source detail for editing. The management list only carries
 * id/name/isUnknown (Req 19.2), so the optional fields are fetched on demand
 * from GET /api/sources/{id}.
 */
async function startEdit(source: SourceRow): Promise<void> {
  formError.value = null
  generalError.value = null
  editingId.value = source.id
  form.name = source.name
  form.birthDate = ''
  form.deathDate = ''
  form.description = ''
  form.linkUrl = ''
  try {
    const detail = await api.getSource(source.id)
    // Guard against a newer edit having started while this fetch was in flight.
    if (editingId.value !== source.id) {
      return
    }
    form.name = detail.name
    form.birthDate = detail.birthDate ?? ''
    form.deathDate = detail.deathDate ?? ''
    form.description = detail.description ?? ''
    form.linkUrl = detail.linkUrl ?? ''
  } catch (error) {
    if (!handleUnauthorized(error)) {
      generalError.value = apiErrorMessage(i18n, error)
    }
  }
}

/** Empty optional fields are sent as null so they clear server-side. */
function optional(value: string): string | null {
  const trimmed = value.trim()
  return trimmed.length > 0 ? value : null
}

function handleUnauthorized(error: unknown): boolean {
  if (error instanceof ApiError && error.isUnauthorized) {
    emit('unauthorized')
    return true
  }
  return false
}

async function onSubmit(): Promise<void> {
  if (submitting.value) {
    return
  }
  submitting.value = true
  formError.value = null
  const input: SourceInput = {
    name: form.name,
    birthDate: optional(form.birthDate),
    deathDate: optional(form.deathDate),
    description: optional(form.description),
    linkUrl: optional(form.linkUrl),
  }
  try {
    if (editingId.value !== null) {
      await api.updateSource(editingId.value, input)
    } else {
      await api.createSource(input)
    }
    resetForm()
    emit('changed')
  } catch (error) {
    if (!handleUnauthorized(error)) {
      formError.value = apiErrorMessage(i18n, error)
    }
  } finally {
    submitting.value = false
  }
}

/**
 * First-pass delete without a strategy. On `SOURCE_HAS_QUOTES` the decision
 * dialog is opened; other errors are surfaced inline.
 */
async function onDelete(source: SourceRow): Promise<void> {
  if (deletingId.value !== null) {
    return
  }
  // eslint-disable-next-line no-alert
  if (typeof window !== 'undefined' && !window.confirm(i18n.t('admin.confirmDelete'))) {
    return
  }
  deletingId.value = source.id
  generalError.value = null
  try {
    await api.deleteSource(source.id)
    afterDelete(source.id)
  } catch (error) {
    if (handleUnauthorized(error)) {
      // handled
    } else if (error instanceof ApiError && error.code === 'SOURCE_HAS_QUOTES') {
      conflictSource.value = source
    } else {
      generalError.value = apiErrorMessage(i18n, error)
    }
  } finally {
    if (conflictSource.value === null) {
      deletingId.value = null
    }
  }
}

/** Re-issues the delete with the strategy chosen in the dialog (Req 11.6). */
async function onStrategy(strategy: SourceDeleteStrategy): Promise<void> {
  const source = conflictSource.value
  if (!source) {
    return
  }
  if (strategy === 'cancel') {
    // Load-abort: nothing changes server-side; just close the dialog (11.4).
    await api.deleteSource(source.id, 'cancel').catch(() => undefined)
    conflictSource.value = null
    deletingId.value = null
    return
  }
  try {
    await api.deleteSource(source.id, 'reassign')
    const removedId = source.id
    conflictSource.value = null
    afterDelete(removedId)
  } catch (error) {
    conflictSource.value = null
    if (!handleUnauthorized(error)) {
      generalError.value = apiErrorMessage(i18n, error)
    }
  } finally {
    deletingId.value = null
  }
}

function afterDelete(removedId: number): void {
  if (editingId.value === removedId) {
    resetForm()
  }
  emit('changed')
}
</script>

<template>
  <section class="source-manager">
    <form class="source-manager__form" @submit.prevent="onSubmit">
      <h3>{{ isEditing ? i18n.t('common.edit') : i18n.t('admin.newSource') }}</h3>

      <p v-if="formError" class="source-manager__error" role="alert">{{ formError }}</p>

      <label class="source-manager__field">
        <span>{{ i18n.t('admin.sourceName') }}</span>
        <LengthLimitedInput v-model="form.name" :max-length="MAX_SOURCE_NAME_LENGTH" />
      </label>

      <label class="source-manager__field">
        <span>{{ i18n.t('admin.sourceBirthDate') }}</span>
        <LengthLimitedInput
          v-model="form.birthDate"
          :max-length="MAX_SOURCE_OPTIONAL_FIELD_LENGTH"
        />
      </label>

      <label class="source-manager__field">
        <span>{{ i18n.t('admin.sourceDeathDate') }}</span>
        <LengthLimitedInput
          v-model="form.deathDate"
          :max-length="MAX_SOURCE_OPTIONAL_FIELD_LENGTH"
        />
      </label>

      <label class="source-manager__field">
        <span>{{ i18n.t('admin.sourceDescription') }}</span>
        <LengthLimitedInput
          v-model="form.description"
          :max-length="MAX_SOURCE_OPTIONAL_FIELD_LENGTH"
          multiline
        />
      </label>

      <label class="source-manager__field">
        <span>{{ i18n.t('admin.sourceLinkUrl') }}</span>
        <LengthLimitedInput
          v-model="form.linkUrl"
          :max-length="MAX_LINK_URL_LENGTH"
          type="url"
        />
      </label>

      <div class="source-manager__form-actions">
        <button type="submit" :disabled="submitting">
          {{
            submitting
              ? i18n.t('admin.saving')
              : isEditing
                ? i18n.t('admin.update')
                : i18n.t('admin.create')
          }}
        </button>
        <button v-if="isEditing" type="button" @click="startCreate">
          {{ i18n.t('common.cancel') }}
        </button>
      </div>
    </form>

    <p v-if="generalError" class="source-manager__error" role="alert">{{ generalError }}</p>

    <p v-if="sources.length === 0" class="source-manager__empty">
      {{ i18n.t('admin.emptySources') }}
    </p>

    <ul v-else class="source-manager__list">
      <li v-for="source in sources" :key="source.id" class="source-manager__item">
        <div class="source-manager__item-name">{{ source.name }}</div>
        <div class="source-manager__item-actions">
          <button type="button" @click="startEdit(source)">{{ i18n.t('common.edit') }}</button>
          <button type="button" :disabled="deletingId === source.id" @click="onDelete(source)">
            {{ deletingId === source.id ? i18n.t('admin.deleting') : i18n.t('common.delete') }}
          </button>
        </div>
      </li>
    </ul>

    <DeleteSourceDialog
      v-if="conflictSource"
      :source-name="conflictSource.name"
      :busy="deletingId !== null && conflictSource !== null && submitting"
      @choose="onStrategy"
    />
  </section>
</template>

<style scoped>
.source-manager__form,
.source-manager__field {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.source-manager__form {
  max-width: 40rem;
  margin-bottom: 1.5rem;
}

.source-manager__error {
  color: #b00020;
}

.source-manager__list {
  list-style: none;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.source-manager__item {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 1rem;
  border: 1px solid #ddd;
  border-radius: 0.375rem;
  padding: 0.5rem 0.75rem;
}

.source-manager__item-actions,
.source-manager__form-actions {
  display: flex;
  gap: 0.5rem;
}
</style>
