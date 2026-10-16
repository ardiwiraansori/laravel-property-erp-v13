import { useEffect, useState } from 'react'
import { Link } from 'react-router'

import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { api } from '@/lib/api'

type PortfolioSummary = {
  total: number
  active: number
  inactive: number
}

type Props = {
  companyId: number
  companyName: string
  canOpenDirectory: boolean
}

export function PropertyDevelopmentSummaryCard({ companyId, companyName, canOpenDirectory }: Props) {
  const [summary, setSummary] = useState<PortfolioSummary | null>(null)
  const [loading, setLoading] = useState(true)
  const [failed, setFailed] = useState(false)
  const [revision, setRevision] = useState(0)

  useEffect(() => {
    let current = true

    // The selected company is supplied by the existing tenant context provider.
    // The API independently verifies the X-Company-ID and view permission.
    void api.get<{ data: PortfolioSummary }>('/api/master-data/property-developments/summary')
      .then((response) => {
        if (!current) return
        setSummary(response.data.data)
        setFailed(false)
      })
      .catch(() => {
        if (!current) return
        setSummary(null)
        setFailed(true)
      })
      .finally(() => {
        if (current) setLoading(false)
      })

    return () => {
      current = false
    }
  }, [companyId, revision])

  function retry() {
    setLoading(true)
    setFailed(false)
    setRevision((value) => value + 1)
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>Property Development Portfolio</CardTitle>
        <CardDescription>
          Summary for your active company: {companyName}. Counts include active and inactive developments.
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-4">
        {loading ? (
          <p role="status" className="text-sm text-muted-foreground">Loading portfolio summary...</p>
        ) : failed ? (
          <div className="flex flex-wrap items-center gap-3" role="alert">
            <p className="text-sm text-destructive">Unable to load the portfolio summary.</p>
            <Button type="button" size="sm" variant="outline" onClick={retry}>Try again</Button>
          </div>
        ) : summary ? (
          <>
            <div className="grid gap-3 sm:grid-cols-3" aria-label="Property development counts">
              <div className="rounded-lg border p-4">
                <p className="text-sm text-muted-foreground">Total developments</p>
                <p className="mt-1 text-2xl font-semibold tabular-nums">{summary.total}</p>
              </div>
              <div className="rounded-lg border p-4">
                <p className="text-sm text-muted-foreground">Active</p>
                <p className="mt-1 text-2xl font-semibold tabular-nums">{summary.active}</p>
              </div>
              <div className="rounded-lg border p-4">
                <p className="text-sm text-muted-foreground">Inactive</p>
                <p className="mt-1 text-2xl font-semibold tabular-nums">{summary.inactive}</p>
              </div>
            </div>
            {summary.total === 0 ? (
              <p className="text-sm text-muted-foreground">No property developments have been recorded for this company.</p>
            ) : null}
            {canOpenDirectory ? (
              <nav className="flex flex-wrap gap-x-5 gap-y-2 text-sm" aria-label="Property development directory filters">
                <Link to="/master-data/property-developments" className="font-medium text-primary underline-offset-4 hover:underline">
                  View all developments
                </Link>
                <Link to="/master-data/property-developments?status=active" className="font-medium text-primary underline-offset-4 hover:underline">
                  View active
                </Link>
                <Link to="/master-data/property-developments?status=inactive" className="font-medium text-primary underline-offset-4 hover:underline">
                  View inactive
                </Link>
              </nav>
            ) : null}
          </>
        ) : null}
      </CardContent>
    </Card>
  )
}
