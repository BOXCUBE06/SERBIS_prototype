<!--
  DashboardCharts.vue

  The chart row under the queue. Built from what the dashboard already loaded,
  so nothing here fetches. Reuses the Analytics page's section card and its
  screen-reader table.
-->
<template>
  <v-row>
    <v-col cols="12" md="6" xl="3">
      <AnalyticsSection title="Fleet status" :empty="!fleet || fleet.total === 0" empty-text="No vehicle data">
        <div style="height: 220px;">
          <Doughnut :data="fleetData" :options="donut" />
          <ChartDataTable
            caption="Fleet status — same data as the chart above"
            category-label="Status"
            :labels="fleetLabels"
            :series="[{ label: 'Units', data: fleetCounts }]"
          />
        </div>
      </AnalyticsSection>
    </v-col>
  </v-row>
</template>

<script setup>
import { computed } from 'vue'
import { Doughnut } from 'vue-chartjs'
import AnalyticsSection from '@/components/AnalyticsSection.vue'
import ChartDataTable from '@/components/ChartDataTable.vue'
import { BOOKED_COLOR } from '@/composables/adminUi'
import { useChartTheme } from '@/composables/useChartTheme'

const props = defineProps({
  // { total, available, assigned, onTrip, maintenance }, or null when the account cannot read vehicles.
  fleet: { type: Object, default: null },
})

const { colors, isDark, donut } = useChartTheme()

// Status colours, so the donut reads like the pills elsewhere in the panel.
const slices = computed(() => {
  const f = props.fleet
  if (!f) return []
  return [
    { label: 'Available', count: f.available, color: colors.value.success },
    { label: 'Assigned', count: f.assigned, color: isDark.value ? '#A78BFA' : BOOKED_COLOR },
    { label: 'On trip', count: f.onTrip, color: colors.value.info },
    { label: 'Maintenance', count: f.maintenance, color: colors.value.warning },
  ].filter((s) => s.count > 0)
})

const fleetLabels = computed(() => slices.value.map((s) => s.label))
const fleetCounts = computed(() => slices.value.map((s) => s.count))
const fleetData = computed(() => ({
  labels: fleetLabels.value,
  datasets: [{
    data: fleetCounts.value,
    backgroundColor: slices.value.map((s) => s.color),
    borderColor: colors.value.surface,
    borderWidth: 2,
  }],
}))
</script>
