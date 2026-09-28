import { Monitor, Moon, Sun } from 'lucide-react'

import { useTheme } from '@/lib/theme'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'

export function ThemeToggle() {
  const { theme, setTheme } = useTheme()

  return (
    <DropdownMenu>
      <DropdownMenuTrigger
        render={
          <Button variant="ghost" size="icon-sm" aria-label="Change theme" />
        }
      >
        {theme === 'dark' ? (
          <Moon />
        ) : theme === 'light' ? (
          <Sun />
        ) : (
          <Monitor />
        )}
      </DropdownMenuTrigger>

      <DropdownMenuContent align="end" className="w-36">
        <DropdownMenuItem onClick={() => setTheme('light')}>
          <Sun />
          Light
        </DropdownMenuItem>

        <DropdownMenuItem onClick={() => setTheme('dark')}>
          <Moon />
          Dark
        </DropdownMenuItem>

        <DropdownMenuItem onClick={() => setTheme('system')}>
          <Monitor />
          System
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  )
}
