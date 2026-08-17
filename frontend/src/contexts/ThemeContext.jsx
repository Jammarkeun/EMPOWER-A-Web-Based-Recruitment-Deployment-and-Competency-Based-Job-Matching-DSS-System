import * as React from 'react'

const ThemeContext = React.createContext(null)
const STORAGE_KEY = 'empower.theme'

/**
 * Light, dark, or follow the operating system.
 *
 * Three states rather than two. "System" is the default because most people have
 * already told their machine which they prefer, and a system that ignores that
 * feels careless — but an explicit choice must still win, since a shared office
 * machine is often set differently from the person using it.
 */
export function ThemeProvider({ children }) {
  const [preference, setPreference] = React.useState(
    () => localStorage.getItem(STORAGE_KEY) ?? 'system'
  )

  const apply = React.useCallback((value) => {
    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches
    const shouldBeDark = value === 'dark' || (value === 'system' && prefersDark)

    document.documentElement.classList.toggle('dark', shouldBeDark)
    // Lets the browser paint form controls and scrollbars to match, which
    // otherwise stay stubbornly light against a dark page.
    document.documentElement.style.colorScheme = shouldBeDark ? 'dark' : 'light'
  }, [])

  React.useEffect(() => {
    apply(preference)

    if (preference !== 'system') return

    // Follow the OS while set to "system", so the page changes with it rather
    // than only at the next reload.
    const query = window.matchMedia('(prefers-color-scheme: dark)')
    const onChange = () => apply('system')
    query.addEventListener('change', onChange)
    return () => query.removeEventListener('change', onChange)
  }, [preference, apply])

  const setTheme = React.useCallback((value) => {
    localStorage.setItem(STORAGE_KEY, value)
    setPreference(value)
  }, [])

  const value = React.useMemo(
    () => ({
      theme: preference,
      setTheme,
      // Convenience for the toggle button: cycles light -> dark -> system.
      cycle: () =>
        setTheme(preference === 'light' ? 'dark' : preference === 'dark' ? 'system' : 'light'),
    }),
    [preference, setTheme]
  )

  return <ThemeContext.Provider value={value}>{children}</ThemeContext.Provider>
}

export function useTheme() {
  const context = React.useContext(ThemeContext)
  if (!context) throw new Error('useTheme must be used within a ThemeProvider')
  return context
}
