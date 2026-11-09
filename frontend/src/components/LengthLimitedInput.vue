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

<style scoped>
.length-limited-input {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}

.length-limited-input__field {
  width: 100%;
  box-sizing: border-box;
}

.length-limited-input__counter {
  align-self: flex-end;
  font-size: 0.8em;
  color: #666;
}

.length-limited-input__counter--at-limit {
  color: #b00020;
  font-weight: 600;
}
</style>
