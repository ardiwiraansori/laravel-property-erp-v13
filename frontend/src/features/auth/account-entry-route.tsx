import axios from 'axios'
import { useEffect, useState } from 'react'
import { Navigate, useLocation } from 'react-router'

import { Button } from '@/components/ui/button'
import { useAuth } from '@/features/auth/auth-state'
import { api } from '@/lib/api'

type Result = 'loading' | 'platform' | 'mfa' | 'tenant' | 'unauthenticated' | 'error'

function safeReturnPath(value: unknown): string | null {
  if (typeof value !== 'string' || !value.startsWith('/') || value.startsWith('//') ||
    value.includes('\\') || /[\r\n]/.test(value)) return null
  if (value === '/login' || value === '/entry' || value.startsWith('/entry?')) return null
  return value
}

export function AccountEntryRoute() {
  const { user, checkingSession } = useAuth()
  const location = useLocation()
  const [result, setResult] = useState<Result>('loading')
  const [revision, setRevision] = useState(0)
  const from = safeReturnPath((location.state as { from?: unknown } | null)?.from)

  useEffect(() => {
    if (checkingSession || !user) return
    let active = true

    // A failed network call is not evidence of tenant access.
    void api.get<{ data: { platform_access: boolean } }>('/api/platform/me')
      .then(response => {
        if (active) setResult(response.data.data.platform_access === true ? 'platform' : 'error')
      })
      .catch((error: unknown) => {
        if (!active) return
        if (axios.isAxiosError(error) && error.response?.status === 428) {
          setResult('mfa')
        } else if (axios.isAxiosError(error) && error.response?.status === 403) {
          setResult('tenant')
        } else if (axios.isAxiosError(error) && error.response?.status === 401) {
          setResult('unauthenticated')
        } else {
          setResult('error')
        }
      })
    return () => { active = false }
  }, [checkingSession, user, revision])

  if (checkingSession || result === 'loading') {
    if (!checkingSession && !user) return <Navigate to="/login" replace />
    return <main className="flex min-h-screen items-center justify-center text-sm text-muted-foreground">Checking account access...</main>
  }
  if (!user || result === 'unauthenticated') return <Navigate to="/login" replace />
  if (result === 'mfa') return <Navigate to="/platform/mfa" replace />
  if (result === 'platform') {
    return <Navigate to={from?.startsWith('/platform') ? from : '/platform'} replace />
  }
  if (result === 'tenant') {
    return <Navigate to={from && !from.startsWith('/platform') ? from : '/dashboard'} replace />
  }

  return (
    <main className="flex min-h-screen flex-col items-center justify-center gap-4 p-4 text-center">
      <p role="alert" className="text-sm text-destructive">Unable to verify account access.</p>
      <Button variant="outline" onClick={() => { setResult('loading'); setRevision(value => value + 1) }}>Try again</Button>
    </main>
  )
}
