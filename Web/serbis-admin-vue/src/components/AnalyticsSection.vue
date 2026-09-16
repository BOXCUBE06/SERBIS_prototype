<!--
  AnalyticsSection.vue

  One card on the Analytics page: title, subtitle, an optional sample-size
  chip, and a body that is either loading, failed, empty or real.

  The states are per section rather than per page on purpose. This office
  runs on small numbers, and several sections are legitimately empty while
  their neighbours have data — turnaround in particular reads columns that
  are only partly backfilled. A single page-level empty state would hide the
  sections that do have something to show, and a single page-level error
  would blame every section for one failure.

  `count` is the sample the section actually drew on, and it is deliberately
  prominent: a median over eight resolutions and a median over eight hundred
  should not look alike. Pass null to omit the chip entirely (a section whose
  figure is a total rather than a sample).
-->
<template>
  <v-card elevation="0" rounded="xl" class="soft-card h-100">
    <v-card-item>
      <div class="d-flex justify-space-between align-start flex-wrap gap-2">
        <div class="min-w-0">
          <v-card-title class="text-body-1 font-weight-bold pa-0">{{ title }}</v-card-title>
          <!-- wrap-subtitle: v-card-subtitle ships nowrap + ellipsis, which
               silently truncated these one-line explanations in the narrower
               columns. The sentence is the point of the card; it wraps. -->
          <v-card-subtitle class="pa-0 wrap-subtitle">
            <slot name="subtitle">{{ subtitle }}</slot>
          </v-card-subtitle>
        </div>

        <v-chip
          v-if="count !== null && !loading && !error"
          size="x-small"
          variant="tonal"
          color="primary"
          class="font-weight-bold"
        >
          n = {{ count.toLocaleString() }}
        </v-chip>
      </div>
    </v-card-item>

    <v-card-text :class="bodyClass">
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
  subtitle: { type: String, default: '' },
  loading: { type: Boolean, default: false },
  error: { type: String, default: '' },
  empty: { type: Boolean, default: false },
  emptyText: { type: String, default: 'Nothing in this range' },
  emptyHint: { type: String, default: '' },
  count: { type: Number, default: null },
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

.wrap-subtitle {
  white-space: normal;
  overflow: visible;
  text-overflow: clip;
  line-height: 1.35;
}
</style>
