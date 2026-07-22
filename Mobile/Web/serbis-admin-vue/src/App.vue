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
  background-color: #113F36;
}

.v-main__wrap {
  padding: 0 !important;
}

.outer-wrapper {
  display: flex;
  height: 100vh;
  padding: 12px; /* equal padding on all sides now */
  box-sizing: border-box;
  margin-left: 280px;
}

.inner-wrapper {
  flex: 1;
  background-color: #F4F7FC;
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
</style>