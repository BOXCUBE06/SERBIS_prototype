<!--
  How a resident's past equipment loans came back, shown on their profile so
  staff can see a pattern before approving a new borrow request. Display only:
  nothing here blocks a request, and the office decides what a pattern means.

  Photos live on the private disk, so an <img src> cannot fetch them — each is
  loaded as a blob with the bearer token when staff open it, and revoked when
  the panel moves to another resident or closes.
-->
<template>
  <div>
    <div v-if="loading" class="text-body-2 text-medium-emphasis">Loading returns…</div>
    <div v-else-if="error" class="text-body-2 text-medium-emphasis" role="status">{{ error }}</div>
    <div v-else-if="!summary || summary.total === 0" class="text-body-2 text-medium-emphasis">
      No returned equipment yet.
    </div>

    <template v-else>
      <div class="d-flex flex-wrap ga-2 mb-4">
        <span class="cond-pill cond-neutral">{{ summary.total }} returned</span>
        <span class="cond-pill cond-good">{{ summary.good }} good</span>
        <span class="cond-pill" :class="summary.bad > 0 ? 'cond-bad' : 'cond-neutral'">{{ summary.bad }} bad</span>
        <span v-if="summary.unrecorded > 0" class="cond-pill cond-neutral">{{ summary.unrecorded }} not recorded</span>
      </div>

      <ul class="return-list">
        <li v-for="row in rows" :key="row.borrow_id" class="return-row">
          <div class="d-flex justify-space-between align-start ga-3">
            <div class="text-body-2 font-weight-medium text-high-emphasis">
              {{ row.item || 'Item' }}<span v-if="row.quantity > 1" class="text-medium-emphasis"> × {{ row.quantity }}</span>
            </div>
            <span
              v-if="row.return_condition"
              class="cond-pill"
              :class="row.return_condition === 'Bad' ? 'cond-bad' : 'cond-good'"
            >{{ row.return_condition }}</span>
            <span v-else class="cond-pill cond-neutral">Not recorded</span>
          </div>

          <div class="text-caption text-medium-emphasis">Returned {{ fmtDate(row.returned_at) }}</div>
          <p v-if="row.return_condition_note" class="text-body-2 mt-1 mb-0 note">{{ row.return_condition_note }}</p>

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
        </li>
      </ul>
    </template>
  </div>
</template>

<script setup lang="ts">
import { onBeforeUnmount, reactive, ref, watch } from 'vue'
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
.return-list { list-style: none; padding: 0; margin: 0; }
.return-row { padding: 10px 0; border-top: 1px solid rgba(var(--v-theme-on-surface), 0.08); }
.return-row:first-child { border-top: 0; padding-top: 0; }
.note { white-space: pre-wrap; word-break: break-word; }

/* Tinted pills with the -strong token for text, like the status pills beside
   them. A bad return is warning-toned, not error-toned: it is information for
   the office, not an alarm. */
.cond-pill {
  display: inline-flex;
  align-items: center;
  padding: 3px 10px;
  border-radius: 8px;
  font-size: 0.75rem;
  font-weight: 700;
  white-space: nowrap;
}
.cond-good { background: rgba(var(--v-theme-primary), 0.14); color: rgb(var(--v-theme-primary-strong)); }
.cond-bad { background: rgba(var(--v-theme-warning), 0.14); color: rgb(var(--v-theme-warning-strong)); }
.cond-neutral { background: rgba(var(--v-theme-on-surface), 0.08); color: rgba(var(--v-theme-on-surface), 0.82); }
</style>
