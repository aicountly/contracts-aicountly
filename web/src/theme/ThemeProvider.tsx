import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react'
import type { ReactNode } from 'react'

/**
 * Light/dark and accent, kept in sync with the rest of the AICOUNTLY fleet.
 *
 * Two independent axes, exactly as Drive and Books model them:
 *   - mode: the `dark` class on <html>, or follow the OS
 *   - accent: `data-theme` on <html>
 *
 * Both are applied before first paint by the inline script in index.html; this
 * provider only takes over afterwards. Doing it here alone would flash the
 * light theme on every load for a dark-mode user.
 */

export type ThemeMode = 'light' | 'dark' | 'system'

export const ACCENTS = [
  { id: 'default', label: 'AICOUNTLY green' },
  { id: 'blue', label: 'Blue' },
  { id: 'sky', label: 'Sky' },
  { id: 'teal', label: 'Teal' },
  { id: 'lime', label: 'Lime' },
  { id: 'purple', label: 'Purple' },
  { id: 'orange', label: 'Orange' },
  { id: 'yellow', label: 'Yellow' },
  { id: 'red', label: 'Red' },
] as const

export type AccentId = (typeof ACCENTS)[number]['id']

const MODE_KEY = 'aic.theme.mode'
const ACCENT_KEY = 'aic.theme.accent'

interface ThemeValue {
  mode: ThemeMode
  accent: AccentId
  resolvedMode: 'light' | 'dark'
  setMode: (mode: ThemeMode) => void
  setAccent: (accent: AccentId) => void
}

const ThemeContext = createContext<ThemeValue | null>(null)

function readStored(key: string, fallback: string): string {
  try {
    return window.localStorage.getItem(key) ?? fallback
  } catch {
    // Private-mode Safari throws on access rather than returning null.
    return fallback
  }
}

function store(key: string, value: string): void {
  try {
    window.localStorage.setItem(key, value)
  } catch {
    /* a remembered theme is a convenience, not a requirement */
  }
}

function systemPrefersDark(): boolean {
  return window.matchMedia?.('(prefers-color-scheme: dark)').matches ?? false
}

export function ThemeProvider({ children }: { children: ReactNode }) {
  const [mode, setModeState] = useState<ThemeMode>(
    () => readStored(MODE_KEY, 'system') as ThemeMode,
  )
  const [accent, setAccentState] = useState<AccentId>(
    () => readStored(ACCENT_KEY, 'default') as AccentId,
  )
  const [systemDark, setSystemDark] = useState(systemPrefersDark)

  useEffect(() => {
    const query = window.matchMedia?.('(prefers-color-scheme: dark)')
    if (!query) return
    const onChange = (event: MediaQueryListEvent) => setSystemDark(event.matches)
    query.addEventListener('change', onChange)
    return () => query.removeEventListener('change', onChange)
  }, [])

  const resolvedMode: 'light' | 'dark' = mode === 'system' ? (systemDark ? 'dark' : 'light') : mode

  useEffect(() => {
    const root = document.documentElement
    root.classList.toggle('dark', resolvedMode === 'dark')
    root.dataset.theme = accent
    root.style.colorScheme = resolvedMode
  }, [resolvedMode, accent])

  const setMode = useCallback((next: ThemeMode) => {
    setModeState(next)
    store(MODE_KEY, next)
  }, [])

  const setAccent = useCallback((next: AccentId) => {
    setAccentState(next)
    store(ACCENT_KEY, next)
  }, [])

  const value = useMemo<ThemeValue>(
    () => ({ mode, accent, resolvedMode, setMode, setAccent }),
    [mode, accent, resolvedMode, setMode, setAccent],
  )

  return <ThemeContext.Provider value={value}>{children}</ThemeContext.Provider>
}

export function useTheme(): ThemeValue {
  const ctx = useContext(ThemeContext)
  if (!ctx) throw new Error('useTheme must be used inside <ThemeProvider>')
  return ctx
}
