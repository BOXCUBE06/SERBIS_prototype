<template>
  <v-container fluid class="pa-6 analytics-bg">

    <PageHeader
      title="Analytics"
      subtitle="Demand, turnaround and backlog over a period. The Dashboard answers today; this answers the quarter."
      class="mb-6"
    />

    <!-- Filter bar. Governs every section below, so it sits above all of them
         and stays put while the page scrolls — the alternative is scrolling
         back up to change a range you are in the middle of reading. -->
    <v-card elevation="0" rounded="xl" class="soft-card mb-6 filter-bar">
      <v-card-text class="py-4">
        <div class="d-flex flex-wrap align-center gap-4">
          <v-btn-toggle
            v-model="preset"
            mandatory
            variant="outlined"
            color="primary"
            density="compact"
            divided
            rounded="lg"
          >
            <v-btn value="month" size="small" class="text-none font-weight-bold px-3">This month</v-btn>
            <v-btn value="quarter" size="small" class="text-none font-weight-bold px-3">This quarter</v-btn>
            <v-btn value="year" size="small" class="text-none font-weight-bold px-3">This year</v-btn>
            <v-btn value="custom" size="small" class="text-none font-weight-bold px-3">Custom</v-btn>
          </v-btn-toggle>

          <template v-if="preset === 'custom'">
            <v-text-field
              v-model="customFrom"
              type="date"
              label="From"
              density="compact"
              variant="outlined"
              hide-details
              class="date-field"
            />
            <v-text-field
              v-model="customTo"
              type="date"
              label="To"
              density="compact"
              variant="outlined"
              hide-details
              class="date-field"
            />
          </template>

          <v-select
            v-model="barangayId"
            :items="barangayOptions"
            item-title="label"
            item-value="value"
            label="Barangay"
            density="compact"
            variant="outlined"
            hide-details
            class="filter-field"
          />

          <v-select
            v-model="serviceId"
            :items="serviceOptions"
            item-title="label"
            item-value="value"
            label="Service"
            density="compact"
            variant="outlined"
            hide-details
            class="filter-field"
          />

          <v-btn
            v-if="hasFilters"
            variant="text"
            size="small"
            color="primary"
            class="text-none font-weight-bold"
            @click="clearFilters"
          >
            Clear
          </v-btn>
        </div>

        <!-- Reconciled against the same helper the Dashboard uses, so the two
             pages cannot report different totals for the same window. -->
        <div v-if="report" class="text-caption text-medium-emphasis mt-3">
          {{ report.range.from }} to {{ report.range.to }} ({{ report.range.timezone }})
          &bull; {{ report.totals.serviceRequests.toLocaleString() }}
          {{ report.totals.serviceRequests === 1 ? 'request' : 'requests' }} in range
          <template v-if="report.totals.walkIn > 0">
            &bull; {{ report.totals.walkIn.toLocaleString() }} walk-in (no barangay)
          </template>
        </div>
      </v-card-text>
    </v-card>

    <v-alert
      v-if="error && !loading"
      type="warning"
      variant="tonal"
      rounded="lg"
      class="mb-6"
    >
      <div class="d-flex align-center justify-space-between flex-wrap gap-3">
        <span>{{ error }}</span>
        <v-btn size="small" variant="tonal" color="warning" class="text-none font-weight-bold" @click="fetchReport">
          Try again
        </v-btn>
      </div>
    </v-alert>

    <!-- Sections 1-5 land here in the next commit. -->

  </v-container>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import PageHeader from '@/components/PageHeader.vue'
import { getToken } from '@/composables/authToken'
import { API_BASE } from '@/config/api'

/*
 * Held sections, deliberately not built and deliberately not stubbed. Each
 * was chosen from the data and cut only for scope, so this list is the
 * shortlist to pick up from rather than a wish list:
 *
 *   6.  Equipment utilization — times borrowed and quantity borrowed per
 *       item, INCLUDING zero-borrow items, because dead stock is half the
 *       purchasing decision and a chart of only borrowed items hides it.
 *   7.  Loan turnaround and overdue — median days released to returned,
 *       currently overdue, share returned late. Columns already exist
 *       (released_at, returned_at, due_date).
 *   8.  Fleet usage — trips per unit and median trip duration from the
 *       conduction timeline. Odometer km is present on a minority of trips,
 *       so it needs its own sample size.
 *   9.  Barangay: residents vs requests — reveals barangays with accounts
 *       but no requests, and barangays with neither. Needs the walk-in row
 *       BarangayRequestCounts already returns.
 *   10. App adoption — walk-in vs app-filed share by month. Measures the
 *       project's own premise and is invisible today.
 *   11. Account activation backlog — Inactive residents and signups over
 *       time. Small, but nothing currently surfaces the waiting accounts.
 *
 * Not built at all, with reasons, so nobody re-proposes them: per-staff
 * productivity (one admin exists; processed_by is set on 2 of 50 rows; and
 * per-person metrics in a three-person office are surveillance), SMS reach
 * (tbl_sms_logs persists nothing), patient demographics (populated on under
 * a quarter of bookings), and per-capita rates (no barangay population).
 */

const ALL = 'all'

const preset = ref('quarter')
const customFrom = ref('')
const customTo = ref('')
const barangayId = ref(ALL)
const serviceId = ref(ALL)

const report = ref(null)
const loading = ref(true)
const error = ref('')

const barangays = ref([])
const services = ref([])

const barangayOptions = computed(() => [
  { label: 'All barangays', value: ALL },
  ...barangays.value.map(b => ({ label: b.barangay_name, value: b.barangay_id })),
])

const serviceOptions = computed(() => [
  { label: 'All services', value: ALL },
  ...services.value.map(s => ({ label: s.service_name, value: s.service_id })),
])

const hasFilters = computed(() =>
  barangayId.value !== ALL || serviceId.value !== ALL || preset.value !== 'quarter'
)

const clearFilters = () => {
  preset.value = 'quarter'
  barangayId.value = ALL
  serviceId.value = ALL
  customFrom.value = ''
  customTo.value = ''
}

const authHeaders = () => ({
  Authorization: `Bearer ${getToken()}`,
  Accept: 'application/json',
})

const queryString = () => {
  const params = new URLSearchParams({ preset: preset.value })

  // Only sent when both ends are present: the server treats a half-filled
  // custom range as no range at all and falls back to the quarter, which
  // would read as the filter silently doing nothing.
  if (preset.value === 'custom' && customFrom.value && customTo.value) {
    params.set('from', customFrom.value)
    params.set('to', customTo.value)
  }

  if (barangayId.value !== ALL) params.set('barangay_id', barangayId.value)
  if (serviceId.value !== ALL) params.set('service_id', serviceId.value)

  return params.toString()
}

const fetchReport = async () => {
  loading.value = true
  error.value = ''

  try {
    const response = await fetch(`${API_BASE}/admin/analytics?${queryString()}`, { headers: authHeaders() })

    if (!response.ok) throw new Error(`Request failed (${response.status})`)

    report.value = await response.json()
  } catch {
    // The sections read their own error prop from this, so one failed fetch
    // does not leave stale numbers on screen looking current.
    report.value = null
    error.value = 'Could not load analytics. The server may be unreachable.'
  } finally {
    loading.value = false
  }
}

const fetchFilterOptions = async () => {
  try {
    const [barangayResponse, serviceResponse] = await Promise.all([
      fetch(`${API_BASE}/barangays`, { headers: authHeaders() }),
      fetch(`${API_BASE}/services`, { headers: authHeaders() }),
    ])

    if (barangayResponse.ok) barangays.value = await barangayResponse.json()

    if (serviceResponse.ok) {
      // /services answers {data: [...]} while /barangays answers a bare
      // array. Three response envelopes are already in use across this API;
      // do not assume a shape here.
      const payload = await serviceResponse.json()
      services.value = payload.data ?? payload
    }
  } catch {
    // A filter list that fails to load leaves "All" selected, which is the
    // correct default anyway — not worth failing the page over.
  }
}

// A custom range with only one end filled is not yet a range, so it must not
// fire a fetch that would silently return the quarter.
watch([preset, barangayId, serviceId, customFrom, customTo], () => {
  if (preset.value === 'custom' && !(customFrom.value && customTo.value)) return
  fetchReport()
})

onMounted(() => {
  fetchFilterOptions()
  fetchReport()
})

defineExpose({ fetchReport })
</script>

<style scoped>
.analytics-bg {
  background-color: rgb(var(--v-theme-background));
}

.soft-card {
  border: 1px solid rgba(var(--v-theme-on-surface), 0.08);
  box-shadow: 0 1px 2px rgba(var(--v-theme-on-surface), 0.04), 0 4px 14px rgba(var(--v-theme-on-surface), 0.08);
}

/* Sticky so the controls stay reachable while reading a section far down the
   page. z-index keeps it above the cards it scrolls over. */
.filter-bar {
  position: sticky;
  top: 0;
  z-index: 3;
  background-color: rgb(var(--v-theme-surface));
}

.gap-4 {
  gap: 16px;
}

/* A max-width alone collapses these to ~100px inside a flex row (see the
   PageHeader note on the same trap). Both need a real width. */
.filter-field {
  width: 190px;
  max-width: 100%;
}

.date-field {
  width: 170px;
  max-width: 100%;
}
</style>
