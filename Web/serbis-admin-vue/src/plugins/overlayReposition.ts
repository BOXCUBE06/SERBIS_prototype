// Brave only: a menu (v-select, v-autocomplete, v-menu) opens detached toward the
// bottom-right, and a window resize snaps it into place. Vuetify's connected
// location strategy recomputes on `resize`, so fire one two frames after each menu
// opens, once per open, no polling. Dialogs are skipped. The extra resize
// reaches every other resize listener once per open; remove this if Brave is fixed.
const open = new Set<Element>()

function check(box: Element) {
  const now = new Set(box.querySelectorAll('.v-overlay--active.v-menu'))
  for (const menu of open) {
    if (!now.has(menu)) {
      open.delete(menu)
    }
  }
  for (const menu of now) {
    if (open.has(menu)) {
      continue
    }
    open.add(menu)
    requestAnimationFrame(() => requestAnimationFrame(() => window.dispatchEvent(new Event('resize'))))
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
