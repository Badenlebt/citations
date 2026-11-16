import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { createRouter, createMemoryHistory } from 'vue-router'
import AppHeader from './AppHeader.vue'
import { routes } from '@/router'
import { createI18n, installI18n } from '@/i18n'

// Validates: Requirements 5.4, 5.5, 5.6
async function activeLinks(path: string) {
  const router = createRouter({ history: createMemoryHistory(), routes })
  await router.push(path)
  await router.isReady()
  const wrapper = mount(AppHeader, {
    global: { plugins: [router, (app) => installI18n(app, createI18n('de'))] },
  })
  await wrapper.vm.$nextTick()
  const links = wrapper.findAll('.app-header__nav-link')
  return links
    .filter((l) => l.classes().includes('router-link-exact-active'))
    .map((l) => l.attributes('href'))
}

describe('AppHeader active entry', () => {
  it("marks exactly the Start entry at '/'", async () => {
    expect(await activeLinks('/')).toEqual(['/'])
  })

  it("marks exactly the Search entry at '/search'", async () => {
    expect(await activeLinks('/search')).toEqual(['/search'])
  })
})
