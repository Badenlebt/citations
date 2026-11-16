<script setup lang="ts">
// Startseite mit zufaelligem Zitat (Spec random-quote-homepage, Task 5.6).
// Zustandslogik liegt in useRandomQuote; die View stellt nur dar.
import { computed, onBeforeUnmount, onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import { api } from '@/api'
import { RouteName } from '@/router'
import { useI18n } from '@/i18n'
import { useRandomQuote } from '@/composables/useRandomQuote'

const i18n = useI18n()

const { quote, status, initialLoading, errorVisible, pending, reload, dispose } = useRandomQuote({
  client: api,
  locale: () => i18n.locale.value,
})

// Nachladen-Schaltflaeche: bei angezeigtem Zitat oder Fehler der ersten Anfrage
// (Req 4.3); im Leerzustand nie (Req 1.9).
const showReload = computed(() => quote.value !== null || status.value === 'error')
// Fehlermeldung genau einmal; im Leerzustand nicht (Req 4.2).
const showError = computed(() => errorVisible.value && status.value !== 'empty')

onMounted(() => {
  void reload()
})

onBeforeUnmount(() => {
  dispose()
})
</script>

<template>
  <section class="home-view">
    <h1>{{ i18n.t('home.heading') }}</h1>

    <!-- Immer gerenderte Live-Region (Req 2.4). -->
    <div class="home-view__live" aria-live="polite">
      <p v-if="initialLoading" class="home-view__status" role="status">
        {{ i18n.t('common.loading') }}
      </p>

      <figure v-else-if="quote" class="home-view__quote">
        <blockquote class="home-view__text">{{ quote.text }}</blockquote>
        <figcaption class="home-view__caption">
          <RouterLink
            class="home-view__link"
            :to="{ path: `/sources/${quote.source.id}` }"
          >
            {{ quote.source.name }}
          </RouterLink>
          <span class="home-view__sep"> · </span>
          <span class="home-view__language">{{ quote.languageName }}</span>
          <span class="home-view__sep"> · </span>
          <RouterLink class="home-view__link" :to="{ path: `/quotes/${quote.id}` }">
            {{ i18n.t('search.openQuote') }}
          </RouterLink>
        </figcaption>
      </figure>

      <p v-else-if="status === 'empty'" class="home-view__status">
        {{ i18n.t('home.empty') }}
      </p>

      <p v-if="showError" class="home-view__error" role="alert">
        {{ i18n.t('home.error') }}
      </p>
    </div>

    <p v-if="showReload" class="home-view__actions">
      <button type="button" class="home-view__reload" :disabled="pending" @click="reload()">
        {{ i18n.t('home.reload') }}
      </button>
    </p>

    <p class="home-view__search">
      <RouterLink class="home-view__link" :to="{ name: RouteName.Search }">
        {{ i18n.t('home.searchLink') }}
      </RouterLink>
    </p>
  </section>
</template>

<style scoped lang="scss">
@use '@/scss/variables' as tokens;
@use '@/scss/mixins' as mix;

.home-view {
  display: flex;
  flex-direction: column;
  gap: var(--spacing-md, #{tokens.$spacing-md});
  min-width: 0; // einspaltig, auch ab 320 px
}

.home-view__status {
  color: var(--color-text, #{tokens.$color-text});
}

.home-view__error {
  color: var(--color-text, #{tokens.$color-text});
  border-left: 3px solid var(--color-primary, #{tokens.$color-primary});
  padding-left: var(--spacing-md, #{tokens.$spacing-md});
  overflow-wrap: anywhere;
}

.home-view__quote {
  margin: 0;
  min-width: 0;
}

.home-view__text {
  font-size: var(--font-size-h5, #{tokens.$font-size-h5});
  margin: var(--spacing-md, #{tokens.$spacing-md}) 0;
  padding-left: var(--spacing-md, #{tokens.$spacing-md});
  border-left: 3px solid var(--color-primary, #{tokens.$color-primary});
  overflow-wrap: anywhere;
}

.home-view__caption {
  overflow-wrap: anywhere;
  color: var(--color-text, #{tokens.$color-text});
}

.home-view__link {
  color: var(--color-primary, #{tokens.$color-primary});
  @include mix.transition(color);

  &:hover {
    color: var(--color-purple-bright, #{tokens.$color-purple-bright});
  }

  &:focus-visible {
    outline: 2px solid var(--color-primary, #{tokens.$color-primary});
    outline-offset: 2px;
  }
}

.home-view__actions,
.home-view__search {
  margin: 0;
}

.home-view__reload {
  &:focus-visible {
    outline: 2px solid var(--color-primary, #{tokens.$color-primary});
    outline-offset: 2px;
  }

  &:disabled {
    cursor: not-allowed;
    opacity: 0.6;
  }
}
</style>
