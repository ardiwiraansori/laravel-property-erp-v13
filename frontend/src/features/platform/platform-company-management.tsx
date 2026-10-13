import { useState } from 'react'
import type { FormEvent } from 'react'

import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { createPlatformCompany, updatePlatformCompany } from '@/features/platform/platform-management-api'
import { PlatformWriteNotice } from '@/features/platform/platform-write-status'
import { usePlatformWriteStatus } from '@/features/platform/use-platform-write-status'

export function PlatformCompanyManagement() {
  const access = usePlatformWriteStatus()
  const [code, setCode] = useState('')
  const [name, setName] = useState('')
  const [companyId, setCompanyId] = useState('')
  const [newName, setNewName] = useState('')
  const [status, setStatus] = useState('keep')
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState('')

  async function create(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!access.enabled || submitting) return
    if (!window.confirm(`Create new company ${code}?`)) return
    setSubmitting(true); setError('')
    try {
      await createPlatformCompany({ code, name })
      window.location.reload()
    } catch { setError('Unable to create company. Check values and authorization.') }
    finally { setSubmitting(false) }
  }

  async function update(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!access.enabled || submitting) return
    const id = Number(companyId)
    if (!Number.isSafeInteger(id) || id < 1) { setError('Enter a valid company ID.'); return }
    const payload: { name?: string; is_active?: boolean } = {}
    if (newName.trim()) payload.name = newName.trim()
    if (status !== 'keep') payload.is_active = status === 'active'
    if (Object.keys(payload).length === 0) { setError('Select a change.'); return }
    if (!window.confirm(`Update company ID ${id}?`)) return
    setSubmitting(true); setError('')
    try {
      await updatePlatformCompany(id, payload)
      window.location.reload()
    } catch { setError('Unable to update company. Check values and authorization.') }
    finally { setSubmitting(false) }
  }

  return <Card>
    <CardHeader><CardTitle>Company management</CardTitle>
      <CardDescription>Prepared create/update operations. Disabled until server-confirmed platform MFA is available.</CardDescription>
    </CardHeader>
    <CardContent className="space-y-4">
      <PlatformWriteNotice {...access} />
      {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
      <div className="grid gap-4 lg:grid-cols-2">
        <form className="space-y-2" onSubmit={create}>
          <p className="text-sm font-medium">Create company</p>
          <Input aria-label="New company code" placeholder="Unique code e.g. NEW-01" maxLength={30} value={code} onChange={e => setCode(e.target.value.toUpperCase())} required />
          <Input aria-label="New company name" placeholder="Company name" maxLength={150} value={name} onChange={e => setName(e.target.value)} required />
          <Button disabled={!access.enabled || submitting} type="submit">Create company</Button>
        </form>
        <form className="space-y-2" onSubmit={update}>
          <p className="text-sm font-medium">Update company</p>
          <Input aria-label="Company ID to update" type="number" min="1" placeholder="Company ID" value={companyId} onChange={e => setCompanyId(e.target.value)} required />
          <Input aria-label="Updated company name" placeholder="New name (optional)" maxLength={150} value={newName} onChange={e => setNewName(e.target.value)} />
          <select aria-label="Company status change" className="h-8 w-full rounded-lg border bg-background px-2 text-sm" value={status} onChange={e => setStatus(e.target.value)}>
            <option value="keep">Keep current status</option><option value="active">Activate</option><option value="inactive">Deactivate</option>
          </select>
          <Button disabled={!access.enabled || submitting} type="submit">Save company changes</Button>
        </form>
      </div>
    </CardContent>
  </Card>
}
