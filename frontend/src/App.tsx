import { useEffect, useState } from 'react'

type HealthResponse = {
  status: string
  service: string
}

type ConnectionStatus =
  | { state: 'loading' }
  | { state: 'success'; data: HealthResponse }
  | { state: 'error'; message: string }

function App() {
  const [connection, setConnection] = useState<ConnectionStatus>({
    state: 'loading',
  })

  useEffect(() => {
    const controller = new AbortController()

    async function checkApiConnection() {
      try {
        const response = await fetch('/api/health', {
          signal: controller.signal,
          headers: {
            Accept: 'application/json',
          },
        })

        if (!response.ok) {
          throw new Error(`HTTP ${response.status}`)
        }

        const data: HealthResponse = await response.json()

        setConnection({
          state: 'success',
          data,
        })
      } catch (error) {
        if (controller.signal.aborted) {
          return
        }

        setConnection({
          state: 'error',
          message:
            error instanceof Error
              ? error.message
              : 'Unknown connection error',
        })
      }
    }

    void checkApiConnection()

    return () => {
      controller.abort()
    }
  }, [])

  return (
    <main
      style={{
        maxWidth: '720px',
        margin: '80px auto',
        padding: '0 24px',
        fontFamily: 'system-ui, sans-serif',
      }}
    >
      <h1>Property ERP</h1>

      <p>Laravel REST API + React TypeScript</p>

      {connection.state === 'loading' && (
        <p>Checking backend connection...</p>
      )}

      {connection.state === 'success' && (
        <div>
          <h2>Backend Connected</h2>
          <p>Status: {connection.data.status}</p>
          <p>Service: {connection.data.service}</p>
        </div>
      )}

      {connection.state === 'error' && (
        <div>
          <h2>Backend Connection Failed</h2>
          <p>{connection.message}</p>
        </div>
      )}
    </main>
  )
}

export default App
