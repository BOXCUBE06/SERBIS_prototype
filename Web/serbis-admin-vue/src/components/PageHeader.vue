<!--
  PageHeader.vue

  One header row — title, trailing actions, vertically centred against each
  other — for every route in the admin panel. The `subtitle` slot survives
  only for the Residents count, which has no footer count of its own; the
  grey description prop is gone.

  Reference shape lifted from Resident Requests and Ambulance Dispatch
  Requests (ServiceRequestQueue.vue's standalone block and
  ConductionRequestView.vue), the two pages that already agreed on this
  structure before this component existed; every other view had assembled
  its own slightly different version by hand.

  Deliberately NOT included:
  - Outer margin-bottom. Every page still owns its own spacing below the
    header via whatever class it puts on the component tag (`class="mb-6"`,
    matching what almost every view already used) — Conduction's height-
    locked flex-column layout is `flex-shrink: 0` for a real structural
    reason (routes/index.ts's `fixedHeight`) and nothing here should risk
    that math.
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
  <div class="d-flex justify-space-between align-center flex-wrap gap-3">
    <div class="min-w-0">
      <h2 class="page-title text-high-emphasis">{{ title }}</h2>
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
