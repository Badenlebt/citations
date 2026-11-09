import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import HelloCitations from './HelloCitations.vue'

// Smoke test proving the Vitest component runner is wired up.
// Replaced by real component tests in later tasks.
describe('HelloCitations', () => {
  it('renders the given title', () => {
    const wrapper = mount(HelloCitations, { props: { title: 'Zitate' } })
    expect(wrapper.text()).toContain('Zitate')
  })
})
