import { onBeforeUnmount, ref, watch, type Ref } from 'vue'

/** How long "Saved" shows before the dialog closes (the Motion board's ~0.8s). */
const SAVED_MS = 800

/**
 * The save button's three looks for a dialog, without touching its save logic:
 *   idle    the normal label
 *   saving  a spinner and "Saving", disabled (while `loading` is true)
 *   saved   a check and "Saved" for a moment, once a save ends with the dialog
 *           closed and no error
 * `shown` is what the dialog actually displays. It follows `open`, except that a
 * dialog its page closed mid-save (or in the same tick the save ended) is held
 * open until "Saved" has been seen. On an error the dialog stays as it is and
 * the button returns to its label.
 */
export function useSaveFeedback(open: () => boolean, loading: () => boolean, error: () => string) {
  const shown: Ref<boolean> = ref(open())
  const phase: Ref<'idle' | 'saving' | 'saved'> = ref('idle')
  let timer: ReturnType<typeof setTimeout> | undefined

  watch(open, (isOpen) => {
    if (isOpen) {
      clearTimeout(timer)
      shown.value = true
      phase.value = 'idle'
    } else if (phase.value !== 'saving') {
      shown.value = false
    }
  })

  watch(loading, (isLoading) => {
    if (isLoading) {
      phase.value = 'saving'
      return
    }
    if (phase.value !== 'saving') return
    if (!open() && !error()) {
      phase.value = 'saved'
      timer = setTimeout(() => {
        shown.value = false
        phase.value = 'idle'
      }, SAVED_MS)
    } else {
      phase.value = 'idle'
      shown.value = open()
    }
  })

  onBeforeUnmount(() => clearTimeout(timer))

  return { shown, phase }
}
