<template>
    <v-container fluid class="bg-grey-lighten-4 fill-height align-start pa-8"
        style="background-color: #F4F7FC !important;">
        <v-row>
            <v-col cols="12">
                <v-card elevation="2" rounded="xl" class="pa-6 border-0">

                    <v-row class="mb-6" align="center" justify="space-between">
                        <v-col cols="12" md="3">
                            <h2 class="text-h5 font-weight-bold text-grey-darken-3">User Management</h2>
                            <div class="text-subtitle-2 text-grey">Manage resident access and details</div>
                        </v-col>

                        <v-col cols="12" md="9" class="d-flex gap-4 justify-end">
                            <v-text-field v-model="search" prepend-inner-icon="mdi-magnify" placeholder="Search users..."
                                variant="outlined" density="compact" hide-details rounded="lg" bg-color="white"
                                class="max-w-xs"></v-text-field>

                            <v-select v-model="filters.status" :items="['All', 'Active', 'Deactivated']" label="Status"
                                variant="outlined" density="compact" hide-details rounded="lg" bg-color="white"
                                class="max-w-xs"></v-select>

                            <v-select v-model="filters.barangay"
                                :items="['All', ...barangays.map(b => b.barangay_name)]" label="Barangay"
                                variant="outlined" density="compact" hide-details rounded="lg" bg-color="white"
                                class="max-w-xs"></v-select>

                            <v-btn color="primary" elevation="0" rounded="lg" height="40"
                                class="px-6 text-none font-weight-bold" @click="openAddModal">
                                <v-icon start>mdi-plus</v-icon> Add User
                            </v-btn>
                        </v-col>
                    </v-row>

                    <v-data-table :headers="headers" :items="filteredAndSortedResidents" :search="search"
                        :items-per-page="10" hover class="custom-table" @click:row="openEditModal">
                        <template v-slot:item.photo="{ item }">
                            <v-avatar color="blue-lighten-4" size="40" class="my-2">
                                <v-img v-if="item.photo" :src="item.photo" alt="Photo"></v-img>
                                <span v-else class="text-blue-darken-2 font-weight-bold text-body-2">
                                    {{ item.first_name?.charAt(0) }}{{ item.last_name?.charAt(0) }}
                                </span>
                            </v-avatar>
                        </template>

                        <template v-slot:item.fullName="{ item }">
                            <div class="font-weight-medium text-grey-darken-3">
                                {{ item.last_name }}, {{ item.first_name }} {{ item.middle_name || '' }}
                            </div>
                        </template>

                        <template v-slot:item.barangay_name="{ item }">
                            <span class="text-grey-darken-2">{{ item.barangay?.barangay_name || item.barangay_name ||
                                'N/A' }}</span>
                        </template>

                        <template v-slot:item.phone_number="{ item }">
                            <span class="text-grey-darken-2">{{ item.phone_number }}</span>
                        </template>

                        <template v-slot:item.email_address="{ item }">
                            <span class="text-grey-darken-2">{{ item.email_address }}</span>
                        </template>

                        <template v-slot:item.status="{ item }">
                            <v-chip :color="item.status === 'Active' ? 'success' : 'grey'" size="small" label
                                variant="tonal" class="text-uppercase font-weight-bold px-3">
                                {{ item.status }}
                            </v-chip>
                        </template>
                    </v-data-table>
                </v-card>
            </v-col>
        </v-row>

        <v-dialog v-model="modal.isOpen" max-width="650" persistent>
            <v-card rounded="xl" elevation="10">
                <v-card-title class="d-flex justify-space-between align-center pa-6 border-b">
                    <span class="text-h6 font-weight-bold text-grey-darken-3">
                        {{ modal.isEditing ? 'User Details' : 'Add New User' }}
                    </span>
                    <v-btn icon="mdi-close" variant="text" size="small" color="grey" @click="closeModal"></v-btn>
                </v-card-title>

                <v-card-text class="pa-6">
                    <v-alert v-if="apiError" type="error" variant="tonal" class="mb-6" density="compact" rounded="lg">
                        {{ apiError }}
                    </v-alert>

                    <v-form ref="form" @submit.prevent="saveUser">
                        <v-row>
                            <v-col cols="12" class="d-flex align-center gap-4 mb-2">
                                <v-avatar color="grey-lighten-3" size="70">
                                    <span class="text-h5 text-grey-darken-1 font-weight-bold">
                                        {{ formData.first_name?.charAt(0) || 'A' }}{{ formData.last_name?.charAt(0) ||
                                        'V' }}
                                    </span>
                                </v-avatar>
                                <div>
                                    <div class="text-subtitle-2 font-weight-bold text-grey-darken-3 mb-1">Profile Picture</div>
                                    <v-btn variant="outlined" color="primary" size="small" rounded="lg" class="text-none">Change Photo</v-btn>
                                </div>
                            </v-col>

                            <v-col cols="12" md="4">
                                <v-text-field v-model="formData.first_name" label="First Name *" variant="outlined"
                                    density="comfortable" rounded="lg" color="primary" bg-color="white"
                                    required></v-text-field>
                            </v-col>
                            <v-col cols="12" md="4">
                                <v-text-field v-model="formData.middle_name" label="Middle Name" variant="outlined"
                                    density="comfortable" rounded="lg" color="primary" bg-color="white"></v-text-field>
                            </v-col>
                            <v-col cols="12" md="4">
                                <v-text-field v-model="formData.last_name" label="Last Name *" variant="outlined"
                                    density="comfortable" rounded="lg" color="primary" bg-color="white"
                                    required></v-text-field>
                            </v-col>

                            <v-col cols="12" md="6">
                                <v-text-field v-model="formData.phone_number" label="Phone Number *" variant="outlined"
                                    density="comfortable" rounded="lg" color="primary" bg-color="white"
                                    required></v-text-field>
                            </v-col>
                            <v-col cols="12" md="6">
                                <v-text-field v-model="formData.email_address" label="Email Address *" type="email"
                                    variant="outlined" density="comfortable" rounded="lg" color="primary"
                                    bg-color="white" required></v-text-field>
                            </v-col>

                            <v-col cols="12" md="6" v-if="!modal.isEditing">
                                <v-text-field v-model="formData.password" label="Password (Min 8 chars) *"
                                    type="password" variant="outlined" density="comfortable" rounded="lg"
                                    color="primary" bg-color="white" append-inner-icon="mdi-key" required></v-text-field>
                            </v-col>

                            <v-col cols="12" :md="modal.isEditing ? 12 : 6">
                                <v-select v-model="formData.barangay_id" :items="barangays" item-title="barangay_name"
                                    item-value="barangay_id" label="Barangay *" variant="outlined" density="comfortable"
                                    rounded="lg" color="primary" bg-color="white" required></v-select>
                            </v-col>

                            <v-col cols="12">
                                <div class="text-subtitle-2 font-weight-bold text-grey-darken-3 mb-2">Account Status</div>
                                <v-radio-group v-model="formData.status" inline hide-details color="primary">
                                    <v-radio label="Activate" value="Active"></v-radio>
                                    <v-radio label="Deactivate" value="Deactivated"></v-radio>
                                </v-radio-group>
                            </v-col>
                        </v-row>
                    </v-form>
                </v-card-text>

                <v-card-actions class="pa-6 pt-0 d-flex justify-start">
                    <v-btn color="primary" variant="flat" rounded="lg" class="px-6 text-none font-weight-bold"
                        height="44" @click="saveUser" :loading="loading">
                        Save Changes
                    </v-btn>
                    <v-btn v-if="modal.isEditing" color="error" variant="outlined" rounded="lg"
                        class="px-6 text-none font-weight-bold ml-3" height="44" @click="deleteUser"
                        :loading="deleteLoading">
                        Delete
                    </v-btn>
                    <v-spacer></v-spacer>
                    <v-btn color="grey-darken-1" variant="text" rounded="lg" class="px-6 text-none font-weight-bold"
                        height="44" @click="closeModal">
                        Cancel
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </v-container>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'

const headers = [
    { title: 'Photo', key: 'photo', sortable: false, align: 'center', width: '80px' },
    { title: 'Full Name', key: 'fullName' },
    { title: 'Barangay', key: 'barangay_name' },
    { title: 'Phone Number', key: 'phone_number' },
    { title: 'Email', key: 'email_address' },
    { title: 'Status', key: 'status', align: 'center' }
]

const residents = ref([])
const barangays = ref([])
const search = ref('')
const loading = ref(false)
const deleteLoading = ref(false)
const apiError = ref('')

const filters = ref({
    status: 'All',
    barangay: 'All'
})

const modal = ref({
    isOpen: false,
    isEditing: false,
    targetId: null
})

const formData = ref({
    first_name: '',
    middle_name: '',
    last_name: '',
    phone_number: '',
    email_address: '',
    password: '',
    barangay_id: null,
    status: 'Active'
})

const filteredAndSortedResidents = computed(() => {
    let result = residents.value

    if (filters.value.status !== 'All') {
        result = result.filter(r => r.status === filters.value.status)
    }

    if (filters.value.barangay !== 'All') {
        // Accommodate flat structure or nested relation from Laravel
        result = result.filter(r => (r.barangay?.barangay_name || r.barangay_name) === filters.value.barangay)
    }

    return result.sort((a, b) => {
        const nameA = `${a.last_name} ${a.first_name}`.toLowerCase()
        const nameB = `${b.last_name} ${b.first_name}`.toLowerCase()
        return nameA.localeCompare(nameB)
    })
})

const getHeaders = () => ({
    'Authorization': `Bearer ${localStorage.getItem('serbis_token')}`,
    'Content-Type': 'application/json',
    'Accept': 'application/json'
})

const fetchResidents = async () => {
    try {
        const res = await fetch('http://localhost:8000/api/residents', { headers: getHeaders() })
        const data = await res.json()
        residents.value = data.data || data
    } catch (error) {
        console.error('Failed to fetch residents:', error)
    }
}

const fetchBarangays = async () => {
    try {
        const res = await fetch('http://localhost:8000/api/barangays', { headers: getHeaders() })
        const data = await res.json()
        barangays.value = data.data || data
    } catch (error) {
        console.error('Failed to fetch barangays:', error)
    }
}

const openAddModal = () => {
    apiError.value = ''
    formData.value = {
        first_name: '', middle_name: '', last_name: '', phone_number: '',
        email_address: '', password: '', barangay_id: null, status: 'Active'
    }
    modal.value = { isOpen: true, isEditing: false, targetId: null }
}

const openEditModal = (event, { item }) => {
    apiError.value = ''
    formData.value = {
        first_name: item.first_name,
        middle_name: item.middle_name,
        last_name: item.last_name,
        phone_number: item.phone_number,
        email_address: item.email_address,
        password: '',
        barangay_id: item.barangay_id,
        status: item.status
    }
    modal.value = { isOpen: true, isEditing: true, targetId: item.id || item.resident_id }
}

const closeModal = () => {
    modal.value.isOpen = false
}

const saveUser = async () => {
    loading.value = true
    apiError.value = ''

    const url = modal.value.isEditing
        ? `http://localhost:8000/api/residents/${modal.value.targetId}`
        : 'http://localhost:8000/api/residents'

    const method = modal.value.isEditing ? 'PUT' : 'POST'

    try {
        const res = await fetch(url, {
            method,
            headers: getHeaders(),
            body: JSON.stringify(formData.value)
        })

        if (!res.ok) {
            const errData = await res.json()
            throw new Error(errData.message || 'Validation failed')
        }

        await fetchResidents()
        closeModal()
    } catch (error) {
        apiError.value = error.message
    } finally {
        loading.value = false
    }
}

const deleteUser = async () => {
    if (!confirm('Are you sure you want to delete this user?')) return

    deleteLoading.value = true
    try {
        const res = await fetch(`http://localhost:8000/api/residents/${modal.value.targetId}`, {
            method: 'DELETE',
            headers: getHeaders()
        })

        if (!res.ok) throw new Error('Failed to delete')

        await fetchResidents()
        closeModal()
    } catch (error) {
        apiError.value = error.message
    } finally {
        deleteLoading.value = false
    }
}

onMounted(() => {
    fetchBarangays()
    fetchResidents()
})
</script>

<style scoped>
.gap-4 {
    gap: 16px;
}

.custom-table :deep(th) {
    font-weight: 600 !important;
    color: #616161 !important;
    background-color: transparent !important;
    border-bottom: 2px solid #EEEEEE !important;
}

.custom-table :deep(td) {
    border-bottom: 1px solid #F5F5F5 !important;
}
</style>