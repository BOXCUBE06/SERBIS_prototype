<!--
  NoticeBanner.vue

  The ResNotice board: a plain-spoken note above a list. `tone` is "info" (blue)
  or "warning" (amber); the body is the default slot, one block per paragraph
  (6px apart). Text sits on a 10-12% tint of its accent in the -strong token, so
  it stays readable in both themes.
-->
<template>
  <div class="notice-banner" :class="`notice-banner--${tone}`" :role="tone === 'error' ? 'alert' : 'note'">
    <v-icon size="20" class="notice-banner__icon" aria-hidden="true">{{ icon }}</v-icon>
    <div class="notice-banner__body"><slot /></div>
    <button v-if="dismissible" type="button" class="notice-banner__close" aria-label="Dismiss" @click="emit('dismiss')">
      <v-icon size="14">mdi-close</v-icon>
    </button>
  </div>
</template>

<script setup lang="ts">
withDefaults(defineProps<{ tone?: 'info' | 'warning' | 'error'; icon?: string; dismissible?: boolean }>(), { tone: 'info', icon: 'mdi-information-outline', dismissible: false })
const emit = defineEmits<{ dismiss: [] }>()
</script>

<style scoped>
.notice-banner {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  padding: 12px 16px;
  border-radius: 12px;
  font-size: 14px;
  line-height: 20px;
}
.notice-banner__icon { flex: none; }
.notice-banner__body { flex: 1; display: flex; flex-direction: column; gap: 6px; }
.notice-banner__close {
  flex: none;
  display: grid;
  place-items: center;
  width: 28px;
  height: 28px;
  margin: -4px -6px 0 0;
  border: 0;
  border-radius: 8px;
  background: transparent;
  color: inherit;
  cursor: pointer;
}
.notice-banner--error {
  background: color-mix(in srgb, rgb(var(--v-theme-error)) 10%, rgb(var(--v-theme-surface)));
  color: rgb(var(--v-theme-error-strong));
}
.notice-banner__body :deep(p) { margin: 0; }
.notice-banner--info {
  background: color-mix(in srgb, rgb(var(--v-theme-info)) 10%, rgb(var(--v-theme-surface)));
  color: rgb(var(--v-theme-info-strong));
}
.notice-banner--warning {
  background: color-mix(in srgb, rgb(var(--v-theme-warning)) 12%, rgb(var(--v-theme-surface)));
  color: rgb(var(--v-theme-warning-strong));
}
</style>
