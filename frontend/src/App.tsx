import axios from 'axios'
import { useEffect, useState } from 'react'
import type { FormEvent } from 'react'
import { api } from './lib/api'

type User = {
  id: number
  name: string
  email: string
}

function getErrorMessage(error: unknown): string {
  if (axios.isAxiosError(error)) {
    return (
      error.response?.data?.message ??
      'Unable to communicate with the server.'
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
        if (
          !axios.isAxiosError(error) ||
          error.response?.status !== 401
        ) {
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
      <main style={{ padding: '48px', fontFamily: 'system-ui' }}>
        <p>Checking authentication...</p>
      </main>
    )
  }

  return (
    <main
      style={{
        maxWidth: '480px',
        margin: '80px auto',
        padding: '0 24px',
        fontFamily: 'system-ui, sans-serif',
      }}
    >
      <h1>Property ERP</h1>
      <p>Laravel Sanctum + React TypeScript</p>

      {error && (
        <div
          role="alert"
          style={{
            padding: '12px',
            marginBottom: '20px',
            border: '1px solid currentColor',
          }}
        >
          {error}
        </div>
      )}

      {user ? (
        <section>
          <h2>Authenticated</h2>

          <p>
            <strong>Name:</strong> {user.name}
          </p>

          <p>
            <strong>Email:</strong> {user.email}
          </p>

          <button
            type="button"
            onClick={() => void handleLogout()}
            disabled={submitting}
          >
            {submitting ? 'Logging out...' : 'Logout'}
          </button>
        </section>
      ) : (
        <form onSubmit={handleLogin}>
          <h2>Login</h2>

          <div style={{ marginBottom: '16px' }}>
            <label htmlFor="email">Email</label>

            <input
              id="email"
              type="email"
              autoComplete="email"
              value={email}
              onChange={(event) => setEmail(event.target.value)}
              required
              style={{
                display: 'block',
                width: '100%',
                marginTop: '6px',
                padding: '10px',
                boxSizing: 'border-box',
              }}
            />
          </div>

          <div style={{ marginBottom: '20px' }}>
            <label htmlFor="password">Password</label>

            <input
              id="password"
              type="password"
              autoComplete="current-password"
              value={password}
              onChange={(event) => setPassword(event.target.value)}
              required
              style={{
                display: 'block',
                width: '100%',
                marginTop: '6px',
                padding: '10px',
                boxSizing: 'border-box',
              }}
            />
          </div>

          <button type="submit" disabled={submitting}>
            {submitting ? 'Signing in...' : 'Login'}
          </button>
        </form>
      )}
    </main>
  )
}

export default App