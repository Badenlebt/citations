<script setup lang="ts">
// Language management (Req 12): create new languages and list existing ones.
// The API exposes creation and a public list only (no edit/delete), so this
// manager offers a length-limited create form (max 100, Req 14.5/14.6) plus a
// read-only listing.
import { ref } from 'vue'
import { api, ApiError } from '@/api'
import type { Language } from '@/api'
import { useI18n } from '@/i18n'
import { MAX_LANGUAGE_NAME_LENGTH } from '@/constants/fieldLimits'
import LengthLimitedInput from '@/components/LengthLimitedInput.vue'
import { apiErrorMessage } from './errorMessage'

const props = defineProps<{ languages: Language[] }>()

const emit = defineEmits<{
  (e: 'changed'): void
  (e: 'unauthorized'): void
}>()

const i18n = useI18n()

const name = ref('')
const formError = ref<string | null>(null)
const submitting = ref(false)

async function onSubmit(): Promise<void> {
  if (submitting.value) {
    return
  }
  submitting.value = true
  formError.value = null
  try {
    await api.createLanguage({ name: name.value })
    name.value = ''
    emit('changed')
  } catch (error) {
    if (error instanceof ApiError && error.isUnauthorized) {
      emit('unauthorized')
    } else {
      formError.value = apiErrorMessage(i18n, error)
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <section class="language-manager">
    <form class="language-manager__form" @submit.prevent="onSubmit">
      <h3>{{ i18n.t('admin.newLanguage') }}</h3>

      <p v-if="formError" class="language-manager__error" role="alert">{{ formError }}</p>

      <label class="language-manager__field">
        <span>{{ i18n.t('admin.languageName') }}</span>
        <LengthLimitedInput v-model="name" :max-length="MAX_LANGUAGE_NAME_LENGTH" />
      </label>

      <button type="submit" :disabled="submitting">
        {{ submitting ? i18n.t('admin.saving') : i18n.t('admin.create') }}
      </button>
    </form>

    <p v-if="props.languages.length === 0" class="language-manager__empty">
      {{ i18n.t('admin.emptyLanguages') }}
    </p>

    <ul v-else class="language-manager__list">
      <li v-for="language in props.languages" :key="language.id">{{ language.name }}</li>
    </ul>
  </section>
</template>

<style scoped lang="scss">
// Token-based styling for the language manager (Task 6.6).
// Markup, BEM class names, props, logic, routes and i18n keys remain
// unchanged; only the presentation is driven by design tokens. Form, list and
// error-state styling are sourced from the token palette — either via
// build-checked SCSS variables (Req 1.5) or the :root-mirrored custom
// properties (Req 1.4); no hardcoded colors/spacing (Req 2.2, 4.6, 5.5, 6.1,
// 6.6, 8.3). Error red (#b00020) is replaced by the status token.
@use '@/scss/variables' as tokens;

.language-manager__form,
.language-manager__field {
  display: flex;
  flex-direction: column;
  gap: var(--spacing-sm, #{tokens.$spacing-sm});
}

.language-manager__form {
  max-width: 28rem;
  margin-bottom: var(--spacing-lg, #{tokens.$spacing-lg});
}

.language-manager__error {
  // Status/error colour from the token palette (replaces #b00020).
  color: var(--color-error, #{tokens.$color-error});
}

.language-manager__list {
  list-style: none;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: var(--spacing-xs, #{tokens.$spacing-xs});
}
</style>
