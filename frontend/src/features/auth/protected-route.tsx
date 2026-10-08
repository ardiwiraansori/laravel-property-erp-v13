import { Navigate, Outlet, useLocation } from 'react-router'

import { useAuth } from '@/features/auth/auth-state'
import { useCompany } from '@/features/company/company-state'
import { Button } from '@/components/ui/button'

export function ProtectedRoute() {
  const { user, checkingSession } = useAuth()
  const location = useLocation()
  const { activeCompany, companies, loading, error, retry } = useCompany()

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

  if (loading || (!activeCompany && !error && companies.length > 0)) {
    return <main className="flex min-h-screen items-center justify-center text-sm text-muted-foreground">Loading company access...</main>
  }
  if (error) {
    return <main className="flex min-h-screen flex-col items-center justify-center gap-4 p-4 text-center">
      <p role="alert" className="text-sm text-destructive">{error}</p>
      <Button variant="outline" onClick={retry}>Retry company access</Button>
    </main>
  }
  if (!activeCompany) {
    return <main className="flex min-h-screen items-center justify-center p-4 text-center text-sm text-muted-foreground">
      Your account does not have an active company membership.
    </main>
  }
  return <Outlet />
}