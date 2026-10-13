import { useEffect, useState } from 'react'
import type { FormEvent } from 'react'

import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { DirectoryPager } from '@/features/platform/directory-pager'
import { getPlatformMemberships, getPlatformUsers } from '@/features/platform/platform-api'
import type { Paginated, PlatformMembership, PlatformUser } from '@/features/platform/platform-api'

function MembershipPanel({ user }: { user: PlatformUser }) {
  const [page, setPage] = useState(1)
  const [revision, setRevision] = useState(0)
  const [response, setResponse] = useState<Paginated<PlatformMembership> | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(false)

  useEffect(() => {
    let active = true
    void getPlatformMemberships(user.id, page).then(data => {
      if (active) { setResponse(data); setLoading(false) }
    }).catch(() => { if (active) { setError(true); setLoading(false) } })
    return () => { active = false }
  }, [user.id, page, revision])

  return <Card>
    <CardHeader><CardTitle>Memberships · {user.name}</CardTitle>
      <CardDescription>Only company associations are shown. Roles and business data are not included.</CardDescription>
    </CardHeader>
    <CardContent className="space-y-4">
      {error ? <div role="alert" className="text-sm text-destructive">Unable to load memberships.
        <Button size="sm" variant="outline" className="ml-2" onClick={() => {
          setError(false); setLoading(true); setRevision(value => value + 1)
        }}>Retry</Button></div> : loading ? <p className="text-sm text-muted-foreground">Loading memberships...</p> :
        response?.data.length === 0 ? <p className="text-sm text-muted-foreground">No company memberships.</p> :
        <div className="overflow-x-auto"><table className="w-full min-w-[440px] text-left text-sm">
          <thead><tr className="border-b text-muted-foreground"><th className="pb-3 pr-4 font-medium">Code</th>
            <th className="pb-3 pr-4 font-medium">Company</th><th className="pb-3 font-medium">Status</th></tr></thead>
          <tbody>{response?.data.map(company => <tr key={company.id} className="border-b last:border-0">
            <td className="py-3 pr-4 font-mono text-xs">{company.code}</td>
            <td className="py-3 pr-4">{company.name}</td><td className="py-3">{company.is_active ? 'Active' : 'Inactive'}</td>
          </tr>)}</tbody>
        </table></div>}
      {!error && <DirectoryPager meta={response?.meta ?? null} loading={loading}
        onPageChange={next => { setLoading(true); setPage(next) }} />}
    </CardContent>
  </Card>
}

export function PlatformUsersPage() {
  const [draft, setDraft] = useState('')
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [revision, setRevision] = useState(0)
  const [response, setResponse] = useState<Paginated<PlatformUser> | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(false)
  const [selectedUser, setSelectedUser] = useState<PlatformUser | null>(null)

  useEffect(() => {
    let active = true
    void getPlatformUsers(page, search).then(data => {
      if (active) { setResponse(data); setLoading(false) }
    }).catch(() => { if (active) { setError(true); setLoading(false) } })
    return () => { active = false }
  }, [page, search, revision])

  function handleSearch(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const next = draft.trim()
    if (next === search && page === 1) return
    setSelectedUser(null); setError(false); setLoading(true); setSearch(next); setPage(1)
  }

  return <div className="space-y-5">
    <div><h1 className="text-2xl font-semibold tracking-tight">Users</h1>
      <p className="mt-1 text-sm text-muted-foreground">Administrative user directory and company membership lookup.</p>
    </div>
    <Card>
      <CardHeader><CardTitle>User directory</CardTitle>
        <CardDescription>Identity and membership counts · Read-only</CardDescription></CardHeader>
      <CardContent className="space-y-4">
        <form className="flex max-w-xl gap-2" onSubmit={handleSearch}>
          <Input aria-label="Search users" placeholder="Search name or email" maxLength={100} value={draft}
            onChange={event => setDraft(event.target.value)} />
          <Button type="submit">Search</Button>
        </form>
        {error ? <div role="alert" className="text-sm text-destructive">Unable to load users.
          <Button size="sm" variant="outline" className="ml-2" onClick={() => {
            setError(false); setLoading(true); setRevision(value => value + 1)
          }}>Retry</Button>
        </div> : loading ? <p className="text-sm text-muted-foreground">Loading users...</p> :
        response?.data.length === 0 ? <p className="rounded-lg border border-dashed p-6 text-sm text-muted-foreground">No users found.</p> :
        <div className="overflow-x-auto"><table className="w-full min-w-[640px] text-left text-sm">
          <thead><tr className="border-b text-muted-foreground"><th className="pb-3 pr-4 font-medium">Name</th>
            <th className="pb-3 pr-4 font-medium">Email</th><th className="pb-3 pr-4 text-right font-medium">Memberships</th>
            <th className="pb-3 font-medium">Action</th></tr></thead>
          <tbody>{response?.data.map(user => <tr key={user.id} className="border-b last:border-0">
            <td className="py-3 pr-4">{user.name}</td><td className="py-3 pr-4">{user.email}</td>
            <td className="py-3 pr-4 text-right tabular-nums">{user.memberships_count}</td>
            <td className="py-3"><Button variant="outline" size="sm" onClick={() => setSelectedUser(user)}>
              View memberships</Button></td>
          </tr>)}</tbody>
        </table></div>}
        {!error && <DirectoryPager meta={response?.meta ?? null} loading={loading}
          onPageChange={next => { setSelectedUser(null); setLoading(true); setPage(next) }} />}
      </CardContent>
    </Card>
    {selectedUser ? <MembershipPanel key={selectedUser.id} user={selectedUser} /> : null}
  </div>
}
