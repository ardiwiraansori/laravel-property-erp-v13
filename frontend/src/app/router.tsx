import { Navigate, createBrowserRouter } from 'react-router'

import { DashboardPage } from '@/components/dashboard/dashboard-page'
import { DashboardLayout } from '@/components/layout/dashboard-layout'
import { ModulePlaceholderPage } from '@/components/module/module-placeholder-page'
import { LoginPage } from '@/features/auth/login-page'
import { ProtectedRoute } from '@/features/auth/protected-route'
import { NotFoundPage } from '@/pages/not-found-page'
import { PermissionRoute } from '@/features/auth/permission-route'
import { permissions } from '@/features/auth/permissions'

export const router = createBrowserRouter([
  {
    path: '/login',
    element: <LoginPage />,
  },
  {
    element: <ProtectedRoute />,
    children: [
      {
        element: <DashboardLayout />,
        children: [
          {
            index: true,
            element: <Navigate to="/dashboard" replace />,
          },
          {
            path: 'dashboard',
            element: (
              <PermissionRoute permission={permissions.dashboardView}>
                <DashboardPage />
              </PermissionRoute>
            ),
          },
          {
            path: 'master-data',
            element: (
              <PermissionRoute permission={permissions.masterDataView}>
                <ModulePlaceholderPage
                  title="Master Data"
                  description="Manage the core reference data used across Property ERP."
                />
              </PermissionRoute>
            ),
          },
          {
            path: 'crm',
            element: (
              <PermissionRoute permission={permissions.crmView}>
                <ModulePlaceholderPage
                  title="CRM"
                  description="Customer relationship management module foundation."
                />
              </PermissionRoute>
            ),
          },
          {
            path: 'sales',
            element: (
              <PermissionRoute permission={permissions.salesView}>
                <ModulePlaceholderPage
                  title="Sales"
                  description="Property sales workflow module foundation."
                />
              </PermissionRoute>
            ),
          },
          {
            path: 'accounting',
            element: (
              <PermissionRoute permission={permissions.accountingView}>
                <ModulePlaceholderPage
                  title="Accounting"
                  description="Financial and accounting module foundation."
                />
              </PermissionRoute>
            ),
          },
          {
            path: 'reports',
            element: (
              <PermissionRoute permission={permissions.reportsView}>
                <ModulePlaceholderPage
                  title="Reports"
                  description="Reporting and business information module foundation."
                />
              </PermissionRoute>
            ),
          },
          {
            path: '*',
            element: <NotFoundPage />,
          },
        ],
      },
    ],
  },
])
