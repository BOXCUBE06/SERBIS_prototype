<template>
  <v-navigation-drawer
    v-model="isOpen"
    :permanent="!mobile"
    :temporary="mobile"
    width="260"
    class="modern-drawer"
  >
    <div class="d-flex flex-column h-100 sidebar-shell">

      <div class="sidebar-header d-flex align-center justify-space-between mb-2 mt-2 px-4">
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

      <div ref="navScrollEl" class="nav-scroll px-4">
        <div class="text-caption font-weight-medium text-white-50 mb-1 px-2 tracking-widest">Main Menu</div>
        <v-list bg-color="transparent" density="compact" nav class="px-0">
          <v-list-item
            v-for="item in mainMenu"
            :key="item.to"
            :to="item.to"
            class="nav-item"
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

        <div class="text-caption font-weight-medium text-white-50 mt-3 mb-1 px-2 tracking-widest">System</div>
        <v-list bg-color="transparent" density="compact" nav class="px-0">
          <v-list-item
            v-for="item in systemMenu"
            :key="item.to"
            :to="item.to"
            class="nav-item"
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
      </div>

      <div class="sidebar-footer px-4 pb-4">
        <v-card
          color="rgba(255, 255, 255, 0.03)"
          border="0"
          class="pa-2 d-flex align-center profile-card"
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
    </div>
  </v-navigation-drawer>

  <!-- A confirmation holds nothing typed, so Esc and a click outside are both
       valid answers to it. -->
  <v-dialog v-model="showLogoutDialog" max-width="380">
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
import { nextTick, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useDisplay } from 'vuetify'
import { useAuth } from './index'
import { useAppTheme } from '@/composables/useAppTheme'

const { isLoggingOut, showLogoutDialog, handleLogout } = useAuth()
const { theme, toggle } = useAppTheme()

// Both sections share one scroll region (see the style block below) so a
// short viewport clips the end of the list instead of a section going
// unreachable — see the P0 finding in the 2026-09-15 sidebar audit.
const navScrollEl = ref<HTMLElement | null>(null)
const route = useRoute()
const router = useRouter()

function scrollActiveIntoView() {
  navScrollEl.value?.querySelector('.active-nav-item')?.scrollIntoView({ block: 'nearest' })
}

onMounted(() => {
  router.isReady().then(() => nextTick(scrollActiveIntoView))
})
watch(() => route.path, () => nextTick(scrollActiveIntoView))

// permanent forces the drawer to render at full width regardless of
// model-value, which is what made every page below Vuetify's own mobile
// breakpoint unusable — the drawer never yielded the screen, and
// App.vue's .outer-wrapper margin-left:260px pushed everything else into a
// sliver beside it (impeccable critique, P0, 2026-08-30). Below the
// breakpoint this becomes a real temporary drawer instead: closed by
// default, opened by the hamburger button App.vue renders only on mobile.
const { mobile } = useDisplay()
const isOpen = defineModel<boolean>('open', { default: true })

const mainMenu = [
  { to: '/', icon: 'mdi-view-dashboard-outline', title: 'Dashboard' },
  // Directly after Dashboard: the two are read together, one for today and
  // one for the quarter.
  { to: '/analytics', icon: 'mdi-chart-box-outline', title: 'Analytics' },
  { to: '/manage-requests', icon: 'mdi-clipboard-text-outline', title: 'Resident Requests' },
  { to: '/conduction-requests', icon: 'mdi-ambulance', title: 'Ambulance Dispatch Requests' },
  { to: '/borrowings', icon: 'mdi-hand-extended-outline', title: 'Equipment Borrowing' },
  { to: '/vehicles', icon: 'mdi-ambulance', title: 'Vehicles' },
  { to: '/inventory', icon: 'mdi-toolbox-outline', title: 'Resource Management' },
  // Below Resource Management on purpose: it is the list of what the catalogue
  // above does not carry, and it is read next to it, not next to the board.
  { to: '/procurement', icon: 'mdi-clipboard-list-outline', title: 'Procurement Reference' },
  { to: '/sms', icon: 'mdi-message-text-fast-outline', title: 'Text Blast (SMS)' }
]

const systemMenu = [
  { to: '/services-config', icon: 'mdi-wrench-outline', title: 'Manage Services' },
  { to: '/users', icon: 'mdi-account-group-outline', title: 'Residents' },
  { to: '/staff', icon: 'mdi-shield-account-outline', title: 'Staff Accounts' },
  { to: '/files', icon: 'mdi-folder-outline', title: 'Documents' },
  { to: '/logs', icon: 'mdi-history', title: 'Activity Logs' }
]
</script>

<style scoped>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;900&display=swap');

/* .modern-drawer's background lives in App.vue now -- it was a byte-for-byte
   duplicate here (this file is scoped, App.vue's copy is global and already
   matched this element by class name regardless). One definition, theme-aware. */

.tracking-widest { letter-spacing: 0.1em; text-transform: uppercase; }
.text-white-50 { color: rgba(255, 255, 255, 0.5) !important; }

/* One scroll region between a pinned header and a pinned footer, instead of
   two independently-clippable v-lists — see the P0 sidebar audit finding
   (2026-09-15). Vuetify forces `.v-navigation-drawer .v-list{overflow:hidden}`
   (VNavigationDrawer.css), which gives a v-list an automatic flex min-height
   of 0; two such lists as flex-column siblings meant the shorter one
   (System) could be shrunk to zero by the flex algorithm while Main Menu
   kept most of the space. Wrapping both lists in one flex:1 region with its
   own overflow-y fixes that: there's exactly one thing left that can shrink. */
.sidebar-header, .sidebar-footer {
  flex: none;
}
.nav-scroll {
  flex: 1 1 auto;
  min-height: 0;
  overflow-y: auto;
  overscroll-behavior: contain;
  scrollbar-width: thin;
  scrollbar-color: rgba(255, 255, 255, 0.15) transparent;
}
.nav-scroll::-webkit-scrollbar {
  width: 6px;
}
.nav-scroll::-webkit-scrollbar-thumb {
  background: rgba(255, 255, 255, 0.15);
  border-radius: 3px;
}

/* Tightened from the default compact item height so more of the 13 items
   fit before .nav-scroll needs to scroll at all — same value in both
   sections since they share this rule. */
.nav-scroll :deep(.v-list-item) {
  min-height: 38px;
  margin-bottom: 2px;
}

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