import { createContext, useContext } from 'react'

export type AuthUser = {
  id: number
  name: string
  email: string
  roles: string[]
  permissions: string[]
}

export type LoginCredentials = {
  email: string
  password: string
}

export type AuthContextValue = {
  user: AuthUser | null
  checkingSession: boolean
  submitting: boolean
  error: string | null
  login: (credentials: LoginCredentials) => Promise<boolean>
  logout: () => Promise<void>
}

export const AuthContext = createContext<AuthContextValue | null>(null)

export function useAuth() {
  const context = useContext(AuthContext)

  if (!context) {
    throw new Error('useAuth must be used within an AuthProvider.')
  }

  return context
}
