import { lazy, Suspense } from 'react'
import type { ReactNode } from 'react'
import { useLocation } from 'react-router'

import { LazyRouteErrorBoundary } from '@/app/lazy-route-error-boundary'

// This module exports React components only. Keep lazy page definitions apart
// from router.tsx, which exports a non-component Router instance. This avoids
// violating React Fast Refresh boundaries without disabling lint rules.
// Feature pages are imported only when their route element is rendered.
// Authentication, company, MFA and permissions remain eager in router.tsx.
export const DashboardPage = lazy(() => import('@/components/dashboard/dashboard-page')
  .then((module) => ({ default: module.DashboardPage })))
export const ReportProblemPage = lazy(() => import('@/features/diagnostics/report-problem-page')
  .then((module) => ({ default: module.ReportProblemPage })))
export const PropertyDevelopmentsPage = lazy(() => import('@/features/master-data/property-developments/property-developments-page')
  .then((module) => ({ default: module.PropertyDevelopmentsPage })))
export const PlatformOverviewPage = lazy(() => import('@/features/platform/platform-overview-page')
  .then((module) => ({ default: module.PlatformOverviewPage })))
export const PlatformCompaniesPage = lazy(() => import('@/features/platform/platform-companies-page')
  .then((module) => ({ default: module.PlatformCompaniesPage })))
export const PlatformDiagnosticsPage = lazy(() => import('@/features/platform/platform-diagnostics-page')
  .then((module) => ({ default: module.PlatformDiagnosticsPage })))
export const PlatformUsersPage = lazy(() => import('@/features/platform/platform-users-page')
  .then((module) => ({ default: module.PlatformUsersPage })))

export function DeferredPage({ children }: { children: ReactNode }) {
  const { pathname } = useLocation()

  // Navigating to a different route resets a failed page without ever
  // bypassing its route-level authorization or attempting an automatic reload.
  return (
    <LazyRouteErrorBoundary key={pathname}>
      <Suspense fallback={<p role="status" className="p-4 text-sm text-muted-foreground">Loading page...</p>}>
        {children}
      </Suspense>
    </LazyRouteErrorBoundary>
  )
}
