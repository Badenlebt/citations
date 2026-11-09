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

<style scoped>
.language-manager__form,
.language-manager__field {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.language-manager__form {
  max-width: 28rem;
  margin-bottom: 1.5rem;
}

.language-manager__error {
  color: #b00020;
}

.language-manager__list {
  list-style: none;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}
</style>
