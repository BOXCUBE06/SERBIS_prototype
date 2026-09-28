// Brave only: a select inside a v-dialog opens detached toward the bottom-right, and
// only for a trusted mouse click (Playwright's synthetic clicks never do it). A
// recompute corrects it, and Vuetify's connected location strategy recomputes on
// `resize`, so fire one. Every menu gets an early one, two frames after it opens.
// A menu inside a dialog also gets one on the next pointerup/click and one when its
// enter transition ends (250ms fallback: Vuetify's animation may fire no
// transitionend), since the first measurement can still be stale then. At most
// three per open, cancelled if the menu closes first, no polling. The extra resize
// reaches every other resize listener; remove this if Brave is fixed.
const open = new Map<Element, () => void>()
const recompute = () => window.dispatchEvent(new Event('resize'))

function onOpen(menu: Element): () => void {
  let alive = true
  const cancels: Array<() => void> = []
  const fire = () => alive && recompute()
  requestAnimationFrame(() => requestAnimationFrame(fire))

  if (document.querySelector('.v-dialog.v-overlay--active')) {
    // Runs `fire` once, on the first of `types` (or the fallback timer), then unhooks all.
    const once = (target: EventTarget, types: string[], fallbackMs = 0) => {
      let timer = 0
      const run = (e?: Event) => {
        if (e && target !== document && e.target !== target) {
          return
        }
        stop()
        requestAnimationFrame(fire)
      }
      const stop = () => {
        for (const type of types) {
          target.removeEventListener(type, run, true)
        }
        clearTimeout(timer)
      }
      for (const type of types) {
        target.addEventListener(type, run, true)
      }
      if (fallbackMs) {
        timer = window.setTimeout(run, fallbackMs)
      }
      cancels.push(stop)
    }
    once(document, ['pointerup', 'click'])
    const content = menu.querySelector('.v-overlay__content')
    if (content) {
      once(content, ['transitionend'], 250)
    }
  }
  return () => {
    alive = false
    for (const cancel of cancels) {
      cancel()
    }
  }
}

function check(box: Element) {
  const now = new Set(box.querySelectorAll('.v-overlay--active.v-menu'))
  for (const [menu, cancel] of open) {
    if (!now.has(menu)) {
      cancel()
      open.delete(menu)
    }
  }
  for (const menu of now) {
    if (!open.has(menu)) {
      open.set(menu, onOpen(menu))
    }
  }
}

export function installOverlayReposition() {
  // Vuetify creates .v-overlay-container on the first overlay, so wait for it.
  const finder = new MutationObserver(() => {
    const box = document.querySelector('.v-overlay-container')
    if (!box) {
      return
    }
    finder.disconnect()
    new MutationObserver(() => check(box)).observe(box, { subtree: true, childList: true, attributes: true, attributeFilter: ['class'] })
  })
  finder.observe(document.body, { childList: true })
}
