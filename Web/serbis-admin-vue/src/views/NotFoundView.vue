<template>
  <v-container fluid class="fill-height align-start bg-background">
    <v-card elevation="3" rounded="lg" class="bg-surface w-100 empty-state">
      <v-icon size="56" class="text-medium-emphasis mb-4">mdi-compass-off-outline</v-icon>

      <div class="text-h6 font-weight-bold text-high-emphasis mb-1">Page not found</div>

      <div class="text-body-1 text-medium-emphasis mb-1">
        Nothing is routed to
        <code class="path-chip">{{ attemptedPath }}</code>.
      </div>
      <div class="text-body-1 text-medium-emphasis mb-5">
        Check the address, or head back to the dashboard.
      </div>

      <v-btn
        color="primary"
        variant="flat"
        rounded="lg"
        height="48"
        class="px-6 text-none font-weight-bold"
        to="/"
      >
        <v-icon start>mdi-view-dashboard-outline</v-icon> Go to dashboard
      </v-btn>
    </v-card>
  </v-container>
</template>

<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'

const route = useRoute()

// Echo the path so a mistyped link is self-diagnosing. Bound as text, never
// as markup — the value is whatever was in the address bar.
const attemptedPath = computed(() => route.fullPath)
</script>

<style scoped>
.empty-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  text-align: center;
  padding: 64px 24px;
}

.path-chip {
  padding: 2px 7px;
  border-radius: 6px;
  background: rgba(var(--v-theme-on-surface), 0.06);
  border: 1px solid rgba(var(--v-theme-on-surface), 0.12);
  color: rgb(var(--v-theme-on-surface));
  font-size: 0.9em;
  word-break: break-all;
}
</style>
