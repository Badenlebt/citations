import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import LengthLimitedInput from './LengthLimitedInput.vue'
import { FIELD_LIMITS, type FieldLimitKey } from '@/constants/fieldLimits'

// Component tests for the reusable length-limited field (Req 14.5, 14.6).
//
// Parametrised over every length-limited field (the FIELD_LIMITS constants)
// rather than hardcoding the individual maxima, so the tests stay in sync with
// the single source of truth in `@/constants/fieldLimits`.

// Fields that are rendered as a multiline <textarea> (quote text / description).
const MULTILINE_FIELDS: ReadonlySet<FieldLimitKey> = new Set([
  'quoteText',
  'sourceOptionalField',
])

const fieldCases = (Object.entries(FIELD_LIMITS) as [FieldLimitKey, number][]).map(
  ([key, max]) => ({ key, max, multiline: MULTILINE_FIELDS.has(key) }),
)

describe('LengthLimitedInput', () => {
  describe.each(fieldCases)('field "$key" (max $max)', ({ key, max, multiline }) => {
    const fieldTag = multiline ? 'textarea' : 'input'

    it('renders the matching element variant', () => {
      const wrapper = mount(LengthLimitedInput, {
        props: { modelValue: '', maxLength: max, multiline },
      })
      expect(wrapper.find(fieldTag).exists()).toBe(true)
    })

    it('sets the native maxlength attribute to the field limit', () => {
      const wrapper = mount(LengthLimitedInput, {
        props: { modelValue: '', maxLength: max, multiline },
      })
      expect(wrapper.find(fieldTag).attributes('maxlength')).toBe(String(max))
    })

    it('truncates an over-long value to the maximum in the emitted value', async () => {
      const wrapper = mount(LengthLimitedInput, {
        props: { modelValue: '', maxLength: max, multiline },
      })
      const field = wrapper.find(fieldTag)
      // Simulate a value that bypasses the native maxlength (e.g. paste /
      // programmatic set) and exceeds the limit by 10 characters.
      ;(field.element as HTMLInputElement | HTMLTextAreaElement).value = 'x'.repeat(
        max + 10,
      )
      await field.trigger('input')

      const emitted = wrapper.emitted('update:modelValue')
      expect(emitted).toBeTruthy()
      const value = emitted?.[emitted.length - 1][0] as string
      expect(value).toHaveLength(max)
    })

    it('emits a value unchanged when within the limit', async () => {
      const wrapper = mount(LengthLimitedInput, {
        props: { modelValue: '', maxLength: max, multiline },
      })
      const field = wrapper.find(fieldTag)
      const input = 'a'.repeat(Math.min(5, max))
      ;(field.element as HTMLInputElement | HTMLTextAreaElement).value = input
      await field.trigger('input')

      const emitted = wrapper.emitted('update:modelValue')
      expect(emitted?.[emitted.length - 1][0]).toBe(input)
    })

    it('shows the counter as "current / max"', () => {
      const current = Math.min(3, max)
      const wrapper = mount(LengthLimitedInput, {
        props: { modelValue: 'y'.repeat(current), maxLength: max, multiline },
      })
      expect(wrapper.find('.length-limited-input__counter').text()).toBe(
        `${current} / ${max}`,
      )
    })

    it('shows "0 / max" for an empty field', () => {
      const wrapper = mount(LengthLimitedInput, {
        props: { modelValue: '', maxLength: max, multiline },
      })
      expect(wrapper.find('.length-limited-input__counter').text()).toBe(`0 / ${max}`)
    })

    it('does not mark the counter at-limit below the maximum', () => {
      const wrapper = mount(LengthLimitedInput, {
        props: { modelValue: 'z'.repeat(max - 1), maxLength: max, multiline },
      })
      const counter = wrapper.find('.length-limited-input__counter')
      expect(counter.classes()).not.toContain('length-limited-input__counter--at-limit')
    })

    it('marks the counter at-limit when length === maxLength', () => {
      const wrapper = mount(LengthLimitedInput, {
        props: { modelValue: 'z'.repeat(max), maxLength: max, multiline },
      })
      const counter = wrapper.find('.length-limited-input__counter')
      expect(counter.classes()).toContain('length-limited-input__counter--at-limit')
      expect(counter.text()).toBe(`${max} / ${max}`)
    })
  })

  it('wires the counter to the field via aria-describedby when an id is given', () => {
    const wrapper = mount(LengthLimitedInput, {
      props: { modelValue: '', maxLength: FIELD_LIMITS.sourceName, id: 'source-name' },
    })
    expect(wrapper.find('input').attributes('aria-describedby')).toBe(
      'source-name-counter',
    )
    expect(wrapper.find('.length-limited-input__counter').attributes('id')).toBe(
      'source-name-counter',
    )
  })

  it('exposes the counter as an aria-live region', () => {
    const wrapper = mount(LengthLimitedInput, {
      props: { modelValue: '', maxLength: FIELD_LIMITS.searchQuery },
    })
    expect(wrapper.find('.length-limited-input__counter').attributes('aria-live')).toBe(
      'polite',
    )
  })
})
