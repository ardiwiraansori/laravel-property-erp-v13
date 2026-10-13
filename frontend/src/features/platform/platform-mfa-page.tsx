import axios from 'axios'
import { useCallback, useEffect, useState } from 'react'
import type { FormEvent } from 'react'
import { Navigate, useNavigate } from 'react-router'
import { KeyRound, ShieldCheck } from 'lucide-react'

import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { useAuth } from '@/features/auth/auth-state'
import { ForbiddenPage } from '@/pages/forbidden-page'
import {
  challengePlatformMfa, confirmPlatformMfa, getPlatformMfaStatus,
  recoverPlatformMfa, startPlatformMfaSetup,
} from '@/features/platform/platform-mfa-api'
import type { MfaStatus } from '@/features/platform/platform-mfa-api'

type Access = 'loading' | 'ready' | 'denied' | 'error'
type Setup = { manual_secret: string; otpauth_uri: string }

function messageOf(error: unknown) {
  if (axios.isAxiosError(error)) {
    if (error.response?.status === 429) return 'Too many attempts. Try again later.'
    const details = error.response?.data?.errors as Record<string, string[]> | undefined
    const first = details ? Object.values(details).flat()[0] : null
    return first ?? error.response?.data?.message ?? 'Request failed.'
  }
  return 'Unable to communicate with the server.'
}

export function PlatformMfaPage() {
  const navigate = useNavigate()
  const { user, checkingSession, logout } = useAuth()
  const [access, setAccess] = useState<Access>('loading')
  const [status, setStatus] = useState<MfaStatus | null>(null)
  const [revision, setRevision] = useState(0)
  const [pending, setPending] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [password, setPassword] = useState('')
  const [code, setCode] = useState('')
  const [recoveryCode, setRecoveryCode] = useState('')
  const [showRecovery, setShowRecovery] = useState(false)
  const [setup, setSetup] = useState<Setup | null>(null)
  const [savedCodes, setSavedCodes] = useState<string[] | null>(null)

  const reload = useCallback(() => {
    setAccess('loading'); setError(null); setRevision(value => value + 1)
  }, [])

  useEffect(() => {
    if (checkingSession || !user) return
    let live = true
    void getPlatformMfaStatus().then(next => {
      if (live) { setStatus(next); setAccess('ready') }
    }).catch((cause: unknown) => {
      if (!live) return
      if (axios.isAxiosError(cause) && cause.response?.status === 403) setAccess('denied')
      else if (axios.isAxiosError(cause) && cause.response?.status === 401) navigate('/login', { replace: true })
      else { setError(messageOf(cause)); setAccess('error') }
    })
    return () => { live = false }
  }, [checkingSession, user, revision, navigate])

  async function action(task: () => Promise<void>) {
    setPending(true); setError(null)
    try { await task() } catch (cause) { setError(messageOf(cause)) }
    finally { setPending(false); setCode('') }
  }

  function submitChallenge(event: FormEvent) {
    event.preventDefault()
    void action(async () => {
      await challengePlatformMfa(code)
      navigate('/platform', { replace: true })
    })
  }

  function submitSetup(event: FormEvent) {
    event.preventDefault()
    void action(async () => {
      const result = await startPlatformMfaSetup(password)
      setSetup(result); setPassword('')
    })
  }

  function submitConfirm(event: FormEvent) {
    event.preventDefault()
    void action(async () => {
      const result = await confirmPlatformMfa(code)
      setSavedCodes(result.recovery_codes)
      setSetup(null)
    })
  }

  function submitRecovery(event: FormEvent) {
    event.preventDefault()
    void action(async () => {
      await recoverPlatformMfa(password, recoveryCode)
      setPassword(''); setRecoveryCode(''); setShowRecovery(false)
      setSetup(null); setSavedCodes(null); reload()
    })
  }

  if (checkingSession || access === 'loading') {
    if (!checkingSession && !user) {
      return <Navigate to="/login" replace />
    }
    return <main className="flex min-h-screen items-center justify-center text-sm text-muted-foreground">Checking MFA access...</main>
  }
  if (!user) return null
  if (access === 'denied') return <ForbiddenPage />
  if (access === 'error') return <main className="flex min-h-screen flex-col items-center justify-center gap-3 p-5">
    <p role="alert" className="text-sm text-destructive">{error}</p>
    <Button variant="outline" onClick={reload}>Retry</Button>
  </main>

  return <main className="flex min-h-screen items-center justify-center bg-muted/30 p-4 py-12">
    <div className="w-full max-w-lg space-y-4">
      <div className="flex items-center gap-3">
        <div className="rounded-lg bg-primary p-2 text-primary-foreground"><ShieldCheck className="size-5" /></div>
        <div><h1 className="text-xl font-semibold">Platform MFA</h1>
          <p className="text-sm text-muted-foreground">Dedicated platform administrator verification</p></div>
      </div>
      <Card>
        <CardHeader>
          <CardTitle>{savedCodes ? 'Save recovery codes' : !status?.enrolled ? 'Enroll an authenticator' :
            showRecovery ? 'Recover your authenticator' : 'Verify your authenticator'}</CardTitle>
          <CardDescription>Platform accounts require a fresh second factor before reading the directory or changing settings.</CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          {error && <p role="alert" className="rounded-md border border-destructive/30 p-3 text-sm text-destructive">{error}</p>}
          {savedCodes ? <div className="space-y-3">
            <p className="text-sm font-medium">These one-time recovery codes are displayed only once. Store them offline in a secure place.</p>
            <div className="grid grid-cols-1 gap-1 rounded-md border bg-muted/40 p-3 font-mono text-xs sm:grid-cols-2">
              {savedCodes.map(item => <code key={item}>{item}</code>)}
            </div>
            <Button className="w-full" onClick={() => { setSavedCodes(null); navigate('/platform', { replace: true }) }}>
              I have saved my recovery codes
            </Button>
          </div> : status?.verified ? <div className="space-y-3">
            <p className="text-sm text-muted-foreground">Your platform MFA session is currently verified.</p>
            <Button className="w-full" onClick={() => navigate('/platform', { replace: true })}>Continue to platform</Button>
          </div> : !status?.enrolled ? <div className="space-y-4">
            {!setup ? <form className="space-y-3" onSubmit={submitSetup}>
              <p className="text-sm text-muted-foreground">Enter your account password to start enrollment. A new 30-second authenticator secret will be generated.</p>
              <Label htmlFor="mfa-setup-password">Account password</Label>
              <Input id="mfa-setup-password" type="password" autoComplete="current-password" value={password}
                onChange={event => setPassword(event.target.value)} required />
              <Button type="submit" disabled={pending} className="w-full">{pending ? 'Working...' : 'Start secure setup'}</Button>
            </form> : <div className="space-y-4">
              <p className="text-sm">In your authenticator app, add a time-based account using this secret. Never share it.</p>
              <div className="break-all rounded-md border bg-muted/40 p-3 font-mono text-sm select-all">{setup.manual_secret}</div>
              <a className="text-sm underline underline-offset-4" href={setup.otpauth_uri}>Open authenticator app (if supported)</a>
              <p className="text-xs text-muted-foreground">This enrollment must be confirmed within five minutes. If it expires, start setup again.</p>
              <form className="space-y-3" onSubmit={submitConfirm}>
                <Label htmlFor="mfa-confirm-code">6-digit authenticator code</Label>
                <Input id="mfa-confirm-code" inputMode="numeric" autoComplete="one-time-code" pattern="[0-9]{6}" maxLength={6}
                  value={code} onChange={event => setCode(event.target.value)} required />
                <Button disabled={pending} type="submit" className="w-full">Verify and enroll</Button>
              </form>
            </div>}
          </div> : showRecovery ? <form className="space-y-3" onSubmit={submitRecovery}>
            <p className="text-sm text-muted-foreground">A valid recovery code plus your password resets MFA. You must enroll again; this does not grant access to platform data.</p>
            <Label htmlFor="mfa-recovery-password">Account password</Label>
            <Input id="mfa-recovery-password" type="password" autoComplete="current-password" value={password}
              onChange={event => setPassword(event.target.value)} required />
            <Label htmlFor="mfa-recovery-code">Unused recovery code</Label>
            <Input id="mfa-recovery-code" value={recoveryCode} onChange={event => setRecoveryCode(event.target.value)}
              autoComplete="off" placeholder="XXXX-XXXX-XXXX-XXXX-XXXX-XXXX-XXXX-XXXX" required />
            <Button className="w-full" type="submit" disabled={pending}>Reset MFA and re-enroll</Button>
            <Button variant="outline" type="button" onClick={() => setShowRecovery(false)}>Back to authenticator</Button>
          </form> : <div className="space-y-3">
            <form className="space-y-3" onSubmit={submitChallenge}>
              <Label htmlFor="mfa-challenge-code">6-digit authenticator code</Label>
              <Input id="mfa-challenge-code" inputMode="numeric" autoComplete="one-time-code" pattern="[0-9]{6}"
                maxLength={6} value={code} onChange={event => setCode(event.target.value)} required />
              <Button type="submit" disabled={pending} className="w-full"><KeyRound className="size-4" /> Verify</Button>
            </form>
            <Button variant="link" size="sm" onClick={() => setShowRecovery(true)}>Lost access to your authenticator?</Button>
            <p className="text-xs text-muted-foreground">MFA verification expires after ten minutes. Codes cannot be reused, even during the same 30-second window.</p>
          </div>}
        </CardContent>
      </Card>
      <Button variant="outline" className="w-full" onClick={() => { void logout().then(() => navigate('/login', { replace: true })) }}>
        Sign out
      </Button>
    </div>
  </main>
}
