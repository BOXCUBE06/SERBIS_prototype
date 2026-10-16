import { computed, nextTick, ref, watch, type Ref } from 'vue'

/**
 * Submit-time behaviour every admin form shares, so a mistake is seen without
 * hunting for it: the first invalid field is scrolled to the middle of its
 * scroll area and focused, and a Laravel 422 lands on the field it names.
 * Pair it with `validate-on="blur"` on the `<v-form>`.
 */

/** Centres the first errored field in its scroll parent and focuses it. */
export async function focusFirstError(root: HTMLElement | null | undefined) {
  await nextTick()
  const field = root?.querySelector<HTMLElement>('.v-input--error')
  if (!field) return
  field.scrollIntoView({ block: 'center', behavior: 'smooth' })
  // preventScroll: the smooth scroll above must not be cut short by the focus jump.
  field.querySelector<HTMLElement>('input, textarea')?.focus({ preventScroll: true })
}

/**
 * Laravel's `{ field: [msg] }`: the keys in `known` as `{ key: msg }`, the rest as a list for a banner.
 * A `known` entry ending in ".*" ("numbers.*") also takes the indexed keys under it ("numbers.0.label"),
 * which keep their full key so each row can find its own message. `alias` renames a server key first.
 */
export function splitServerErrors(
  errors: Record<string, string[] | string> | undefined,
  known: string[],
  alias: Record<string, string> = {},
) {
  const matched: Record<string, string> = {}
  const rest: string[] = []
  for (const [serverKey, messages] of Object.entries(errors ?? {})) {
    const text = Array.isArray(messages) ? messages.join(' ') : String(messages)
    const key = alias[serverKey] ?? serverKey
    const fits = known.some((k) => (k.endsWith('.*') ? key.startsWith(k.slice(0, -1)) : key === k))
    if (fits) matched[key] = text
    else rest.push(text)
  }
  return { matched, rest }
}

/**
 * @param form   the `<v-form ref>`
 * @param source the object the fields edit. Editing a field clears that field's server message, and only that one;
 *               a key like "drivers.0" or "numbers.1.label" follows the row it names.
 */
export function useFormErrors(form: Ref<any>, source?: () => unknown) {
  const serverErrors = ref<Record<string, string | string[]>>({})
  const attempted = ref(false)

  // What each errored field held when its message arrived, to tell later which one was edited.
  const valueAt = (key: string) =>
    JSON.stringify(key.split('.').reduce<any>((node, part) => (node == null ? undefined : node[part]), source?.()))
  const held = new Map<string, string | undefined>()

  /** Shows these messages (field key to text) and remembers each field's value. */
  const setErrors = (messages: Record<string, string | string[]>) => {
    serverErrors.value = messages
    held.clear()
    for (const key of Object.keys(messages)) held.set(key, valueAt(key))
  }

  // "N fields need attention" only once a submit has been tried.
  const invalidCount = computed(() => (attempted.value ? (form.value?.errors?.length ?? 0) : 0))

  if (source) {
    watch(source, () => {
      const edited = Object.keys(serverErrors.value).filter((key) => valueAt(key) !== held.get(key))
      if (edited.length === 0) return
      const next = { ...serverErrors.value }
      for (const key of edited) delete next[key]
      serverErrors.value = next
    }, { deep: true })
  }

  /** Same `{ valid, errors }` as `<v-form>.validate()`, plus the scroll and focus. */
  const validate = async () => {
    attempted.value = true
    setErrors({})
    const result = await form.value.validate()
    if (!result.valid) await focusFirstError(form.value.$el)
    return result
  }

  /** Puts a 422 on its fields and returns the messages that matched none (for a banner). */
  const applyServerErrors = async (
    errors: Record<string, string[] | string> | undefined,
    known: string[],
    alias: Record<string, string> = {},
  ) => {
    const { matched, rest } = splitServerErrors(errors, known, alias)
    setErrors(matched)
    attempted.value = true
    if (Object.keys(matched).length > 0) await focusFirstError(form.value?.$el)
    return rest
  }

  const reset = () => {
    attempted.value = false
    setErrors({})
  }

  return { serverErrors, setErrors, invalidCount, validate, applyServerErrors, reset }
}
