import { Navigate, Outlet, useLocation } from 'react-router'

import { useAuth } from '@/features/auth/auth-state'

export function ProtectedRoute() {
  const { user, checkingSession } = useAuth()
  const location = useLocation()

  if (checkingSession) {
    return (
      <main className="flex min-h-screen items-center justify-center bg-muted/30">
        <p className="text-sm text-muted-foreground">
          Checking authentication...
        </p>
      </main>
    )
  }

  if (!user) {
    return (
      <Navigate
        to="/login"
        replace
        state={{
          from: `${location.pathname}${location.search}`,
        }}
      />
    )
  }

  return <Outlet />
}