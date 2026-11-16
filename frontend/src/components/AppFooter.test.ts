import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { createRouter, createMemoryHistory } from 'vue-router'
import AppFooter from './AppFooter.vue'
import { routes } from '@/router'
import { createI18n, installI18n } from '@/i18n'

// Validates: Requirement 5.10
describe('AppFooter random quote link', () => {
  it("points search.randomQuote to '/'", async () => {
    const i18n = createI18n('de')
    const router = createRouter({ history: createMemoryHistory(), routes })
    await router.push('/search')
    await router.isReady()
    const wrapper = mount(AppFooter, {
      global: { plugins: [router, (app) => installI18n(app, i18n)] },
    })
    const link = wrapper
      .findAll('a.app-footer__link')
      .find((a) => a.text() === i18n.t('search.randomQuote'))
    expect(link).toBeDefined()
    expect(link!.attributes('href')).toBe('/')
  })
})
