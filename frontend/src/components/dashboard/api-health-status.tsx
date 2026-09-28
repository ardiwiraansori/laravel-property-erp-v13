import axios from 'axios'
import { useEffect, useState } from 'react'
import { Activity, CircleAlert, LoaderCircle } from 'lucide-react'

import { api } from '@/lib/api'

type HealthResponse = {
  status: string
  service: string
}

type HealthState =
  | {
      status: 'loading'
      data: null
    }
  | {
      status: 'online'
      data: HealthResponse
    }
  | {
      status: 'offline'
      data: null
    }

export function ApiHealthStatus() {
  const [health, setHealth] = useState<HealthState>({
    status: 'loading',
    data: null,
  })

  useEffect(() => {
    let active = true

    async function checkHealth() {
      try {
        const response = await api.get<HealthResponse>('/api/health')

        if (!active) {
          return
        }

        setHealth({
          status: 'online',
          data: response.data,
        })
      } catch (error) {
        if (!active) {
          return
        }

        if (axios.isAxiosError(error)) {
          setHealth({
            status: 'offline',
            data: null,
          })

          return
        }

        setHealth({
          status: 'offline',
          data: null,
        })
      }
    }

    void checkHealth()

    return () => {
      active = false
    }
  }, [])

  if (health.status === 'loading') {
    return (
      <div className="flex items-center gap-2 text-sm text-muted-foreground">
        <LoaderCircle className="size-4 animate-spin" />

        <span>Checking API status...</span>
      </div>
    )
  }

  if (health.status === 'offline') {
    return (
      <div className="flex items-center gap-2 text-sm text-destructive">
        <CircleAlert className="size-4" />

        <span>Backend API unavailable</span>
      </div>
    )
  }

  return (
    <div className="flex items-center gap-2 text-sm">
      <span className="relative flex size-2.5">
        <span className="absolute inline-flex size-full animate-ping rounded-full bg-emerald-500 opacity-50" />
        <span className="relative inline-flex size-2.5 rounded-full bg-emerald-500" />
      </span>

      <Activity className="size-4 text-muted-foreground" />

      <span className="font-medium">{health.data.service}</span>

      <span className="text-muted-foreground">online</span>
    </div>
  )
}
