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
              <h2 class="text-h4 font-weight-black text-high-emphasis" style="line-height: 1.1; letter-spacing: -0.02em;">Targeted Text Blast</h2>
              <div class="text-subtitle-1 font-weight-medium text-medium-emphasis mt-2">Dispatch critical SMS alerts to specific barangays</div>
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
                        <span class="text-caption">{{ barangays.length }} barangays — every active resident in the municipality</span>
                      </template>
                    </v-list-item>
                    <v-divider class="mt-2"></v-divider>
                  </template>
                </v-select>
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
                    v => !!v || 'An emergency message is required.',
                    v => v.length <= 160 || 'Message exceeds the standard 160 SMS character limit.'
                  ]"
                ></v-textarea>
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
                  :disabled="!isValid"
                  elevation="2"
                >
                  <v-icon start size="24" class="mr-2">mdi-send</v-icon>
                  <span class="text-h6 font-weight-bold">Dispatch Blast</span>
                </v-btn>
              </div>
            </v-form>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>
  </v-container>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { getToken } from '@/composables/authToken'
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

const getHeaders = () => ({
  'Authorization': `Bearer ${getToken()}`,
  'Content-Type': 'application/json',
  'Accept': 'application/json'
})

const isValid = computed(() => {
  return message.value.length > 0
    && message.value.length <= 160
    && selectedBarangays.value.length > 0
})

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

onMounted(fetchBarangays)

const sendSmsBlast = async () => {
  const { valid } = await form.value.validate()
  if (!valid) return

  // Selecting every barangay is one tap now, and the blast is billed per real
  // send — so the confirmation names the scale instead of listing every
  // barangay, which is the case where a wall of names reads as detail rather
  // than as a warning.
  const confirmMessage = allBarangaysSelected.value
    ? `Dispatch this alert to EVERY barangay in the municipality — all ${barangays.value.length} of them, and every active resident in each?`
    : `Dispatch this alert to all active residents in: ${barangays.value
        .filter(b => selectedBarangays.value.includes(b.barangay_id))
        .map(b => b.barangay_name)
        .join(', ')}?`

  if (!confirm(confirmMessage)) return

  loading.value = true
  alert.value.show = false

  try {
    const res = await fetch(`${API_BASE}/sms/blast`, {
      method: 'POST',
      headers: getHeaders(),
      body: JSON.stringify({
        message: message.value,
        barangays: selectedBarangays.value
      })
    })

    const data = await res.json()

    if (!res.ok) throw new Error(data.message || 'Failed to send blast')

    alert.value = {
      show: true,
      type: 'success',
      message: `Success: ${data.sent} messages dispatched. ${data.failed} failed.`
    }

    message.value = ''
    selectedBarangays.value = []
    if (form.value) form.value.resetValidation()
    
  } catch (error) {
    alert.value = {
      show: true,
      type: 'error',
      message: error.message
    }
  } finally {
    loading.value = false
  }
}
</script>

<style scoped>
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