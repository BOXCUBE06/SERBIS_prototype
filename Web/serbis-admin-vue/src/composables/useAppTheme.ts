import { useTheme } from 'vuetify'

const STORAGE_KEY = 'serbis_theme'

export function useAppTheme() {
  const theme = useTheme()

  const init = () => {
    const saved = localStorage.getItem(STORAGE_KEY)
    if (saved === 'light' || saved === 'dark') {
      theme.change(saved)
    }
  }

  const toggle = () => {
    const next = theme.global.name.value === 'dark' ? 'light' : 'dark'
    theme.change(next)
    localStorage.setItem(STORAGE_KEY, next)
  }

  return { theme, init, toggle }
}
