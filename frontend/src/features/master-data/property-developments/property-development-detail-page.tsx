import axios from 'axios'
import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router'

import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { useCompany } from '@/features/company/company-state'
import {
  getPropertyDevelopment,
  type PropertyDevelopmentDetail,
} from '@/features/master-data/property-developments/property-development-api'

type LoadState = 'loading' | 'ready' | 'not-found' | 'error'

function formatDate(isoDate: string | null): string {
  if (!isoDate) return 'Not available'
  const date = new Date(isoDate)
  return Number.isNaN(date.getTime()) ? 'Not available' : date.toLocaleString()
}

export function PropertyDevelopmentDetailPage() {
  const { developmentId } = useParams<{ developmentId: string }>()
  const { activeCompany } = useCompany()
  const companyId = activeCompany?.id
  const [state, setState] = useState<LoadState>('loading')
  const [record, setRecord] = useState<PropertyDevelopmentDetail | null>(null)
  const [revision, setRevision] = useState(0)

  const validId = typeof developmentId === 'string' && /^[1-9]\d*$/.test(developmentId)
    && Number.isSafeInteger(Number(developmentId))

  useEffect(() => {
    let current = true

    async function load() {
      // Schedule state updates after the effect setup to meet React lint rules.
      await Promise.resolve()
      if (!current) return
      setState('loading')
      setRecord(null)

      if (!validId) {
        setState('not-found')
        return
      }

      try {
        const detail = await getPropertyDevelopment(Number(developmentId))
        if (current) {
          setRecord(detail)
          setState('ready')
        }
      } catch (error) {
        if (!current) return
        setState(axios.isAxiosError(error) && error.response?.status === 404 ? 'not-found' : 'error')
      }
    }

    void load()
    return () => { current = false }
  }, [companyId, developmentId, revision, validId])

  return (
    <div className="space-y-6">
      <div className="space-y-2">
        <Link className="text-sm text-primary underline-offset-4 hover:underline"
          to="/master-data/property-developments">← Back to developments</Link>
        <h1 className="text-2xl font-semibold tracking-tight">Development details</h1>
        <p className="text-sm text-muted-foreground">Read-only record in your active company.</p>
      </div>
      <Card>
        <CardHeader>
          <CardTitle>Property Development</CardTitle>
          <CardDescription>Details are retrieved from the server for the selected company.</CardDescription>
        </CardHeader>
        <CardContent>
          {state === 'loading' ? (
            <p role="status" className="text-sm text-muted-foreground">Loading development details...</p>
          ) : state === 'not-found' ? (
            <p role="alert" className="text-sm text-muted-foreground">
              This development was not found in your active company.
            </p>
          ) : state === 'error' ? (
            <div className="space-y-3">
              <p role="alert" className="text-sm text-destructive">Unable to load development details.</p>
              <Button variant="outline" onClick={() => setRevision(value => value + 1)}>Try again</Button>
            </div>
          ) : record ? (
            <dl className="grid gap-4 sm:grid-cols-2">
              <div>
                <dt className="text-xs font-medium text-muted-foreground">Code</dt>
                <dd className="mt-1 font-mono text-sm">{record.code}</dd>
              </div>
              <div>
                <dt className="text-xs font-medium text-muted-foreground">Status</dt>
                <dd className="mt-1 text-sm">{record.is_active ? 'Active' : 'Inactive'}</dd>
              </div>
              <div className="sm:col-span-2">
                <dt className="text-xs font-medium text-muted-foreground">Name</dt>
                <dd className="mt-1 text-sm">{record.name}</dd>
              </div>
              <div>
                <dt className="text-xs font-medium text-muted-foreground">Created</dt>
                <dd className="mt-1 text-sm">{formatDate(record.created_at)}</dd>
              </div>
              <div>
                <dt className="text-xs font-medium text-muted-foreground">Last updated</dt>
                <dd className="mt-1 text-sm">{formatDate(record.updated_at)}</dd>
              </div>
            </dl>
          ) : null}
        </CardContent>
      </Card>
    </div>
  )
}
