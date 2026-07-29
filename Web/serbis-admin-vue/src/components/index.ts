import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { getToken, clearToken } from '../composables/authToken'
import { API_BASE } from '../config/api'

export function useAuth() {
  const router = useRouter()
  const isLoggingOut = ref(false)
  const showLogoutDialog = ref(false)

  const handleLogout = async () => {
    isLoggingOut.value = true
    const token = getToken()

    try {
      await fetch(`${API_BASE}/logout`, {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Accept': 'application/json'
        }
      })
    } catch (error) {
      console.error('Backend logout failed:', error)
    } finally {
      // clearToken() removes the expiry stamp too — removing only the token
      // would leave a stale timestamp behind for the next sign-in to inherit.
      clearToken()

      // Fixed to match your router's path mapping
      router.push('/login') 
      
      isLoggingOut.value = false
      showLogoutDialog.value = false
    }
  }

  return {
    isLoggingOut,
    showLogoutDialog,
    handleLogout
  }
}