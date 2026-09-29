import axios from 'axios'
import {
  useCallback,
  useEffect,
  useState,
} from 'react'
import type { ReactNode } from 'react'

import {
  AuthContext,
  type AuthUser,
  type LoginCredentials,
} from '@/features/auth/auth-state'
import { api } from '@/lib/api'

function getErrorMessage(error: unknown): string {
  if (axios.isAxiosError(error)) {
    return (
      error.response?.data?.message ?? 'Unable to communicate with the server.'
    )
  }

  return 'An unexpected error occurred.'
}

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<AuthUser | null>(null)
  const [checkingSession, setCheckingSession] = useState(true)
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    let active = true

    async function loadAuthenticatedUser() {
      try {
        const response = await api.get<AuthUser>('/api/user')

        if (active) {
          setUser(response.data)
        }
      } catch (error) {
        const isUnauthenticated =
          axios.isAxiosError(error) && error.response?.status === 401

        if (active && !isUnauthenticated) {
          setError(getErrorMessage(error))
        }
      } finally {
        if (active) {
          setCheckingSession(false)
        }
      }
    }

    void loadAuthenticatedUser()

    return () => {
      active = false
    }
  }, [])

  const login = useCallback(
    async (credentials: LoginCredentials): Promise<boolean> => {
      setSubmitting(true)
      setError(null)

      try {
        await api.get('/sanctum/csrf-cookie')

        await api.post('/api/login', credentials)

        const response = await api.get<AuthUser>('/api/user')

        setUser(response.data)

        return true
      } catch (error) {
        setError(getErrorMessage(error))

        return false
      } finally {
        setSubmitting(false)
      }
    },
    [],
  )

  const logout = useCallback(async () => {
    setSubmitting(true)
    setError(null)

    try {
      await api.post('/api/logout')

      setUser(null)
    } catch (error) {
      setError(getErrorMessage(error))
    } finally {
      setSubmitting(false)
    }
  }, [])

  return (
    <AuthContext.Provider
      value={{
        user,
        checkingSession,
        submitting,
        error,
        login,
        logout,
      }}
    >
      {children}
    </AuthContext.Provider>
  )
}