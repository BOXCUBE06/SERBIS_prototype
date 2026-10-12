<!--
  BlastDetail.vue

  One text blast opened: when and by whom, whether it has been checked, the
  message, the four counts, then a table of the barangays it reached. A
  barangay opens to the residents it went to, one page at a time. Presentational:
  BlastHistory owns the data and the calls, this only draws and emits.
-->
<template>
  <section class="bd" :aria-label="`Blast of ${formatWhen(blast.created_at)}`">
    <header class="bd-head">
      <div class="min-w-0">
        <h3 class="bd-title">Blast of {{ formatWhen(blast.created_at) }}</h3>
        <p class="bd-sub">Sent by {{ blast.sender }}</p>
      </div>
      <v-btn
        v-if="closable" icon="mdi-close" variant="tonal" size="36" rounded="lg"
        aria-label="Close" class="flex-shrink-0" @click="$emit('close')"
      ></v-btn>
    </header>

    <div class="bd-check">
      <v-tooltip :disabled="blast.checkable" location="top" text="No SkySMS message ids were stored for this blast, so its delivery can't be checked.">
        <template #activator="{ props: tip }">
          <span v-bind="tip" class="flex-shrink-0">
            <v-btn
              variant="flat" height="36" class="check-btn text-none"
              prepend-icon="mdi-refresh"
              :disabled="!blast.checkable"
              :loading="checking"
              @click="$emit('check')"
            >Check status</v-btn>
          </span>
        </template>
      </v-tooltip>
      <div class="bd-checked">
        <template v-if="blast.delivery_checked_at">Checked {{ formatWhen(blast.delivery_checked_at) }}</template>
        <template v-else>Not checked yet. The numbers below are what was recorded when it was sent.</template>
        <span v-if="note" role="status"> {{ note }}</span>
      </div>
    </div>

    <p class="bd-message">{{ blast.message }}</p>

    <!-- Queued means SkySMS accepted it and billed the credits, not that it
         arrived. Unconfirmed and Other appear only when there is one: they are
         not delivery states, they are things to look at. -->
    <div class="bd-tiles">
      <div v-for="state in tiles" :key="state.key" class="bd-tile">
        <span class="bd-tile-n">{{ blast.counts[state.key] }}</span>
        <span class="bd-tile-l">{{ state.label }}</span>
      </div>
    </div>

    <h4 class="bd-h">By barangay</h4>
    <div class="bd-table-wrap">
      <table class="bd-table">
        <thead>
          <tr>
            <th scope="col"></th>
            <th scope="col">Barangay</th>
            <th scope="col" class="num">Residents</th>
            <th scope="col" class="num">Sent</th>
            <th scope="col" class="num">Pending</th>
            <th scope="col" class="num">Queued</th>
            <th scope="col" class="num">Failed</th>
          </tr>
        </thead>
        <tbody>
          <template v-for="row in blast.barangays" :key="row.sms_log_id">
            <tr class="bd-row" :class="{ 'is-open': !!expanded[row.sms_log_id] }">
              <td class="bd-toggle-cell">
                <button
                  type="button" class="bd-toggle"
                  :aria-expanded="!!expanded[row.sms_log_id]"
                  :aria-label="`${expanded[row.sms_log_id] ? 'Hide' : 'Show'} residents of ${row.barangay}`"
                  @click="$emit('toggle', row)"
                >
                  <v-icon size="18">{{ expanded[row.sms_log_id] ? 'mdi-chevron-down' : 'mdi-chevron-right' }}</v-icon>
                </button>
              </td>
              <th scope="row" class="bd-name">{{ row.barangay }}</th>
              <td class="num">{{ row.recipient_count }}</td>
              <td class="num">{{ row.counts.sent }}</td>
              <td class="num">{{ row.counts.pending }}</td>
              <td class="num">{{ row.counts.queued }}</td>
              <td class="num" :class="{ 'bd-bad': row.counts.failed > 0 }">{{ row.counts.failed }}</td>
            </tr>

            <tr v-if="expanded[row.sms_log_id]" class="bd-residents-row">
              <td colspan="7">
                <div class="bd-residents">
                  <div class="bd-r-tools">
                    <v-text-field
                      :model-value="stateOf(row).search"
                      placeholder="Search resident or number"
                      prepend-inner-icon="mdi-magnify"
                      density="compact" variant="outlined" hide-details clearable
                      class="bd-search"
                      :aria-label="`Search residents of ${row.barangay}`"
                      @update:model-value="$emit('search', row, $event ?? '')"
                    ></v-text-field>
                    <div class="bd-chips" role="group" :aria-label="`Filter residents of ${row.barangay}`">
                      <button
                        v-for="f in RESIDENT_FILTERS" :key="f.label" type="button"
                        class="bd-chip" :class="{ 'is-on': stateOf(row).status === f.status }"
                        :aria-pressed="stateOf(row).status === f.status"
                        @click="$emit('filter', row, f.status)"
                      >{{ f.label }}</button>
                    </div>
                  </div>

                  <div v-if="stateOf(row).loading && stateOf(row).rows.length === 0" class="bd-note">Loading residents…</div>
                  <div v-else-if="stateOf(row).error" class="bd-note text-error" role="alert">
                    {{ stateOf(row).error }}
                    <button type="button" class="bd-link" @click="$emit('retry', row)">Try again</button>
                  </div>
                  <div v-else-if="stateOf(row).rows.length === 0" class="bd-note">
                    {{ stateOf(row).search || stateOf(row).status ? 'No residents match.' : 'No residents were recorded for this barangay.' }}
                  </div>
                  <table v-else class="bd-rtable">
                    <thead>
                      <tr>
                        <th scope="col">Resident</th>
                        <th scope="col">Phone</th>
                        <th scope="col">Status</th>
                        <th scope="col">Updated</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr v-for="r in stateOf(row).rows" :key="r.recipient_id">
                        <th scope="row" class="bd-rname">{{ r.name }}</th>
                        <td class="bd-phone">{{ r.phone }}</td>
                        <td><StatusPill small :status="pillFor(r.status)" :label="r.status" /></td>
                        <td class="bd-when">{{ formatWhen(r.updated_at) }}</td>
                      </tr>
                    </tbody>
                  </table>

                  <div v-if="stateOf(row).meta" class="bd-r-foot">
                    <span>Showing {{ stateOf(row).rows.length }} of {{ stateOf(row).meta.total }}</span>
                    <v-btn
                      v-if="stateOf(row).meta.current_page < stateOf(row).meta.last_page"
                      variant="flat" height="32" class="check-btn text-none"
                      :loading="stateOf(row).loadingMore"
                      @click="$emit('more', row)"
                    >Load more</v-btn>
                  </div>
                </div>
              </td>
            </tr>
          </template>
        </tbody>
      </table>
    </div>
  </section>
</template>

<script setup>
import { computed } from 'vue'
import StatusPill from '@/components/StatusPill.vue'
import { DELIVERY_STATES, formatWhen, pillFor } from '@/composables/blastDelivery'

const props = defineProps({
  blast: { type: Object, required: true },
  /** Resident pages by sms_log_id, owned by BlastHistory. */
  residents: { type: Object, default: () => ({}) },
  expanded: { type: Object, default: () => ({}) },
  checking: { type: Boolean, default: false },
  note: { type: String, default: '' },
  closable: { type: Boolean, default: false },
})

defineEmits(['close', 'check', 'toggle', 'search', 'filter', 'more', 'retry'])

const RESIDENT_FILTERS = [
  { label: 'All', status: '' },
  { label: 'Failed', status: 'Failed' },
  { label: 'Pending', status: 'Pending' },
]

const EMPTY = { loading: false, loadingMore: false, error: '', rows: [], meta: null, search: '', status: '' }
const stateOf = (row) => props.residents[row.sms_log_id] ?? EMPTY

// Queued, Pending, Sent, Failed always; the two oddities only when present.
const tiles = computed(() =>
  DELIVERY_STATES.filter(s => ['queued', 'pending', 'sent', 'failed'].includes(s.key) || props.blast.counts[s.key] > 0),
)
</script>

<style scoped>
.bd { padding: 20px 24px 24px; }
.bd-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; }
.bd-title { margin: 0; font-size: 18px; line-height: 26px; font-weight: 700; }
.bd-sub { margin: 2px 0 0; font-size: 14px; line-height: 20px; color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); }
.bd-check { display: flex; align-items: center; flex-wrap: wrap; gap: 8px 12px; margin-top: 14px; }
.bd-checked { font-size: 12px; line-height: 16px; color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); }
.bd-message {
  margin: 14px 0 0;
  padding: 12px 14px;
  border-radius: 12px;
  background: rgba(var(--v-theme-on-surface), 0.05);
  font-size: 14px;
  line-height: 22px;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}
.bd-tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(96px, 1fr)); gap: 10px; margin-top: 14px; }
.bd-tile {
  display: flex; flex-direction: column; gap: 2px;
  padding: 10px 12px;
  border: 1px solid rgba(var(--v-theme-on-surface), 0.1);
  border-radius: 12px;
}
.bd-tile-n { font-size: 22px; line-height: 28px; font-weight: 800; font-variant-numeric: tabular-nums; }
.bd-tile-l { font-size: 12px; line-height: 16px; color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); }
.bd-h { margin: 22px 0 8px; font-size: 12px; line-height: 16px; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase; color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); }

.bd-table-wrap { border: 1px solid rgba(var(--v-theme-on-surface), 0.1); border-radius: 12px; overflow-x: auto; }
.bd-table, .bd-rtable { width: 100%; border-collapse: collapse; font-size: 14px; line-height: 20px; }
.bd-table th, .bd-table td { padding: 8px 10px; text-align: left; }
.bd-table thead th,
.bd-rtable thead th {
  font-size: 12px; font-weight: 700; letter-spacing: 0.04em; text-transform: uppercase;
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
  background: rgba(var(--v-theme-on-surface), 0.04);
}
.num { text-align: right !important; font-variant-numeric: tabular-nums; }
.bd-row + .bd-row td, .bd-row th { border-top: 1px solid rgba(var(--v-theme-on-surface), 0.08); }
.bd-row td, .bd-row th { border-top: 1px solid rgba(var(--v-theme-on-surface), 0.08); }
.bd-name { font-weight: 700; }
.bd-bad { color: rgb(var(--v-theme-error)); font-weight: 700; }
.bd-toggle-cell { width: 44px; padding: 0 !important; }
.bd-toggle {
  display: inline-grid; place-items: center;
  width: 44px; height: 44px;
  background: none; border: 0; cursor: pointer; color: inherit;
}
.bd-toggle:hover { background: rgba(var(--v-theme-on-surface), 0.05); }

.bd-residents-row > td { padding: 0 !important; background: rgba(var(--v-theme-on-surface), 0.03); }
.bd-residents { padding: 12px 14px 14px 14px; }
.bd-r-tools { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; }
.bd-search { flex: 1 1 200px; min-width: 180px; }
.bd-chips { display: inline-flex; gap: 6px; }
.bd-chip {
  min-height: 36px; padding: 0 12px;
  border: 1px solid rgba(var(--v-theme-on-surface), 0.16);
  border-radius: 999px; background: rgb(var(--v-theme-surface));
  font-family: inherit; font-size: 13px; font-weight: 600; cursor: pointer; color: inherit;
}
.bd-chip.is-on {
  background: rgba(var(--v-theme-primary), 0.12);
  border-color: rgba(var(--v-theme-primary), 0.5);
  color: rgb(var(--v-theme-primary-strong));
}
.bd-note { padding: 14px 4px; font-size: 13px; color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); }
.bd-link { margin-left: 8px; background: none; border: 0; font: inherit; font-weight: 700; color: rgb(var(--v-theme-primary-strong)); cursor: pointer; text-decoration: underline; }
.bd-rtable { margin-top: 10px; }
.bd-rtable th, .bd-rtable td { padding: 8px 8px; text-align: left; border-top: 1px solid rgba(var(--v-theme-on-surface), 0.08); }
.bd-rname { font-weight: 600; }
.bd-phone { font-variant-numeric: tabular-nums; white-space: nowrap; }
.bd-when { font-size: 13px; white-space: nowrap; color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); }
.bd-r-foot { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-top: 10px; font-size: 12px; color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); }

.check-btn {
  padding: 0 14px;
  border: 1px solid rgba(var(--v-theme-on-surface), 0.14);
  border-radius: 10px;
  background: rgb(var(--v-theme-surface));
  color: rgb(var(--v-theme-primary-strong));
  font-size: 13px; font-weight: 700; letter-spacing: 0;
}
</style>
