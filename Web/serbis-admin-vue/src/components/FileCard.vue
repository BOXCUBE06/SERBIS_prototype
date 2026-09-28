<!--
  FileCard.vue

  One document in the Documents grid: a thumbnail (the image itself, or a
  neutral icon tile for a PDF or anything else), the title, and a muted
  "size · date" line. Verified shows as a badge over the thumbnail's corner; the
  ⋯ menu holds Download, Mark verified / Unverify and Delete. Clicking the card
  opens the file (Enter too); the menu stops its own clicks. Presentational:
  the page owns what each action does.
-->
<template>
  <article
    class="file-card"
    tabindex="0"
    :aria-label="`Open ${title}`"
    @click="emit('open')"
    @keydown.enter.self.prevent="emit('open')"
    @keydown.space.self.prevent="emit('open')"
  >
    <div class="file-card__thumb">
      <img
        v-if="image && !broken"
        :src="url"
        alt=""
        loading="lazy"
        class="file-card__img"
        @error="broken = true"
      />
      <v-icon v-else size="40" class="text-medium-emphasis">{{ icon }}</v-icon>

      <span v-if="verified" class="file-card__badge" :title="verifiedBy || undefined">
        <v-icon size="14">mdi-check-decagram</v-icon> Verified
      </span>

      <div class="file-card__menu" @click.stop @keydown.stop>
        <v-menu location="bottom end">
          <template v-slot:activator="{ props }">
            <v-btn
              v-bind="props"
              class="file-card__more text-medium-emphasis"
              icon="mdi-dots-horizontal"
              variant="flat"
              size="x-small"
              :loading="busy"
              :aria-label="`Actions for ${title}`"
            ></v-btn>
          </template>
          <v-list density="compact" rounded="lg">
            <v-list-item title="Download" prepend-icon="mdi-download" @click="emit('download')"></v-list-item>
            <v-list-item
              :title="verified ? 'Unverify' : 'Mark verified'"
              :prepend-icon="verified ? 'mdi-close-circle-outline' : 'mdi-check-decagram-outline'"
              :disabled="busy"
              @click="emit('verify')"
            ></v-list-item>
            <v-list-item title="Delete" prepend-icon="mdi-delete-outline" base-color="error" @click="emit('delete')"></v-list-item>
          </v-list>
        </v-menu>
      </div>
    </div>

    <div class="file-card__body">
      <div class="file-card__title text-body-2 font-weight-bold text-high-emphasis" :title="title">{{ title }}</div>
      <div class="text-caption text-medium-emphasis">{{ meta }}</div>
    </div>
  </article>
</template>

<script setup lang="ts">
import { ref } from 'vue'

defineProps<{
  title: string
  url: string
  /** Draw `url` as the thumbnail; otherwise the icon tile. */
  image: boolean
  icon: string
  /** The muted line, e.g. "2.1 MB · Yesterday". */
  meta: string
  verified: boolean
  /** "Name, Role", shown as the badge's tooltip. */
  verifiedBy?: string
  /** A verify write is in flight for this file. */
  busy?: boolean
}>()
const emit = defineEmits<{ open: []; download: []; verify: []; delete: [] }>()

// An image that fails to load falls back to the icon tile instead of a broken box.
const broken = ref(false)
</script>

<style scoped>
.file-card {
  display: flex;
  flex-direction: column;
  min-width: 0;
  border: 1px solid rgba(var(--v-theme-on-surface), 0.1);
  border-radius: 12px;
  background: rgb(var(--v-theme-surface));
  overflow: hidden;
  cursor: pointer;
  transition: border-color var(--motion-fast) var(--ease-out);
}
.file-card:hover { border-color: rgba(var(--v-theme-on-surface), 0.28); }
.file-card:focus-visible { outline: 2px solid rgb(var(--v-theme-primary)); outline-offset: 2px; }

.file-card__thumb {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  height: 128px;
  background: rgba(var(--v-theme-on-surface), 0.05);
}
.file-card__img { width: 100%; height: 100%; object-fit: cover; }

/* Over a photo, so both corners sit on the surface colour, not on the image. */
.file-card__badge {
  position: absolute;
  top: 8px;
  left: 8px;
  display: inline-flex;
  align-items: center;
  gap: 4px;
  height: 24px;
  padding: 0 8px;
  border-radius: 999px;
  font-size: 0.75rem;
  font-weight: 500;
  background: rgb(var(--v-theme-surface));
  color: rgb(var(--v-theme-primary-strong));
}
.file-card__menu { position: absolute; top: 6px; right: 6px; }
.file-card__more { background: rgb(var(--v-theme-surface)) !important; }

.file-card__body { padding: 10px 12px 12px; min-width: 0; }
.file-card__title {
  display: -webkit-box;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
  line-clamp: 2;
  overflow: hidden;
  overflow-wrap: anywhere;
  margin-bottom: 2px;
}
</style>
