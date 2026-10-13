import { useState } from 'react'
import type { FormEvent } from 'react'

import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { updatePlatformMembership, updatePlatformUser } from '@/features/platform/platform-management-api'
import { PlatformWriteNotice } from '@/features/platform/platform-write-status'
import { usePlatformWriteStatus } from '@/features/platform/use-platform-write-status'

export function PlatformUserManagement() {
  const access = usePlatformWriteStatus()
  const [userId, setUserId] = useState('')
  const [name, setName] = useState('')
  const [memberUserId, setMemberUserId] = useState('')
  const [companyId, setCompanyId] = useState('')
  const [operation, setOperation] = useState<'attach' | 'detach'>('attach')
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState('')

  async function editUser(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!access.enabled || submitting) return
    const id = Number(userId)
    if (!Number.isSafeInteger(id) || id < 1) return setError('Invalid user ID.')
    if (!window.confirm(`Update name of user ID ${id}?`)) return
    setSubmitting(true); setError('')
    try { await updatePlatformUser(id, name); window.location.reload() }
    catch { setError('Unable to update user. Check authorization.') }
    finally { setSubmitting(false) }
  }

  async function membership(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!access.enabled || submitting) return
    const id = Number(memberUserId), company = Number(companyId)
    if (!Number.isSafeInteger(id) || id < 1 || !Number.isSafeInteger(company) || company < 1) return setError('Invalid ID.')
    if (!window.confirm(`${operation === 'attach' ? 'Add' : 'Remove'} membership: user ${id}, company ${company}?`)) return
    setSubmitting(true); setError('')
    try { await updatePlatformMembership(id, company, operation); window.location.reload() }
    catch { setError('Membership change blocked. Check permissions, roles and authorization.') }
    finally { setSubmitting(false) }
  }

  return <Card>
    <CardHeader><CardTitle>User and membership management</CardTitle>
      <CardDescription>Names and company associations only. Never assigns tenant roles or permissions.</CardDescription>
    </CardHeader>
    <CardContent className="space-y-4">
      <PlatformWriteNotice {...access} />
      {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
      <div className="grid gap-4 lg:grid-cols-2">
        <form className="space-y-2" onSubmit={editUser}>
          <p className="text-sm font-medium">Update user name</p>
          <Input type="number" aria-label="User ID to update" placeholder="User ID" min="1" value={userId} onChange={e => setUserId(e.target.value)} required />
          <Input aria-label="Updated user name" placeholder="Updated name" minLength={2} maxLength={255} value={name} onChange={e => setName(e.target.value)} required />
          <Button disabled={!access.enabled || submitting} type="submit">Save name</Button>
        </form>
        <form className="space-y-2" onSubmit={membership}>
          <p className="text-sm font-medium">Company membership</p>
          <Input type="number" aria-label="Membership user ID" placeholder="User ID" min="1" value={memberUserId} onChange={e => setMemberUserId(e.target.value)} required />
          <Input type="number" aria-label="Membership company ID" placeholder="Company ID" min="1" value={companyId} onChange={e => setCompanyId(e.target.value)} required />
          <select aria-label="Membership operation" className="h-8 w-full rounded-lg border bg-background px-2 text-sm" value={operation} onChange={e => setOperation(e.target.value as 'attach' | 'detach')}>
            <option value="attach">Add membership</option><option value="detach">Remove membership</option>
          </select>
          <Button disabled={!access.enabled || submitting} type="submit">Apply membership change</Button>
        </form>
      </div>
    </CardContent>
  </Card>
}
