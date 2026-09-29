import {
  Activity,
  Database,
  Layers3,
  LockKeyhole,
  Server,
  UserRound,
} from 'lucide-react'

import { ApiHealthStatus } from '@/components/dashboard/api-health-status'
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card'
import { useAuth } from '@/features/auth/auth-state'

export function DashboardPage() {
  const { user } = useAuth()

  if (!user) {
    return null
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">Dashboard</h1>

        <p className="mt-1 text-sm text-muted-foreground">
          Application overview and development environment status.
        </p>
      </div>

      <div className="grid gap-4 lg:grid-cols-2">
        <Card>
          <CardHeader className="flex flex-row items-start justify-between">
            <div className="space-y-1">
              <CardTitle>Authenticated User</CardTitle>

              <CardDescription>Current Laravel Sanctum session</CardDescription>
            </div>

            <UserRound className="size-5 text-muted-foreground" />
          </CardHeader>

          <CardContent>
            <div className="space-y-1">
              <p className="font-medium">{user.name}</p>

              <p className="text-sm text-muted-foreground">{user.email}</p>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex flex-row items-start justify-between">
            <div className="space-y-1">
              <CardTitle>Backend Status</CardTitle>

              <CardDescription>
                Live Laravel REST API health check
              </CardDescription>
            </div>

            <Activity className="size-5 text-muted-foreground" />
          </CardHeader>

          <CardContent>
            <ApiHealthStatus />
          </CardContent>
        </Card>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Application Architecture</CardTitle>

          <CardDescription>
            Current technologies implemented in this project
          </CardDescription>
        </CardHeader>

        <CardContent>
          <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <div className="flex items-start gap-3 rounded-lg border p-4">
              <Server className="mt-0.5 size-5 text-muted-foreground" />

              <div>
                <p className="text-sm font-medium">Laravel 13</p>

                <p className="mt-1 text-xs text-muted-foreground">
                  REST API backend
                </p>
              </div>
            </div>

            <div className="flex items-start gap-3 rounded-lg border p-4">
              <Layers3 className="mt-0.5 size-5 text-muted-foreground" />

              <div>
                <p className="text-sm font-medium">React 19</p>

                <p className="mt-1 text-xs text-muted-foreground">
                  TypeScript SPA frontend
                </p>
              </div>
            </div>

            <div className="flex items-start gap-3 rounded-lg border p-4">
              <LockKeyhole className="mt-0.5 size-5 text-muted-foreground" />

              <div>
                <p className="text-sm font-medium">Laravel Sanctum</p>

                <p className="mt-1 text-xs text-muted-foreground">
                  Session-based SPA authentication
                </p>
              </div>
            </div>

            <div className="flex items-start gap-3 rounded-lg border p-4">
              <Database className="mt-0.5 size-5 text-muted-foreground" />

              <div>
                <p className="text-sm font-medium">MariaDB</p>

                <p className="mt-1 text-xs text-muted-foreground">
                  Relational database
                </p>
              </div>
            </div>
          </div>
        </CardContent>
      </Card>
    </div>
  )
}
