import { useEffect, useState } from 'react'
import type { FormEvent } from 'react'
import axios from 'axios'

import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { DirectoryPager } from '@/features/platform/directory-pager'
import { usePlatformWriteStatus } from '@/features/platform/use-platform-write-status'
import { PlatformWriteNotice } from '@/features/platform/platform-write-status'
import { categories, impacts } from '@/features/diagnostics/diagnostic-api'
import {
  DIAGNOSTIC_STATUSES, EMPTY_QUEUE_FILTERS, NEXT_STATUSES,
  changeDiagnosticAssignee, changeDiagnosticStatus,
  getDiagnosticAssignees, getPlatformReportDetail, getPlatformReports,
} from '@/features/platform/platform-diagnostics-api'
import type {
  Assignee, DiagnosticStatus, PlatformReportPage, ReportDetail, SupportQueueFilters,
} from '@/features/platform/platform-diagnostics-api'

function readableError(error: unknown): string {
  if (axios.isAxiosError(error)) {
    if (error.response?.status === 409) return 'This report has changed. Reload the report and try again.'
    if (error.response?.status === 423) return 'Writes are locked. Platform configuration and MFA are required.'
    if (error.response?.status === 422) return 'This change was rejected. Refresh the report and verify the selection.'
  }
  return 'Request failed. Please retry.'
}

function statusLabel(status: string): string {
  return status.replaceAll('_', ' ')
}

export function PlatformDiagnosticsPage() {
  const [page, setPage] = useState(1)
  const [draft, setDraft] = useState<SupportQueueFilters>({ ...EMPTY_QUEUE_FILTERS })
  const [filters, setFilters] = useState<SupportQueueFilters>({ ...EMPTY_QUEUE_FILTERS })
  const [revision, setRevision] = useState(0)
  const [results, setResults] = useState<PlatformReportPage | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(false)
  const [selected, setSelected] = useState<string | null>(null)
  const [detail, setDetail] = useState<ReportDetail | null>(null)
  const [detailPage, setDetailPage] = useState(1)
  const [detailLoading, setDetailLoading] = useState(false)
  const [detailError, setDetailError] = useState('')
  const [assignees, setAssignees] = useState<Assignee[]>([])
  const [nextStatus, setNextStatus] = useState<DiagnosticStatus | ''>('')
  const [nextAssignee, setNextAssignee] = useState('')
  const [saving, setSaving] = useState(false)
  const [actionError, setActionError] = useState('')
  const write = usePlatformWriteStatus()

  useEffect(() => {
    let active = true
    void getPlatformReports(page, filters)
      .then(data => { if (active) { setResults(data); setLoading(false); setError(false) } })
      .catch(() => { if (active) { setLoading(false); setError(true) } })
    return () => { active = false }
  }, [page, filters, revision])

  useEffect(() => {
    let active = true
    void getDiagnosticAssignees().then(list => { if (active) setAssignees(list) }).catch(() => {
      if (active) setAssignees([])
    })
    return () => { active = false }
  }, [])

  useEffect(() => {
    if (!selected) return
    let active = true
    void getPlatformReportDetail(selected, detailPage)
      .then(value => {
        if (!active) return
        setDetail(value)
        setNextStatus('')
        setNextAssignee(value.data.assigned_platform_admin_user_id === null ? '' : String(value.data.assigned_platform_admin_user_id))
        setDetailLoading(false)
        setDetailError('')
      })
      .catch(() => { if (active) { setDetailLoading(false); setDetailError('Cannot retrieve report history.') } })
    return () => { active = false }
  }, [selected, detailPage, revision])

  function updateDraft<K extends keyof SupportQueueFilters>(key: K, value: SupportQueueFilters[K]) {
    setDraft(current => ({ ...current, [key]: value }))
  }
  function apply(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const companyId = draft.companyId.trim()
    if (companyId !== '' && !/^[1-9][0-9]*$/.test(companyId)) return
    setPage(1)
    setLoading(true)
    setError(false)
    setFilters({ ...draft, companyId })
    setRevision(v => v + 1)
  }
  function clear() {
    setDraft({ ...EMPTY_QUEUE_FILTERS })
    setFilters({ ...EMPTY_QUEUE_FILTERS })
    setPage(1)
    setError(false)
    setLoading(true)
    setRevision(v => v + 1)
  }
  function inspect(publicId: string) {
    setSelected(publicId)
    setDetail(null)
    setDetailPage(1)
    setDetailError('')
    setActionError('')
    setDetailLoading(true)
    setRevision(v => v + 1)
  }
  async function saveStatus() {
    if (!write.enabled || !detail || !selected || !nextStatus || saving) return
    setSaving(true); setActionError('')
    try {
      await changeDiagnosticStatus(selected, nextStatus, detail.data.workflow_version)
      setDetailLoading(true)
      setRevision(v => v + 1)
    } catch (err) { setActionError(readableError(err)) }
    finally { setSaving(false) }
  }
  async function saveAssignee() {
    if (!write.enabled || !detail || !selected || saving) return
    setSaving(true); setActionError('')
    try {
      await changeDiagnosticAssignee(selected, nextAssignee ? Number(nextAssignee) : null, detail.data.workflow_version)
      setDetailLoading(true)
      setRevision(v => v + 1)
    } catch (err) { setActionError(readableError(err)) }
    finally { setSaving(false) }
  }

  const options = detail ? NEXT_STATUSES[detail.data.status] : []
  const canWrite = write.enabled && !write.loading && !write.error && !saving

  return <div className="space-y-5">
    <div><h1 className="text-2xl font-semibold tracking-tight">Diagnostic support workflow</h1>
      <p className="mt-1 text-sm text-muted-foreground">Metadata-only issue tracking. No screenshots, messages, request contents or tenant impersonation.</p>
    </div>
    <PlatformWriteNotice enabled={write.enabled} loading={write.loading} error={write.error} />
    <Card><CardHeader><CardTitle>Incoming reports</CardTitle>
      <CardDescription>Read-only workload insights, filters and audit. Summary covers all statuses and assignments within the selected company, category and impact.</CardDescription></CardHeader>
      <CardContent className="space-y-4">
        <form className="flex flex-wrap items-end gap-3" onSubmit={apply}>
          <label className="space-y-1 text-xs font-medium">Company ID
            <input className="block h-9 w-32 rounded-lg border border-input bg-background px-2 text-sm" inputMode="numeric" pattern="[1-9][0-9]*"
              value={draft.companyId} onChange={e => updateDraft('companyId', e.target.value)} placeholder="All companies" />
          </label>
          <label className="space-y-1 text-xs font-medium">Status
            <select className="block h-9 w-36 rounded-lg border border-input bg-background px-2 text-sm" value={draft.status} onChange={e => updateDraft('status', e.target.value)}>
              <option value="">All statuses</option>
              {DIAGNOSTIC_STATUSES.map(value => <option value={value} key={value}>{statusLabel(value)}</option>)}
            </select>
          </label>
          <label className="space-y-1 text-xs font-medium">Category
            <select className="block h-9 w-44 rounded-lg border border-input bg-background px-2 text-sm" value={draft.category} onChange={e => updateDraft('category', e.target.value)}>
              <option value="">All categories</option>
              {categories.map(([value, label]) => <option value={value} key={value}>{label}</option>)}
            </select>
          </label>
          <label className="space-y-1 text-xs font-medium">Impact
            <select className="block h-9 w-44 rounded-lg border border-input bg-background px-2 text-sm" value={draft.impact} onChange={e => updateDraft('impact', e.target.value)}>
              <option value="">All impacts</option>
              {impacts.map(([value, label]) => <option value={value} key={value}>{label}</option>)}
            </select>
          </label>
          <label className="space-y-1 text-xs font-medium">Assignment
            <select className="block h-9 w-36 rounded-lg border border-input bg-background px-2 text-sm" value={draft.assignment}
              onChange={e => updateDraft('assignment', e.target.value as SupportQueueFilters['assignment'])}>
              <option value="all">All assignments</option><option value="mine">Assigned to me</option>
              <option value="unassigned">Unassigned</option><option value="assigned">Assigned</option>
            </select>
          </label>
          <Button type="submit">Filter</Button><Button type="button" variant="outline" onClick={clear}>Reset</Button>
        </form>
        {error ? <p role="alert" className="text-sm text-destructive">Failed to load reports. <Button variant="outline" size="sm"
          onClick={() => { setError(false); setLoading(true); setRevision(v => v + 1) }}>Retry</Button></p> :
          loading ? <p role="status" className="text-sm text-muted-foreground">Loading support queue...</p> : <>
            {results && <section aria-label="Support workload overview" className="grid gap-2 sm:grid-cols-3 xl:grid-cols-6">
              <div className="rounded-lg border p-3"><p className="text-xs text-muted-foreground">Total reports</p><p className="text-xl font-semibold tabular-nums">{results.summary.total}</p></div>
              {DIAGNOSTIC_STATUSES.map(status => <div className="rounded-lg border p-3" key={status}>
                <p className="text-xs capitalize text-muted-foreground">{statusLabel(status)}</p><p className="text-xl font-semibold tabular-nums">{results.summary.by_status[status]}</p>
              </div>)}
              <div className="rounded-lg border p-3"><p className="text-xs text-muted-foreground">Unassigned</p><p className="text-xl font-semibold tabular-nums">{results.summary.unassigned}</p></div>
              <div className="rounded-lg border p-3"><p className="text-xs text-muted-foreground">Assigned</p><p className="text-xl font-semibold tabular-nums">{results.summary.assigned}</p></div>
              <div className="rounded-lg border p-3"><p className="text-xs text-muted-foreground">Assigned to me</p><p className="text-xl font-semibold tabular-nums">{results.summary.mine}</p></div>
            </section>}
            {results?.data.length === 0 ? <p className="text-sm text-muted-foreground">No reports found for the selected queue filters.</p> :
              <div className="overflow-x-auto"><table className="w-full min-w-[1050px] text-left text-xs">
                <thead><tr className="border-b">
                  <th className="py-2 pr-3">Report ID</th><th className="pr-3">Company</th><th className="pr-3">Reporter ID</th>
                  <th className="pr-3">Module</th><th className="pr-3">Category</th><th className="pr-3">Impact</th>
                  <th className="pr-3">Browser / Viewport</th><th className="pr-3">Status</th><th className="pr-3">Assigned ID</th><th>Action</th>
                </tr></thead>
                <tbody>{results?.data.map(row => <tr className="border-b last:border-0" key={row.public_id}>
                  <td className="py-2 pr-3 font-mono">{row.public_id}</td><td className="pr-3">{row.company_code}</td>
                  <td className="pr-3">{row.reporter_user_id}</td><td className="pr-3">{row.module}</td>
                  <td className="pr-3">{row.category}</td><td className="pr-3">{row.impact}</td>
                  <td className="pr-3">{row.browser_family} / {row.viewport}</td><td className="pr-3">{statusLabel(row.status)}</td>
                  <td className="pr-3">{row.assigned_platform_admin_user_id ?? '—'}</td>
                  <td><Button size="sm" variant="outline" onClick={() => inspect(row.public_id)}>Inspect</Button></td>
                </tr>)}</tbody>
              </table></div>}
          </>}
        {!error && <DirectoryPager meta={results?.meta ?? null} loading={loading} onPageChange={n => { setPage(n); setLoading(true) }} />}
      </CardContent>
    </Card>
    {selected && <Card><CardHeader><CardTitle>Report {selected}</CardTitle>
      <CardDescription>Only approved diagnostic metadata and support history are displayed.</CardDescription></CardHeader>
      <CardContent className="space-y-4">
        {detailLoading && <p role="status" className="text-sm text-muted-foreground">Loading support record...</p>}
        {detailError && <p role="alert" className="text-sm text-destructive">{detailError} <Button size="sm" variant="outline" onClick={() => { setDetailLoading(true); setRevision(v => v + 1) }}>Retry</Button></p>}
        {detail && !detailError && <>
          <p className="text-xs text-muted-foreground">Company #{detail.data.company_id} · Reporter #{detail.data.reporter_user_id} · {detail.data.module} · {detail.data.impact} · Version {detail.data.workflow_version}</p>
          <div className="flex flex-wrap items-end gap-3">
            <label className="space-y-1 text-xs font-medium">Next status
              <select aria-label="Next report status" className="block h-9 rounded-lg border border-input bg-background px-2 text-sm" value={nextStatus}
                onChange={e => setNextStatus(e.target.value as DiagnosticStatus | '')} disabled={!canWrite}>
                <option value="">Choose transition</option>
                {options.map(value => <option key={value} value={value}>{statusLabel(value)}</option>)}
              </select>
            </label>
            <Button disabled={!canWrite || !nextStatus} onClick={() => void saveStatus()}>Update status</Button>
            <label className="space-y-1 text-xs font-medium">Support assignee
              <select aria-label="Support assignee" className="block h-9 rounded-lg border border-input bg-background px-2 text-sm" value={nextAssignee}
                onChange={e => setNextAssignee(e.target.value)} disabled={!canWrite}>
                <option value="">Unassigned</option>
                {assignees.map(a => <option value={a.id} key={a.id}>{a.name} (#{a.id})</option>)}
              </select>
            </label>
            <Button disabled={!canWrite || nextAssignee === (detail.data.assigned_platform_admin_user_id === null ? '' : String(detail.data.assigned_platform_admin_user_id))}
              onClick={() => void saveAssignee()}>Save assignment</Button>
          </div>
          {actionError && <p role="alert" className="text-sm text-destructive">{actionError}</p>}
          <div className="space-y-2"><h3 className="font-medium">Change history</h3>
            {detail.history.length === 0 ? <p className="text-sm text-muted-foreground">No workflow events yet.</p> :
              <div className="overflow-x-auto"><table className="w-full text-left text-xs"><thead><tr className="border-b">
                <th className="py-2">When</th><th>Actor ID</th><th>Event</th><th>Change</th>
              </tr></thead><tbody>{detail.history.map((event, i) => <tr key={`${event.created_at}-${i}`} className="border-b last:border-0">
                <td className="py-2">{event.created_at}</td><td>{event.actor_user_id}</td><td>{event.action}</td>
                <td>{event.action === 'status_changed' ? `${event.from_status} → ${event.to_status}` :
                  `#${event.from_assignee_user_id ?? '—'} → #${event.to_assignee_user_id ?? '—'}`}</td>
              </tr>)}</tbody></table></div>}
            <div className="flex items-center gap-2"><Button variant="outline" size="sm" disabled={detailPage <= 1 || detailLoading}
              onClick={() => { setDetailPage(n => n - 1); setDetailLoading(true) }}>Previous</Button>
              <span className="text-xs text-muted-foreground">History page {detail.meta.current_page} / {detail.meta.last_page}</span>
              <Button variant="outline" size="sm" disabled={detailPage >= detail.meta.last_page || detailLoading}
                onClick={() => { setDetailPage(n => n + 1); setDetailLoading(true) }}>Next</Button></div>
          </div>
        </>}
      </CardContent></Card>}
  </div>
}
