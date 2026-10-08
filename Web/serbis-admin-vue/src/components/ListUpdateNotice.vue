<!--
  ListUpdateNotice.vue

  "3 new requests · Show" above a list the server has moved past (see
  composables/usePulse.ts). The rows stay put until staff ask for the new ones,
  so nothing reshuffles under the cursor. The live region is always rendered so
  a screen reader hears the notice when it appears.
-->
<template>
  <div role="status" aria-live="polite">
    <NoticeBanner v-if="update.changed" icon="mdi-refresh" class="mb-4">
      <span>
        {{ update.added > 0 ? `${update.added} new ${update.added === 1 ? noun : `${noun}s`}` : 'This list has changed since it loaded.' }}
        <button type="button" class="list-update__action" @click="emit('show')">{{ update.added > 0 ? 'Show' : 'Refresh' }}</button>
      </span>
    </NoticeBanner>
  </div>
</template>

<script setup lang="ts">
import NoticeBanner from '@/components/NoticeBanner.vue'
import type { ListUpdate } from '@/composables/pulseDiff'

withDefaults(defineProps<{ update: ListUpdate; noun?: string }>(), { noun: 'request' })
const emit = defineEmits<{ show: [] }>()
</script>

<style scoped>
.list-update__action {
  margin-left: 8px;
  font-weight: 700;
  color: inherit;
  text-decoration: underline;
  text-underline-offset: 2px;
}
.list-update__action:focus-visible {
  outline: 2px solid currentColor;
  outline-offset: 2px;
  border-radius: 2px;
}
</style>
