<template>
  <v-container fluid class="bg-grey-lighten-4 fill-height align-start pa-8" style="background-color: #F4F7FC !important;">
    <v-row justify="center">
      <v-col cols="12" md="8" lg="6">
        
        <v-card elevation="2" rounded="xl" class="pa-8 border-0">
          <div class="d-flex align-center mb-6">
            <v-avatar color="blue-lighten-5" size="50" class="mr-4 text-blue-darken-2">
              <v-icon>mdi-message-text-fast</v-icon>
            </v-avatar>
            <div>
              <h2 class="text-h5 font-weight-bold text-grey-darken-3">Targeted Text Blast</h2>
              <div class="text-subtitle-2 text-grey">Send emergency alerts to specific barangays</div>
            </div>
          </div>

          <v-alert v-if="alert.show" :type="alert.type" variant="tonal" class="mb-6" closable @click:close="alert.show = false">
            {{ alert.message }}
          </v-alert>

          <v-form ref="form" @submit.prevent="sendSmsBlast">
            
            <h3 class="text-subtitle-2 font-weight-bold mb-2 text-grey-darken-2">Target Audience</h3>
            <v-select
              v-model="selectedBarangays"
              :items="barangayOptions"
              item-title="barangay_name"
              item-value="barangay_id"
              label="Select Barangays"
              variant="outlined"
              density="comfortable"
              rounded="lg"
              color="primary"
              bg-color="white"
              multiple
              chips
              closable-chips
              persistent-hint
              hint="Select specific barangays or choose 'All Barangays'"
              :rules="[v => v.length > 0 || 'You must select at least one target']"
            >
              <template v-slot:prepend-item>
                <v-list-item title="Select All" @click="selectAllBarangays">
                  <template v-slot:prepend>
                    <v-icon :color="isAllSelected ? 'primary' : ''">
                      {{ isAllSelected ? 'mdi-checkbox-marked' : 'mdi-checkbox-blank-outline' }}
                    </v-icon>
                  </template>
                </v-list-item>
                <v-divider class="mb-2"></v-divider>
              </template>
            </v-select>

            <h3 class="text-subtitle-2 font-weight-bold mb-2 mt-6 text-grey-darken-2">Message Content</h3>
            <v-textarea
              v-model="message"
              label="SMS Message"
              variant="outlined"
              density="comfortable"
              rounded="lg"
              color="primary"
              bg-color="white"
              rows="4"
              counter="160"
              :rules="[
                v => !!v || 'Message is required',
                v => v.length <= 160 || 'Message exceeds 160 characters'
              ]"
              placeholder="e.g., MDRRMO Alert: Flood warning in your area. Evacuate to higher ground immediately."
            ></v-textarea>

            <v-card-actions class="px-0 pt-6 d-flex justify-end">
              <v-btn 
                color="primary" 
                variant="flat" 
                rounded="lg" 
                class="px-8 text-none font-weight-bold" 
                height="48" 
                type="submit" 
                :loading="loading"
                :disabled="!isValid"
              >
                <v-icon start>mdi-send</v-icon> Send Text Blast
              </v-btn>
            </v-card-actions>
          </v-form>

        </v-card>
      </v-col>
    </v-row>
  </v-container>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'

const message = ref('')
const selectedBarangays = ref([])
const barangays = ref([])
const loading = ref(false)
const form = ref(null)

const alert = ref({
  show: false,
  type: 'success',
  message: ''
})

const getHeaders = () => ({
  'Authorization': `Bearer ${localStorage.getItem('serbis_token')}`,
  'Content-Type': 'application/json',
  'Accept': 'application/json'
})

const fetchBarangays = async () => {
  try {
    const res = await fetch('http://localhost:8000/api/barangays', { headers: getHeaders() })
    const data = await res.json()
    barangays.value = data
  } catch (error) {
    console.error('Failed to fetch barangays:', error)
  }
}

// Prepare options by adding 'All' logic
const barangayOptions = computed(() => {
  return [{ barangay_id: 'all', barangay_name: 'All Barangays' }, ...barangays.value]
})

const isAllSelected = computed(() => selectedBarangays.value.includes('all'))

const selectAllBarangays = () => {
  if (isAllSelected.value) {
    selectedBarangays.value = []
  } else {
    selectedBarangays.value = ['all']
  }
}

const isValid = computed(() => {
  return message.value.length > 0 && message.value.length <= 160 && selectedBarangays.value.length > 0
})

const sendSmsBlast = async () => {
  const { valid } = await form.value.validate()
  if (!valid) return

  if (!confirm(`Are you sure you want to send this alert to ${isAllSelected.value ? 'ALL barangays' : selectedBarangays.value.length + ' barangay(s)'}?`)) return

  loading.value = true
  alert.value.show = false

  try {
    const res = await fetch('http://localhost:8000/api/sms/blast', {
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
      message: `Success: ${data.sent} messages sent. ${data.failed} failed.`
    }
    
    // Reset form after successful send
    message.value = ''
    selectedBarangays.value = []
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

onMounted(() => {
  fetchBarangays()
})
</script>