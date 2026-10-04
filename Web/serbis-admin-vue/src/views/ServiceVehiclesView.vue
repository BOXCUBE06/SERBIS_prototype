<template>
  <v-container fluid class="fill-height align-start bg-background">
    <PageHeader title="Service vehicles">
      <template v-slot:subtitle>
        Choose which vehicle types can be sent on each service. With none checked, any available non-ambulance unit can be assigned.
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
        :columns="types"
        :rows="matrixRows"
        :footer="pluralize(rows.length, 'service')"
        :loading="initialLoad"
        :refreshing="refreshing"
        empty-text="No services dispatch a unit"
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
import { authHeaders, pluralize, useSnackbar } from '@/composables/adminUi'
import { API_BASE } from '@/config/api'
import { REFERENCE_TTL_MS, invalidate, useCachedFetch } from '@/composables/useCachedFetch'
import PageHeader from '@/components/PageHeader.vue'
import ChoiceMatrix from '@/components/ChoiceMatrix.vue'

// One row per service that dispatches a unit. Ticking a box saves at once, like
// the Service Audience page: there is no Save button, because a half-saved grid
// is worse than a slow one.
const rows = ref([])
const types = ref([])
const initialLoad = ref(true)
const apiError = ref('')
// The code of the row whose save is in flight; its boxes are disabled meanwhile.
const saving = ref(null)
const { snackbar, notify } = useSnackbar()

// The unit types arrive with the rows, so the columns appear with them.
const matrixRows = computed(() => rows.value.map((r) => ({
  key: r.code,
  name: r.name,
  tag: r.is_active ? '' : 'Switched off',
  dim: !r.is_active,
  busy: saving.value === r.code,
  checks: types.value.map((t) => r.vehicle_types.includes(t)),
})))
const onToggle = (matrixRow, column, checked) => {
  const row = rows.value.find((r) => r.code === matrixRow.key)
  toggle(row, types.value[column], checked)
}

const { get, refreshing } = useCachedFetch()

const load = async () => {
  apiError.value = ''
  try {
    await get('/service-vehicle-types', {
      ttl: REFERENCE_TTL_MS,
      onData: (body) => { rows.value = body.data; types.value = body.types; initialLoad.value = false },
    })
  } catch {
    apiError.value = 'Could not load the service vehicles.'
  } finally {
    initialLoad.value = false
  }
}

const toggle = async (row, type, checked) => {
  const before = [...row.vehicle_types]
  const next = checked ? [...before, type] : before.filter((t) => t !== type)

  row.vehicle_types = next
  saving.value = row.code

  try {
    const res = await fetch(`${API_BASE}/service-vehicle-types/${encodeURIComponent(row.code)}`, {
      method: 'PUT',
      headers: authHeaders(),
      body: JSON.stringify({ vehicle_types: next }),
    })
    const body = await res.json().catch(() => ({}))
    if (!res.ok) throw new Error(body.message || 'Could not save that change.')
    row.vehicle_types = body.vehicle_types
    invalidate('/service-vehicle-types')
    notify(`Saved: ${row.name}`)
  } catch (error) {
    row.vehicle_types = before
    notify(error.message, 'error')
  } finally {
    saving.value = null
  }
}

onMounted(load)
</script>
