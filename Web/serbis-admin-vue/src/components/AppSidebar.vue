<template>
  <v-navigation-drawer
    theme="dark"
    permanent
    width="260"
    class="modern-drawer"
  >
    <div class="pa-4 d-flex flex-column h-100">
      
      <div class="d-flex align-center justify-space-between mb-8 mt-2 px-2">
        <div class="d-flex align-center">
          <div class="logo-accent"></div>
          <span class="text-h6 font-weight-black text-white tracking-widest">SERBIS</span>
        </div>
        <v-btn
          :icon="theme.global.name.value === 'dark' ? 'mdi-weather-sunny' : 'mdi-weather-night'"
          size="small"
          variant="text"
          color="grey-lighten-1"
          :aria-label="theme.global.name.value === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'"
          @click="toggle"
        ></v-btn>
      </div>

      <div class="text-caption font-weight-medium text-white-50 mb-2 px-2 tracking-widest">Main Menu</div>
      <v-list bg-color="transparent" density="compact" nav class="px-0">
        <v-list-item 
          v-for="item in mainMenu" 
          :key="item.to" 
          :to="item.to" 
          class="mb-1 nav-item" 
          rounded="pill" 
          active-class="active-nav-item" 
          :ripple="false"
        >
          <template v-slot:prepend>
            <v-avatar rounded="circle" size="32" class="nav-icon-avatar mr-3" color="transparent">
              <v-icon size="18" color="grey-lighten-1">{{ item.icon }}</v-icon>
            </v-avatar>
          </template>
          <v-list-item-title class="font-weight-medium text-body-2 text-grey-lighten-1 nav-label">
            {{ item.title }}
          </v-list-item-title>
        </v-list-item>
      </v-list>

      <div class="text-caption font-weight-medium text-white-50 mt-6 mb-2 px-2 tracking-widest">System</div>
      <v-list bg-color="transparent" density="compact" nav class="px-0">
        <v-list-item 
          v-for="item in systemMenu" 
          :key="item.to" 
          :to="item.to" 
          class="mb-1 nav-item" 
          rounded="pill" 
          active-class="active-nav-item" 
          :ripple="false"
        >
          <template v-slot:prepend>
            <v-avatar rounded="circle" size="32" class="nav-icon-avatar mr-3" color="transparent">
              <v-icon size="18" color="grey-lighten-1">{{ item.icon }}</v-icon>
            </v-avatar>
          </template>
          <v-list-item-title class="font-weight-medium text-body-2 text-grey-lighten-1 nav-label">
            {{ item.title }}
          </v-list-item-title>
        </v-list-item>
      </v-list>

      <v-spacer></v-spacer>

      <v-card 
        color="rgba(255, 255, 255, 0.03)" 
        border="0" 
        class="pa-2 d-flex align-center mt-auto profile-card"
        style="cursor: pointer"
        @click="showLogoutDialog = true"
      >
        <v-avatar size="32" color="rgba(255, 255, 255, 0.1)" class="mr-2 avatar-soft">
          <v-icon color="white" size="small">mdi-account-outline</v-icon>
        </v-avatar>
        <div style="min-width: 0;">
          <div class="text-caption font-weight-bold text-white text-truncate">MDRRMO Admin</div>
          <div class="text-white-50 text-truncate" style="font-size: 0.65rem !important;">Echague Panel</div>
        </div>
        <v-spacer></v-spacer>
        <v-icon color="white-50" size="small" class="logout-icon">mdi-logout</v-icon>
      </v-card>
    </div>
  </v-navigation-drawer>

  <v-dialog v-model="showLogoutDialog" max-width="380" persistent>
    <v-card rounded="xl" elevation="10" class="pb-2">
      <v-card-title class="pa-6 pb-2 text-subtitle-1 font-weight-bold">Confirm Logout</v-card-title>
      <v-card-text class="px-6 py-2 text-body-2 text-grey-darken-1">
        Are you sure you want to log out of the SERBIS admin panel?
      </v-card-text>
      <v-card-actions class="pa-6 pt-4 d-flex justify-end" style="gap: 12px">
        <v-btn color="grey-darken-2" variant="text" class="px-4 text-none" @click="showLogoutDialog = false" :disabled="isLoggingOut">
          Cancel
        </v-btn>
        <v-btn color="error" variant="flat" class="px-5 text-none" @click="handleLogout" :loading="isLoggingOut">
          Logout
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<script setup lang="ts">
import { useAuth } from './index'
import { useAppTheme } from '@/composables/useAppTheme'

const { isLoggingOut, showLogoutDialog, handleLogout } = useAuth()
const { theme, toggle } = useAppTheme()

const mainMenu = [
  { to: '/', icon: 'mdi-view-dashboard-outline', title: 'Dashboard' },
  { to: '/manage-requests', icon: 'mdi-clipboard-text-outline', title: 'Service Requests' },
  { to: '/borrowings', icon: 'mdi-hand-extended-outline', title: 'Borrow Requests' },
  { to: '/vehicles', icon: 'mdi-ambulance', title: 'Fleet Management' },
  { to: '/inventory', icon: 'mdi-toolbox-outline', title: 'Equipment Inventory' },
  { to: '/sms', icon: 'mdi-message-text-fast-outline', title: 'Text Blast (SMS)' }
]

const systemMenu = [
  { to: '/services-config', icon: 'mdi-wrench-outline', title: 'Services' },
  { to: '/users', icon: 'mdi-account-group-outline', title: 'Users' },
  { to: '/files', icon: 'mdi-folder-outline', title: 'Files' },
  { to: '/logs', icon: 'mdi-history', title: 'Logs' }
]
</script>

<style scoped>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;900&display=swap');

.modern-drawer {
  font-family: 'Inter', sans-serif;
  background: radial-gradient(circle at -10% 50%, #154c41 0%, #0A2620 80%) !important;
}

.tracking-widest { letter-spacing: 0.1em; text-transform: uppercase; }
.text-white-50 { color: rgba(255, 255, 255, 0.5) !important; }

.logo-accent {
  width: 4px; height: 20px;
  background-color: #fff;
  margin-right: 12px; border-radius: 2px;
}

.nav-item, .nav-icon-avatar, .nav-label, .profile-card, .logout-icon, .avatar-soft {
  transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1) !important;
}

.nav-item:hover:not(.active-nav-item) {
  background-color: rgba(255, 255, 255, 0.04) !important;
  transform: translateX(4px);
}
.nav-item:hover:not(.active-nav-item) .nav-icon-avatar { background-color: rgba(255, 255, 255, 0.05) !important; }
.nav-item:hover:not(.active-nav-item) .nav-label,
.nav-item:hover:not(.active-nav-item) .v-icon { color: #fff !important; }

.active-nav-item {
  background: linear-gradient(90deg, rgba(255, 255, 255, 0.1) 0%, transparent 100%) !important;
}
.active-nav-item .nav-label, .active-nav-item .v-icon { color: #fff !important; font-weight: 700 !important; }
.active-nav-item .nav-icon-avatar { border: 1px solid rgba(255, 255, 255, 0.15); }

.profile-card { border-radius: 12px !important; border: 1px solid rgba(255, 255, 255, 0.02) !important; }
.profile-card:hover { background-color: rgba(255, 255, 255, 0.06) !important; border-color: rgba(255, 255, 255, 0.1) !important; transform: translateY(-2px); }
.profile-card:hover .logout-icon { color: #ef4444 !important; transform: translateX(2px); }
.profile-card:hover .avatar-soft { transform: scale(1.05); }
</style>