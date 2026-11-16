<script setup lang="ts">
// UI language mapping (Req 9.9-9.12): one select per UI language that assigns
// the quote language the home page prefers. Lives in its own admin tab.
import { reactive, ref, watch } from 'vue'
import { api, ApiError } from '@/api'
import type { Language, LanguageMappings } from '@/api'
import type { Locale } from '@/i18n/locales'
import { useI18n } from '@/i18n'
import { apiErrorMessage } from './errorMessage'

const props = withDefaults(
  defineProps<{ languages: Language[]; mappings?: LanguageMappings }>(),
  { mappings: () => ({ de: null, en: null }) },
)

const emit = defineEmits<{
  (e: 'changed'): void
  (e: 'unauthorized'): void
}>()

const i18n = useI18n()

const MAPPING_LOCALES: readonly { locale: Locale; labelKey: string }[] = [
  { locale: 'de', labelKey: 'admin.languageMappingsUiDe' },
  { locale: 'en', labelKey: 'admin.languageMappingsUiEn' },
]

function toValue(id: number | null | undefined): string {
  return id === null || id === undefined ? '' : String(id)
}

const selected = reactive<Record<Locale, string>>({
  de: toValue(props.mappings.de),
  en: toValue(props.mappings.en),
})
const mappingError = ref<string | null>(null)
const mappingSaved = ref(false)

// Keep the selection in sync with the last loaded (saved) mappings.
watch(
  () => props.mappings,
  (m) => {
    selected.de = toValue(m.de)
    selected.en = toValue(m.en)
  },
  { deep: true },
)

async function onMappingChange(locale: Locale): Promise<void> {
  const value = selected[locale]
  mappingError.value = null
  mappingSaved.value = false
  try {
    if (value === '') {
      await api.removeLanguageMapping(locale)
    } else {
      await api.setLanguageMapping(locale, Number(value))
    }
    mappingSaved.value = true
    emit('changed')
  } catch (error) {
    // Reset to the last saved value.
    selected[locale] = toValue(props.mappings[locale])
    if (error instanceof ApiError && error.isUnauthorized) {
      emit('unauthorized')
    } else {
      mappingError.value = apiErrorMessage(i18n, error)
    }
  }
}
</script>

<template>
  <section class="language-mapping-manager">
    <h3>{{ i18n.t('admin.languageMappingsHeading') }}</h3>
    <p class="language-mapping-manager__hint">{{ i18n.t('admin.languageMappingsHint') }}</p>

    <p v-if="mappingError" class="language-mapping-manager__error" role="alert">
      {{ mappingError }}
    </p>
    <p v-if="mappingSaved" class="language-mapping-manager__status" role="status">
      {{ i18n.t('admin.languageMappingsSaved') }}
    </p>

    <label
      v-for="entry in MAPPING_LOCALES"
      :key="entry.locale"
      class="language-mapping-manager__field"
    >
      <span>{{ i18n.t(entry.labelKey) }}</span>
      <select
        v-model="selected[entry.locale]"
        :data-testid="`mapping-${entry.locale}`"
        @change="onMappingChange(entry.locale)"
      >
        <option value="">{{ i18n.t('admin.languageMappingsNone') }}</option>
        <option
          v-for="language in props.languages"
          :key="language.id"
          :value="String(language.id)"
        >
          {{ language.name }}
        </option>
      </select>
    </label>
  </section>
</template>

<style scoped lang="scss">
// Token-based styling; no hardcoded colors/spacing (Req 9.12, 7.1).
@use '@/scss/variables' as tokens;
@use '@/scss/mixins' as mix;

.language-mapping-manager {
  display: flex;
  flex-direction: column;
  gap: var(--spacing-sm, #{tokens.$spacing-sm});
  max-width: 28rem;
}

.language-mapping-manager__field {
  display: flex;
  flex-direction: column;
  gap: var(--spacing-sm, #{tokens.$spacing-sm});
}

.language-mapping-manager__error {
  color: var(--color-error, #{tokens.$color-error});
}

.language-mapping-manager__status {
  color: var(--color-success, #{tokens.$color-success});
}

// Dunkles Theme wie bei den Auswahlfeldern der Zitatverwaltung: gunmetal
// Flaeche, heller Text, purple-medium Rahmen (statt weissem Browser-Default).
.language-mapping-manager__field select {
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

  option {
    color: var(--color-text, #{tokens.$color-text});
    background-color: var(--color-background, #{tokens.$color-background});
  }
}
</style>
