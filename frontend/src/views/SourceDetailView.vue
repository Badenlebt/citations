<script setup lang="ts">
// Source single view (Req 5). Fetches a source by id and renders its details,
// treating unset optional fields as empty (Req 5.1/5.2). Provides a link to a
// source-filtered, paginated search listing only this source's quotes (Req 5.3).
import { ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { api, ApiError, type SourceDetail } from '@/api'
import { RouteName } from '@/router'
import { useI18n } from '@/i18n'

const props = defineProps<{ id: string }>()

const i18n = useI18n()

const source = ref<SourceDetail | null>(null)
const loading = ref(false)
const notFound = ref(false)
const failed = ref(false)

let activeController: AbortController | null = null

async function load(rawId: string): Promise<void> {
  source.value = null
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
    source.value = await api.getSource(id, controller.signal)
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
  <section class="source-detail-view">
    <h1>{{ i18n.t('source.heading') }}</h1>

    <p v-if="loading" class="source-detail-view__status">
      {{ i18n.t('common.loading') }}
    </p>

    <p v-else-if="notFound" class="source-detail-view__status">
      {{ i18n.t('source.notFound') }}
    </p>

    <p v-else-if="failed" class="source-detail-view__status">
      {{ i18n.t('common.error') }}
    </p>

    <article v-else-if="source" class="source-detail-view__content">
      <h2 class="source-detail-view__name">{{ source.name }}</h2>

      <dl class="source-detail-view__meta">
        <template v-if="source.birthDate">
          <dt>{{ i18n.t('source.birthDate') }}</dt>
          <dd>{{ source.birthDate }}</dd>
        </template>
        <template v-if="source.deathDate">
          <dt>{{ i18n.t('source.deathDate') }}</dt>
          <dd>{{ source.deathDate }}</dd>
        </template>
        <template v-if="source.description">
          <dt>{{ i18n.t('source.description') }}</dt>
          <dd>{{ source.description }}</dd>
        </template>
        <template v-if="source.linkUrl">
          <dt>{{ i18n.t('source.link') }}</dt>
          <dd>
            <a
              class="source-detail-view__external-link"
              :href="source.linkUrl"
              target="_blank"
              rel="noopener noreferrer"
            >
              {{ source.linkUrl }}
            </a>
          </dd>
        </template>
      </dl>

      <!-- Link into a source-filtered, paginated search (Req 5.3). The source
           id travels as a query param the search page reads. -->
      <p class="source-detail-view__quotes-link">
        <RouterLink
          :to="{ name: RouteName.Search, query: { sourceId: String(source.id) } }"
        >
          {{ i18n.t('source.viewQuotes') }}
        </RouterLink>
      </p>
    </article>

    <p class="source-detail-view__back">
      <RouterLink :to="{ name: RouteName.Search }">
        {{ i18n.t('source.backToSearch') }}
      </RouterLink>
    </p>
  </section>
</template>

<style scoped>
.source-detail-view__meta {
  display: grid;
  grid-template-columns: max-content 1fr;
  gap: 0.25rem 1rem;
}

.source-detail-view__meta dt {
  font-weight: 600;
}

.source-detail-view__quotes-link {
  margin-top: 1rem;
}

.source-detail-view__back {
  margin-top: 1.5rem;
}
</style>
