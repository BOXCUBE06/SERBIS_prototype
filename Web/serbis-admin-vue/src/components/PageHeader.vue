<!--
  PageHeader.vue

  One header row — title, trailing actions, vertically centred against each
  other — for every route in the admin panel. The grey description prop is
  gone. Two optional slots remain: `badge`, drawn on the title's own line (the
  Residents count, as a chip), and `subtitle`, a line below it.

  Reference shape lifted from Resident Requests and Ambulance Dispatch
  Requests (ServiceRequestQueue.vue's standalone block and
  ConductionRequestView.vue), the two pages that already agreed on this
  structure before this component existed; every other view had assembled
  its own slightly different version by hand.

  The header owns its min-height (48px) and its 24px bottom margin, and the
  shell owns page padding (App.vue), so the title lands in the same spot on
  every page. Callers must not add mb-* or pa-* of their own.

  Deliberately NOT included:
  - Action button styling. What goes in the `actions` slot keeps its own
    classes/colors exactly as each page already had them — a "Log Service
    Request" button and an "Add Unit" button are different actions on
    different pages, not the same button wearing two labels.

  Pitfall found while extracting this (LogsView's search field): a
  `v-text-field`/`v-select` styled with `max-width` alone, and no `width`,
  collapsed to ~100px here — one flex nesting level deeper than it used to
  be (this component's own actions wrapper, around whatever the caller
  puts in the slot) is enough to break the implicit sizing an un-nested
  `max-width`-only field was relying on. Give any such field a real
  `width` (`max-width: 100%` alongside it for narrow viewports), the way
  UsersView's `.search-field`/`.status-field` already do — don't rely on
  `max-width` alone inside this slot.

  Type scale (see the two Vuetify-utility measurement notes in
  settings.scss's .page-title/.page-subtitle for why these are hand-picked
  px values, not text-h4/text-subtitle-2): title sits one full step above
  the app's own dialog/section-title tier (text-h6, ~18.7px here), the
  slotted count one step below body text (16px) — a tighter, denser ladder
  than the old text-h4/text-subtitle-2 pairing this replaces, per
  Impeccable's Operate-mode guidance (1.125-1.2 typical step ratio; product
  UI carries more type elements than a marketing surface, so looser contrast
  reads as noise).
-->
<template>
  <div class="page-header d-flex justify-space-between align-center flex-wrap gap-3">
    <div class="min-w-0">
      <!-- The wrapper only exists when there is a badge, so every other page's
           header keeps exactly the markup it had. -->
      <div v-if="$slots.badge" class="d-flex align-center flex-wrap ga-3">
        <h2 class="page-title text-high-emphasis">{{ title }}</h2>
        <slot name="badge" />
      </div>
      <h2 v-else class="page-title text-high-emphasis">{{ title }}</h2>
      <div v-if="$slots.subtitle" class="page-subtitle text-medium-emphasis">
        <slot name="subtitle" />
      </div>
    </div>

    <div v-if="$slots.actions" class="d-flex align-center flex-wrap gap-3">
      <slot name="actions" />
    </div>
  </div>
</template>

<script setup>
defineProps({
  title: { type: String, required: true },
})
</script>

<style scoped>
/* 48px is the tallest thing that goes in the actions slot (a 48px button or
   outlined field), so a title-only page and a page with actions have the
   same header height and the title sits at the same place on both. The
   bottom margin lives here, not on each caller. */
.page-header {
  min-height: 48px;
  margin-bottom: 24px;
}
</style>
