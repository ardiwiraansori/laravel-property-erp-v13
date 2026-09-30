import { ShieldAlert } from 'lucide-react'

import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card'

export function ForbiddenPage() {
  return (
    <div className="flex flex-1 items-center justify-center py-12">
      <Card className="w-full max-w-lg">
        <CardHeader>
          <div className="mb-2 flex size-10 items-center justify-center rounded-lg bg-muted">
            <ShieldAlert className="size-5 text-muted-foreground" />
          </div>

          <CardTitle>Access denied</CardTitle>

          <CardDescription>
            Your account does not have permission to access this module.
          </CardDescription>
        </CardHeader>

        <CardContent>
          <p className="text-sm text-muted-foreground">
            Use the available navigation to continue working in Property ERP.
          </p>
        </CardContent>
      </Card>
    </div>
  )
}
