import {
  Navigate,
  Outlet,
  createBrowserRouter,
} from 'react-router'

import {
  DashboardPage,
  DeferredPage,
  PlatformCompaniesPage,
  PlatformDiagnosticsPage,
  PlatformOverviewPage,
  PlatformUsersPage,
  PropertyDevelopmentsPage,
  ReportProblemPage,
} from '@/app/deferred-pages'
import { DashboardLayout } from '@/components/layout/dashboard-layout'
import { PlatformLayout } from '@/components/layout/platform-layout'
import { ModulePlaceholderPage } from '@/components/module/module-placeholder-page'
import { AccountEntryRoute } from '@/features/auth/account-entry-route'
import { LoginPage } from '@/features/auth/login-page'
import { PermissionRoute } from '@/features/auth/permission-route'
import { permissions } from '@/features/auth/permissions'
import { ProtectedRoute } from '@/features/auth/protected-route'
import { CompanyProvider } from '@/features/company/company-context'
import { PlatformMfaPage } from '@/features/platform/platform-mfa-page'
import { PlatformRoute } from '@/features/platform/platform-route'
import { NotFoundPage } from '@/pages/not-found-page'

export const router = createBrowserRouter([
  { path: '/', element: <Navigate to="/entry" replace /> },
  { path: '/login', element: <LoginPage /> },
  { path: '/entry', element: <AccountEntryRoute /> },
  { path: '/platform/mfa', element: <PlatformMfaPage /> },
  {
    path: '/platform',
    element: <PlatformRoute />,
    children: [{
      element: <PlatformLayout />,
      children: [
        { index: true, element: <DeferredPage><PlatformOverviewPage /></DeferredPage> },
        { path: 'companies', element: <DeferredPage><PlatformCompaniesPage /></DeferredPage> },
        { path: 'diagnostics', element: <DeferredPage><PlatformDiagnosticsPage /></DeferredPage> },
        { path: 'users', element: <DeferredPage><PlatformUsersPage /></DeferredPage> },
        { path: '*', element: <NotFoundPage /> },
      ],
    }],
  },
  {
    element: <CompanyProvider><ProtectedRoute /></CompanyProvider>,
    children: [
      {
        element: <DashboardLayout />,
        children: [
          { path: 'report-problem', element: <DeferredPage><ReportProblemPage /></DeferredPage> },
          {
            path: 'dashboard',
            element: <PermissionRoute permission={permissions.dashboardView}>
              <DeferredPage><DashboardPage /></DeferredPage>
            </PermissionRoute>,
          },
          {
            path: 'master-data',
            element: <PermissionRoute permission={permissions.masterDataView}>
              <Outlet />
            </PermissionRoute>,
            children: [
              { index: true, element: <Navigate to="property-developments" replace /> },
              {
                path: 'property-developments',
                element: <PermissionRoute permission={permissions.propertyDevelopmentsView}>
                  <DeferredPage><PropertyDevelopmentsPage /></DeferredPage>
                </PermissionRoute>,
              },
            ],
          },
          {
            path: 'crm',
            element: <PermissionRoute permission={permissions.crmView}>
              <ModulePlaceholderPage title="CRM" description="Customer relationship management module foundation." />
            </PermissionRoute>,
          },
          {
            path: 'sales',
            element: <PermissionRoute permission={permissions.salesView}>
              <ModulePlaceholderPage title="Sales" description="Property sales workflow module foundation." />
            </PermissionRoute>,
          },
          {
            path: 'accounting',
            element: <PermissionRoute permission={permissions.accountingView}>
              <ModulePlaceholderPage title="Accounting" description="Financial and accounting module foundation." />
            </PermissionRoute>,
          },
          {
            path: 'reports',
            element: <PermissionRoute permission={permissions.reportsView}>
              <ModulePlaceholderPage title="Reports" description="Reporting and business information module foundation." />
            </PermissionRoute>,
          },
          { path: '*', element: <NotFoundPage /> },
        ],
      },
    ],
  },
])
