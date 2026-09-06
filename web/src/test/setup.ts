import '@testing-library/jest-dom/vitest'

/**
 * jsdom does not implement matchMedia, and ThemeProvider asks for it on mount.
 * Without this stub every component test that renders the shell throws before
 * it reaches an assertion.
 */
if (!window.matchMedia) {
  window.matchMedia = ((query: string) => ({
    matches: false,
    media: query,
    onchange: null,
    addEventListener: () => {},
    removeEventListener: () => {},
    addListener: () => {},
    removeListener: () => {},
    dispatchEvent: () => false,
  })) as typeof window.matchMedia
}
