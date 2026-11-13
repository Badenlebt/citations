<script setup lang="ts">
// Quote management (Req 8, 9): create, edit and delete quotes. The quote text
// uses the length-limited input (max 5000, Req 14.5/14.6); source and language
// are chosen from the available lists. Omitting the source assigns the Unknown
// source server-side (Req 13.3), surfaced here as the "no source" option.
import { computed, reactive, ref } from 'vue'
import { api, ApiError } from '@/api'
import type { Language, QuoteInput } from '@/api'
import { useI18n } from '@/i18n'
import { MAX_QUOTE_TEXT_LENGTH } from '@/constants/fieldLimits'
import LengthLimitedInput from '@/components/LengthLimitedInput.vue'
import { apiErrorMessage } from './errorMessage'

/** A quote row as managed in the admin list. */
interface AdminQuote {
  id: number
  text: string
  languageId: number
  languageName: string
  sourceId: number
  sourceName: string
}

/** Minimal source reference for the quote form's source dropdown (Req 19.2). */
interface SourceOption {
  id: number
  name: string
}

const props = defineProps<{
  sources: SourceOption[]
  languages: Language[]
}>()

const emit = defineEmits<{ (e: 'unauthorized'): void }>()

const i18n = useI18n()

const quotes = ref<AdminQuote[]>([])
const loadError = ref<string | null>(null)
const formError = ref<string | null>(null)
const submitting = ref(false)
const deletingId = ref<number | null>(null)
const editingId = ref<number | null>(null)

const form = reactive<{ text: string; sourceId: number | null; languageId: number | null }>({
  text: '',
  sourceId: null,
  languageId: null,
})

const isEditing = computed(() => editingId.value !== null)

function sourceName(id: number): string {
  return props.sources.find((s) => s.id === id)?.name ?? ''
}

// Admin lists are loaded in large pages and shown as a single flat list. The
// backend caps `pageSize` at 100 (Req 18.1), so when more than one page exists
// we fetch successive pages until every quote has been collected.
const LIST_PAGE_SIZE = 100

/**
 * Loads all quotes via the dedicated admin list endpoint (GET /api/quotes,
 * Req 18). The endpoint requires a session; a 401 is surfaced to the parent via
 * the `unauthorized` emit so it can route back to the login view (Req 18.6).
 */
async function reload(): Promise<void> {
  loadError.value = null
  try {
    const collected: AdminQuote[] = []
    let page = 1
    let total = 0
    do {
      const res = await api.listQuotes({ page, pageSize: LIST_PAGE_SIZE })
      total = res.total
      for (const item of res.items) {
        collected.push({
          id: item.id,
          text: item.text,
          languageId: item.languageId,
          languageName: item.languageName,
          sourceId: item.source.id,
          sourceName: item.source.name,
        })
      }
      if (res.items.length === 0) {
        break
      }
      page += 1
    } while (collected.length < total)
    quotes.value = collected
  } catch (error) {
    if (!handleUnauthorized(error)) {
      loadError.value = apiErrorMessage(i18n, error)
    }
  }
}

function resetForm(): void {
  form.text = ''
  form.sourceId = null
  form.languageId = props.languages[0]?.id ?? null
  editingId.value = null
  formError.value = null
}

function startCreate(): void {
  resetForm()
}

function startEdit(quote: AdminQuote): void {
  editingId.value = quote.id
  form.text = quote.text
  form.sourceId = quote.sourceId
  form.languageId = quote.languageId
  formError.value = null
}

function handleUnauthorized(error: unknown): boolean {
  if (error instanceof ApiError && error.isUnauthorized) {
    emit('unauthorized')
    return true
  }
  return false
}

async function onSubmit(): Promise<void> {
  if (submitting.value || form.languageId === null) {
    return
  }
  submitting.value = true
  formError.value = null
  const input: QuoteInput = {
    text: form.text,
    languageId: form.languageId,
    sourceId: form.sourceId ?? undefined,
  }
  try {
    if (editingId.value !== null) {
      await api.updateQuote(editingId.value, input)
    } else {
      await api.createQuote(input)
    }
    resetForm()
    await reload()
  } catch (error) {
    if (!handleUnauthorized(error)) {
      formError.value = apiErrorMessage(i18n, error)
    }
  } finally {
    submitting.value = false
  }
}

async function onDelete(quote: AdminQuote): Promise<void> {
  if (deletingId.value !== null) {
    return
  }
  // eslint-disable-next-line no-alert
  if (typeof window !== 'undefined' && !window.confirm(i18n.t('admin.confirmDelete'))) {
    return
  }
  deletingId.value = quote.id
  try {
    await api.deleteQuote(quote.id)
    if (editingId.value === quote.id) {
      resetForm()
    }
    await reload()
  } catch (error) {
    if (!handleUnauthorized(error)) {
      loadError.value = apiErrorMessage(i18n, error)
    }
  } finally {
    deletingId.value = null
  }
}

defineExpose({ reload })

resetForm()
void reload()
</script>

<template>
  <section class="quote-manager">
    <form class="quote-manager__form" @submit.prevent="onSubmit">
      <h3>{{ isEditing ? i18n.t('common.edit') : i18n.t('admin.newQuote') }}</h3>

      <p v-if="formError" class="quote-manager__error" role="alert">{{ formError }}</p>

      <label class="quote-manager__field">
        <span>{{ i18n.t('admin.quoteText') }}</span>
        <LengthLimitedInput
          v-model="form.text"
          :max-length="MAX_QUOTE_TEXT_LENGTH"
          multiline
        />
      </label>

      <label class="quote-manager__field">
        <span>{{ i18n.t('admin.quoteSource') }}</span>
        <select v-model="form.sourceId">
          <option :value="null">{{ i18n.t('admin.noSourceSelected') }}</option>
          <option v-for="source in sources" :key="source.id" :value="source.id">
            {{ source.name }}
          </option>
        </select>
      </label>

      <label class="quote-manager__field">
        <span>{{ i18n.t('admin.quoteLanguage') }}</span>
        <select v-model="form.languageId">
          <option v-for="language in languages" :key="language.id" :value="language.id">
            {{ language.name }}
          </option>
        </select>
      </label>

      <div class="quote-manager__form-actions">
        <button type="submit" :disabled="submitting || form.languageId === null">
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

    <p v-if="loadError" class="quote-manager__error" role="alert">{{ loadError }}</p>

    <p v-if="quotes.length === 0 && !loadError" class="quote-manager__empty">
      {{ i18n.t('admin.emptyQuotes') }}
    </p>

    <ul v-else class="quote-manager__list">
      <li v-for="quote in quotes" :key="quote.id" class="quote-manager__item">
        <div class="quote-manager__item-text">{{ quote.text }}</div>
        <div class="quote-manager__item-meta">
          {{ quote.languageName }} · {{ quote.sourceName || sourceName(quote.sourceId) }}
        </div>
        <div class="quote-manager__item-actions">
          <button type="button" @click="startEdit(quote)">{{ i18n.t('common.edit') }}</button>
          <button type="button" :disabled="deletingId === quote.id" @click="onDelete(quote)">
            {{ deletingId === quote.id ? i18n.t('admin.deleting') : i18n.t('common.delete') }}
          </button>
        </div>
      </li>
    </ul>
  </section>
</template>

<style scoped lang="scss">
// Token-based styling for the quote manager (Task 6.6).
// Markup, BEM class names, props, logic, routes and i18n keys remain
// unchanged; only the presentation is driven by design tokens. Form, list and
// error-state styling are sourced from the token palette — either via
// build-checked SCSS variables (Req 1.5) or the :root-mirrored custom
// properties (Req 1.4); no hardcoded colors/spacing (Req 2.2, 4.6, 5.5, 6.1,
// 6.5, 6.6, 8.3). Grays (#ddd/#555) and error red (#b00020) are replaced.
@use '@/scss/variables' as tokens;
@use '@/scss/mixins' as mix;

.quote-manager__form,
.quote-manager__field {
  display: flex;
  flex-direction: column;
  gap: var(--spacing-sm, #{tokens.$spacing-sm});
}

.quote-manager__form {
  max-width: 40rem;
  margin-bottom: var(--spacing-lg, #{tokens.$spacing-lg});
}

.quote-manager__error {
  // Status/error colour from the token palette (replaces #b00020).
  color: var(--color-error, #{tokens.$color-error});
}

// Auswahlfelder (Quelle/Sprache) im dunklen Theme: gunmetal Flaeche, heller
// Text, purple-medium Rahmen — konsistent zum Suchfeld. Zuvor waren die
// <select> der Browser-Default (weisser Grund mit geerbtem weissen Text).
.quote-manager__field select {
  width: 100%;
  box-sizing: border-box;
  padding: var(--spacing-xs, #{tokens.$spacing-xs})
    var(--spacing-sm, #{tokens.$spacing-sm});
  color: var(--color-text, #{tokens.$color-text});
  background-color: var(--color-background, #{tokens.$color-background});
  border: 1px solid var(--color-purple-medium, #{tokens.$color-purple-medium});
  border-radius: var(--radius-sm, #{tokens.$radius-sm});
  font: inherit;
  cursor: pointer;

  @include mix.transition(border-color);

  &:hover {
    border-color: var(--color-primary, #{tokens.$color-primary});
  }

  &:focus-visible {
    outline: 2px solid var(--color-primary, #{tokens.$color-primary});
    outline-offset: 2px;
    border-color: var(--color-primary, #{tokens.$color-primary});
  }

  // Optionsliste ebenfalls im dunklen Theme.
  option {
    color: var(--color-text, #{tokens.$color-text});
    background-color: var(--color-background, #{tokens.$color-background});
  }
}

.quote-manager__list {
  list-style: none;
  padding: 0;
  display: flex;
  flex-direction: column;
  // 0.75rem has no single token; composed from the spacing scale (sm + xs).
  gap: calc(
    var(--spacing-sm, #{tokens.$spacing-sm}) +
      var(--spacing-xs, #{tokens.$spacing-xs})
  );
}

.quote-manager__item {
  // Subtle divider in the secondary purple token (replaces #ddd).
  border: 1px solid var(--color-purple-medium, #{tokens.$color-purple-medium});
  border-radius: var(--radius-sm, #{tokens.$radius-sm});
  // 0.75rem has no single token; composed from the spacing scale (sm + xs).
  padding: calc(
    var(--spacing-sm, #{tokens.$spacing-sm}) +
      var(--spacing-xs, #{tokens.$spacing-xs})
  );
}

.quote-manager__item-meta {
  font-size: 0.85em;
  // Muted secondary text via the purple-bright token (replaces #555).
  color: var(--color-purple-bright, #{tokens.$color-purple-bright});
  margin: var(--spacing-xs, #{tokens.$spacing-xs}) 0;
}

.quote-manager__item-actions,
.quote-manager__form-actions {
  display: flex;
  gap: var(--spacing-sm, #{tokens.$spacing-sm});
}
</style>
