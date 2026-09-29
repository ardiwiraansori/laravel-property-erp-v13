import { Link } from 'react-router'

import { buttonVariants } from '@/components/ui/button'

export function NotFoundPage() {
  return (
    <div className="flex min-h-[60vh] items-center justify-center">
      <div className="max-w-md text-center">
        <p className="text-sm font-medium text-muted-foreground">
          404
        </p>

        <h1 className="mt-2 text-3xl font-semibold tracking-tight">
          Page not found
        </h1>

        <p className="mt-3 text-sm text-muted-foreground">
          The page you requested does not exist or may have moved.
        </p>

        <Link
          to="/dashboard"
          className={buttonVariants({
            variant: 'default',
            className: 'mt-6',
          })}
        >
          Back to dashboard
        </Link>
      </div>
    </div>
  )
}