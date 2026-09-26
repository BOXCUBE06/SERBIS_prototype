<template>
  <v-container fluid class="fill-height align-start bg-background">
    <v-row class="ma-0 w-100">
      <v-col cols="12" class="pa-0 w-100">

        <PageHeader title="Service Audience" />

        <v-alert v-if="apiError" type="error" variant="tonal" class="mb-6" density="comfortable" rounded="lg">
          {{ apiError }}
          <template v-slot:append>
            <v-btn variant="outlined" color="primary" class="text-none font-weight-bold" @click="load">Retry</v-btn>
          </template>
        </v-alert>

        <v-card elevation="0" rounded="xl" class="checkbox-grid-card">
          <v-table :key="initialLoad ? 'loading' : 'ready'" class="checkbox-grid table-fade">
            <thead>
              <tr>
                <th scope="col" class="text-left">Service</th>
                <th v-for="type in ACCOUNT_TYPE_ITEMS" :key="type.value" scope="col" class="checkbox-grid-check">
                  {{ type.title }}
                </th>
              </tr>
            </thead>
            <tbody>
              <SkeletonRows v-if="initialLoad" :rows="6" :columns="ACCOUNT_TYPE_ITEMS.length + 1" />
              <tr v-for="row in rows" :key="row.code" :data-code="row.code">
                <th scope="row" class="text-left font-weight-bold checkbox-grid-service">
                  <span :class="{ 'text-disabled': !row.is_active }">{{ row.name }}</span>
                  <span v-if="!row.is_active" class="text-caption text-medium-emphasis ml-2">Switched off</span>
                </th>
                <td v-for="type in ACCOUNT_TYPE_ITEMS" :key="type.value" class="checkbox-grid-check">
                  <v-checkbox-btn
                    :model-value="row.account_types.includes(type.value)"
                    :disabled="saving === row.code"
                    :aria-label="`${type.title} may request ${row.name}`"
                    color="primary"
                    @update:model-value="(checked) => toggle(row, type.value, checked)"
                  ></v-checkbox-btn>
                </td>
              </tr>
            </tbody>
          </v-table>
        </v-card>
      </v-col>
    </v-row>

    <v-snackbar v-model="snackbar.show" :color="snackbar.color" :timeout="3500" location="bottom right">
      {{ snackbar.text }}
    </v-snackbar>
  </v-container>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { ACCOUNT_TYPE_ITEMS } from '@/composables/accountType'
import { authHeaders, useSnackbar } from '@/composables/adminUi'
import { API_BASE } from '@/config/api'
import PageHeader from '@/components/PageHeader.vue'
import SkeletonRows from '@/components/SkeletonRows.vue'
import '@/styles/checkbox-grid.css'

// One row per thing a resident can ask for, including the two that are not
// services (Equipment Borrowing, Others). Ticking a box saves at once: there is
// no Save button, because a half-saved grid is worse than a slow one.
const rows = ref([])
const initialLoad = ref(true)
const apiError = ref('')
// The code of the row whose save is in flight; its boxes are disabled meanwhile.
const saving = ref(null)
const { snackbar, notify } = useSnackbar()

const load = async () => {
  apiError.value = ''
  try {
    const res = await fetch(`${API_BASE}/service-audience`, { headers: authHeaders() })
    if (!res.ok) throw new Error('Could not load the service audience.')
    rows.value = (await res.json()).data
  } catch (error) {
    apiError.value = error.message || 'Could not load the service audience.'
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

