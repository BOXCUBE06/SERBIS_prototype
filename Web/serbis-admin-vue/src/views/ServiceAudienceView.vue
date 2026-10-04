<template>
  <v-container fluid class="fill-height align-start bg-background">
    <PageHeader title="Service audience">
      <template v-slot:subtitle>
        Choose which account types can request each service. An unchecked service is hidden from that account type in the mobile app and refused if filed.
      </template>
    </PageHeader>

    <v-alert v-if="apiError" type="error" variant="tonal" class="mb-6 w-100" density="compact" rounded="lg">
      {{ apiError }}
      <template v-slot:append>
        <v-btn variant="outlined" color="primary" size="small" class="text-none font-weight-bold" @click="load">Retry</v-btn>
      </template>
    </v-alert>

    <div class="w-100">
      <ChoiceMatrix
        :columns="ACCOUNT_TYPE_ITEMS.map((t) => t.title)"
        :rows="matrixRows"
        :footer="summary"
        :loading="initialLoad"
        :refreshing="refreshing"
        empty-text="No services"
        @toggle="onToggle"
      />
    </div>

    <v-snackbar v-model="snackbar.show" :color="snackbar.color" :timeout="3500" location="bottom right">
      {{ snackbar.text }}
    </v-snackbar>
  </v-container>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { ACCOUNT_TYPE_ITEMS } from '@/composables/accountType'
import { authHeaders, pluralize, useSnackbar } from '@/composables/adminUi'
import { API_BASE } from '@/config/api'
import { REFERENCE_TTL_MS, invalidate, useCachedFetch } from '@/composables/useCachedFetch'
import PageHeader from '@/components/PageHeader.vue'
import ChoiceMatrix from '@/components/ChoiceMatrix.vue'

// One row per thing a resident can ask for, including the two that are not
// services (Equipment Borrowing, Others). Ticking a box saves at once: there is
// no Save button, because a half-saved grid is worse than a slow one.
const rows = ref([])
const initialLoad = ref(true)
const apiError = ref('')
// The code of the row whose save is in flight; its boxes are disabled meanwhile.
const saving = ref(null)
const { snackbar, notify } = useSnackbar()

// Services and the two app features (Equipment Borrowing, Others) counted apart.
const summary = computed(() => {
  const services = rows.value.filter((r) => r.is_service).length
  return `${pluralize(services, 'service')} · ${pluralize(rows.value.length - services, 'app feature')}`
})

// The matrix's rows: a tag for what is not a plain active service.
const matrixRows = computed(() => rows.value.map((r) => ({
  key: r.code,
  name: r.name,
  tag: r.is_service ? (r.is_active ? '' : 'Switched off') : 'App feature',
  dim: !r.is_active,
  busy: saving.value === r.code,
  checks: ACCOUNT_TYPE_ITEMS.map((t) => r.account_types.includes(t.value)),
})))
const onToggle = (matrixRow, column, checked) => {
  const row = rows.value.find((r) => r.code === matrixRow.key)
  toggle(row, ACCOUNT_TYPE_ITEMS[column].value, checked)
}

const { get, refreshing } = useCachedFetch()

const load = async () => {
  apiError.value = ''
  try {
    await get('/service-audience', {
      ttl: REFERENCE_TTL_MS,
      onData: (body) => { rows.value = body.data; initialLoad.value = false },
    })
  } catch {
    apiError.value = 'Could not load the service audience.'
  } finally {
    initialLoad.value = false
  }
}

const toggle = async (row, type, checked) => {
  const before = [...row.account_types]
  const next = checked ? [...before, type] : before.filter((t) => t !== type)

  // An empty set would read as "open to everyone" on the server, the opposite
  // of an empty row of boxes, so it is refused here and there. The boxes are
  // controlled, so the tick simply does not take.
  if (next.length === 0) {
    notify('Keep at least one account type. To stop a service being requested, switch it off in Manage Services.', 'warning')
    return
  }

  row.account_types = next
  saving.value = row.code

  try {
    const res = await fetch(`${API_BASE}/service-audience/${encodeURIComponent(row.code)}`, {
      method: 'PUT',
      headers: authHeaders(),
      body: JSON.stringify({ account_types: next }),
    })
    const body = await res.json().catch(() => ({}))
    if (!res.ok) throw new Error(body.errors?.account_types?.[0] || body.message || 'Could not save that change.')
    row.account_types = body.account_types
    invalidate('/service-audience')
    notify(`Saved: ${row.name}`)
  } catch (error) {
    row.account_types = before
    notify(error.message, 'error')
  } finally {
    saving.value = null
  }
}

onMounted(load)
</script>
