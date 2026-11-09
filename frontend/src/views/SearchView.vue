<script setup lang="ts">
// Search page (Req 1, 2, 5.3). Provides a length-limited search input, a
// language filter fetched from GET /api/languages, pagination controls and an
// optional source filter driven by the `sourceId` query param so the source
// page can link into a source-filtered, paginated search (Req 5.3).
//
// The route query is the single source of truth for q / page / languages /
// sourceId: the form writes to the query, and a watcher re-runs the search
// whenever the query changes. This keeps results bookmarkable and makes deep
// links (e.g. from a source page) work on first load.
import { computed, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter, type LocationQueryRaw } from 'vue-router'
import {
  api,
  ApiError,
  type Language,
  type QuoteListItem,
  type SearchResponse,
} from '@/api'
import { RouteName } from '@/router'
import { useI18n } from '@/i18n'
import LengthLimitedInput from '@/components/LengthLimitedInput.vue'
import { MAX_SEARCH_QUERY_LENGTH } from '@/constants/fieldLimits'

const DEFAULT_PAGE_SIZE = 20

const i18n = useI18n()
const route = useRoute()
const router = useRouter()

// --- Form state (edited by the user, committed to the URL on submit) --------
const queryInput = ref('')
const selectedLanguageId = ref<number | ''>('')

// --- Result + request state -------------------------------------------------
const results = ref<SearchResponse | null>(null)
const languages = ref<Language[]>([])
const loading = ref(false)
const failed = ref(false)
const emptyQuery = ref(false)

// --- Derived values from the route query ------------------------------------
function parsePositiveInt(value: unknown, fallback: number): number {
  const n = Number(Array.isArray(value) ? value[0] : value)
  return Number.isInteger(n) && n > 0 ? n : fallback
}

const activeQuery = computed(() => {
  const q = route.query.q
  return typeof q === 'string' ? q : ''
})

const activePage = computed(() => parsePositiveInt(route.query.page, 1))

const activeSourceId = computed(() => {
  const raw = route.query.sourceId
  const n = Number(Array.isArray(raw) ? raw[0] : raw)
  return Number.isInteger(n) && n > 0 ? n : undefined
})

const activeLanguages = computed<number[]>(() => {
  const raw = route.query.languages
  const value = Array.isArray(raw) ? raw[0] : raw
  if (typeof value !== 'string' || value.length === 0) {
    return []
  }
  return value
    .split(',')
    .map((part) => Number(part))
    .filter((n) => Number.isInteger(n) && n > 0)
})

const totalPages = computed(() => {
  const r = results.value
  if (!r || r.pageSize <= 0) {
    return 0
  }
  return Math.max(1, Math.ceil(r.total / r.pageSize))
})

const pageInfo = computed(() =>
  i18n
    .t('search.pageInfo')
    .replace('{page}', String(results.value?.page ?? activePage.value))
    .replace('{pages}', String(totalPages.value)),
)

const hasPrevious = computed(() => (results.value?.page ?? 1) > 1)
const hasNext = computed(() => (results.value?.page ?? 1) < totalPages.value)

// --- URL navigation helpers -------------------------------------------------
function buildQuery(overrides: Partial<{
  q: string
  page: number
  languages: number[]
  sourceId: number | undefined
}>): LocationQueryRaw {
  const q = overrides.q ?? activeQuery.value
  const page = overrides.page ?? 1
  const langs = overrides.languages ?? activeLanguages.value
  const sourceId =
    'sourceId' in overrides ? overrides.sourceId : activeSourceId.value

  const query: LocationQueryRaw = {}
  if (q.length > 0) {
    query.q = q
  }
  if (page > 1) {
    query.page = String(page)
  }
  if (langs.length > 0) {
    query.languages = langs.join(',')
  }
  if (sourceId !== undefined) {
    query.sourceId = String(sourceId)
  }
  return query
}

function submitSearch(): void {
  const trimmed = queryInput.value.trim()
  if (trimmed.length === 0) {
    emptyQuery.value = true
    return
  }
  emptyQuery.value = false
  const langs = selectedLanguageId.value === '' ? [] : [selectedLanguageId.value]
  router.push({
    name: RouteName.Search,
    query: buildQuery({ q: queryInput.value, page: 1, languages: langs }),
  })
}

function goToPage(page: number): void {
  if (page < 1) {
    return
  }
  router.push({ name: RouteName.Search, query: buildQuery({ page }) })
}

function clearSourceFilter(): void {
  router.push({
    name: RouteName.Search,
    query: buildQuery({ page: 1, sourceId: undefined }),
  })
}

// --- Data loading -----------------------------------------------------------
let searchController: AbortController | null = null

async function runSearch(): Promise<void> {
  const q = activeQuery.value
  // No query committed yet: show the empty-ish initial state without an error.
  if (q.trim().length === 0) {
    results.value = null
    failed.value = false
    loading.value = false
    return
  }

  searchController?.abort()
  const controller = new AbortController()
  searchController = controller
  loading.value = true
  failed.value = false
  try {
    results.value = await api.search(
      {
        q,
        languages: activeLanguages.value.length ? activeLanguages.value : undefined,
        page: activePage.value,
        pageSize: DEFAULT_PAGE_SIZE,
        sourceId: activeSourceId.value,
      },
      controller.signal,
    )
  } catch (error) {
    if (controller.signal.aborted) {
      return
    }
    results.value = null
    // An empty / whitespace query is guarded client-side; any other failure is
    // surfaced generically.
    if (error instanceof ApiError && error.code === 'MISSING_QUERY') {
      emptyQuery.value = true
    } else {
      failed.value = true
    }
  } finally {
    if (!controller.signal.aborted) {
      loading.value = false
    }
  }
}

async function loadLanguages(): Promise<void> {
  try {
    languages.value = await api.getLanguages()
  } catch {
    // The language filter is optional chrome; a failure here must not break
    // searching, so it is swallowed and the filter simply stays empty.
    languages.value = []
  }
}

// Keep the form fields in sync with the committed query (e.g. on deep links,
// back/forward navigation, or clearing the source filter).
watch(
  () => route.query,
  () => {
    queryInput.value = activeQuery.value
    const langs = activeLanguages.value
    selectedLanguageId.value = langs.length === 1 ? langs[0] : ''
    emptyQuery.value = false
    void runSearch()
  },
  { immediate: true, deep: true },
)

void loadLanguages()
</script>

<template>
  <section class="search-view">
    <h1>{{ i18n.t('search.heading') }}</h1>

    <form class="search-view__form" @submit.prevent="submitSearch">
      <label class="search-view__field" for="search-query">
        <span>{{ i18n.t('search.inputLabel') }}</span>
        <LengthLimitedInput
          id="search-query"
          v-model="queryInput"
          :max-length="MAX_SEARCH_QUERY_LENGTH"
          :placeholder="i18n.t('search.inputPlaceholder')"
        />
      </label>

      <label class="search-view__field" for="search-language">
        <span>{{ i18n.t('search.filterLanguages') }}</span>
        <select id="search-language" v-model="selectedLanguageId">
          <option value="">{{ i18n.t('search.filterAllLanguages') }}</option>
          <option v-for="lang in languages" :key="lang.id" :value="lang.id">
            {{ lang.name }}
          </option>
        </select>
      </label>

      <button type="submit" class="search-view__submit">
        {{ i18n.t('search.submit') }}
      </button>
    </form>

    <p v-if="activeSourceId !== undefined" class="search-view__source-filter">
      <span>{{ i18n.t('search.sourceFilterActive') }}</span>
      <button type="button" @click="clearSourceFilter">
        {{ i18n.t('search.clearSourceFilter') }}
      </button>
    </p>

    <p v-if="emptyQuery" class="search-view__status search-view__status--error">
      {{ i18n.t('search.emptyQuery') }}
    </p>

    <p v-if="loading" class="search-view__status">
      {{ i18n.t('common.loading') }}
    </p>

    <p v-else-if="failed" class="search-view__status search-view__status--error">
      {{ i18n.t('common.error') }}
    </p>

    <template v-else-if="results">
      <p class="search-view__count">
        {{ results.total }} {{ i18n.t('search.resultsCount') }}
      </p>

      <p v-if="results.items.length === 0" class="search-view__status">
        {{ i18n.t('search.noResults') }}
      </p>

      <ul v-else class="search-view__results">
        <li
          v-for="item in results.items"
          :key="item.id"
          class="search-view__result"
        >
          <RouterLink
            class="search-view__result-link"
            :to="{ name: RouteName.QuoteDetail, params: { id: String(item.id) } }"
          >
            {{ item.text }}
          </RouterLink>
          <span class="search-view__result-meta">
            — {{ item.source.name }} ({{ item.languageName }})
          </span>
        </li>
      </ul>

      <nav
        v-if="totalPages > 1"
        class="search-view__pagination"
        :aria-label="i18n.t('search.heading')"
      >
        <button
          type="button"
          :disabled="!hasPrevious"
          @click="goToPage((results?.page ?? 1) - 1)"
        >
          {{ i18n.t('search.previousPage') }}
        </button>
        <span class="search-view__page-info">{{ pageInfo }}</span>
        <button
          type="button"
          :disabled="!hasNext"
          @click="goToPage((results?.page ?? 1) + 1)"
        >
          {{ i18n.t('search.nextPage') }}
        </button>
      </nav>
    </template>
  </section>
</template>

<style scoped>
.search-view__form {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: 1rem;
  margin-bottom: 1rem;
}

.search-view__field {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}

.search-view__source-filter {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.search-view__status--error {
  color: #b00020;
}

.search-view__results {
  list-style: none;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.search-view__result-meta {
  color: #666;
  font-size: 0.9em;
}

.search-view__pagination {
  display: flex;
  align-items: center;
  gap: 1rem;
  margin-top: 1rem;
}
</style>
