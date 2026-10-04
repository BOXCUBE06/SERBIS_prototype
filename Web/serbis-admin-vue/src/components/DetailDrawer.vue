<!--
  DetailDrawer.vue

  The 520px right drawer every request, booking and borrowing detail opens in.
  Header: eyebrow, print/export (`actions` slot), close, avatar, name, status
  pill (`status` slot) and one line of status text. Body: the default slot,
  scrolling titled sections (detail-dialog.css). Footer: sticky `footer` slot.

  A `panel` slot replaces all three, so a picker (PickerDrawer) opens in the
  same panel instead of a dialog on top of a drawer.
-->
<template>
  <v-navigation-drawer
    :model-value="modelValue"
    @update:model-value="$emit('update:modelValue', $event)"
    location="right"
    temporary
    :width="Math.min(520, width)"
    :persistent="persistent"
    class="detail-drawer"
    :aria-label="eyebrow"
  >
    <div class="d-flex flex-column h-100">
      <slot v-if="$slots.panel" name="panel" />
      <template v-else>
        <header class="dd-header">
          <div class="d-flex align-center justify-space-between">
            <div class="sect-label mb-0">{{ eyebrow }}</div>
            <div class="d-flex ga-2">
              <slot name="actions" />
              <v-btn icon="mdi-close" variant="tonal" size="36" rounded="lg" aria-label="Close" @click="$emit('update:modelValue', false)"></v-btn>
            </div>
          </div>
          <div class="d-flex align-center mt-4 ga-3 min-w-0">
            <v-avatar color="primary" variant="tonal" size="48" class="flex-shrink-0">
              <v-img v-if="photo" :src="photo" alt=""></v-img>
              <span v-else class="font-weight-bold">{{ initials }}</span>
            </v-avatar>
            <div class="min-w-0">
              <div class="d-flex align-center ga-2">
                <span class="dd-name text-truncate">{{ name }}</span>
                <slot name="badge" />
              </div>
              <div v-if="secondary" class="text-body-2 text-medium-emphasis text-truncate">{{ secondary }}</div>
            </div>
          </div>
          <div class="d-flex align-center flex-wrap ga-3 mt-3">
            <slot name="status" />
            <span v-if="statusText" class="text-body-2" :class="statusTextClass || 'text-medium-emphasis'">{{ statusText }}</span>
          </div>
        </header>

        <div class="dd-body">
          <slot />
        </div>

        <footer v-if="$slots.footer" class="detail-footer d-flex align-center ga-3 px-6 py-4">
          <slot name="footer" />
        </footer>
      </template>
    </div>
  </v-navigation-drawer>
</template>

<script setup lang="ts">
import { useDisplay } from 'vuetify'
import '@/components/detail-dialog.css'

defineProps<{
  modelValue: boolean
  eyebrow: string
  name: string
  initials?: string
  photo?: string | null
  secondary?: string | null
  statusText?: string | null
  /** e.g. the wait-tone class, so a long wait reads amber or red. */
  statusTextClass?: string | null
  /** A drawer holding a form: a click on the scrim does not close it. */
  persistent?: boolean
}>()
defineEmits<{ 'update:modelValue': [value: boolean] }>()

// Full width on a phone, 520px everywhere else.
const { width } = useDisplay()
</script>

<style scoped>
/* Only while open: a closed drawer is parked just past the viewport edge, and its
   64px shadow would bleed onto the page as a grey band against the scrollbar. */
.detail-drawer { box-shadow: none; }
.detail-drawer.v-navigation-drawer--active { box-shadow: 0 28px 64px rgba(2, 20, 16, 0.32); }
/* Closed: no shadow (it fades out with the slide, on the drawer's own transition) and
   hidden once the slide ends, so nothing in it can be tabbed to or read out. Vuetify's
   drawer transition already animates visibility, so the slide-out stays visible. */
.detail-drawer:not(.v-navigation-drawer--active) { visibility: hidden; }
.dd-header {
  flex-shrink: 0;
  padding: 24px 24px 16px;
  border-bottom: 1px solid rgba(var(--v-theme-on-surface), 0.12);
}
.dd-name { font-size: 1.17rem; line-height: 28px; font-weight: 600; }
.dd-body {
  flex: 1 1 auto;
  min-height: 0;
  overflow-y: auto;
  padding: 24px;
}
.dd-body :deep(.detail-section) { margin-bottom: 28px; }
.dd-body :deep(.detail-section:last-child) { margin-bottom: 0; }
.min-w-0 { min-width: 0; }
</style>
