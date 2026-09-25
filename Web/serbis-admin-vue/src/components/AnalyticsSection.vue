<!--
  AnalyticsSection.vue

  One card on the Analytics page: title (with an optional info tooltip and
  right-aligned `actions` slot), and a body that is either loading, failed,
  empty or real.

  The states are per section rather than per page on purpose. This office
  runs on small numbers, and several sections are legitimately empty while
  their neighbours have data — turnaround in particular reads columns that
  are only partly backfilled. A single page-level empty state would hide the
  sections that do have something to show, and a single page-level error
  would blame every section for one failure.

-->
<template>
  <v-card elevation="0" rounded="xl" class="soft-card h-100">
    <v-progress-linear v-if="refreshing" indeterminate color="primary" height="2" absolute location="top"></v-progress-linear>
    <v-card-item>
      <div class="d-flex justify-space-between align-center flex-wrap gap-2">
        <div class="d-flex align-center gap-1 min-w-0">
          <v-card-title class="text-body-1 font-weight-bold pa-0 wrap-title">{{ title }}</v-card-title>
          <v-tooltip v-if="info" :text="info" location="top" max-width="320">
            <template #activator="{ props: tip }">
              <v-icon v-bind="tip" size="16" class="text-medium-emphasis" tabindex="0" :aria-label="info">
                mdi-information-outline
              </v-icon>
            </template>
          </v-tooltip>
        </div>
        <slot name="actions" />
      </div>
    </v-card-item>

    <v-card-text :class="[bodyClass, { 'is-dim': refreshing }]">
      <v-skeleton-loader v-if="loading" :type="skeleton" />

      <div v-else-if="error" class="text-center py-8">
        <v-icon color="medium-emphasis" size="28" class="mb-2">mdi-alert-circle-outline</v-icon>
        <div class="text-body-2 text-medium-emphasis mb-3">{{ error }}</div>
        <v-btn size="small" variant="tonal" color="primary" class="text-none" @click="$emit('retry')">
          Try again
        </v-btn>
      </div>

      <div v-else-if="empty" class="text-center py-8">
        <div class="text-body-2 text-medium-emphasis">{{ emptyText }}</div>
        <div v-if="emptyHint" class="text-caption text-medium-emphasis mt-1">{{ emptyHint }}</div>
      </div>

      <slot v-else />
    </v-card-text>
  </v-card>
</template>

<script setup>
defineProps({
  title: { type: String, required: true },
  info: { type: String, default: '' },
  // First load only; a refetch passes `refreshing` and keeps the body on screen.
  loading: { type: Boolean, default: false },
  refreshing: { type: Boolean, default: false },
  error: { type: String, default: '' },
  empty: { type: Boolean, default: false },
  emptyText: { type: String, default: 'Nothing in this range' },
  emptyHint: { type: String, default: '' },
  skeleton: { type: String, default: 'image' },
  bodyClass: { type: String, default: 'pt-0' },
})

defineEmits(['retry'])
</script>

<style scoped>
.soft-card {
  border: 1px solid rgba(var(--v-theme-on-surface), 0.08);
  box-shadow: 0 1px 2px rgba(var(--v-theme-on-surface), 0.04), 0 4px 14px rgba(var(--v-theme-on-surface), 0.08);
}

.min-w-0 {
  min-width: 0;
}

/* v-card-title ships nowrap + ellipsis, which clipped "Requests by month and
   service" inside its own card at phone width. */
.wrap-title {
  white-space: normal;
  overflow: visible;
  text-overflow: clip;
  line-height: 1.35;
}
</style>
