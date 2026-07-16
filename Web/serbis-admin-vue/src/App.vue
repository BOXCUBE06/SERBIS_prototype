<template>
  <v-app>
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
import { useAppTheme } from '@/composables/useAppTheme'

const route = useRoute()

const isAuthPage = computed(() => route?.path === '/login')

useAppTheme().init()
</script>

<style>
.v-application {
  font-family: 'Inter', sans-serif !important;
}

/* Shared theme-aware surfaces. Vuetify's own bg-surface-light resolves to a
   fixed grey (#424242) that ignores the palette, so tint on-surface instead —
   that tracks whichever theme is active. */
.subtle-surface {
  background-color: rgba(var(--v-theme-on-surface), 0.05);
}

.subtle-border {
  border: 1px solid rgba(var(--v-theme-on-surface), 0.08) !important;
}

.v-main {
  padding: 0 !important;
  background-color: #0A2620;
}

.v-main__wrap {
  padding: 0 !important;
}

.outer-wrapper {
  display: flex;
  height: 100vh;
  padding: 12px;
  box-sizing: border-box;
  margin-left: 260px; 
}

.inner-wrapper {
  flex: 1;
  background-color: rgb(var(--v-theme-background));
  border-radius: 24px;
  overflow-y: auto;
  height: 100%;

  scrollbar-width: none;
  -ms-overflow-style: none;
}

.inner-wrapper::-webkit-scrollbar {
  display: none;
}

.v-main .v-container {
  padding-left: 40px !important;
  padding-right: 24px !important;
}

.modern-drawer {
  font-family: 'Inter', sans-serif;
  background: radial-gradient(circle at -10% 50%, #154c41 0%, #0A2620 80%) !important;
  border-right: none !important;
}

.nav-item {
  transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1) !important;
}
</style>