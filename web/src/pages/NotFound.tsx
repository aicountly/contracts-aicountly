import { useNavigate } from 'react-router-dom'
import { Compass, FileQuestion, LayoutDashboard } from 'lucide-react'

import { Button, Card } from '../components/ui'

/**
 * The 404.
 *
 * Deliberately short — it is the one page here that should not be padded. Two
 * ways out rather than one, because the two reasons anyone arrives are a stale
 * link to a record that was archived or renamed, and a URL that was never
 * right; the first wants search, the second wants the dashboard.
 */
export default function NotFound() {
  const navigate = useNavigate()

  return (
    <div style={{ display: 'flex', justifyContent: 'center', padding: '48px 16px' }}>
      <Card style={{ maxWidth: 520, width: '100%' }}>
        <div style={{ textAlign: 'center', padding: '40px 24px' }}>
          <div
            aria-hidden
            style={{
              display: 'inline-flex',
              alignItems: 'center',
              justifyContent: 'center',
              width: 52,
              height: 52,
              borderRadius: '50%',
              background: 'var(--color-bg-subtle)',
              color: 'rgb(var(--color-primary))',
              marginBottom: 16,
            }}
          >
            <FileQuestion size={24} />
          </div>

          <h1 style={{ fontSize: 18, fontWeight: 600, color: 'var(--color-text)', margin: 0 }}>
            This page does not exist
          </h1>

          <p
            style={{
              fontSize: 13.5,
              lineHeight: 1.6,
              color: 'var(--color-text-secondary)',
              margin: '10px auto 22px',
              maxWidth: 400,
            }}
          >
            The link may be out of date, or the record it pointed at may have been archived or
            renamed. Nothing has been lost — it is still reachable from the repository or from
            search.
          </p>

          <div style={{ display: 'flex', gap: 10, justifyContent: 'center', flexWrap: 'wrap' }}>
            <Button
              variant="primary"
              icon={<LayoutDashboard size={15} />}
              onClick={() => navigate('/')}
            >
              Go to dashboard
            </Button>
            <Button
              variant="secondary"
              icon={<Compass size={15} />}
              onClick={() => navigate('/search')}
            >
              Search contracts
            </Button>
          </div>
        </div>
      </Card>
    </div>
  )
}
