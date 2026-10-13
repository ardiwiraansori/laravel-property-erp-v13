import axios from 'axios'
import { useEffect, useState } from 'react'
import { Navigate, Outlet, useLocation } from 'react-router'

import { Button } from '@/components/ui/button'
import { useAuth } from '@/features/auth/auth-state'
import { api } from '@/lib/api'
import { ForbiddenPage } from '@/pages/forbidden-page'

type Access = 'loading' | 'allowed' | 'denied' | 'unauthenticated' | 'error'

export function PlatformRoute() {
  const { user, checkingSession } = useAuth()
  const location = useLocation()
  const [access, setAccess] = useState<Access>('loading')
  const [revision, setRevision] = useState(0)

  useEffect(() => {
    if (checkingSession || !user) return
    let active = true

    void api.get<{ data: { platform_access: boolean } }>('/api/platform/me')
      .then(response => {
        if (active) setAccess(response.data.data.platform_access === true ? 'allowed' : 'error')
      })
      .catch((error: unknown) => {
        if (!active) return
        if (axios.isAxiosError(error) && error.response?.status === 403) {
          setAccess('denied')
        } else if (axios.isAxiosError(error) && error.response?.status === 401) {
          setAccess('unauthenticated')
        } else {
          setAccess('error')
        }
      })
    return () => { active = false }
  }, [checkingSession, user, revision])

  if (checkingSession || access === 'loading') {
    if (!checkingSession && !user) return <Navigate to="/login" replace state={{ from: location.pathname }} />
    return <main className="flex min-h-screen items-center justify-center text-sm text-muted-foreground">Verifying platform access...</main>
  }
  if (!user || access === 'unauthenticated') {
    return <Navigate to="/login" replace state={{ from: location.pathname }} />
  }
  if (access === 'denied') return <ForbiddenPage />
  if (access === 'error') {
    return <main className="flex min-h-screen flex-col items-center justify-center gap-4 p-4 text-center">
      <p role="alert" className="text-sm text-destructive">Unable to verify platform access.</p>
      <Button variant="outline" onClick={() => { setAccess('loading'); setRevision(value => value + 1) }}>Try again</Button>
    </main>
  }
  return <Outlet />
}
