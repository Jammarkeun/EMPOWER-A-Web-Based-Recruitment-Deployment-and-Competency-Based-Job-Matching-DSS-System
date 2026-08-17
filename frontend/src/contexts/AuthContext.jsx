import * as React from 'react'
import { get, post, tokenStore } from '@/lib/api'

const AuthContext = React.createContext(null)

/*
 * Nothing here raises a toast, deliberately.
 *
 * This context is the transport for signing in and out; it does not know
 * whether it was called from the staff login form, the applicant registration
 * page, or a session being restored silently on page load. The screen that
 * triggered the action knows what the user was trying to do and reports the
 * outcome itself, which is why login failures read "Could not sign in" rather
 * than something generic about a request.
 */

export function AuthProvider({ children }) {
  const [user, setUser] = React.useState(null)
  const [loading, setLoading] = React.useState(true)

  // Restore the session on load. A stored token may have been revoked server
  // side, so it is verified rather than trusted.
  React.useEffect(() => {
    let cancelled = false

    async function restore() {
      if (!tokenStore.get()) {
        setLoading(false)
        return
      }

      try {
        const response = await get('/auth/me')
        if (!cancelled) setUser(response.data)
      } catch {
        tokenStore.clear()
      } finally {
        if (!cancelled) setLoading(false)
      }
    }

    restore()
    return () => {
      cancelled = true
    }
  }, [])

  const login = React.useCallback(async (email, password) => {
    const response = await post('/auth/login', { email, password })
    tokenStore.set(response.data.token)
    setUser(response.data.user)
    return response.data.user
  }, [])

  /**
   * Applicant self-registration.
   *
   * The API returns a token along with the new account, so the applicant is
   * signed in immediately rather than being asked for the password they just
   * chose. Returns the full payload because the caller also needs the reference
   * number to show them.
   */
  const register = React.useCallback(async (payload) => {
    const response = await post('/register', payload)
    tokenStore.set(response.data.token)
    setUser(response.data.user)
    return response.data
  }, [])

  const logout = React.useCallback(async () => {
    try {
      await post('/auth/logout')
    } catch {
      // The local session is cleared regardless: if the token is already
      // invalid, the user should still end up logged out.
    } finally {
      tokenStore.clear()
      setUser(null)
    }
  }, [])

  /**
   * Permission check used to hide actions the user cannot perform.
   *
   * This is a courtesy, not a control. The API enforces every one of these
   * independently, so hiding a button never stands in for authorisation.
   */
  const can = React.useCallback(
    (permission) => {
      if (!user?.permissions) return false
      return user.permissions.includes(permission)
    },
    [user]
  )

  const hasRole = React.useCallback((role) => user?.roles?.includes(role) ?? false, [user])

  const value = React.useMemo(
    () => ({ user, loading, login, register, logout, can, hasRole, isAuthenticated: !!user }),
    [user, loading, login, register, logout, can, hasRole]
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

export function useAuth() {
  const context = React.useContext(AuthContext)
  if (!context) throw new Error('useAuth must be used within an AuthProvider')
  return context
}
