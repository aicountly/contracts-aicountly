import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { FileText, Scale, Search as SearchIcon, SearchX } from 'lucide-react'

import { Card, Chip, EmptyState, ErrorState, Input, PageHeader, Skeleton } from '../components/ui'
import { useApiResource } from '../hooks/useApiResource'
import { api } from '../services/apiClient'
import type {
  SearchClauseHit,
  SearchContractHit,
  SearchDocumentHit,
  SearchResults,
} from '../types/contracts'
import { humanise } from '../utils/format'

/**
 * Results for the topbar search box.
 *
 * Snippets arrive as plain text with no markup, and they are rendered as text.
 * The highlight is applied here by splitting the string on the term and
 * emitting React nodes — building HTML from a server string and handing it to
 * dangerouslySetInnerHTML is how an XSS reaches a page whose data came from a
 * counterparty's document.
 */

const MIN_TERM = 2
const DEBOUNCE_MS = 300

/** Split a snippet around every occurrence of the term, as nodes rather than markup. */
function highlight(text: string, term: string) {
  const needle = term.trim()
  if (needle.length < MIN_TERM) return text

  // The term is user input and reaches a regex, so it is escaped first.
  const pattern = new RegExp(`(${needle.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')})`, 'ig')

  return text.split(pattern).map((part, index) =>
    part.toLowerCase() === needle.toLowerCase() ? (
      <mark
        key={index}
        style={{ background: 'rgb(var(--color-primary) / 0.18)', color: 'inherit', padding: '0 1px' }}
      >
        {part}
      </mark>
    ) : (
      part
    ),
  )
}

function Snippet({ text, term }: { text?: string | null; term: string }) {
  if (!text) return null

  return (
    <p
      style={{
        fontSize: 12.5,
        lineHeight: 1.55,
        color: 'var(--color-text-secondary)',
        margin: '4px 0 0',
      }}
    >
      {highlight(text, term)}
    </p>
  )
}

function Section<T>({
  title,
  icon,
  hits,
  term,
  onOpen,
  render,
}: {
  title: string
  icon: React.ReactNode
  hits: T[]
  term: string
  onOpen: (hit: T) => void
  render: (hit: T) => React.ReactNode
}) {
  if (hits.length === 0) return null

  return (
    <section style={{ marginTop: 16 }}>
      <h2
        style={{
          display: 'flex',
          alignItems: 'center',
          gap: 7,
          fontSize: 12,
          fontWeight: 600,
          textTransform: 'uppercase',
          letterSpacing: '.04em',
          color: 'var(--color-text-muted)',
          margin: '0 0 8px',
        }}
      >
        <span aria-hidden>{icon}</span>
        {title}
        <Chip tone="neutral" size="sm">
          {hits.length}
        </Chip>
      </h2>

      <Card padded={false}>
        <ul style={{ listStyle: 'none', margin: 0, padding: 0 }}>
          {hits.map((hit, index) => (
            <li
              key={index}
              style={{ borderTop: index === 0 ? 'none' : '1px solid rgb(var(--color-border))' }}
            >
              <div
                role="button"
                tabIndex={0}
                onClick={() => onOpen(hit)}
                onKeyDown={(event) => {
                  if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault()
                    onOpen(hit)
                  }
                }}
                style={{ padding: '12px 16px', cursor: 'pointer' }}
              >
                {render(hit)}
                <Snippet text={(hit as { snippet?: string | null }).snippet} term={term} />
              </div>
            </li>
          ))}
        </ul>
      </Card>
    </section>
  )
}

export default function GlobalSearch() {
  const navigate = useNavigate()
  const [params, setParams] = useSearchParams()

  const initial = params.get('q') ?? ''
  const [draft, setDraft] = useState(initial)
  const [term, setTerm] = useState(initial)
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null)

  // The box is debounced, but the URL is what the request keys off, so a
  // pasted or bookmarked ?q= searches immediately without a keystroke.
  useEffect(() => {
    if (timer.current) clearTimeout(timer.current)
    timer.current = setTimeout(() => {
      setTerm(draft)
      setParams(draft.trim() === '' ? {} : { q: draft }, { replace: true })
    }, DEBOUNCE_MS)

    return () => {
      if (timer.current) clearTimeout(timer.current)
    }
  }, [draft, setParams])

  const ready = term.trim().length >= MIN_TERM

  const results = useApiResource<SearchResults>(
    (signal) => api.get<SearchResults>('/search', { q: term.trim(), limit: 20 }, signal),
    [term],
    { enabled: ready },
  )

  const contracts = useMemo(() => results.data?.contracts ?? [], [results.data])
  const clauses = useMemo(() => results.data?.clauses ?? [], [results.data])
  const documents = useMemo(() => results.data?.documents ?? [], [results.data])
  const total = contracts.length + clauses.length + documents.length

  const open = useCallback((path: string) => navigate(path), [navigate])

  return (
    <div>
      <PageHeader
        title="Search"
        description="Across contracts, clause wording and the text inside uploaded documents."
      />

      <Input
        label="Search contracts, clauses and documents"
        value={draft}
        onChange={(event) => setDraft(event.target.value)}
        placeholder="Contract number, counterparty, a phrase from a clause…"
        autoFocus
      />

      {!ready ? (
        <Card style={{ marginTop: 16 }}>
          <EmptyState
            icon={<SearchIcon size={22} />}
            title="Type at least two characters"
            description="Search covers contract numbers, titles, counterparties and tags; the standard clause library and the clauses standing in each contract; and the extracted text of every uploaded document."
          />
        </Card>
      ) : results.loading ? (
        <div style={{ marginTop: 16, display: 'grid', gap: 10 }}>
          {[0, 1, 2].map((i) => (
            <Skeleton key={i} height={72} />
          ))}
        </div>
      ) : results.error ? (
        <Card style={{ marginTop: 16 }}>
          <ErrorState title="Search failed" detail={results.error.message} onRetry={results.reload} />
        </Card>
      ) : total === 0 ? (
        <Card style={{ marginTop: 16 }}>
          <EmptyState
            icon={<SearchX size={22} />}
            title={`Nothing matches “${term.trim()}”`}
            description="Try a shorter phrase, the counterparty's name, or a contract number. Documents are searchable only once their text has been extracted, which happens shortly after upload."
          />
        </Card>
      ) : (
        <>
          <p style={{ fontSize: 12.5, color: 'var(--color-text-muted)', margin: '14px 0 0' }}>
            {total} result{total === 1 ? '' : 's'} for “{term.trim()}”
          </p>

          <Section<SearchContractHit>
            title="Contracts"
            icon={<FileText size={13} />}
            hits={contracts}
            term={term}
            onOpen={(hit) => open(hit.link_path)}
            render={(hit) => (
              <>
                <div style={{ display: 'flex', gap: 8, alignItems: 'baseline', flexWrap: 'wrap' }}>
                  <span style={{ fontSize: 13.5, fontWeight: 600, color: 'var(--color-text)' }}>
                    {highlight(hit.title, term)}
                  </span>
                  {hit.status ? (
                    <Chip tone="neutral" size="sm">
                      {humanise(hit.status)}
                    </Chip>
                  ) : null}
                </div>
                <div style={{ fontSize: 11.5, color: 'var(--color-text-muted)', marginTop: 2 }}>
                  {hit.contract_number ?? '—'}
                  {hit.counterparty_name ? ` · ${hit.counterparty_name}` : ''}
                  {hit.contract_type_name ? ` · ${hit.contract_type_name}` : ''}
                </div>
              </>
            )}
          />

          <Section<SearchClauseHit>
            title="Clauses"
            icon={<Scale size={13} />}
            hits={clauses}
            term={term}
            onOpen={(hit) => open(hit.link_path)}
            render={(hit) => (
              <>
                <div style={{ display: 'flex', gap: 8, alignItems: 'baseline', flexWrap: 'wrap' }}>
                  <span style={{ fontSize: 13.5, fontWeight: 600, color: 'var(--color-text)' }}>
                    {highlight(hit.heading ?? 'Untitled clause', term)}
                  </span>
                  {/* Which of the two this came from is the thing the reader
                      needs: the company standard, or the wording that actually
                      ended up in one contract. */}
                  <Chip tone={hit.source === 'library' ? 'neutral' : 'warning'} size="sm">
                    {hit.source === 'library' ? 'Standard wording' : 'In a contract'}
                  </Chip>
                </div>
                <div style={{ fontSize: 11.5, color: 'var(--color-text-muted)', marginTop: 2 }}>
                  {hit.category_name ?? 'Uncategorised'}
                  {hit.contract_number ? ` · ${hit.contract_number}` : ''}
                </div>
              </>
            )}
          />

          <Section<SearchDocumentHit>
            title="Documents"
            icon={<FileText size={13} />}
            hits={documents}
            term={term}
            onOpen={(hit) => open(hit.link_path)}
            render={(hit) => (
              <>
                <span style={{ fontSize: 13.5, fontWeight: 600, color: 'var(--color-text)' }}>
                  {highlight(hit.document_title ?? hit.filename ?? 'Untitled document', term)}
                </span>
                <div style={{ fontSize: 11.5, color: 'var(--color-text-muted)', marginTop: 2 }}>
                  {hit.contract_number ?? '—'}
                  {hit.contract_title ? ` · ${hit.contract_title}` : ''}
                </div>
              </>
            )}
          />
        </>
      )}
    </div>
  )
}
