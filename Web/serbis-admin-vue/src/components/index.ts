import { ref } from 'vue'
import { useRouter } from 'vue-router'

export function useAuth() {
  const router = useRouter()
  const isLoggingOut = ref(false)
  const showLogoutDialog = ref(false)

  const handleLogout = async () => {
    isLoggingOut.value = true
    const token = localStorage.getItem('serbis_token')

    try {
      await fetch('http://localhost:8000/api/logout', {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Accept': 'application/json'
        }
      })
    } catch (error) {
      console.error('Backend logout failed:', error)
    } finally {
      localStorage.removeItem('serbis_token')
      
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