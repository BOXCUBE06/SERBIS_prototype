<!--
  RowActions.vue

  Edit and Delete for a resource row: quiet icon buttons with tooltips. Delete is
  error-coloured at reduced strength, so it reads as destructive without hover.
  Three optional props shape the row: `extra` adds one more action (Accounts'
  Activate/Deactivate, Documents' Download), `deletable=false` drops Delete
  (Accounts deletes from the profile) and `editable=false` drops Edit
  (Documents has nothing to edit). Below 600px everything collapses into one "more" menu, so the
  column stays narrow. `label` names the row for screen readers. The wrapper
  stops clicks so a row click (which opens Edit or the profile) is not also
  triggered from here.
-->
<template>
  <div class="d-flex justify-end gap-1" @click.stop>
    <div class="row-actions__inline d-flex gap-1">
      <v-tooltip v-if="editable" text="Edit" location="top">
        <template v-slot:activator="{ props }">
          <v-btn
            v-bind="props"
            icon="mdi-pencil-outline"
            variant="text"
            size="small"
            class="text-medium-emphasis"
            :aria-label="`Edit ${label}`"
            @click="emit('edit')"
          ></v-btn>
        </template>
      </v-tooltip>
      <v-tooltip v-if="extra" :text="extra.label" location="top">
        <template v-slot:activator="{ props }">
          <v-btn
            v-bind="props"
            :icon="extra.icon"
            variant="text"
            size="small"
            :color="extra.color"
            :disabled="extra.disabled"
            :aria-label="`${extra.label}: ${label}`"
            @click="emit('extra')"
          ></v-btn>
        </template>
      </v-tooltip>
      <v-tooltip v-if="deletable" text="Delete" location="top">
        <template v-slot:activator="{ props }">
          <v-btn
            v-bind="props"
            icon="mdi-delete-outline"
            variant="text"
            size="small"
            class="text-error row-action-delete"
            :aria-label="`Delete ${label}`"
            @click="emit('delete')"
          ></v-btn>
        </template>
      </v-tooltip>
    </div>

    <v-menu location="bottom end">
      <template v-slot:activator="{ props }">
        <v-btn
          v-bind="props"
          class="row-actions__more text-medium-emphasis"
          icon="mdi-dots-horizontal"
          variant="text"
          size="small"
          :aria-label="`Actions for ${label}`"
        ></v-btn>
      </template>
      <v-list density="compact" rounded="lg">
        <v-list-item v-if="editable" title="Edit" prepend-icon="mdi-pencil-outline" @click="emit('edit')"></v-list-item>
        <v-list-item v-if="extra" :title="extra.label" :prepend-icon="extra.icon" :disabled="extra.disabled" @click="emit('extra')"></v-list-item>
        <v-list-item v-if="deletable" title="Delete" prepend-icon="mdi-delete-outline" base-color="error" @click="emit('delete')"></v-list-item>
      </v-list>
    </v-menu>
  </div>
</template>

<script setup lang="ts">
export interface RowExtra {
  label: string
  icon: string
  color?: string
  disabled?: boolean
}

withDefaults(defineProps<{ label: string; extra?: RowExtra | null; deletable?: boolean; editable?: boolean }>(), {
  extra: null,
  deletable: true,
  editable: true,
})
const emit = defineEmits<{ edit: []; delete: []; extra: [] }>()
</script>

<style scoped>
.gap-1 { gap: 4px; }
.row-action-delete { opacity: 0.7; transition: opacity var(--motion-fast) var(--ease-out); }
.row-action-delete:hover,
.row-action-delete:focus-visible { opacity: 1; }
.row-actions__more { display: none !important; }
@media (max-width: 599px) {
  .row-actions__inline { display: none !important; }
  .row-actions__more { display: inline-flex !important; }
}
</style>
