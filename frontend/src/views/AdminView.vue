<script setup lang="ts">
// Admin area orchestrator (Req 7-13, 18, 19). Gates the management UI behind a
// login and, once authenticated, loads the full source and language lists and
// renders the quote / source / language managers.
//
// Authentication is probed implicitly: there is no session-check endpoint, so
// the first protected list call (GET /api/sources, Req 19) doubles as the probe
// — a 401 drops back to the login form. Likewise, any `unauthorized` event a
// manager raises after its session expires returns the UI to the login gate
// (Req 18.6 / 19.6).
import { ref } from 'vue'
import { api, ApiError, type Language, type SourceListItem } from '@/api'
import { useI18n } from '@/i18n'
import AdminLogin from '@/components/admin/AdminLogin.vue'
import QuoteManager from '@/components/admin/QuoteManager.vue'
import SourceManager from '@/components/admin/SourceManager.vue'
import LanguageManager from '@/components/admin/LanguageManager.vue'

type Tab = 'quotes' | 'sources' | 'languages'

const i18n = useI18n()

const authenticated = ref(false)
const activeTab = ref<Tab>('quotes')
const sources = ref<SourceListItem[]>([])
const languages = ref<Language[]>([])
const loadError = ref<string | null>(null)
const quoteManager = ref<InstanceType<typeof QuoteManager> | null>(null)

/** Backend caps list pageSize at 100 (Req 19.1); pull pages until complete. */
const LIST_PAGE_SIZE = 100

/** Loads every source via the admin list endpoint (Req 19), paging as needed. */
async function loadSources(): Promise<void> {
  const collected: SourceListItem[] = []
  let page = 1
  let total = 0
  do {
    const res = await api.listSources({ page, pageSize: LIST_PAGE_SIZE })
    total = res.total
    collected.push(...res.items)
    if (res.items.length === 0) {
      break
    }
    page += 1
  } while (collected.length < total)
  sources.value = collected
}

async function loadLanguages(): Promise<void> {
  languages.value = await api.getLanguages()
}

/**
 * Loads the data the managers depend on. The source list is a protected call,
 * so a 401 here means the session is absent or expired and we fall back to the
 * login gate rather than surfacing an error.
 */
async function loadData(): Promise<boolean> {
  loadError.value = null
  try {
    await Promise.all([loadSources(), loadLanguages()])
    return true
  } catch (error) {
    if (error instanceof ApiError && error.isUnauthorized) {
      authenticated.value = false
      return false
    }
    loadError.value = i18n.t('admin.loadFailed')
    return true
  }
}

async function onAuthenticated(): Promise<void> {
  authenticated.value = true
  await loadData()
}

/** A manager reports its session is no longer valid: return to the login gate. */
function onUnauthorized(): void {
  authenticated.value = false
}

/** A source or language changed: refresh the shared lists and the quote list. */
async function onChanged(): Promise<void> {
  const ok = await loadData()
  if (ok) {
    await quoteManager.value?.reload()
  }
}

async function onLogout(): Promise<void> {
  await api.logout().catch(() => undefined)
  authenticated.value = false
  sources.value = []
  languages.value = []
}

// Probe the session on mount: if a valid cookie is present the lists load and
// the management UI is shown immediately; otherwise the login gate appears.
void (async () => {
  const ok = await loadData()
  if (ok && !loadError.value) {
    authenticated.value = true
  }
})()
</script>

<template>
  <section class="admin-view">
    <h1>{{ i18n.t('admin.heading') }}</h1>

    <AdminLogin v-if="!authenticated" @authenticated="onAuthenticated" />

    <template v-else>
      <div class="admin-view__bar">
        <span class="admin-view__status">{{ i18n.t('admin.signedInAs') }}</span>
        <button type="button" class="admin-view__logout" @click="onLogout">
          {{ i18n.t('admin.logout') }}
        </button>
      </div>

      <nav class="admin-view__tabs" :aria-label="i18n.t('admin.heading')">
        <button
          type="button"
          :class="{ 'admin-view__tab--active': activeTab === 'quotes' }"
          :aria-pressed="activeTab === 'quotes'"
          @click="activeTab = 'quotes'"
        >
          {{ i18n.t('admin.tabQuotes') }}
        </button>
        <button
          type="button"
          :class="{ 'admin-view__tab--active': activeTab === 'sources' }"
          :aria-pressed="activeTab === 'sources'"
          @click="activeTab = 'sources'"
        >
          {{ i18n.t('admin.tabSources') }}
        </button>
        <button
          type="button"
          :class="{ 'admin-view__tab--active': activeTab === 'languages' }"
          :aria-pressed="activeTab === 'languages'"
          @click="activeTab = 'languages'"
        >
          {{ i18n.t('admin.tabLanguages') }}
        </button>
      </nav>

      <p v-if="loadError" class="admin-view__error" role="alert">{{ loadError }}</p>

      <QuoteManager
        v-show="activeTab === 'quotes'"
        ref="quoteManager"
        :sources="sources"
        :languages="languages"
        @unauthorized="onUnauthorized"
      />

      <SourceManager
        v-show="activeTab === 'sources'"
        :sources="sources"
        @changed="onChanged"
        @unauthorized="onUnauthorized"
      />

      <LanguageManager
        v-show="activeTab === 'languages'"
        :languages="languages"
        @changed="onChanged"
        @unauthorized="onUnauthorized"
      />
    </template>
  </section>
</template>

<style scoped>
.admin-view {
  max-width: 48rem;
}

.admin-view__bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  margin-bottom: 1rem;
}

.admin-view__tabs {
  display: flex;
  gap: 0.5rem;
  margin-bottom: 1.5rem;
  border-bottom: 1px solid #ddd;
}

.admin-view__tabs button {
  border: none;
  background: none;
  padding: 0.5rem 0.75rem;
  cursor: pointer;
  border-bottom: 2px solid transparent;
}

.admin-view__tab--active {
  border-bottom-color: #333;
  font-weight: 600;
}

.admin-view__error {
  color: #b00020;
}
</style>
