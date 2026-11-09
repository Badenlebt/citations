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

<style scoped>
.admin-login {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  max-width: 24rem;
}

.admin-login__field {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}

.admin-login__error {
  color: #b00020;
}
</style>
