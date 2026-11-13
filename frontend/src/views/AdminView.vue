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
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api, ApiError, type Language, type SourceListItem } from '@/api'
import { useI18n } from '@/i18n'
import AdminLogin from '@/components/admin/AdminLogin.vue'
import QuoteManager from '@/components/admin/QuoteManager.vue'
import SourceManager from '@/components/admin/SourceManager.vue'
import LanguageManager from '@/components/admin/LanguageManager.vue'

type Tab = 'quotes' | 'sources' | 'languages'

const i18n = useI18n()
const route = useRoute()
const router = useRouter()

const TABS: readonly Tab[] = ['quotes', 'sources', 'languages']

function isTab(value: unknown): value is Tab {
  return typeof value === 'string' && (TABS as readonly string[]).includes(value)
}

const authenticated = ref(false)

// The active tab is derived from the `tab` query param so the footer (and any
// bookmark) can deep-link to a specific management section; it falls back to
// the quotes tab. Selecting a tab writes the param back to the URL.
const activeTab = computed<Tab>(() =>
  isTab(route.query.tab) ? route.query.tab : 'quotes',
)

function selectTab(tab: Tab): void {
  if (activeTab.value === tab) {
    return
  }
  void router.push({ name: route.name ?? undefined, query: { ...route.query, tab } })
}
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
          class="admin-view__tab"
          :class="{ 'admin-view__tab--active': activeTab === 'quotes' }"
          :aria-pressed="activeTab === 'quotes'"
          @click="selectTab('quotes')"
        >
          {{ i18n.t('admin.tabQuotes') }}
        </button>
        <button
          type="button"
          class="admin-view__tab"
          :class="{ 'admin-view__tab--active': activeTab === 'sources' }"
          :aria-pressed="activeTab === 'sources'"
          @click="selectTab('sources')"
        >
          {{ i18n.t('admin.tabSources') }}
        </button>
        <button
          type="button"
          class="admin-view__tab"
          :class="{ 'admin-view__tab--active': activeTab === 'languages' }"
          :aria-pressed="activeTab === 'languages'"
          @click="selectTab('languages')"
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

<style scoped lang="scss">
// Token-based styling for the admin view orchestrator (Task 6.5).
// Markup, BEM class names, props, logic, routes and i18n keys remain
// unchanged; only the presentation is driven by design tokens. Form, tab and
// table styling plus validation/error states are sourced from the token
// palette — either via build-checked SCSS variables (Req 1.5) or the
// :root-mirrored custom properties (Req 1.4); no hardcoded colors/spacing
// (Req 1.3, 2.2, 4.6, 5.5, 6.1, 6.5, 6.6, 8.1, 8.3).
@use '@/scss/variables' as tokens;
@use '@/scss/mixins' as mix;

.admin-view {
  max-width: 48rem;
}

.admin-view__bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--spacing-md, #{tokens.$spacing-md});
  margin-bottom: var(--spacing-md, #{tokens.$spacing-md});
}

.admin-view__tabs {
  display: flex;
  gap: var(--spacing-sm, #{tokens.$spacing-sm});
  margin-bottom: var(--spacing-lg, #{tokens.$spacing-lg});
  border-bottom: 1px solid var(--color-purple-medium, #{tokens.$color-purple-medium});
}

.admin-view__tab {
  border: none;
  background: none;
  // 0.75rem has no single token; composed from the spacing scale (sm + xs).
  padding: var(--spacing-sm, #{tokens.$spacing-sm})
    calc(
      var(--spacing-sm, #{tokens.$spacing-sm}) +
        var(--spacing-xs, #{tokens.$spacing-xs})
    );
  // Inaktive Reiter dezent (gedaempfte Sekundaerfarbe), damit der aktive Reiter
  // klar heraussticht.
  color: var(--color-purple-bright, #{tokens.$color-purple-bright});
  cursor: pointer;
  border-bottom: 2px solid transparent;
  border-radius: 0;
  font-weight: var(--font-weight-heading, #{tokens.$font-weight-heading});
  @include mix.transition(color, border-color);

  &:hover {
    color: var(--color-primary, #{tokens.$color-primary});
  }
}

.admin-view__tab--active {
  // Aktiver Reiter: Akzentfarbe fuer Text UND 2px-Unterstrich, klar sichtbar
  // gegenueber den gedaempften inaktiven Reitern.
  color: var(--color-primary, #{tokens.$color-primary});
  border-bottom-color: var(--color-primary, #{tokens.$color-primary});

  &:hover {
    color: var(--color-primary, #{tokens.$color-primary});
  }
}

.admin-view__error {
  // Status/error colour from the token palette (replaces #b00020).
  color: var(--color-error, #{tokens.$color-error});
}
</style>
