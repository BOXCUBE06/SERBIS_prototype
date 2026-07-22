<template>
  <div class="split-viewport d-flex align-center justify-center overflow-hidden">
    <v-row class="ma-0 fill-height w-100">
      
      <!-- Left Side -->
      <v-col cols="12" md="5" class="d-flex flex-column align-center justify-center pa-8">
        <div class="d-flex flex-column align-center text-center" style="margin-top: -80px;">
          <img 
            src="@/assets/mdrrmo_logo.jpg" 
            alt="MDRRMO Logo"
            style="width: 220px; height: 220px; object-fit: contain; border-radius: 50%;"
            class="mb-6" 
          />
          <h1 class="text-h2 font-weight-black text-grey-darken-4 mb-3" style="letter-spacing: 6px; line-height: 1.2; margin-top: -16px;">
            SERBIS
          </h1>
          <div class="text-body-1 text-grey-darken-1 font-weight-medium" style="max-width: 350px; line-height: 1.8;">
            MDRRMO Echague Disaster Communication<br>& Service Coordination System
          </div>
        </div>
      </v-col>

      <!-- Right Side -->
      <v-col cols="12" md="7" class="d-flex flex-column align-center justify-center pa-8">
        <div style="width: 100%; max-width: 420px; margin-top: -50px;">
          <v-form @submit.prevent="handleLogin" class="w-100">
            
            <!-- Error Alert -->
            <v-alert
  v-if="errorMessage"
  type="error"
  variant="flat"
  rounded="lg"
  density="comfortable"
  class="mb-4"
  closable
  @click:close="errorMessage = ''"
  style="background-color: rgba(211, 47, 47, 0.15); border: 1px solid rgba(211, 47, 47, 0.4);"
>
  <span class="text-body-2 font-weight-medium" style="color: #ffcdd2;">
    {{ errorMessage }}
  </span>
</v-alert>

            <div class="text-body-2 text-white mb-2 font-weight-medium">Email</div>
            <v-text-field
              v-model="credentials.email_address"
              placeholder="Example@serbis.com"
              variant="solo"
              bg-color="white"
              density="comfortable"
              rounded="md"
              hide-details
              elevation="0"
              autocomplete="off"
              class="mb-5 flat-input"
            ></v-text-field>

            <div class="text-body-2 text-white mb-2 font-weight-medium">Password</div>
            <v-text-field
              v-model="credentials.password"
              type="password"
              placeholder="********"
              variant="solo"
              bg-color="white"
              density="comfortable"
              rounded="md"
              hide-details
              elevation="0"
              autocomplete="off"
              class="mb-4 flat-input"
            ></v-text-field>

            <div class="d-flex justify-space-between align-center mb-5">
              <v-checkbox 
                v-model="rememberMe" 
                label="Remember me" 
                density="compact" 
                hide-details 
                class="custom-checkbox"
              ></v-checkbox>
              <a href="#" class="text-body-2 text-green-lighten-3 text-decoration-none hover-underline">
                Recover password
              </a>
            </div>

            <v-btn 
              type="submit" 
              color="#66BB6A" 
              block 
              height="52" 
              rounded="md" 
              elevation="0" 
              class="text-none font-weight-bold text-white text-body-1"
              :loading="loading"
            >
              SIGN IN
            </v-btn>

          </v-form>
        </div>
      </v-col>

    </v-row>
  </div>
</template>

<script setup>
import { ref, reactive } from 'vue'
import { useRouter } from 'vue-router'

const router = useRouter()
const loading = ref(false)
const rememberMe = ref(false)
const errorMessage = ref('')

const credentials = reactive({
  email_address: '',
  password: ''
})

const handleLogin = async () => {
  loading.value = true
  errorMessage.value = ''

  try {
    const response = await fetch('http://localhost:8000/api/admin/login', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json'
      },
      body: JSON.stringify(credentials)
    })

    const data = await response.json()

    if (response.ok) {
      localStorage.setItem('serbis_token', data.token)
      router.push('/')
    } else if (response.status === 401) {
      errorMessage.value = 'Invalid email or password. Please try again.'
    } else if (response.status === 422) {
      // Laravel validation errors
      const errors = data.errors
      if (errors) {
        errorMessage.value = Object.values(errors).flat().join(' ')
      } else {
        errorMessage.value = data.message || 'Invalid input. Please check your details.'
      }
    } else {
      errorMessage.value = 'Something went wrong. Please try again later.'
    }
  } catch (error) {
    errorMessage.value = 'Network error. Please check your connection.'
  } finally {
    loading.value = false
  }
}
</script>

<style scoped>
.split-viewport {
  height: 100vh;
  width: 100vw;
  background: linear-gradient(105deg, #ffffff 42%, #113F36 42%);
}

@media (max-width: 959px) {
  .split-viewport {
    background: linear-gradient(180deg, #ffffff 40%, #113F36 40%);
  }
}

.flat-input :deep(.v-field) {
  box-shadow: none !important;
  border-radius: 6px;
}

/* Hide the browser autofill key icon */
.flat-input :deep(.v-field__append-inner),
.flat-input :deep(input::-webkit-credentials-auto-fill-button),
.flat-input :deep(input::-webkit-contacts-auto-fill-button) {
  display: none !important;
  visibility: hidden !important;
}

.flat-input :deep(.v-icon) {
  display: none !important;
}

.custom-checkbox :deep(.v-label) {
  color: white !important;
  opacity: 1 !important;
  font-size: 0.875rem !important;
}

.custom-checkbox :deep(.v-selection-control__input > .v-icon) {
  color: white !important;
}

.hover-underline:hover {
  text-decoration: underline !important;
}
</style>