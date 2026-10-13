import { useEffect, useState } from 'react'

import { getPlatformCapabilities } from '@/features/platform/platform-management-api'

export function usePlatformWriteStatus() {
  const [enabled, setEnabled] = useState(false)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(false)

  useEffect(() => {
    let active = true
    void getPlatformCapabilities()
      .then(result => { if (active) setEnabled(result.writes_enabled === true) })
      .catch(() => { if (active) setError(true) })
      .finally(() => { if (active) setLoading(false) })
    return () => { active = false }
  }, [])

  return { enabled, loading, error }
}
