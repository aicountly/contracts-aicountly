import { useCallback, useEffect, useRef, useState } from 'react'
import { ApiError } from '../services/apiClient'

/**
 * Fetch-on-mount with the four states every screen needs.
 *
 * No data-fetching library: the app makes plain reads against its own API and a
 * cache layer would be a dependency to keep current for no behaviour anyone
 * asked for. What this does add is the two things hand-rolled fetching usually
 * gets wrong — cancelling on unmount, and ignoring a response that arrives
 * after a newer request has already been issued.
 */
export interface Resource<T> {
  data: T | null
  loading: boolean
  error: ApiError | Error | null
  reload: () => void
  /** Replace the data locally after a mutation, without a round trip. */
  setData: (next: T) => void
}

export function useApiResource<T>(
  fetcher: (signal: AbortSignal) => Promise<T>,
  deps: unknown[],
  options: { enabled?: boolean } = {},
): Resource<T> {
  const { enabled = true } = options

  const [data, setData] = useState<T | null>(null)
  const [loading, setLoading] = useState(enabled)
  const [error, setError] = useState<ApiError | Error | null>(null)
  const [nonce, setNonce] = useState(0)

  // Incremented per request; a response whose id is not the latest is dropped.
  // Without this, a slow first request can overwrite a fast second one and the
  // screen shows the previous filter's results.
  const requestId = useRef(0)
  const fetcherRef = useRef(fetcher)
  fetcherRef.current = fetcher

  useEffect(() => {
    if (!enabled) {
      setLoading(false)
      return
    }

    const controller = new AbortController()
    const id = ++requestId.current

    setLoading(true)
    setError(null)

    fetcherRef
      .current(controller.signal)
      .then((result) => {
        if (id !== requestId.current) return
        setData(result)
        setLoading(false)
      })
      .catch((err: unknown) => {
        if (controller.signal.aborted || id !== requestId.current) return
        setError(err instanceof Error ? err : new Error('Request failed.'))
        setLoading(false)
      })

    return () => controller.abort()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [...deps, nonce, enabled])

  const reload = useCallback(() => setNonce((n) => n + 1), [])

  return { data, loading, error, reload, setData }
}
