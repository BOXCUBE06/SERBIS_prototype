<template>
  <v-container fluid class="fill-height align-start pa-6 bg-background">
    <v-row justify="center" class="ma-0 w-100 mt-4">
      <v-col cols="12" md="10" lg="8" xl="6" class="pa-0">
        
        <v-card elevation="4" rounded="lg" class="bg-surface fade-in w-100">
          <div class="pa-8 border-b bg-surface d-flex align-center gap-4">
            <v-avatar color="red-lighten-5" size="72" class="rounded-lg">
              <v-icon color="error" size="36">mdi-bullhorn-outline</v-icon>
            </v-avatar>
            <div>
              <h2 class="page-title text-high-emphasis">Text Blast (SMS)</h2>
              <!-- "active, opted-in" is exact: SmsController::sendBlast filters
                   status = Active AND sms_opt_in AND a non-null phone number, so
                   "every resident" would overstate who actually receives this. -->
              <div class="page-subtitle text-medium-emphasis">One message to the active, opted-in residents of the barangays you pick</div>
            </div>

            <!-- Pushed right, and deliberately quiet. The balance is context for
                 a decision, not a call to action — and it must never read as a
                 blocker, because a failed lookup does not stop a send.

                 Both fields are printed exactly as PhilSMS sent them. The peso
                 figure arrives carrying its own currency symbol and the expiry
                 in a format no Date constructor reads twice the same way, so
                 neither is parsed or reformatted here. -->
            <div class="ml-auto text-right flex-shrink-0">
              <!-- Each line is nowrap and the block refuses to shrink: at this
                   card width the expiry is long enough to wrap mid-phrase into
                   "expires 21st / Aug 27", which reads as two separate facts. -->
              <template v-if="balance.available">
                <div class="text-h6 font-weight-bold text-high-emphasis" style="white-space: nowrap;">{{ balance.remaining }}</div>
                <div class="text-caption text-medium-emphasis" style="white-space: nowrap;">SMS credit</div>
                <div class="text-caption text-medium-emphasis" style="white-space: nowrap;">expires {{ balance.expiresOn }}</div>
              </template>
              <div v-else-if="balance.checked" class="text-caption text-medium-emphasis" style="max-width: 180px;">
                {{ balance.message }}
              </div>
            </div>
          </div>

          <v-card-text class="pa-8">
            <v-alert 
              v-if="alert.show" 
              :type="alert.type" 
              variant="tonal" 
              class="mb-8" 
              density="comfortable" 
              rounded="lg" 
              closable 
              @click:close="alert.show = false"
            >
              <span class="font-weight-medium">{{ alert.message }}</span>
            </v-alert>

            <v-form ref="form" @submit.prevent="sendSmsBlast">
              
              <div class="mb-6">
                <div class="text-caption text-uppercase font-weight-bold text-medium-emphasis mb-2">Target Audience</div>
                <v-select
                  v-model="selectedBarangays"
                  :items="barangays"
                  item-title="barangay_name"
                  item-value="barangay_id"
                  :loading="barangaysLoading"
                  multiple
                  chips
                  closable-chips
                  placeholder="Select one or more barangays"
                  variant="outlined"
                  density="comfortable"
                  rounded="lg"
                  color="error"
                  bg-color="grey-lighten-5"
                  class="font-weight-medium"
                  :rules="[v => (v && v.length > 0) || 'Select at least one barangay to target.']"
                  :error-messages="fieldErrors.barangays"
                >
                  <!-- The old duplicate panel had a Select All and the rewrite
                       that swapped a hardcoded list for real GET /barangays rows
                       dropped it. Deliberately NOT the old implementation: that
                       one sent a literal 'all' sentinel, and sendBlast validates
                       barangays.* as integer|exists:tbl_barangay,barangay_id, so
                       'all' is a 422 now. This selects every real barangay_id. -->
                  <template #prepend-item>
                    <v-list-item :title="allBarangaysSelected ? 'Clear all' : 'Select all barangays'" @click="toggleAllBarangays">
                      <template #prepend>
                        <v-checkbox-btn
                          :model-value="allBarangaysSelected"
                          :indeterminate="someBarangaysSelected"
                          color="error"
                        ></v-checkbox-btn>
                      </template>
                      <template #subtitle>
                        <span class="text-caption">{{ barangays.length }} barangays — everyone reachable in the municipality</span>
                      </template>
                    </v-list-item>
                    <v-divider class="mt-2"></v-divider>
                  </template>
                </v-select>
              </div>

              <div class="mb-6">
                <div class="text-caption text-uppercase font-weight-bold text-medium-emphasis mb-2">Message Template</div>
                <v-select
                  v-model="selectedTemplate"
                  :items="templates"
                  item-title="label"
                  item-value="label"
                  placeholder="Start from a template (optional)"
                  variant="outlined"
                  density="comfortable"
                  rounded="lg"
                  color="error"
                  bg-color="grey-lighten-5"
                  clearable
                  class="font-weight-medium"
                  @update:model-value="applyTemplate"
                ></v-select>
              </div>

              <div class="mb-2">
                <div class="text-caption text-uppercase font-weight-bold text-medium-emphasis mb-2">Message Content</div>
                <v-textarea
                  v-model="message"
                  placeholder="e.g., MDRRMO Alert: Flood warning in your area. Evacuate to higher ground immediately."
                  variant="outlined"
                  density="comfortable"
                  rounded="lg"
                  color="error"
                  bg-color="grey-lighten-5"
                  rows="5"
                  counter="160"
                  class="font-weight-medium text-body-1"
                  :rules="[
                    v => !!v || 'A message is required.',
                    v => v.length <= 160 || 'Message exceeds the standard 160 SMS character limit.'
                  ]"
                  :error-messages="fieldErrors.message"
                ></v-textarea>
              </div>

              <!-- What this blast costs, stated before the button rather than
                   discovered on the bill. The two numbers are estimates of
                   different kinds: the recipient count is exact for the moment
                   it was fetched, but the roll can change before Send; the
                   segment count is derived from the GSM 03.38 tables because
                   PhilSMS has no sandbox to confirm it against. -->
              <div class="mt-6 pa-4 rounded-lg bg-grey-lighten-5 border">
                <div class="d-flex align-center justify-space-between flex-wrap gap-3">
                  <div class="d-flex align-center gap-2">
                    <v-icon size="20" class="text-medium-emphasis">mdi-account-group-outline</v-icon>
                    <span class="text-body-2 font-weight-medium">
                      <template v-if="selectedBarangays.length === 0">
                        <span class="text-medium-emphasis">Pick a barangay to see how many residents this reaches</span>
                      </template>
                      <template v-else-if="recipientCountLoading">
                        <span class="text-medium-emphasis">Counting recipients…</span>
                      </template>
                      <template v-else-if="recipientCountError">
                        <span class="text-warning">{{ recipientCountError }}</span>
                      </template>
                      <template v-else>
                        <strong>{{ recipientCount }}</strong>
                        {{ recipientCount === 1 ? 'recipient' : 'recipients' }}
                      </template>
                    </span>
                  </div>

                  <div class="d-flex align-center gap-2">
                    <v-icon size="20" class="text-medium-emphasis">mdi-message-text-outline</v-icon>
                    <span class="text-body-2 font-weight-medium">
                      <strong>{{ sms.segments }}</strong>
                      {{ sms.segments === 1 ? 'segment' : 'segments' }}
                      <span class="text-medium-emphasis">· {{ sms.units }}/{{ sms.capacity }} {{ sms.encoding }}</span>
                    </span>
                  </div>
                </div>

                <div v-if="billedUnits !== null" class="text-caption text-medium-emphasis mt-3">
                  About {{ billedUnits.toLocaleString() }} SMS {{ billedUnits === 1 ? 'unit' : 'units' }} for this blast
                  ({{ recipientCount.toLocaleString() }} × {{ sms.segments }}).
                </div>

                <!-- The whole reason this panel exists. A 160-character message
                     is one segment in GSM-7 and three in UCS-2, and the field
                     counter cannot tell them apart — so the warning has to name
                     the character that moved it, not just report the total. -->
                <v-alert
                  v-if="sms.offendingCharacters.length > 0"
                  type="warning"
                  variant="tonal"
                  density="compact"
                  rounded="lg"
                  class="mt-3"
                >
                  <span class="text-body-2">
                    This message has left the GSM-7 alphabet, so one segment now holds 70 characters instead of 160.
                    Caused by {{ offendingSummary }}.
                    Swapping {{ sms.offendingCharacters.length === 1 ? 'it for its' : 'them for their' }} plain-ASCII equivalent brings the cost back down.
                  </span>
                </v-alert>
              </div>
              <div class="pt-6 mt-4 border-t">
                <v-btn 
                  color="#0f4c3a" 
                  variant="flat" 
                  rounded="lg" 
                  class="text-none font-weight-bold text-white w-100" 
                  size="x-large"
                  height="64"
                  type="submit"
                  :loading="loading"
                  elevation="2"
                >
                  <v-icon start size="24" class="mr-2">mdi-send</v-icon>
                  <!-- "Send", not "Dispatch". Dispatch means sending a vehicle
                       everywhere else in this panel (Ambulance Dispatch
                       Requests, Approve & Dispatch); reusing it for SMS blurs
                       the one word the desk uses for a physical response. -->
                  <span class="text-h6 font-weight-bold">Send Blast</span>
                </v-btn>
              </div>
            </v-form>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>

    <!-- Replaces a native confirm(). The scale and the cost still read the
         same; what is new is the password, which the server re-checks against
         the signed-in account before it spends anything. -->
    <v-dialog v-model="confirmDialog.open" max-width="520" persistent>
      <v-card rounded="lg">
        <v-card-title class="d-flex justify-space-between align-center text-h6 font-weight-bold pt-5 px-6">
          <span>Confirm this blast</span>
          <v-btn
            icon="mdi-close" variant="text" size="small" aria-label="Close"
            :disabled="loading" @click="cancelSend"
          ></v-btn>
        </v-card-title>
        <v-card-text class="px-6">
          <p class="text-body-1 mb-3">{{ confirmDialog.summary }}</p>
          <p v-if="confirmDialog.cost" class="text-body-2 text-medium-emphasis mb-4">{{ confirmDialog.cost }}</p>
          <v-text-field
            v-model="confirmDialog.password"
            label="Your password"
            placeholder="Re-enter your account password"
            type="password"
            variant="outlined"
            density="comfortable"
            rounded="lg"
            autocomplete="current-password"
            :error-messages="fieldErrors.password"
            :disabled="loading"
            @keyup.enter="confirmSend"
          ></v-text-field>
        </v-card-text>
        <v-card-actions class="px-6 pb-5 d-flex justify-end gap-3">
          <v-btn
            variant="text"
            class="text-none font-weight-bold"
            height="44"
            :disabled="loading"
            @click="cancelSend"
          >Cancel</v-btn>
          <v-btn
            color="#0f4c3a"
            variant="flat"
            rounded="lg"
            class="text-none font-weight-bold text-white px-6"
            height="44"
            :loading="loading"
            @click="confirmSend"
          >Send Blast</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </v-container>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { getToken } from '@/composables/authToken'
import { describeSms, nameCharacter } from '@/composables/smsSegments'
import { API_BASE } from '@/config/api'

const message = ref('')
const loading = ref(false)
const form = ref(null)
const barangays = ref([])
const selectedBarangays = ref([])
const barangaysLoading = ref(false)

const alert = ref({
  show: false,
  type: 'success',
  message: ''
})

// The send confirmation. `password` lives only as long as the dialog is open —
// cleared on cancel, on a successful send, and on any failure that closes it.
const confirmDialog = ref({ open: false, summary: '', cost: '', password: '' })

// Starting text, not a fill-in form. There are deliberately no [AREA]-style
// tokens: a token that survives editing goes out to a real handset with the
// blank still in it. Each draft stops mid-sentence instead, so an unfinished
// message reads as unfinished to the person about to press Send.
//
// Bodies run 113 / 112 / 119 / 29 characters against the 160-character
// single-segment budget, leaving room to finish the sentence without the blast
// quietly billing a second segment. All four are pure GSM-7 — no curly
// punctuation, no dashes that are not hyphens — so pasting one in does not
// halve the budget before a word has been typed.
const templates = [
  {
    label: 'Weather warning',
    body: 'MDRRMO Echague weather advisory: heavy rain and strong winds expected today. Residents in low-lying areas should ',
  },
  {
    label: 'Early warning',
    body: 'MDRRMO Echague early warning: conditions are worsening. Prepare a go-bag and be ready to evacuate when told to. ',
  },
  {
    label: 'Heat index warning',
    body: 'MDRRMO Echague heat advisory: heat index is dangerously high today. Avoid outdoor work 10AM-3PM and drink water often. ',
  },
  {
    label: 'Announcement',
    body: 'MDRRMO Echague announcement: ',
  },
]

const selectedTemplate = ref(null)

const applyTemplate = (label) => {
  if (!label) return

  const chosen = templates.find(t => t.label === label)
  if (!chosen) return

  // Only ask when there is work to lose. Picking a template into an empty box
  // is the normal first action on this page and must not cost a dialog.
  if (message.value.trim() && !confirm('Replace the message you have typed with the template text?')) {
    selectedTemplate.value = null
    return
  }

  message.value = chosen.body
}

// What the message actually bills, rather than what it counts. See
// composables/smsSegments.ts — one curly quote pasted out of Word moves the
// whole message to UCS-2 and cuts a segment from 160 characters to 70.
const sms = computed(() => describeSms(message.value))

const offendingSummary = computed(() =>
  sms.value.offendingCharacters.map(nameCharacter).join(', '))

const recipientCount = ref(null)
const recipientCountLoading = ref(false)
const recipientCountError = ref('')

const billedUnits = computed(() =>
  recipientCount.value === null || sms.value.segments === 0
    ? null
    : recipientCount.value * sms.value.segments)


const getHeaders = () => ({
  'Authorization': `Bearer ${getToken()}`,
  'Content-Type': 'application/json',
  'Accept': 'application/json'
})

// Server-side errors, keyed by field, so a 422 lands on the input it belongs
// to instead of being concatenated into the banner above the form. Mirrors
// VehiclesView's/StaffView's applyServerErrors.
const fieldErrors = ref({ message: '', barangays: '', password: '' })
const clearFieldErrors = () => { fieldErrors.value = { message: '', barangays: '', password: '' } }

const applyServerErrors = (data) => {
  if (data?.errors && typeof data.errors === 'object') {
    const mapped = { message: '', barangays: '', password: '' }
    const leftovers = []
    for (const [key, messages] of Object.entries(data.errors)) {
      const text = Array.isArray(messages) ? messages.join(' ') : String(messages)
      // barangays.* validation failures report as "barangays.0", not "barangays".
      if (key === 'message') mapped.message = text
      else if (key === 'barangays' || key.startsWith('barangays.')) mapped.barangays = text
      else if (key === 'password') mapped.password = text
      else leftovers.push(text)
    }
    fieldErrors.value = mapped
    return leftovers.length > 0 ? leftovers.join(' ') : 'Please correct the highlighted fields.'
  }
  return data?.message || 'Failed to send blast'
}

// Guarded on barangays.length > 0 so an empty list (still loading, or the
// request failed) does not report "all selected" when nothing is.
const allBarangaysSelected = computed(() =>
  barangays.value.length > 0 && selectedBarangays.value.length === barangays.value.length)
const someBarangaysSelected = computed(() =>
  selectedBarangays.value.length > 0 && !allBarangaysSelected.value)

const toggleAllBarangays = () => {
  selectedBarangays.value = allBarangaysSelected.value
    ? []
    : barangays.value.map(b => b.barangay_id)
}

// Deliberately asks the server rather than counting client-side. The set is
// not derivable from anything this page holds: it turns on status, on the
// resident's own sms_opt_in, and on whether PhilSms::normalize() accepts the
// stored number — the last of which is PHP, not SQL. SmsController resolves
// the preview through the identical code path the send uses, so the number
// shown here is the number that will be billed.
let countTimer = null
let countRequestId = 0

// The count depends on the barangay selection and on nothing else. Reducing
// that selection to a stable string is what stops the refetching: Vuetify
// replaces the array on every interaction, so watching the array fires on a new
// reference holding identical ids, and each of those fires starts a fresh
// debounce that ends in a request for a selection already counted. Debouncing
// cannot help — it collapses one burst, and the problem is burst after burst.
//
// Sorted numerically because the select appends in click order, and [2,1] is
// the same blast as [1,2].
const selectionKey = (ids) => [...ids].map(Number).sort((a, b) => a - b).join(',')

const currentSelectionKey = computed(() => selectionKey(selectedBarangays.value))

// Keyed by selectionKey, and deliberately not expired on a timer. The roll only
// moves when staff add or deactivate a resident, which is rare next to how often
// one selection is revisited while composing a single message — and the send
// recomputes server-side through resolveRecipients() regardless, so a stale
// preview can mislead an expectation but can never cause a wrong send. Lives for
// the life of the page; a reload is the refresh.
const countCache = new Map()

const fetchRecipientCount = async (key) => {
  const ids = key.split(',')
  const requestId = ++countRequestId

  recipientCountError.value = ''

  try {
    const params = new URLSearchParams()
    ids.forEach(id => params.append('barangays[]', id))

    const res = await fetch(`${API_BASE}/sms/recipient-count?${params}`, { headers: getHeaders() })

    // Throttling is not the count failing, it is this page having asked too
    // often, and it clears by itself. Handled before the body is read because
    // Laravel answers with its own "Too Many Attempts.", which tells an operator
    // nothing they can act on. Not cached: there is no count here to remember.
    if (res.status === 429) {
      if (requestId !== countRequestId) return

      recipientCount.value = null
      recipientCountError.value = 'Recipient count paused briefly — reselect to refresh'
      return
    }

    const data = await res.json()
    if (!res.ok) throw new Error(data.message || 'Failed to count recipients')

    // A slower earlier request must not overwrite a newer answer — ticking
    // through the barangay list fires several of these in a row.
    if (requestId !== countRequestId) return

    recipientCount.value = data.count
    // Cached on success only. A failed or throttled attempt must stay retryable
    // rather than be remembered as an answer.
    countCache.set(key, data.count)
  } catch {
    if (requestId !== countRequestId) return

    // Deliberately not the page-level alert: failing to preview a count is not
    // a reason to redden a form that still sends perfectly well.
    recipientCount.value = null
    recipientCountError.value = 'Could not count recipients'
  } finally {
    if (requestId === countRequestId) recipientCountLoading.value = false
  }
}

// Watches the key, not the array, so it never fires for a selection whose
// contents did not change — which is what was driving the 429. `deep` is gone
// with it: a plain string needs no traversal, and the array was only ever
// replaced, never mutated in place.
watch(currentSelectionKey, (key) => {
  clearTimeout(countTimer)

  if (key === '') {
    // Abandon anything still in flight, or its answer arrives after the
    // selection was cleared and prints a count for nobody.
    countRequestId++
    recipientCount.value = null
    recipientCountError.value = ''
    recipientCountLoading.value = false
    return
  }

  // Already counted. No request, no debounce, and no "Counting…" flicker for a
  // number that is on hand. The id bump abandons anything in flight so a late
  // reply cannot overwrite the cached value with an older one.
  if (countCache.has(key)) {
    countRequestId++
    recipientCount.value = countCache.get(key)
    recipientCountError.value = ''
    recipientCountLoading.value = false
    return
  }

  recipientCountLoading.value = true
  // 600ms, up from 300. This only ever delays the first look at a selection —
  // every repeat is a cache hit — so the extra wait costs nothing on the common
  // path and buys room on the slow one.
  countTimer = setTimeout(() => fetchRecipientCount(key), 600)
})
const fetchBarangays = async () => {
  barangaysLoading.value = true
  try {
    const res = await fetch(`${API_BASE}/barangays`, { headers: getHeaders() })
    const data = await res.json()
    if (!res.ok) throw new Error(data.message || 'Failed to load barangays')
    barangays.value = data.data || data
  } catch (error) {
    alert.value = {
      show: true,
      type: 'error',
      message: `Could not load barangays: ${error.message}`
    }
  } finally {
    barangaysLoading.value = false
  }
}

// Account-level rather than per-message, so it is read once on mount and never
// again while the page is open.
//
// PhilSMS returns a peso balance, not a unit count — there is no "messages
// remaining" figure to display, and the page must not imply one. Converting
// pesos to segments would need a per-segment rate hardcoded here, which is a
// confident wrong number next to Send the first time the vendor reprices.
const balance = ref({ available: false, checked: false, remaining: '', expiresOn: '', message: '' })

const fetchBalance = async () => {
  try {
    const res = await fetch(`${API_BASE}/sms/balance`, { headers: getHeaders() })
    const data = await res.json()

    balance.value = {
      available: !!data.available,
      checked: true,
      remaining: data.data?.remaining_balance ?? '',
      expiresOn: data.data?.expired_on ?? '',
      message: data.message ?? 'SMS credit unavailable',
    }
  } catch {
    // Swallowed on purpose. GET /sms/balance already answers 200 on every
    // failure path it knows about, so this catches only a dead network — and a
    // missing balance is not a reason to redden a form that still sends.
    balance.value = { available: false, checked: true, remaining: '', expiresOn: '', message: 'SMS credit unavailable' }
  }
}

onMounted(() => {
  fetchBarangays()
  fetchBalance()
})

const sendSmsBlast = async () => {
  clearFieldErrors()

  const { valid } = await form.value.validate()
  if (!valid) {
    alert.value = { show: true, type: 'error', message: 'Please correct the highlighted fields.' }
    return
  }

  // Selecting every barangay is one tap now, and the blast is billed per real
  // send — so the confirmation names the scale instead of listing every
  // barangay, which is the case where a wall of names reads as detail rather
  // than as a warning.
  const confirmMessage = allBarangaysSelected.value
    ? `Send this message to EVERY barangay in the municipality — all ${barangays.value.length} of them, and every active, opted-in resident in each?`
    : `Send this message to the active, opted-in residents of: ${barangays.value
        .filter(b => selectedBarangays.value.includes(b.barangay_id))
        .map(b => b.barangay_name)
        .join(', ')}?`

  // The scale is the point of the confirmation, so it names what is about to
  // be spent as well as who it reaches.
  const costLine = billedUnits.value === null
    ? ''
    : `${recipientCount.value.toLocaleString()} recipients × ${sms.value.segments} segment${sms.value.segments === 1 ? '' : 's'} ≈ ${billedUnits.value.toLocaleString()} SMS units.`

  confirmDialog.value = { open: true, summary: confirmMessage, cost: costLine, password: '' }
}

const cancelSend = () => {
  confirmDialog.value.open = false
  confirmDialog.value.password = ''
  fieldErrors.value.password = ''
}

// The dialog stays open on a rejected password so the wrong one can be
// corrected in place, and the send is retried against the same message and
// barangay selection rather than composed again.
const confirmSend = async () => {
  fieldErrors.value.password = ''
  loading.value = true
  alert.value.show = false

  try {
    const res = await fetch(`${API_BASE}/sms/blast`, {
      method: 'POST',
      headers: getHeaders(),
      body: JSON.stringify({
        message: message.value,
        barangays: selectedBarangays.value,
        password: confirmDialog.value.password
      })
    })

    const data = await res.json()

    if (!res.ok) throw new Error(applyServerErrors(data))

    // Sent. The password is dropped here rather than held for a second blast.
    confirmDialog.value = { open: false, summary: '', cost: '', password: '' }

    // A 202 with `unconfirmed` means the vendor never answered, so res.ok is
    // true but the send is not confirmed. Branching on it matters more than it
    // looks: without this the server's "do NOT send it again" is discarded and
    // the box reads "Success: 0 messages dispatched", which is worse than the
    // error it replaced.
    alert.value = data.unconfirmed
      ? { show: true, type: 'warning', message: data.message }
      : {
          show: true,
          type: 'success',
          message: `Success: ${data.sent} messages dispatched. ${data.failed} failed.`
        }

    message.value = ''
    selectedTemplate.value = null
    selectedBarangays.value = []
    clearFieldErrors()
    if (form.value) form.value.resetValidation()
    
  } catch (error) {
    alert.value = {
      show: true,
      type: 'error',
      message: error.message
    }

    // A rejected password keeps the dialog open to be retyped. Any other
    // failure closes it, because the alert explaining that failure renders on
    // the page behind this overlay and would otherwise not be readable.
    if (!fieldErrors.value.password) {
      confirmDialog.value.open = false
      confirmDialog.value.password = ''
    }
  } finally {
    loading.value = false
  }
}
</script>

<style scoped>
.gap-2 { gap: 8px; }
.gap-3 { gap: 12px; }
.gap-4 { gap: 16px; }

.fade-in {
  animation: fadeIn 0.5s cubic-bezier(0.16, 1, 0.3, 1);
}

@keyframes fadeIn {
  from { opacity: 0; transform: translateY(15px); }
  to { opacity: 1; transform: translateY(0); }
}

:deep(.v-field__input) {
  line-height: 1.6;
}
</style>