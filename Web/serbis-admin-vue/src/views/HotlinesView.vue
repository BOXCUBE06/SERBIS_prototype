<template>
  <v-container fluid class="fill-height align-start bg-background">
    <PageHeader title="Emergency Hotlines">
      <template v-slot:subtitle>
        The numbers residents see in the app's Library. The app keeps the last list it downloaded, so a change reaches a phone the next time it is online.
      </template>
      <template v-slot:actions>
        <v-btn color="primary" variant="flat" rounded="lg" height="36" class="px-5 text-none font-weight-bold" prepend-icon="mdi-plus" @click="openAdd">
          Add hotline
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
        <template v-slot:item.label="{ item }">
          <div class="font-weight-bold text-high-emphasis">{{ item.label }}</div>
          <div v-if="item.label_fil && item.label_fil !== item.label" class="text-caption text-medium-emphasis">{{ item.label_fil }}</div>
        </template>

        <template v-slot:item.numbers="{ item }">
          <div v-for="(n, i) in item.numbers" :key="i" class="text-body-2">
            <span v-if="n.label" class="text-medium-emphasis">{{ n.label }}: </span>{{ n.number }}
          </div>
        </template>

        <template v-slot:item.actions="{ item, index }">
          <div class="d-flex justify-end">
            <v-btn icon="mdi-arrow-up" variant="text" size="small" :disabled="index === 0 || moving" aria-label="Move up" @click.stop="move(index, -1)"></v-btn>
            <v-btn icon="mdi-arrow-down" variant="text" size="small" :disabled="index === rows.length - 1 || moving" aria-label="Move down" @click.stop="move(index, 1)"></v-btn>
            <v-btn icon="mdi-pencil-outline" variant="text" size="small" aria-label="Edit" @click.stop="openEdit(item)"></v-btn>
            <v-btn icon="mdi-delete-outline" variant="text" size="small" color="error" aria-label="Delete" @click.stop="deleteDialog = { show: true, hotline: item, loading: false }"></v-btn>
          </div>
        </template>
      </DataTablePage>
    </div>

    <!-- Add / Edit -->
    <v-dialog v-model="formDialog.show" max-width="520" persistent>
      <v-card rounded="xl" class="pa-2">
        <v-card-title class="d-flex justify-space-between align-center pa-6 pb-2">
          <span class="text-h6 font-weight-bold text-high-emphasis">{{ formDialog.editing ? 'Edit hotline' : 'Add hotline' }}</span>
          <v-btn icon="mdi-close" variant="tonal" rounded="circle" size="small" @click="formDialog.show = false"></v-btn>
        </v-card-title>
        <v-card-text class="px-6 py-2">
          <v-alert v-if="formDialog.error" type="error" variant="tonal" density="compact" rounded="lg" class="mb-4" role="alert">{{ formDialog.error }}</v-alert>
          <v-form ref="formRef">
            <v-text-field v-model="form.label" label="Name *" :rules="[required]" :error-messages="fieldErrors.label" variant="outlined" density="comfortable" rounded="lg" class="mb-3"></v-text-field>
            <v-text-field v-model="form.label_fil" label="Name in Filipino" hint="Leave blank to show the English name" persistent-hint :error-messages="fieldErrors.label_fil" variant="outlined" density="comfortable" rounded="lg" class="mb-3"></v-text-field>

            <div class="d-flex justify-space-between align-center mb-2">
              <span class="text-subtitle-2 font-weight-bold">Numbers</span>
              <v-btn variant="text" size="small" class="text-none" prepend-icon="mdi-plus" :disabled="form.numbers.length >= 6" @click="form.numbers.push({ label: '', number: '' })">Add number</v-btn>
            </div>
            <div v-for="(n, i) in form.numbers" :key="i" class="d-flex align-start ga-2 mb-2">
              <v-text-field v-model="n.label" label="Carrier / line" placeholder="e.g. Globe" variant="outlined" density="compact" rounded="lg" class="flex-0-0" style="width: 150px" :error-messages="fieldErrors[`numbers.${i}.label`]"></v-text-field>
              <v-text-field :model-value="n.number" @input="(e) => (n.number = e.target.value = phoneChars(e.target.value))" inputmode="tel" maxlength="20" label="Number *" placeholder="0917-000-0000" :rules="[required]" variant="outlined" density="compact" rounded="lg" :error-messages="fieldErrors[`numbers.${i}.number`]"></v-text-field>
              <!-- At least one number: the first row cannot be removed. -->
              <v-btn v-if="i > 0" icon="mdi-close" variant="text" size="small" class="mt-1" aria-label="Remove number" @click="form.numbers.splice(i, 1)"></v-btn>
            </div>
          </v-form>
        </v-card-text>
        <v-card-actions class="pa-6 pt-2 justify-end gap-3">
          <v-btn variant="outlined" color="primary" rounded="lg" class="text-none" :disabled="formDialog.loading" @click="formDialog.show = false">Cancel</v-btn>
          <v-btn color="primary" variant="flat" rounded="lg" class="px-6 text-none font-weight-bold" :loading="formDialog.loading" @click="save">
            {{ formDialog.editing ? 'Save changes' : 'Add hotline' }}
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

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
import { authHeaders, useSnackbar } from '@/composables/adminUi'
import { API_BASE } from '@/config/api'
import { REFERENCE_TTL_MS, invalidate, useCachedFetch } from '@/composables/useCachedFetch'
import PageHeader from '@/components/PageHeader.vue'
import DataTablePage from '@/components/DataTablePage.vue'

const headers = [
  { title: 'Hotline', key: 'label', sortable: false, width: '35%' },
  { title: 'Numbers', key: 'numbers', sortable: false },
  { title: '', key: 'actions', sortable: false, align: 'end', width: '200px' },
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
</style>
