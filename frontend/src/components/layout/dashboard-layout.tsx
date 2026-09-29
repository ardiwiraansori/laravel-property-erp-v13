import { Outlet } from 'react-router'

import { AppSidebar } from '@/components/app-sidebar'
import { ApiHealthStatus } from '@/components/dashboard/api-health-status'
import { ThemeToggle } from '@/components/layout/theme-toggle'
import { TopbarUserMenu } from '@/components/layout/topbar-user-menu'
import { Separator } from '@/components/ui/separator'
import {
  SidebarInset,
  SidebarProvider,
  SidebarTrigger,
} from '@/components/ui/sidebar'
import { useAuth } from '@/features/auth/auth-state'

export function DashboardLayout() {
  const { user, submitting, logout } = useAuth()

  if (!user) {
    return null
  }

  return (
    <SidebarProvider>
      <AppSidebar />

      <SidebarInset>
        <header className="sticky top-0 z-20 flex h-14 shrink-0 items-center gap-2 border-b bg-background/95 px-4 backdrop-blur supports-[backdrop-filter]:bg-background/80">
          <SidebarTrigger className="-ml-1" />

          <Separator orientation="vertical" className="mr-2 h-4" />

          <div className="min-w-0">
            <p className="truncate text-sm font-medium">
              Property ERP
            </p>

            <p className="hidden truncate text-xs text-muted-foreground sm:block">
              Full-Stack Portfolio
            </p>
          </div>

          <div className="ml-auto flex items-center gap-2">
            <div className="hidden lg:block">
              <ApiHealthStatus />
            </div>

            <ThemeToggle />

            <TopbarUserMenu
              user={user}
              loggingOut={submitting}
              onLogout={() => void logout()}
            />
          </div>
        </header>

        <div className="flex flex-1 flex-col gap-4 p-4">
          <Outlet />
        </div>
      </SidebarInset>
    </SidebarProvider>
  )
}