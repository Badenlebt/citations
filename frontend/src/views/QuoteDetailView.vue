<script setup lang="ts">
// Quote single view (Req 4). Fetches a single quote by id and renders its text,
// language and source citation. The source name links to the source page
// (Req 4.2). When the source carries a non-empty link URL, the citation is
// additionally shown as an external reference (Req 4.3); otherwise no external
// link is rendered (Req 4.4).
import { ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { api, ApiError, type QuoteDetail } from '@/api'
import { RouteName } from '@/router'
import { useI18n } from '@/i18n'

const props = defineProps<{ id: string }>()

const i18n = useI18n()

const quote = ref<QuoteDetail | null>(null)
const loading = ref(false)
const notFound = ref(false)
const failed = ref(false)

let activeController: AbortController | null = null

async function load(rawId: string): Promise<void> {
  // Reset view state for the new id.
  quote.value = null
  notFound.value = false
  failed.value = false

  const id = Number(rawId)
  if (!Number.isInteger(id) || id <= 0) {
    notFound.value = true
    return
  }

  activeController?.abort()
  const controller = new AbortController()
  activeController = controller
  loading.value = true
  try {
    quote.value = await api.getQuote(id, controller.signal)
  } catch (error) {
    if (controller.signal.aborted) {
      return
    }
    if (error instanceof ApiError && error.isNotFound) {
      notFound.value = true
    } else {
      failed.value = true
    }
  } finally {
    if (!controller.signal.aborted) {
      loading.value = false
    }
  }
}

watch(() => props.id, load, { immediate: true })
</script>

<template>
  <section class="quote-detail-view">
    <h1>{{ i18n.t('quote.heading') }}</h1>

    <p v-if="loading" class="quote-detail-view__status">
      {{ i18n.t('common.loading') }}
    </p>

    <p v-else-if="notFound" class="quote-detail-view__status">
      {{ i18n.t('quote.notFound') }}
    </p>

    <p v-else-if="failed" class="quote-detail-view__status">
      {{ i18n.t('common.error') }}
    </p>

    <article v-else-if="quote" class="quote-detail-view__content">
      <blockquote class="quote-detail-view__text">{{ quote.text }}</blockquote>

      <dl class="quote-detail-view__meta">
        <dt>{{ i18n.t('quote.source') }}</dt>
        <dd>
          <!-- Source name as a link to the source page (Req 4.2). -->
          <RouterLink
            class="quote-detail-view__source-link"
            :to="{ name: RouteName.SourceDetail, params: { id: String(quote.source.id) } }"
          >
            {{ quote.source.name }}
          </RouterLink>
          <!-- Optional external link only when a link URL is present (Req 4.3/4.4). -->
          <template v-if="quote.source.linkUrl">
            <span class="quote-detail-view__sep"> · </span>
            <a
              class="quote-detail-view__external-link"
              :href="quote.source.linkUrl"
              target="_blank"
              rel="noopener noreferrer"
            >
              {{ i18n.t('quote.externalLink') }}
            </a>
          </template>
        </dd>

        <dt>{{ i18n.t('quote.language') }}</dt>
        <dd>{{ quote.languageName }}</dd>
      </dl>
    </article>

    <p class="quote-detail-view__back">
      <RouterLink :to="{ name: RouteName.Search }">
        {{ i18n.t('quote.backToSearch') }}
      </RouterLink>
    </p>
  </section>
</template>

<style scoped>
.quote-detail-view__text {
  font-size: 1.25rem;
  margin: 1rem 0;
  padding-left: 1rem;
  border-left: 3px solid #ccc;
}

.quote-detail-view__meta {
  display: grid;
  grid-template-columns: max-content 1fr;
  gap: 0.25rem 1rem;
}

.quote-detail-view__meta dt {
  font-weight: 600;
}

.quote-detail-view__back {
  margin-top: 1.5rem;
}
</style>
