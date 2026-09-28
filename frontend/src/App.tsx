import axios from 'axios'
import { useEffect, useState } from 'react'
import type { FormEvent } from 'react'

import { Button } from '@/components/ui/button'
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { DashboardPage } from '@/components/dashboard/dashboard-page'
import { DashboardLayout } from '@/components/layout/dashboard-layout'
import { api } from '@/lib/api'

type User = {
  id: number
  name: string
  email: string
}

function getErrorMessage(error: unknown): string {
  if (axios.isAxiosError(error)) {
    return (
      error.response?.data?.message ?? 'Unable to communicate with the server.'
    )
  }

  return 'An unexpected error occurred.'
}

function App() {
  const [user, setUser] = useState<User | null>(null)
  const [checkingSession, setCheckingSession] = useState(true)
  const [submitting, setSubmitting] = useState(false)

  const [email, setEmail] = useState('ardi@example.com')
  const [password, setPassword] = useState('')
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    async function loadAuthenticatedUser() {
      try {
        const response = await api.get<User>('/api/user')
        setUser(response.data)
      } catch (error) {
        if (!axios.isAxiosError(error) || error.response?.status !== 401) {
          setError(getErrorMessage(error))
        }
      } finally {
        setCheckingSession(false)
      }
    }

    void loadAuthenticatedUser()
  }, [])

  async function handleLogin(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()

    setSubmitting(true)
    setError(null)

    try {
      await api.get('/sanctum/csrf-cookie')

      await api.post('/api/login', {
        email,
        password,
      })

      const response = await api.get<User>('/api/user')

      setUser(response.data)
      setPassword('')
    } catch (error) {
      setError(getErrorMessage(error))
    } finally {
      setSubmitting(false)
    }
  }

  async function handleLogout() {
    setSubmitting(true)
    setError(null)

    try {
      await api.post('/api/logout')

      setUser(null)
      setPassword('')
    } catch (error) {
      setError(getErrorMessage(error))
    } finally {
      setSubmitting(false)
    }
  }

  if (checkingSession) {
    return (
      <main className="flex min-h-screen items-center justify-center bg-muted/30">
        <p className="text-sm text-muted-foreground">
          Checking authentication...
        </p>
      </main>
    )
  }

  if (user) {
    return (
      <DashboardLayout
        user={user}
        loggingOut={submitting}
        onLogout={() => void handleLogout()}
      >
        <DashboardPage user={user} />
      </DashboardLayout>
    )
  }

  return (
    <main className="relative flex min-h-screen items-center justify-center overflow-hidden bg-muted/30 px-4 py-10">
      <div className="pointer-events-none absolute inset-0 bg-[radial-gradient(circle_at_top_left,rgba(0,0,0,0.05),transparent_30%),radial-gradient(circle_at_bottom_right,rgba(0,0,0,0.04),transparent_35%)]" />

      <div className="relative w-full max-w-md">
        <div className="mb-8 text-center">
          <div className="mx-auto mb-4 flex size-12 items-center justify-center rounded-xl bg-primary text-lg font-semibold text-primary-foreground shadow-sm">
            PE
          </div>

          <h1 className="text-2xl font-semibold tracking-tight">
            Property ERP
          </h1>

          <p className="mt-2 text-sm text-muted-foreground">
            Property Management & Enterprise Resource Planning
          </p>
        </div>

        <Card className="shadow-sm">
          <CardHeader>
            <CardTitle>Sign in</CardTitle>
            <CardDescription>
              Enter your account credentials to continue.
            </CardDescription>
          </CardHeader>

          <CardContent>
            <form onSubmit={handleLogin} className="space-y-5">
              {error && (
                <div
                  role="alert"
                  className="rounded-md border border-destructive/30 bg-destructive/10 px-3 py-2 text-sm text-destructive"
                >
                  {error}
                </div>
              )}

              <div className="space-y-2">
                <Label htmlFor="email">Email</Label>

                <Input
                  id="email"
                  type="email"
                  autoComplete="email"
                  value={email}
                  onChange={(event) => setEmail(event.target.value)}
                  placeholder="name@company.com"
                  required
                />
              </div>

              <div className="space-y-2">
                <Label htmlFor="password">Password</Label>

                <Input
                  id="password"
                  type="password"
                  autoComplete="current-password"
                  value={password}
                  onChange={(event) => setPassword(event.target.value)}
                  placeholder="Enter your password"
                  required
                />
              </div>

              <Button type="submit" className="w-full" disabled={submitting}>
                {submitting ? 'Signing in...' : 'Sign in'}
              </Button>
            </form>
          </CardContent>
        </Card>

        <p className="mt-6 text-center text-xs text-muted-foreground">
          Property ERP Portfolio · Laravel 13 · React · TypeScript
        </p>
      </div>
    </main>
  )
}

export default App
