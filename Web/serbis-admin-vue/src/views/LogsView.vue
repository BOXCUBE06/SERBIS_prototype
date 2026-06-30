<template>
  <v-container fluid class="fill-height align-start pa-8" style="background-color: #F4F7FC !important;">
    <v-row>
      <v-col cols="12">
        <div class="d-flex justify-space-between align-center mb-6">
          <div>
            <h2 class="text-h4 font-weight-black text-grey-darken-4">System Logs</h2>
            <div class="text-subtitle-1 text-grey-darken-1">Monitor user activity and SMS broadcast history</div>
          </div>
          
          <v-text-field
            v-model="search"
            prepend-inner-icon="mdi-magnify"
            placeholder="Search logs..."
            variant="solo"
            density="compact"
            hide-details
            rounded="lg"
            class="elevation-1"
            style="max-width: 300px;"
          ></v-text-field>
        </div>

        <v-card elevation="0" border rounded="xl" class="bg-white">
          <v-tabs v-model="activeTab" color="primary" class="border-b px-4">
            <v-tab value="system" class="text-none font-weight-bold">
              <v-icon start>mdi-laptop</v-icon> System Activity
            </v-tab>
            <v-tab value="sms" class="text-none font-weight-bold">
              <v-icon start>mdi-message-text</v-icon> SMS History
            </v-tab>
          </v-tabs>

          <v-card-text class="pa-0">
            <v-window v-model="activeTab">
              <v-window-item value="system">
                <v-data-table
                  :headers="systemHeaders"
                  :items="systemLogs"
                  :search="search"
                  :loading="loading"
                  hover
                  class="bg-transparent"
                >
                  <template v-slot:item.action="{ item }">
                    <v-chip :color="getActionColor(item.action)" size="small" variant="tonal" class="font-weight-bold">
                      {{ item.action }}
                    </v-chip>
                  </template>
                  <template v-slot:item.created_at="{ item }">
                    {{ formatDate(item.created_at) }}
                  </template>
                </v-data-table>
              </v-window-item>

              <v-window-item value="sms">
                <v-data-table
                  :headers="smsHeaders"
                  :items="smsLogs"
                  :search="search"
                  :loading="loading"
                  hover
                  class="bg-transparent"
                >
                  <template v-slot:item.status="{ item }">
                    <v-chip :color="item.status === 'Completed' ? 'green' : 'orange'" size="small" variant="tonal" class="font-weight-bold">
                      {{ item.status }}
                    </v-chip>
                  </template>
                  <template v-slot:item.created_at="{ item }">
                    {{ formatDate(item.created_at) }}
                  </template>
                </v-data-table>
              </v-window-item>
            </v-window>
          </v-card-text>
        </v-card>

      </v-col>
    </v-row>
  </v-container>
</template>

<script setup>
import { ref, onMounted } from 'vue'

const activeTab = ref('system')
const search = ref('')
const loading = ref(false)

const systemLogs = ref([])
const smsLogs = ref([])

// Table Definitions
const systemHeaders = [
  { title: 'Date & Time', key: 'created_at', width: '20%' },
  { title: 'User', key: 'user.name', width: '20%' },
  { title: 'Module', key: 'module', width: '15%' },
  { title: 'Action', key: 'action', width: '15%' },
  { title: 'Description', key: 'description', width: '30%' },
]

const smsHeaders = [
  { title: 'Date & Time', key: 'created_at', width: '20%' },
  { title: 'Sender', key: 'user.name', width: '20%' },
  { title: 'Message Content', key: 'message', width: '40%' },
  { title: 'Recipients', key: 'recipient_count', align: 'center', width: '10%' },
  { title: 'Status', key: 'status', align: 'center', width: '10%' },
]

// Data Fetching
const getHeaders = () => ({
  'Authorization': `Bearer ${localStorage.getItem('serbis_token')}`,
  'Accept': 'application/json'
})

const fetchLogs = async () => {
  loading.value = true
  try {
    const [systemRes, smsRes] = await Promise.all([
      fetch('http://localhost:8000/api/logs/system', { headers: getHeaders() }),
      fetch('http://localhost:8000/api/logs/sms', { headers: getHeaders() })
    ])

    const systemData = await systemRes.json()
    const smsData = await smsRes.json()

    systemLogs.value = systemData.data || systemData
    smsLogs.value = smsData.data || smsData
  } catch (error) {
    console.error('Failed to fetch logs:', error)
  } finally {
    loading.value = false
  }
}

// Helpers
const formatDate = (dateString) => {
  if (!dateString) return ''
  const date = new Date(dateString)
  return new Intl.DateTimeFormat('en-PH', { 
    year: 'numeric', month: 'short', day: '2-digit', 
    hour: '2-digit', minute: '2-digit' 
  }).format(date)
}

const getActionColor = (action) => {
  switch (action?.toLowerCase()) {
    case 'created': return 'green'   // was 'create'
    case 'updated': return 'blue'    // was 'update'
    case 'deleted': return 'red'     // was 'delete'
    case 'login':   return 'purple'
    default:        return 'grey'
  }
}

onMounted(() => {
  fetchLogs()
})
</script>