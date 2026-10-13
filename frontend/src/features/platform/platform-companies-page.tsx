import { useEffect, useState } from 'react'
import type { FormEvent } from 'react'

import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { DirectoryPager } from '@/features/platform/directory-pager'
import { getPlatformCompanies } from '@/features/platform/platform-api'
import type { Paginated, PlatformCompany } from '@/features/platform/platform-api'

export function PlatformCompaniesPage() {
  const [draft, setDraft] = useState('')
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [revision, setRevision] = useState(0)
  const [response, setResponse] = useState<Paginated<PlatformCompany> | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(false)

  useEffect(() => {
    let active = true
    void getPlatformCompanies(page, search).then(data => {
      if (active) { setResponse(data); setLoading(false) }
    }).catch(() => {
      if (active) { setError(true); setLoading(false) }
    })
    return () => { active = false }
  }, [page, search, revision])

  function handleSearch(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const next = draft.trim()
    if (next === search && page === 1) return
    setError(false); setLoading(true); setSearch(next); setPage(1)
  }

  return <div className="space-y-5">
    <div><h1 className="text-2xl font-semibold tracking-tight">Companies</h1>
      <p className="mt-1 text-sm text-muted-foreground">Directory of registered companies, without tenant business data.</p>
    </div>
    <Card>
      <CardHeader><CardTitle>Company directory</CardTitle><CardDescription>Names, codes and membership counts only · Read-only</CardDescription></CardHeader>
      <CardContent className="space-y-4">
        <form className="flex max-w-xl gap-2" onSubmit={handleSearch}>
          <Input aria-label="Search companies" placeholder="Search company name or code" maxLength={100}
            value={draft} onChange={event => setDraft(event.target.value)} />
          <Button type="submit">Search</Button>
        </form>
        {error ? <div role="alert" className="flex items-center gap-2 text-sm text-destructive">
          Unable to load companies. <Button variant="outline" size="sm" onClick={() => {
            setLoading(true); setError(false); setRevision(value => value + 1)
          }}>Retry</Button>
        </div> : loading ? <p className="text-sm text-muted-foreground">Loading companies...</p> : response?.data.length === 0 ?
          <p className="rounded-lg border border-dashed p-6 text-sm text-muted-foreground">No companies found.</p> :
          <div className="overflow-x-auto"><table className="w-full min-w-[640px] text-left text-sm">
            <thead><tr className="border-b text-muted-foreground">
              <th className="pb-3 pr-4 font-medium">Code</th><th className="pb-3 pr-4 font-medium">Company</th>
              <th className="pb-3 pr-4 font-medium">Status</th><th className="pb-3 text-right font-medium">Members</th>
            </tr></thead>
            <tbody>{response?.data.map(company => <tr key={company.id} className="border-b last:border-0">
              <td className="py-3 pr-4 font-mono text-xs">{company.code}</td><td className="py-3 pr-4">{company.name}</td>
              <td className="py-3 pr-4">{company.is_active ? 'Active' : 'Inactive'}</td>
              <td className="py-3 text-right tabular-nums">{company.members_count}</td>
            </tr>)}</tbody>
          </table></div>}
        {!error && <DirectoryPager meta={response?.meta ?? null} loading={loading}
          onPageChange={next => { setLoading(true); setPage(next) }} />}
      </CardContent>
    </Card>
  </div>
}
