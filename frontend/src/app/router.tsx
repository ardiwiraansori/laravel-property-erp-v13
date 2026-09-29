import {
  Navigate,
  createBrowserRouter,
} from 'react-router'

import { DashboardPage } from '@/components/dashboard/dashboard-page'
import { DashboardLayout } from '@/components/layout/dashboard-layout'
import { ModulePlaceholderPage } from '@/components/module/module-placeholder-page'
import { LoginPage } from '@/features/auth/login-page'
import { ProtectedRoute } from '@/features/auth/protected-route'
import { NotFoundPage } from '@/pages/not-found-page'

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
            element: <DashboardPage />,
          },
          {
            path: 'master-data',
            element: (
              <ModulePlaceholderPage
                title="Master Data"
                description="Manage the core reference data used across Property ERP."
              />
            ),
          },
          {
            path: 'crm',
            element: (
              <ModulePlaceholderPage
                title="CRM"
                description="Customer relationship management module foundation."
              />
            ),
          },
          {
            path: 'sales',
            element: (
              <ModulePlaceholderPage
                title="Sales"
                description="Property sales workflow module foundation."
              />
            ),
          },
          {
            path: 'accounting',
            element: (
              <ModulePlaceholderPage
                title="Accounting"
                description="Financial and accounting module foundation."
              />
            ),
          },
          {
            path: 'reports',
            element: (
              <ModulePlaceholderPage
                title="Reports"
                description="Reporting and business information module foundation."
              />
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