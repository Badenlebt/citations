<script setup lang="ts">
// Reusable length-limited input / textarea (Req 14.5, 14.6).
//
// - Enforces a configurable maximum length so the user cannot enter more than
//   `maxLength` characters (14.5). Enforcement happens both via the native
//   `maxlength` attribute and defensively in the input handler (covers paste
//   and programmatic value changes).
// - Visibly displays the current character count and the maximum, e.g.
//   "12 / 200" (14.6).
//
// Usable for every length-limited field by passing the matching constant from
// `@/constants/fieldLimits` as `maxLength`. Set `multiline` to render a
// textarea (e.g. for quote text or source description).

import { computed } from 'vue'

const props = withDefaults(
  defineProps<{
    /** Current field value (v-model). */
    modelValue: string
    /** Maximum number of characters allowed. */
    maxLength: number
    /** Render a <textarea> instead of a single-line <input>. */
    multiline?: boolean
    /** Associated input id (for label `for` wiring). */
    id?: string
    /** Native input type for single-line fields. */
    type?: string
    placeholder?: string
    disabled?: boolean
    /** Number of visible rows for the textarea variant. */
    rows?: number
  }>(),
  {
    multiline: false,
    id: undefined,
    type: 'text',
    placeholder: '',
    disabled: false,
    rows: 4,
  },
)

const emit = defineEmits<{
  (e: 'update:modelValue', value: string): void
}>()

const currentLength = computed(() => props.modelValue.length)

const counterText = computed(() => `${currentLength.value} / ${props.maxLength}`)

/** True once the value has reached the configured maximum length (14.6 cue). */
const atLimit = computed(() => currentLength.value >= props.maxLength)

function onInput(event: Event): void {
  const target = event.target as HTMLInputElement | HTMLTextAreaElement
  let value = target.value
  // Defensive enforcement in case a value exceeds the limit (e.g. paste on
  // browsers that bypass `maxlength`, or a programmatic set). (14.5)
  if (value.length > props.maxLength) {
    value = value.slice(0, props.maxLength)
    target.value = value
  }
  emit('update:modelValue', value)
}
</script>

<template>
  <div class="length-limited-input">
    <textarea
      v-if="multiline"
      :id="id"
      class="length-limited-input__field"
      :value="modelValue"
      :maxlength="maxLength"
      :placeholder="placeholder"
      :disabled="disabled"
      :rows="rows"
      :aria-describedby="id ? `${id}-counter` : undefined"
      @input="onInput"
    ></textarea>
    <input
      v-else
      :id="id"
      class="length-limited-input__field"
      :type="type"
      :value="modelValue"
      :maxlength="maxLength"
      :placeholder="placeholder"
      :disabled="disabled"
      :aria-describedby="id ? `${id}-counter` : undefined"
      @input="onInput"
    />
    <span
      :id="id ? `${id}-counter` : undefined"
      class="length-limited-input__counter"
      :class="{ 'length-limited-input__counter--at-limit': atLimit }"
      aria-live="polite"
    >
      {{ counterText }}
    </span>
  </div>
</template>

<style scoped lang="scss">
// Token-based styling for the length-limited input/textarea (Task 6.4).
// Markup, BEM class names, props, logic (length enforcement/counter), events
// and i18n keys remain unchanged; only the presentation is driven by design
// tokens. Values are sourced either via build-checked SCSS variables (Req 1.5)
// or the :root-mirrored custom properties (Req 1.4); no hardcoded
// colors/spacing (Req 1.3, 6.1, 6.6). Transitions use the transition() mixin
// (Req 6.2).
@use '@/scss/variables' as tokens;
@use '@/scss/mixins' as mix;

.length-limited-input {
  display: flex;
  flex-direction: column;
  gap: var(--spacing-xs, #{tokens.$spacing-xs});
}

.length-limited-input__field {
  width: 100%;
  box-sizing: border-box;
  padding: var(--spacing-xs, #{tokens.$spacing-xs})
    var(--spacing-sm, #{tokens.$spacing-sm});
  color: var(--color-text, #{tokens.$color-text});
  background-color: var(--color-background, #{tokens.$color-background});
  border: 1px solid var(--color-purple-medium, #{tokens.$color-purple-medium});
  border-radius: var(--radius-sm, #{tokens.$radius-sm});
  font: inherit;

  // Unified 0.3s ease transitions for interactive feedback (Req 6.2).
  @include mix.transition(border-color, box-shadow, background-color);

  &::placeholder {
    color: var(--color-purple-bright, #{tokens.$color-purple-bright});
  }

  &:hover:not(:disabled) {
    border-color: var(--color-primary, #{tokens.$color-primary});
  }

  // Visible, token-based focus indicator with sufficient contrast (Req 6.4):
  // the purple-light outline (#C15BEB) against the gunmetal background exceeds
  // the 3:1 threshold required for graphical/UI controls.
  &:focus-visible {
    outline: 2px solid var(--color-primary, #{tokens.$color-primary});
    outline-offset: 2px;
    border-color: var(--color-primary, #{tokens.$color-primary});
  }

  &:disabled {
    cursor: not-allowed;
    opacity: 0.6;
  }
}

.length-limited-input__counter {
  align-self: flex-end;
  font-size: var(--font-size-h6, #{tokens.$font-size-h6});
  color: var(--color-purple-bright, #{tokens.$color-purple-bright});

  @include mix.transition(color);
}

// Error state once the configured maximum length is reached (Req 6.6):
// the status error token signals the limit has been hit.
.length-limited-input__counter--at-limit {
  color: var(--color-error, #{tokens.$color-error});
  font-weight: var(--font-weight-heading, #{tokens.$font-weight-heading});
}
</style>
