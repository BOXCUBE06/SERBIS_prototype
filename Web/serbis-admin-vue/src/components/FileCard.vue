<!--
  FileCard.vue

  One document in the Documents grid: a thumbnail (the image itself, or a
  neutral icon tile for a PDF or anything else), the title, and a muted
  "size · date" line. The ⋯ menu holds Download and Delete. Clicking the card
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

      <span class="file-card__ext">{{ ext }}</span>

      <div class="file-card__menu" @click.stop @keydown.stop>
        <v-menu location="bottom end">
          <template v-slot:activator="{ props }">
            <v-btn
              v-bind="props"
              class="file-card__more text-medium-emphasis"
              icon="mdi-dots-horizontal"
              variant="flat"
              size="default"
              :aria-label="`Actions for ${title}`"
            ></v-btn>
          </template>
          <v-list density="compact" rounded="lg">
            <v-list-item title="Download" prepend-icon="mdi-download" @click="emit('download')"></v-list-item>
            <v-list-item title="Delete" prepend-icon="mdi-delete-outline" base-color="error" @click="emit('delete')"></v-list-item>
          </v-list>
        </v-menu>
      </div>
    </div>

    <div class="file-card__body">
      <div class="file-card__title text-body-2 font-weight-bold text-high-emphasis" :title="title">{{ title }}</div>
      <div class="file-card__meta">{{ meta }}</div>
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
  /** The extension, shown as the badge over the preview. */
  ext: string
}>()
const emit = defineEmits<{ open: []; download: []; delete: [] }>()

// An image that fails to load falls back to the icon tile instead of a broken box.
const broken = ref(false)
</script>

<style scoped>
.file-card {
  display: flex;
  flex-direction: column;
  min-width: 0;
  border-radius: 24px;
  background: rgb(var(--v-theme-surface));
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04), 0 4px 14px rgba(0, 0, 0, 0.08);
  overflow: hidden;
  cursor: pointer;
}
.file-card:focus-visible { outline: 2px solid rgb(var(--v-theme-primary)); outline-offset: 2px; }

.file-card__thumb {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  height: 150px;
  background: rgba(var(--v-theme-on-surface), 0.05);
}
.file-card__img { width: 100%; height: 100%; object-fit: cover; }

/* Extension badge, bottom left of the preview. */
.file-card__ext {
  position: absolute;
  left: 12px;
  bottom: 10px;
  padding: 2px 10px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 700;
  background: rgba(255, 255, 255, 0.92);
  color: #334155;
}
/* The ⋯ button: a 40px white circle with its own small shadow. */
.file-card__menu { position: absolute; top: 10px; right: 10px; }
.file-card__more {
  width: 40px;
  height: 40px;
  background: #fff !important;
  color: rgba(0, 0, 0, 0.7);
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
}
.file-card__more :deep(.v-icon) { font-size: 18px; }

.file-card__body { padding: 14px 16px 16px; min-width: 0; }
.file-card__title {
  display: -webkit-box;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
  line-clamp: 2;
  overflow: hidden;
  overflow-wrap: anywhere;
}
.file-card__meta {
  margin-top: 4px;
  font-size: 12px;
  line-height: 16px;
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
}
</style>
