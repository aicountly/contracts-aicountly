import { useCallback, useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { AlertTriangle, Bell, BellOff, CheckCheck, CircleAlert, Info, MailCheck } from 'lucide-react'

import {
  Button,
  Card,
  Chip,
  EmptyState,
  ErrorState,
  PageHeader,
  Pagination,
  Skeleton,
  Tabs,
} from '../components/ui'
import { useToast } from '../context/ToastProvider'
import { useApiResource } from '../hooks/useApiResource'
import { api } from '../services/apiClient'
import type { NotificationItem, NotificationPage } from '../types/contracts'
import { formatDateTime, humanise } from '../utils/format'

/**
 * The notification inbox.
 *
 * The unread count comes back on the page rather than from a second request,
 * and both are re-read from the server after every action. A badge computed in
 * the browser from the rows on screen disagrees with the bell the moment a
 * sweep raises something while the page is open, and a count that disagrees
 * with the list is the kind of bug users notice immediately and never trust
 * afterwards.
 */

const PER_PAGE = 25

const SEVERITY: Record<string, { tone: 'neutral' | 'success' | 'warning' | 'danger'; icon: typeof Info }> = {
  info: { tone: 'neutral', icon: Info },
  success: { tone: 'success', icon: MailCheck },
  warning: { tone: 'warning', icon: AlertTriangle },
  critical: { tone: 'danger', icon: CircleAlert },
}

export default function Notifications() {
  const navigate = useNavigate()
  const toast = useToast()

  const [unreadOnly, setUnreadOnly] = useState(true)
  const [page, setPage] = useState(1)
  const [busy, setBusy] = useState<number | 'all' | null>(null)

  const inbox = useApiResource<NotificationPage>(
    (signal) =>
      api.get<NotificationPage>(
        '/notifications',
        { page, per_page: PER_PAGE, ...(unreadOnly ? { unread_only: '1' } : {}) },
        signal,
      ),
    [page, unreadOnly],
  )

  const items = useMemo(() => inbox.data?.items ?? [], [inbox.data])
  const unread = inbox.data?.unread ?? 0

  const markRead = useCallback(
    async (item: NotificationItem) => {
      if (item.is_read) return
      setBusy(item.id)
      try {
        await api.post(`/notifications/${item.id}/read`)
        // Re-read rather than patching the row in place: the count and the
        // list have to move together, and only the server knows both.
        inbox.reload()
      } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Could not mark that as read.')
      } finally {
        setBusy(null)
      }
    },
    [inbox, toast],
  )

  const markAllRead = useCallback(async () => {
    setBusy('all')
    try {
      const result = await api.post<{ read: number }>('/notifications/read-all')
      toast.success(
        result.read === 0 ? 'Nothing was unread.' : `Marked ${result.read} notification${result.read === 1 ? '' : 's'} as read.`,
      )
      inbox.reload()
    } catch (error) {
      toast.error(error instanceof Error ? error.message : 'Could not mark everything as read.')
    } finally {
      setBusy(null)
    }
  }, [inbox, toast])

  const open = useCallback(
    (item: NotificationItem) => {
      void markRead(item)
      if (item.link_path) navigate(item.link_path)
    },
    [markRead, navigate],
  )

  return (
    <div>
      <PageHeader
        title="Notifications"
        description="What the product needs you to know about, newest first."
        actions={
          <Button
            variant="secondary"
            icon={<CheckCheck size={15} />}
            onClick={markAllRead}
            loading={busy === 'all'}
            disabled={unread === 0}
          >
            Mark all read
          </Button>
        }
      />

      <Tabs
        items={[
          { id: 'unread', label: unread > 0 ? `Unread (${unread})` : 'Unread' },
          { id: 'all', label: 'Everything' },
        ]}
        active={unreadOnly ? 'unread' : 'all'}
        ariaLabel="Filter notifications"
        onChange={(id) => {
          setUnreadOnly(id === 'unread')
          setPage(1)
        }}
      />

      <Card style={{ marginTop: 14 }} padded={false}>
        {inbox.loading ? (
          <div style={{ padding: 18, display: 'grid', gap: 10 }}>
            {[0, 1, 2, 3, 4].map((i) => (
              <Skeleton key={i} height={58} />
            ))}
          </div>
        ) : inbox.error ? (
          <ErrorState
            title="Could not load your notifications"
            detail={inbox.error.message}
            onRetry={inbox.reload}
          />
        ) : items.length === 0 ? (
          <EmptyState
            icon={unreadOnly ? <BellOff size={22} /> : <Bell size={22} />}
            title={unreadOnly ? 'Nothing unread' : 'No notifications yet'}
            description={
              unreadOnly
                ? 'You are up to date. Anything the expiry, obligation, renewal and approval sweeps raise will appear here.'
                : 'Notifications are raised by the nightly sweeps and by approvals and signatures as they move. None has been raised for you yet.'
            }
            action={
              unreadOnly ? (
                <Button variant="secondary" onClick={() => setUnreadOnly(false)}>
                  Show everything
                </Button>
              ) : undefined
            }
          />
        ) : (
          <ul style={{ listStyle: 'none', margin: 0, padding: 0 }}>
            {items.map((item) => {
              const meta = SEVERITY[String(item.severity)] ?? SEVERITY.info
              const Icon = meta.icon
              const clickable = Boolean(item.link_path)

              return (
                <li
                  key={item.id}
                  style={{ borderTop: '1px solid rgb(var(--color-border))' }}
                >
                  <div
                    role={clickable ? 'button' : undefined}
                    tabIndex={clickable ? 0 : undefined}
                    onClick={clickable ? () => open(item) : undefined}
                    onKeyDown={
                      clickable
                        ? (event) => {
                            if (event.key === 'Enter' || event.key === ' ') {
                              event.preventDefault()
                              open(item)
                            }
                          }
                        : undefined
                    }
                    style={{
                      display: 'flex',
                      gap: 12,
                      padding: '14px 18px',
                      cursor: clickable ? 'pointer' : 'default',
                      background: item.is_read ? 'transparent' : 'var(--color-bg-subtle)',
                    }}
                  >
                    <span
                      aria-hidden
                      style={{
                        flexShrink: 0,
                        marginTop: 2,
                        color:
                          meta.tone === 'danger'
                            ? 'var(--color-danger)'
                            : meta.tone === 'warning'
                              ? 'var(--color-warning)'
                              : meta.tone === 'success'
                                ? 'var(--color-success)'
                                : 'var(--color-text-subtle)',
                      }}
                    >
                      <Icon size={17} />
                    </span>

                    <div style={{ minWidth: 0, flex: 1 }}>
                      <div style={{ display: 'flex', gap: 8, alignItems: 'baseline', flexWrap: 'wrap' }}>
                        <span
                          style={{
                            fontSize: 13.5,
                            fontWeight: item.is_read ? 500 : 600,
                            color: 'var(--color-text)',
                          }}
                        >
                          {item.title}
                        </span>
                        <Chip tone="neutral" size="sm">
                          {humanise(item.event_type)}
                        </Chip>
                        {!item.is_read ? (
                          <span className="ct-sr-only">Unread</span>
                        ) : null}
                      </div>

                      {item.body ? (
                        <p
                          style={{
                            fontSize: 12.5,
                            lineHeight: 1.55,
                            color: 'var(--color-text-secondary)',
                            margin: '4px 0 0',
                          }}
                        >
                          {item.body}
                        </p>
                      ) : null}

                      <div
                        style={{
                          fontSize: 11.5,
                          color: 'var(--color-text-muted)',
                          marginTop: 5,
                        }}
                      >
                        {formatDateTime(item.created_at)}
                        {item.contract_number ? ` · ${item.contract_number}` : ''}
                        {item.contract_title ? ` · ${item.contract_title}` : ''}
                      </div>
                    </div>

                    {!item.is_read ? (
                      <Button
                        variant="ghost"
                        size="sm"
                        loading={busy === item.id}
                        onClick={(event) => {
                          event.stopPropagation()
                          void markRead(item)
                        }}
                      >
                        Mark read
                      </Button>
                    ) : null}
                  </div>
                </li>
              )
            })}
          </ul>
        )}
      </Card>

      {inbox.data && (inbox.data.total ?? 0) > PER_PAGE ? (
        <Pagination
          page={page}
          perPage={PER_PAGE}
          total={inbox.data.total ?? 0}
          onPageChange={setPage}
        />
      ) : null}
    </div>
  )
}
