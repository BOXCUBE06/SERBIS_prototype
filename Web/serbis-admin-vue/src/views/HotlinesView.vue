<template>
  <v-container fluid class="fill-height align-start bg-background">
    <PageHeader title="Emergency hotlines">
      <template v-slot:subtitle>
        The numbers residents see in the app's Library. The app keeps the last list it downloaded, so a change reaches a phone the next time it is online.
      </template>
      <template v-slot:actions>
        <v-btn color="primary" variant="flat" height="40" class="add-btn text-none font-weight-bold" @click="openAdd">
          <v-icon start size="16">mdi-plus</v-icon> Add hotline
        </v-btn>
      </template>
    </PageHeader>

    <v-alert v-if="apiError" type="error" variant="tonal" class="mb-6 w-100" density="compact" rounded="lg">
      {{ apiError }}
      <template v-slot:append>
        <v-btn variant="outlined" color="primary" size="small" class="text-none font-weight-bold" @click="load">Retry</v-btn>
      </template>
    </v-alert>

    <div class="w-100">
      <DataTablePage
        :refreshing="refreshing"
        compact
        filter-bar
        board-table
        :row-height="56"
        class="hotline-table"
        :searchable="false"
        :loading="initialLoad"
        :headers="headers"
        :items="rows"
        item-value="hotline_id"
        :items-per-page="50"
        :items-per-page-options="[50]"
        result-noun="hotlines"
        no-data-text="No hotlines — the app falls back to its built-in list"
      >
        <template v-slot:summary>{{ pluralize(rows.length, 'hotline') }}</template>

        <template v-slot:item.label="{ item }">
          <div class="hotline-name">{{ item.label }}</div>
          <div v-if="item.label_fil && item.label_fil !== item.label" class="hotline-sub">{{ item.label_fil }}</div>
        </template>

        <template v-slot:item.numbers="{ item }">
          <div v-for="(n, i) in item.numbers" :key="i" class="hotline-line">
            <span v-if="n.label" class="hotline-line__label">{{ n.label }}:</span> {{ n.number }}
          </div>
        </template>

        <template v-slot:item.actions="{ item, index }">
          <div class="d-flex justify-end hotline-actions">
            <v-btn icon="mdi-arrow-up" variant="text" :disabled="index === 0 || moving" :aria-label="`Move ${item.label} up`" @click.stop="move(index, -1)"></v-btn>
            <v-btn icon="mdi-arrow-down" variant="text" :disabled="index === rows.length - 1 || moving" :aria-label="`Move ${item.label} down`" @click.stop="move(index, 1)"></v-btn>
            <v-btn icon="mdi-pencil-outline" variant="text" class="hotline-actions__quiet" :aria-label="`Edit ${item.label}`" @click.stop="openEdit(item)"></v-btn>
            <v-btn icon="mdi-delete-outline" variant="text" color="error" :aria-label="`Delete ${item.label}`" @click.stop="deleteDialog = { show: true, hotline: item, loading: false }"></v-btn>
          </div>
        </template>
      </DataTablePage>
    </div>

    <!-- Add / Edit -->
    <EditDialog
      ref="formRef"
      v-model="formDialog.show"
      :title="formDialog.editing ? 'Edit hotline' : 'Add hotline'"
      :confirm-label="formDialog.editing ? 'Save changes' : 'Add hotline'"
      :width="520"
      :fields="hotlineFields"
      :form="form"
      :field-errors="fieldErrors"
      :error="formDialog.error"
      :loading="formDialog.loading"
      @save="save"
    />

    <!-- Delete confirm -->
    <v-dialog v-model="deleteDialog.show" max-width="420">
      <v-card rounded="xl" class="pa-2">
        <v-card-title class="pa-6 pb-2 text-h6 font-weight-bold text-high-emphasis">Delete hotline?</v-card-title>
        <v-card-text class="px-6 py-4 text-body-2 text-medium-emphasis">
          <strong class="text-high-emphasis">{{ deleteDialog.hotline?.label }}</strong> will disappear from the app the next time each phone is online. This cannot be undone.
        </v-card-text>
        <v-card-actions class="pa-6 pt-2 justify-end gap-3">
          <v-btn variant="outlined" color="primary" rounded="lg" class="text-none" :disabled="deleteDialog.loading" @click="deleteDialog.show = false">Cancel</v-btn>
          <v-btn color="error" variant="flat" rounded="lg" class="px-6 text-none font-weight-bold" :loading="deleteDialog.loading" @click="confirmDelete">Delete</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <v-snackbar v-model="snackbar.show" :color="snackbar.color" :timeout="3500" location="bottom right">
      {{ snackbar.text }}
    </v-snackbar>
  </v-container>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { authHeaders, pluralize, useSnackbar } from '@/composables/adminUi'
import { API_BASE } from '@/config/api'
import { REFERENCE_TTL_MS, invalidate, useCachedFetch } from '@/composables/useCachedFetch'
import PageHeader from '@/components/PageHeader.vue'
import DataTablePage from '@/components/DataTablePage.vue'
import EditDialog from '@/components/EditDialog.vue'

const headers = [
  { title: 'Hotline', key: 'label', sortable: false, width: '34%' },
  { title: 'Numbers', key: 'numbers', sortable: false },
  { title: 'Actions', key: 'actions', sortable: false, align: 'end', width: '200px' },
]

const rows = ref([])
const initialLoad = ref(true)
const apiError = ref('')
const moving = ref(false)
const { snackbar, notify } = useSnackbar()

const required = (v) => !!(v && String(v).trim()) || 'Required'
// Letters are dropped as they are typed. Digits plus ( ) + - and spaces, the
// same set EmergencyHotlineController accepts, so landlines like (078) 324-5410 still fit.
const phoneChars = (v) => (v || '').replace(/[^0-9()+\- ]/g, '')

// The add / edit dialog's fields. `numbers` is the repeatable carrier + number rows.
const hotlineFields = [
  { key: 'label', label: 'Name', required: true, rules: [required] },
  { key: 'label_fil', label: 'Name in Filipino', hint: 'Leave blank to show the English name.' },
  { key: 'numbers', label: 'Numbers', numbers: true, maxRows: 6, rules: [required], sanitize: phoneChars, inputmode: 'tel', maxlength: 20 },
]

const { get, refreshing } = useCachedFetch()

const load = async (fresh = false) => {
  apiError.value = ''
  try {
    await get('/hotlines', {
      ttl: REFERENCE_TTL_MS,
      fresh,
      onData: (body) => { rows.value = body; initialLoad.value = false },
    })
  } catch {
    apiError.value = 'Could not load the hotlines.'
  } finally {
    initialLoad.value = false
  }
}

// After a write: drop the cached list, then fetch past it.
const reload = () => { invalidate('/hotlines'); return load(true) }

// The fields the API accepts, so a row can be sent back whole (PUT).
const payloadOf = (h) => ({
  label: h.label,
  label_fil: h.label_fil || null,
  numbers: h.numbers.map((n) => ({ label: n.label || null, number: n.number })),
  sort_order: h.sort_order,
})

const put = async (hotline) => {
  const res = await fetch(`${API_BASE}/hotlines/${hotline.hotline_id}`, {
    method: 'PUT',
    headers: authHeaders(),
    body: JSON.stringify(payloadOf(hotline)),
  })
  if (!res.ok) throw new Error('Could not save the new order.')
}

// Swaps sort_order with the neighbour. Positions are renumbered first, so
// rows that share a sort_order still move.
const move = async (index, step) => {
  const list = rows.value.map((h, i) => ({ ...h, sort_order: i + 1 }))
  const a = list[index]
  const b = list[index + step]
  ;[a.sort_order, b.sort_order] = [b.sort_order, a.sort_order]

  moving.value = true
  try {
    await Promise.all([put(a), put(b)])
    await reload()
  } catch (error) {
    notify(error.message, 'error')
  } finally {
    moving.value = false
  }
}

const formRef = ref(null)
const formDialog = ref({ show: false, editing: null, loading: false, error: '' })
const form = ref(null)
const fieldErrors = ref({})
const blankForm = () => ({ label: '', label_fil: '', numbers: [{ label: '', number: '' }] })

const openAdd = () => {
  form.value = blankForm()
  fieldErrors.value = {}
  formDialog.value = { show: true, editing: null, loading: false, error: '' }
}

const openEdit = (hotline) => {
  form.value = {
    label: hotline.label,
    label_fil: hotline.label_fil || '',
    numbers: hotline.numbers.map((n) => ({ label: n.label || '', number: n.number })),
  }
  fieldErrors.value = {}
  formDialog.value = { show: true, editing: hotline, loading: false, error: '' }
}

const save = async () => {
  const { valid } = await formRef.value.validate()
  if (!valid) return

  const editing = formDialog.value.editing
  // A new hotline goes to the bottom of the list.
  const sortOrder = editing ? editing.sort_order : Math.max(0, ...rows.value.map((h) => h.sort_order)) + 1

  formDialog.value.loading = true
  formDialog.value.error = ''
  fieldErrors.value = {}
  try {
    const res = await fetch(editing ? `${API_BASE}/hotlines/${editing.hotline_id}` : `${API_BASE}/hotlines`, {
      method: editing ? 'PUT' : 'POST',
      headers: authHeaders(),
      body: JSON.stringify(payloadOf({ ...form.value, sort_order: sortOrder })),
    })
    const body = await res.json().catch(() => ({}))
    if (res.status === 422) {
      fieldErrors.value = body.errors || {}
      formDialog.value.error = 'Check the highlighted fields.'
      return
    }
    if (!res.ok) throw new Error(body.message || 'Could not save the hotline.')
    formDialog.value.show = false
    notify(`Saved: ${body.label}`)
    await reload()
  } catch (error) {
    formDialog.value.error = error.message
  } finally {
    formDialog.value.loading = false
  }
}

const deleteDialog = ref({ show: false, hotline: null, loading: false })

const confirmDelete = async () => {
  const hotline = deleteDialog.value.hotline
  deleteDialog.value.loading = true
  try {
    const res = await fetch(`${API_BASE}/hotlines/${hotline.hotline_id}`, { method: 'DELETE', headers: authHeaders() })
    if (!res.ok) throw new Error('Could not delete the hotline.')
    deleteDialog.value.show = false
    notify(`Deleted: ${hotline.label}`)
    await reload()
  } catch (error) {
    notify(error.message, 'error')
  } finally {
    deleteDialog.value.loading = false
  }
}

onMounted(load)
</script>

<style scoped>
/* Rows do nothing on click; only the buttons do. */
.hotline-table.data-table-page :deep(tbody tr) { cursor: default; }

.add-btn {
  padding: 0 18px;
  border-radius: 12px;
  font-size: 14px;
  letter-spacing: 0;
  box-shadow: 0 8px 16px -4px rgba(var(--v-theme-primary), 0.28);
}

/* Hotline cell: the name, then the Filipino name (12/16). */
.hotline-name { font-weight: 700; white-space: nowrap; }
.hotline-sub { font-size: 12px; line-height: 16px; color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); }
/* Numbers: 20px lines in tabular figures, the carrier muted. */
.hotline-line { line-height: 20px; font-variant-numeric: tabular-nums; }
.hotline-line__label { color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); }

/* Actions: 40px square buttons, 10px radius, 18px icons. Move up / down at full
   strength and dimmed to .35 when off (first row up, last row down). */
.hotline-actions { gap: 4px; }
.hotline-actions .v-btn { width: 40px; height: 40px; border-radius: 10px; }
.hotline-actions .v-icon { font-size: 18px; }
.hotline-actions .v-btn--disabled { opacity: 0.35 !important; }
.hotline-actions__quiet { color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); }
</style>
