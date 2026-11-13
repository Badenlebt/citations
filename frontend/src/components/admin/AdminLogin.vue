<script setup lang="ts">
// Login form for the admin area (Req 6, 7). On success the API sets an
// HttpOnly session cookie; this component merely signals success so the parent
// can reveal the management UI. Credentials never leave the request body.
import { ref } from 'vue'
import { api } from '@/api'
import { useI18n } from '@/i18n'
import { apiErrorMessage } from './errorMessage'

const emit = defineEmits<{ (e: 'authenticated'): void }>()

const i18n = useI18n()

const username = ref('')
const password = ref('')
const submitting = ref(false)
const errorMessage = ref<string | null>(null)

async function onSubmit(): Promise<void> {
  if (submitting.value) {
    return
  }
  submitting.value = true
  errorMessage.value = null
  try {
    await api.login({ username: username.value, password: password.value })
    password.value = ''
    emit('authenticated')
  } catch (error) {
    errorMessage.value = apiErrorMessage(i18n, error)
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <form class="admin-login" @submit.prevent="onSubmit">
    <h2 class="admin-login__heading">{{ i18n.t('admin.loginHeading') }}</h2>

    <p v-if="errorMessage" class="admin-login__error" role="alert">
      {{ errorMessage }}
    </p>

    <label class="admin-login__field">
      <span>{{ i18n.t('admin.username') }}</span>
      <input
        v-model="username"
        type="text"
        name="username"
        autocomplete="username"
        :disabled="submitting"
      />
    </label>

    <label class="admin-login__field">
      <span>{{ i18n.t('admin.password') }}</span>
      <input
        v-model="password"
        type="password"
        name="password"
        autocomplete="current-password"
        :disabled="submitting"
      />
    </label>

    <button type="submit" :disabled="submitting">
      {{ submitting ? i18n.t('admin.loggingIn') : i18n.t('admin.login') }}
    </button>
  </form>
</template>

<style scoped lang="scss">
// Token-based styling for the admin login form (Task 6.6, Feinschliff).
// Markup, BEM class names, props, logic, routes and i18n keys remain
// unchanged; only the presentation is driven by design tokens. Form layout,
// the input fields and the validation/error state are sourced from the token
// palette — either via build-checked SCSS variables (Req 1.5) or the
// :root-mirrored custom properties (Req 1.4); no hardcoded colors/spacing
// (Req 2.2, 6.1, 6.6, 8.3).
@use '@/scss/variables' as tokens;
@use '@/scss/mixins' as mix;

.admin-login {
  display: flex;
  flex-direction: column;
  // 0.75rem has no single token; composed from the spacing scale (sm + xs).
  gap: calc(
    var(--spacing-sm, #{tokens.$spacing-sm}) +
      var(--spacing-xs, #{tokens.$spacing-xs})
  );
  max-width: 24rem;
}

.admin-login__field {
  display: flex;
  flex-direction: column;
  gap: var(--spacing-xs, #{tokens.$spacing-xs});
}

// Eingabefelder im dunklen Theme: gunmetal Flaeche, heller Text, purple-medium
// Rahmen — identisch zum Suchfeld-Muster. Zuvor waren die Felder der
// Browser-Default (weisser Grund mit geerbtem weissen Text, unlesbar).
.admin-login__field input {
  width: 100%;
  box-sizing: border-box;
  padding: var(--spacing-xs, #{tokens.$spacing-xs})
    var(--spacing-sm, #{tokens.$spacing-sm});
  color: var(--color-text, #{tokens.$color-text});
  background-color: var(--color-background, #{tokens.$color-background});
  border: 1px solid var(--color-purple-medium, #{tokens.$color-purple-medium});
  border-radius: var(--radius-sm, #{tokens.$radius-sm});
  font: inherit;

  @include mix.transition(border-color, background-color);

  &::placeholder {
    color: var(--color-purple-bright, #{tokens.$color-purple-bright});
  }

  &:hover:not(:disabled) {
    border-color: var(--color-primary, #{tokens.$color-primary});
  }

  // Sichtbarer, token-basierter Fokusindikator (Kontrast >= 3:1, Req 9.4).
  &:focus-visible {
    outline: 2px solid var(--color-primary, #{tokens.$color-primary});
    outline-offset: 2px;
    border-color: var(--color-primary, #{tokens.$color-primary});
  }

  &:disabled {
    cursor: not-allowed;
    opacity: 0.6;
  }
}

.admin-login__error {
  // Status/error colour from the token palette (replaces #b00020).
  color: var(--color-error, #{tokens.$color-error});
}
</style>
