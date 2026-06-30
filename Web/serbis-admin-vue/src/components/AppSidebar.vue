<template>
  <v-navigation-drawer
    color="#113F36"
    theme="dark"
    permanent
    width="280"
    class="border-0"
  >
    <div class="pa-4 d-flex flex-column h-100">
      
      <div class="d-flex align-center mb-4 mt-2 px-2">
        <span class="text-h4 font-weight-black text-white" style="letter-spacing: 2px;">SERBIS</span>
      </div>

      <v-divider class="mb-4"></v-divider>

      <div class="text-caption font-weight-bold text-grey-lighten-1 mb-2 px-2 text-uppercase">Main Menu</div>
      <v-list bg-color="transparent" density="compact" nav class="px-0">
        <v-list-item
          to="/"
          prepend-icon="mdi-view-dashboard-outline"
          title="Dashboard"
          active-color="white"
          class="rounded-lg mb-1 text-grey-lighten-1"
        ></v-list-item>

        <v-list-item to="/manage-requests" prepend-icon="mdi-clipboard-text-outline" title="Service Requests" class="rounded-lg mb-1 text-grey-lighten-1"></v-list-item>
        <v-list-item to="/borrowings" prepend-icon="mdi-hand-extended-outline" title="Borrow Requests" class="rounded-lg mb-1 text-grey-lighten-1"></v-list-item>
        <v-list-item to="/vehicles" prepend-icon="mdi-ambulance" title="Fleet Management" class="rounded-lg mb-1 text-grey-lighten-1"></v-list-item>
        <v-list-item to="/inventory" prepend-icon="mdi-toolbox-outline" title="Equipment Inventory" class="rounded-lg mb-1 text-grey-lighten-1"></v-list-item>
        <v-list-item to="/sms" prepend-icon="mdi-message-text-fast-outline" title="Text Blast (SMS)" class="rounded-lg mb-1 text-grey-lighten-1"></v-list-item>
      </v-list>

      <div class="text-caption font-weight-bold text-grey-lighten-1 mt-4 mb-2 px-2 text-uppercase">System</div>
      <v-list bg-color="transparent" density="compact" nav class="px-0">
        <v-list-item to="/services-config" prepend-icon="mdi-wrench-outline" title="Services" class="rounded-lg mb-1 text-grey-lighten-1"></v-list-item>
        <v-list-item to="/users" prepend-icon="mdi-account-group-outline" title="Users" class="rounded-lg mb-1 text-grey-lighten-1"></v-list-item>
        <v-list-item to="/files" prepend-icon="mdi-folder-outline" title="Files" class="rounded-lg mb-1 text-grey-lighten-1"></v-list-item>
        <v-list-item to="/logs" prepend-icon="mdi-history" title="Logs" class="rounded-lg mb-1 text-grey-lighten-1"></v-list-item>
      </v-list>

      <v-spacer></v-spacer>

      <v-card 
        color="rgba(255, 255, 255, 0.05)" 
        border 
        rounded="xl" 
        elevation="0" 
        class="pa-2 d-flex align-center mt-auto cursor-pointer profile-logout-card"
        @click="showLogoutDialog = true"
      >
        <v-avatar size="36" color="grey-darken-3" class="mr-3">
          <v-icon color="white">mdi-account-circle-outline</v-icon>
        </v-avatar>
        <div>
          <div class="text-body-2 font-weight-bold text-white">MDRRMO Admin</div>
          <div class="text-caption text-grey-lighten-1">Echague Panel</div>
        </div>
        <v-spacer></v-spacer>
        <v-icon color="error" size="small" class="mr-2">mdi-logout-variant</v-icon>
      </v-card>
    </div>
  </v-navigation-drawer>

  <v-dialog v-model="showLogoutDialog" max-width="400" persistent>
    <v-card rounded="xl" elevation="10">
      <v-card-title class="pa-6 pb-2 text-h6 font-weight-bold text-grey-darken-3">
        Confirm Logout
      </v-card-title>
      
      <v-card-text class="pa-6 pt-2 text-body-1 text-grey-darken-1">
        Are you sure you want to log out of the SERBIS admin panel?
      </v-card-text>
      
      <v-card-actions class="pa-6 pt-0 d-flex justify-end gap-2">
        <v-btn 
          color="grey-darken-1" 
          variant="text" 
          rounded="lg" 
          class="px-4 text-none font-weight-bold" 
          @click="showLogoutDialog = false" 
          :disabled="isLoggingOut"
        >
          Cancel
        </v-btn>
        <v-btn 
          color="error" 
          variant="flat" 
          rounded="lg" 
          class="px-6 text-none font-weight-bold" 
          @click="handleLogout" 
          :loading="isLoggingOut"
        >
          Logout
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<script setup lang="ts">
import { useAuth } from './index'

const { isLoggingOut, showLogoutDialog, handleLogout } = useAuth()
</script>

<style scoped>
.gap-2 { gap: 8px; }

.v-list-item--active {
  background-color: rgba(255, 255, 255, 0.1) !important;
  color: white !important;
}

.cursor-pointer {
  cursor: pointer;
}

.profile-logout-card:hover {
  background-color: rgba(255, 255, 255, 0.1) !important;
  transition: background-color 0.2s ease-in-out;
}
</style>