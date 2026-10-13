import { Building2, Users } from 'lucide-react'
import { useEffect, useState } from 'react'
import { Link } from 'react-router'

import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { getPlatformCompanies, getPlatformUsers } from '@/features/platform/platform-api'

export function PlatformOverviewPage() {
  const [summary, setSummary] = useState<{ companies: number; users: number } | null>(null)
  const [error, setError] = useState(false)
  const [revision, setRevision] = useState(0)

  useEffect(() => {
    let active = true
    void Promise.all([getPlatformCompanies(1, '', 1), getPlatformUsers(1, '', 1)])
      .then(([companies, users]) => {
        if (active) setSummary({ companies: companies.meta.total, users: users.meta.total })
      })
      .catch(() => { if (active) setError(true) })
    return () => { active = false }
  }, [revision])

  return <div className="space-y-5">
    <div>
      <h1 className="text-2xl font-semibold tracking-tight">Platform overview</h1>
      <p className="mt-1 text-sm text-muted-foreground">Administrative information only. Business data remains inside each tenant.</p>
    </div>
    {error ? <div role="alert" className="rounded-lg border p-4 text-sm">
      Unable to load the platform summary. <Button size="sm" variant="outline" className="ml-2"
        onClick={() => { setSummary(null); setError(false); setRevision(value => value + 1) }}>Retry</Button>
    </div> : null}
    <div className="grid gap-4 sm:grid-cols-2">
      <Card>
        <CardHeader><CardDescription>Registered companies</CardDescription><CardTitle className="text-3xl">{summary?.companies ?? '—'}</CardTitle></CardHeader>
        <CardContent><Link to="/platform/companies" className="inline-flex items-center gap-2 text-sm underline underline-offset-4"><Building2 className="size-4" /> Company directory</Link></CardContent>
      </Card>
      <Card>
        <CardHeader><CardDescription>Registered users</CardDescription><CardTitle className="text-3xl">{summary?.users ?? '—'}</CardTitle></CardHeader>
        <CardContent><Link to="/platform/users" className="inline-flex items-center gap-2 text-sm underline underline-offset-4"><Users className="size-4" /> User directory</Link></CardContent>
      </Card>
    </div>
    <p className="text-xs text-muted-foreground">Management forms are staged and locked until platform MFA is implemented. Role repair and support workflows will follow a separate review.</p>
  </div>
}
