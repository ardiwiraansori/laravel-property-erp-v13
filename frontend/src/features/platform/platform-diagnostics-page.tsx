import { useEffect, useState } from 'react'
import type { FormEvent } from 'react'

import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { DirectoryPager } from '@/features/platform/directory-pager'
import { api } from '@/lib/api'
import type { DiagnosticPage } from '@/features/diagnostics/diagnostic-api'

type PlatformReport = {
  public_id: string
  company_id: number
  company_code: string
  reporter_user_id: number
  category: string
  module: string
  impact: string
  error_kind: string
  browser_family: string
  viewport: string
  status: string
  created_at: string | null
}

async function load(page: number, companyId: string, status: string) {
  const response = await api.get<DiagnosticPage<PlatformReport>>('/api/platform/diagnostics/reports', {
    params: {
      page, per_page: 20,
      ...(companyId ? { company_id: Number(companyId) } : {}),
      ...(status ? { status } : {}),
    },
  })
  return response.data
}

export function PlatformDiagnosticsPage() {
  const [page, setPage] = useState(1)
  const [companyDraft, setCompanyDraft] = useState('')
  const [company, setCompany] = useState('')
  const [statusDraft, setStatusDraft] = useState('')
  const [status, setStatus] = useState('')
  const [revision, setRevision] = useState(0)
  const [results, setResults] = useState<DiagnosticPage<PlatformReport> | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(false)

  useEffect(() => {
    let active = true
    void load(page, company, status).then(data => {
      if (active) { setResults(data); setLoading(false); setError(false) }
    }).catch(() => { if (active) { setError(true); setLoading(false) } })
    return () => { active = false }
  }, [page, company, status, revision])

  function apply(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (companyDraft !== '' && !/^[1-9][0-9]*$/.test(companyDraft)) return
    setPage(1); setLoading(true); setCompany(companyDraft); setStatus(statusDraft)
    setRevision(v => v + 1)
  }

  return <div className="space-y-5">
    <div><h1 className="text-2xl font-semibold tracking-tight">Diagnostic reports</h1>
      <p className="mt-1 text-sm text-muted-foreground">Read-only technical intake. No payloads, customer records, screenshots, raw URLs, or impersonation.</p>
    </div>
    <Card><CardHeader><CardTitle>Incoming reports</CardTitle><CardDescription>Scoped administrative metadata; viewing this directory is audited.</CardDescription></CardHeader>
      <CardContent className="space-y-4">
        <form className="flex flex-wrap items-end gap-3" onSubmit={apply}>
          <label className="space-y-1 text-xs font-medium">Company ID
            <input className="block h-9 w-32 rounded-lg border border-input bg-background px-2 text-sm" inputMode="numeric" pattern="[1-9][0-9]*"
              value={companyDraft} onChange={e => setCompanyDraft(e.target.value)} placeholder="All companies" />
          </label>
          <label className="space-y-1 text-xs font-medium">Status
            <select className="block h-9 w-36 rounded-lg border border-input bg-background px-2 text-sm" value={statusDraft}
              onChange={e => setStatusDraft(e.target.value)}>
              <option value="">All statuses</option>
              <option value="new">New</option><option value="triaged">Triaged</option>
              <option value="resolved">Resolved</option><option value="closed">Closed</option>
            </select>
          </label>
          <Button type="submit">Filter</Button>
        </form>
        {error ? <p role="alert" className="text-sm text-destructive">Failed to load reports. <Button variant="outline" size="sm"
          onClick={() => { setError(false); setLoading(true); setRevision(v => v + 1) }}>Retry</Button></p> :
          loading && !results ? <p className="text-sm text-muted-foreground">Loading reports...</p> :
          results?.data.length === 0 ? <p className="text-sm text-muted-foreground">No reports found.</p> :
          <div className="overflow-x-auto"><table className="w-full min-w-[900px] text-left text-xs">
            <thead><tr className="border-b">
              <th className="py-2 pr-3">Report ID</th><th className="pr-3">Company</th><th className="pr-3">Reporter ID</th>
              <th className="pr-3">Module</th><th className="pr-3">Category</th><th className="pr-3">Impact</th>
              <th className="pr-3">Browser / Viewport</th><th>Status</th>
            </tr></thead>
            <tbody>{results?.data.map(row => <tr className="border-b last:border-0" key={row.public_id}>
              <td className="py-2 pr-3 font-mono">{row.public_id}</td><td className="pr-3">{row.company_code}</td>
              <td className="pr-3">{row.reporter_user_id}</td><td className="pr-3">{row.module}</td>
              <td className="pr-3">{row.category}</td><td className="pr-3">{row.impact}</td>
              <td className="pr-3">{row.browser_family} / {row.viewport}</td><td>{row.status}</td>
            </tr>)}</tbody>
          </table></div>}
        {!error && <DirectoryPager meta={results?.meta ?? null} loading={loading} onPageChange={n => { setPage(n); setLoading(true) }} />}
      </CardContent>
    </Card>
  </div>
}
