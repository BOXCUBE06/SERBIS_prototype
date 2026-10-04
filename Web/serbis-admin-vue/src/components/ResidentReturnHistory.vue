<!--
  How a resident's past equipment loans came back, shown on their profile so
  staff can see a pattern before approving a new borrow request. Display only:
  nothing here blocks a request, and the office decides what a pattern means.

  Owns the section's heading row (the title and the count pills share one line
  on the Account detail board).

  Photos live on the private disk, so an <img src> cannot fetch them — each is
  loaded as a blob with the bearer token when staff open it, and revoked when
  the panel moves to another resident or closes.
-->
<template>
  <div>
    <div class="returns-head">
      <h3 class="returns-title">Equipment returns</h3>
      <template v-if="summary && summary.total > 0">
        <StatusPill tag class="count-pill" :label="`${summary.total} returned`" />
        <StatusPill status="Good" class="count-pill" :label="`${summary.good} good`" />
        <StatusPill v-if="summary.bad > 0" status="Bad" class="count-pill" :label="`${summary.bad} bad`" />
        <StatusPill v-else tag class="count-pill" label="0 bad" />
        <StatusPill v-if="summary.unrecorded > 0" tag class="count-pill" :label="`${summary.unrecorded} not recorded`" />
      </template>
    </div>

    <div v-if="loading" class="text-body-2 text-medium-emphasis pt-3">Loading returns…</div>
    <div v-else-if="error" class="text-body-2 text-medium-emphasis pt-3" role="status">{{ error }}</div>
    <div v-else-if="!summary || summary.total === 0" class="empty-returns mt-3">
      <v-icon size="20" aria-hidden="true">mdi-package-variant-closed</v-icon>
      <div>
        <div class="text-body-2 font-weight-medium text-high-emphasis">No returned equipment yet.</div>
        <div class="text-caption text-medium-emphasis">Returns show here once this account's loans come back. Information only.</div>
      </div>
    </div>

    <ul v-else class="return-list">
      <li v-for="row in rows" :key="row.borrow_id" class="return-row">
        <div class="return-main">
          <div class="return-item">
            {{ row.item || 'Item' }}<span v-if="row.quantity > 1" class="return-qty"> × {{ row.quantity }}</span>
          </div>
          <div class="return-date">Returned {{ fmtDate(row.returned_at) }}</div>
          <p v-if="row.return_condition_note" class="return-note">{{ row.return_condition_note }}</p>

          <template v-if="row.has_return_photo">
            <v-btn
              variant="outlined" color="primary"
              size="small"
              density="comfortable"
              class="text-none mt-1"
              :loading="photos[row.borrow_id]?.loading"
              @click="togglePhoto(row.borrow_id)"
            >
              <v-icon start size="16">mdi-image-outline</v-icon>
              {{ photos[row.borrow_id]?.url ? 'Hide photo' : 'View photo' }}
            </v-btn>
            <div v-if="photos[row.borrow_id]?.error" class="text-caption text-medium-emphasis">
              {{ photos[row.borrow_id]?.error }}
            </div>
            <v-img
              v-if="photos[row.borrow_id]?.url"
              :src="photos[row.borrow_id]?.url"
              :alt="`Return photo for ${row.item || 'equipment'}`"
              max-height="180"
              rounded="lg"
              class="mt-1"
            ></v-img>
          </template>
        </div>

        <StatusPill v-if="row.return_condition" class="flex-none" :status="row.return_condition" :label="row.return_condition" />
        <StatusPill v-else tag class="flex-none" label="Not recorded" />
      </li>
    </ul>
  </div>
</template>

<script setup lang="ts">
import { onBeforeUnmount, reactive, ref, watch } from 'vue'
import StatusPill from '@/components/StatusPill.vue'
import { authHeaders, fmtDate } from '@/composables/adminUi'
import { API_BASE } from '@/config/api'

interface ReturnRow {
  borrow_id: number
  item: string | null
  quantity: number
  returned_at: string | null
  return_condition: 'Good' | 'Bad' | null
  return_condition_note: string | null
  has_return_photo: boolean
}

interface ReturnSummary {
  total: number
  good: number
  bad: number
  unrecorded: number
}

interface PhotoState {
  url: string
  loading: boolean
  error: string
}

const props = defineProps<{ residentId: number | string }>()

const rows = ref<ReturnRow[]>([])
const summary = ref<ReturnSummary | null>(null)
const loading = ref(false)
const error = ref('')
const photos = reactive<Record<number, PhotoState>>({})

const releasePhotos = () => {
  for (const key of Object.keys(photos)) {
    const state = photos[Number(key)]
    if (state?.url) URL.revokeObjectURL(state.url)
    delete photos[Number(key)]
  }
}

// The panel is reused as the selection moves down the list, so the previous
// resident's returns are cleared first — otherwise they would sit under the
// new resident's name until the fetch came back.
watch(
  () => props.residentId,
  async (id) => {
    releasePhotos()
    rows.value = []
    summary.value = null
    error.value = ''
    if (id == null) return

    loading.value = true
    try {
      const res = await fetch(`${API_BASE}/residents/${id}/return-history`, { headers: authHeaders(false) })
      if (props.residentId !== id) return
      if (!res.ok) throw new Error('Could not load return history.')

      const data = await res.json()
      rows.value = data.data ?? []
      summary.value = data.summary ?? null
    } catch (e) {
      if (props.residentId === id) error.value = e instanceof Error ? e.message : 'Could not load return history.'
    } finally {
      if (props.residentId === id) loading.value = false
    }
  },
  { immediate: true },
)

const togglePhoto = async (borrowId: number) => {
  const open = photos[borrowId]
  if (open?.url) {
    URL.revokeObjectURL(open.url)
    delete photos[borrowId]
    return
  }
  if (open?.loading) return

  const forResident = props.residentId
  photos[borrowId] = { url: '', loading: true, error: '' }
  try {
    // Accept */*, not JSON: this route answers with an image.
    const res = await fetch(`${API_BASE}/borrowings/${borrowId}/photo/return`, {
      headers: { ...authHeaders(false), Accept: '*/*' },
    })
    if (!res.ok) throw new Error('Could not load the photo.')

    const blob = await res.blob()
    // Staff moved to another resident while this was in flight: the entry was
    // cleared with the rest, and the blob belongs to nobody on screen.
    if (props.residentId !== forResident || !photos[borrowId]) return
    photos[borrowId] = { url: URL.createObjectURL(blob), loading: false, error: '' }
  } catch (e) {
    if (photos[borrowId]) {
      photos[borrowId] = { url: '', loading: false, error: e instanceof Error ? e.message : 'Could not load the photo.' }
    }
  }
}

onBeforeUnmount(releasePhotos)
</script>

<style scoped>
/* Heading and count pills on one line, a rule under them (the board's section
   header). A bad return is warning-toned, not error-toned: it is information
   for the office, not an alarm. */
.returns-head {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 12px;
  padding-bottom: 12px;
  border-bottom: 1px solid rgba(var(--v-theme-on-surface), 0.08);
}
.returns-title {
  margin: 0 auto 0 0;
  font-size: 12px;
  line-height: 16px;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
}
.count-pill { padding: 2px 10px; }
.flex-none { flex: none; }

.empty-returns {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 14px;
  border-radius: 10px;
  background: rgba(var(--v-theme-on-surface), 0.04);
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
}
.return-list { list-style: none; padding: 0; margin: 0; }
.return-row {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 16px;
  padding: 14px 0;
  border-bottom: 1px solid rgba(var(--v-theme-on-surface), 0.08);
}
.return-main { min-width: 0; }
.return-item { font-size: 14px; line-height: 20px; font-weight: 700; }
.return-qty { font-weight: 400; color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); }
.return-date {
  font-size: 12px;
  line-height: 16px;
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
}
.return-note { margin: 4px 0 0; font-size: 14px; line-height: 20px; white-space: pre-wrap; word-break: break-word; }
</style>
