import type { App, Directive } from 'vue'
import { filters, limits, type FieldKind } from '@/composables/inputRules'

type Binding = FieldKind | { kind?: FieldKind; max?: number } | undefined

/**
 * v-filter="'name'" on a v-text-field / v-textarea: keystrokes and pastes that
 * do not belong to the kind are dropped, and the kind's length is capped.
 * `{ kind, max }` overrides the cap (a 160-character text, a 1000-character note).
 * The cleaned value is re-announced as an `input` event so v-model follows.
 */
const vFilter: Directive<HTMLElement, Binding> = {
  mounted(el, { value }) {
    const { kind, max: customMax } = typeof value === 'string' ? { kind: value, max: undefined } : (value ?? {})
    const field = el.querySelector<HTMLInputElement | HTMLTextAreaElement>('input, textarea')
    if (!kind || !field) {
      return
    }

    const max = customMax ?? limits[kind]
    field.maxLength = max

    field.addEventListener('input', () => {
      const cleaned = filters[kind](field.value).slice(0, max)
      if (cleaned === field.value) {
        return
      }
      const caret = Math.max((field.selectionStart ?? cleaned.length) - (field.value.length - cleaned.length), 0)
      field.value = cleaned
      try { field.setSelectionRange(caret, caret) } catch { /* number and email inputs have no caret API */ }
      field.dispatchEvent(new Event('input', { bubbles: true }))
    })
  },
}

export function installInputFilter(app: App) {
  app.directive('filter', vFilter)
}
