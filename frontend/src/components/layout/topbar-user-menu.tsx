import { ChevronDown, LogOut } from 'lucide-react'

import { Avatar, AvatarFallback } from '@/components/ui/avatar'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuGroup,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'

type User = {
  id: number
  name: string
  email: string
}

type TopbarUserMenuProps = {
  user: User
  loggingOut: boolean
  onLogout: () => void
}

function getInitials(name: string) {
  return name
    .trim()
    .split(/\s+/)
    .slice(0, 2)
    .map((part) => part.charAt(0).toUpperCase())
    .join('')
}

export function TopbarUserMenu({
  user,
  loggingOut,
  onLogout,
}: TopbarUserMenuProps) {
  return (
    <DropdownMenu>
      <DropdownMenuTrigger
        className="flex cursor-pointer items-center gap-2 rounded-lg p-1.5 text-left outline-none transition-colors hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring"
        aria-label="Open user menu"
      >
        <Avatar>
          <AvatarFallback>{getInitials(user.name)}</AvatarFallback>
        </Avatar>

        <div className="hidden min-w-0 sm:block">
          <p className="max-w-40 truncate text-sm font-medium">{user.name}</p>

          <p className="max-w-40 truncate text-xs text-muted-foreground">
            {user.email}
          </p>
        </div>

        <ChevronDown className="hidden size-4 text-muted-foreground sm:block" />
      </DropdownMenuTrigger>

      <DropdownMenuContent align="end" className="w-64">
        <DropdownMenuGroup>
          <DropdownMenuLabel>
            <div className="space-y-1">
              <p className="font-medium text-foreground">{user.name}</p>

              <p className="truncate font-normal text-muted-foreground">
                {user.email}
              </p>
            </div>
          </DropdownMenuLabel>
        </DropdownMenuGroup>

        <DropdownMenuSeparator />

        <DropdownMenuItem
          variant="destructive"
          disabled={loggingOut}
          onClick={onLogout}
        >
          <LogOut />
          {loggingOut ? 'Signing out...' : 'Sign out'}
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  )
}
