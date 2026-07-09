<template>
  <v-app theme="light">
    <AppSidebar v-if="!isAuthPage" />
    <v-main>
      <div v-if="!isAuthPage" class="outer-wrapper">
        <div class="inner-wrapper">
          <RouterView />
        </div>
      </div>
      <RouterView v-else />
    </v-main>
  </v-app>
</template>

<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import AppSidebar from '@/components/AppSidebar.vue'

const route = useRoute()

const isAuthPage = computed(() =>
  route?.path === '/login' || route?.path === '/register'
)
</script>

<style>
.v-main {
  padding: 0 !important;
  background-color: #0A2620; /* Matches the dark edge of the sidebar gradient */
}

.v-main__wrap {
  padding: 0 !important;
}

.outer-wrapper {
  display: flex;
  height: 100vh;
  padding: 12px;
  box-sizing: border-box;
  margin-left: 280px;
}

.inner-wrapper {
  flex: 1;
  background-color: #F8FAFC; /* Matches the soft background used in internal pages */
  border-radius: 24px;
  overflow-y: auto;
  height: 100%;
  
  /* Hide scrollbar but keep scroll functionality */
  scrollbar-width: none; /* Firefox */
  -ms-overflow-style: none; /* IE/Edge */
}

.inner-wrapper::-webkit-scrollbar {
  display: none; /* Chrome/Safari */
}

.v-main .v-container {
  padding-left: 40px !important;
  padding-right: 24px !important;
}

.modern-drawer {
  font-family: 'Inter', sans-serif;
  background: radial-gradient(circle at -10% 50%, #154c41 0%, #0A2620 80%) !important;
  border-right: none !important; /* Removes the 1px seam in the middle */
}

.nav-item {
  margin-right: 8px !important;
  transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1) !important;
}
</style>