import { useEffect, useMemo, useState } from 'react'
import type { ReactNode } from 'react'

import { ThemeProviderContext } from '@/lib/theme'
import type { Theme } from '@/lib/theme'

type ThemeProviderProps = {
  children: ReactNode
  defaultTheme?: Theme
  storageKey?: string
}

export function ThemeProvider({
  children,
  defaultTheme = 'system',
  storageKey = 'property-erp-theme',
}: ThemeProviderProps) {
  const [theme, setTheme] = useState<Theme>(() => {
    const storedTheme = localStorage.getItem(storageKey) as Theme | null

    return storedTheme ?? defaultTheme
  })

  useEffect(() => {
    const root = window.document.documentElement

    root.classList.remove('light', 'dark')

    if (theme === 'system') {
      const systemTheme = window.matchMedia('(prefers-color-scheme: dark)')
        .matches
        ? 'dark'
        : 'light'

      root.classList.add(systemTheme)

      return
    }

    root.classList.add(theme)
  }, [theme])

  const value = useMemo(
    () => ({
      theme,
      setTheme: (nextTheme: Theme) => {
        localStorage.setItem(storageKey, nextTheme)
        setTheme(nextTheme)
      },
    }),
    [storageKey, theme],
  )

  return (
    <ThemeProviderContext.Provider value={value}>
      {children}
    </ThemeProviderContext.Provider>
  )
}
