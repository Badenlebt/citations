import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createRouter, createMemoryHistory, type Router } from 'vue-router'
import AppHeader from './AppHeader.vue'
import { routes } from '@/router'
import { createI18n, installI18n, type I18n } from '@/i18n'

// In-memory localStorage stub so LanguageSwitcher's setLocale never throws.
function stubStorage(): void {
  const store = new Map<string, string>()
  vi.stubGlobal('localStorage', {
    getItem: (key: string) => store.get(key) ?? null,
    setItem: (key: string, value: string) => {
      store.set(key, value)
    },
    removeItem: (key: string) => {
      store.delete(key)
    },
    clear: () => {
      store.clear()
    },
  })
}

function makeRouter(): Router {
  return createRouter({ history: createMemoryHistory(), routes })
}

async function mountHeader(i18n: I18n, router: Router, path = '/') {
  await router.push(path)
  await router.isReady()
  const wrapper = mount(AppHeader, {
    global: {
      plugins: [router, (app) => installI18n(app, i18n)],
    },
  })
  await wrapper.vm.$nextTick()
  return wrapper
}

describe('AppHeader', () => {
  beforeEach(() => {
    stubStorage()
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('renders the brand title and both navigation links (Req 4.1)', async () => {
    const router = makeRouter()
    const wrapper = await mountHeader(createI18n('de'), router, '/')

    expect(wrapper.get('.app-header__title').text()).toBe('Zitate')

    const links = wrapper.findAll('.app-header__nav-link')
    expect(links.map((l) => l.text())).toEqual(['Suche', 'Verwaltung'])
  })

  it('links navigation to the Search and Admin routes', async () => {
    const router = makeRouter()
    const wrapper = await mountHeader(createI18n('en'), router, '/')

    const hrefs = wrapper
      .findAll('a.app-header__nav-link')
      .map((a) => a.attributes('href'))
    expect(hrefs).toEqual(['/', '/admin'])
  })

  it('marks the active route via router-link-exact-active (Req 6.3)', async () => {
    const router = makeRouter()
    const wrapper = await mountHeader(createI18n('de'), router, '/admin')

    const adminLink = wrapper
      .findAll('a.app-header__nav-link')
      .find((a) => a.attributes('href') === '/admin')
    expect(adminLink?.classes()).toContain('router-link-exact-active')

    const searchLink = wrapper
      .findAll('a.app-header__nav-link')
      .find((a) => a.attributes('href') === '/')
    expect(searchLink?.classes()).not.toContain('router-link-exact-active')
  })

  it('renders the LanguageSwitcher', async () => {
    const router = makeRouter()
    const wrapper = await mountHeader(createI18n('de'), router, '/')

    expect(wrapper.find('.language-switcher').exists()).toBe(true)
  })
})
