import { useEffect, useMemo, useState } from 'react'
import { Check, Copy, FileDiff, GitCompare, History } from 'lucide-react'

import { Button, Card, DataTable, EmptyState, ErrorState, StatusChip } from '../../ui'
import type { Column } from '../../ui'
import { VersionDiffViewer } from '../VersionDiffViewer'
import { useToast } from '../../../context/ToastProvider'
import { useApiResource } from '../../../hooks/useApiResource'
import { api } from '../../../services/apiClient'
import type { Contract, ContractDocument, DocumentVersion } from '../../../types/contracts'
import { formatDateTime, truncate } from '../../../utils/format'

/**
 * Every version of every document on this contract, and the diff between two.
 *
 * The checksum is shown rather than hidden behind a tooltip: it is how someone
 * proves the file they downloaded last March is the file that is here now, and
 * a value you cannot copy is a value you cannot check.
 */

interface Props {
  contractId: number
  contract: Contract
  onChanged: () => void
}

interface VersionRow extends DocumentVersion {
  document_title: string
}

function flatten(documents: ContractDocument[]): VersionRow[] {
  return documents
    .flatMap((document) =>
      (document.versions ?? []).map((version) => ({
        ...version,
        document_title: document.title ?? 'Untitled document',
      })),
    )
    .sort((a, b) => {
      if (a.version_number !== b.version_number) return b.version_number - a.version_number
      return (b.created_at ?? '').localeCompare(a.created_at ?? '')
    })
}

export function VersionsTab({ contractId }: Props) {
  const toast = useToast()

  const [base, setBase] = useState<number | null>(null)
  const [target, setTarget] = useState<number | null>(null)
  const [comparing, setComparing] = useState<{ base: number; target: number } | null>(null)
  const [copied, setCopied] = useState<number | null>(null)

  const documents = useApiResource<ContractDocument[]>(
    (signal) => api.get<ContractDocument[]>(`/contracts/${contractId}/documents`, undefined, signal),
    [contractId],
  )

  const rows = useMemo(() => flatten(documents.data ?? []), [documents.data])

  // Default the comparison to the two most recent versions — the pair anyone
  // opening this tab is most likely to want.
  useEffect(() => {
    if (rows.length < 2) return
    setBase((current) => current ?? rows[1].id)
    setTarget((current) => current ?? rows[0].id)
  }, [rows])

  const labelFor = (id: number | null): string | undefined => {
    const row = rows.find((item) => item.id === id)
    return row ? `v${row.version_number}` : undefined
  }

  const copyChecksum = async (row: VersionRow) => {
    if (!row.checksum) return
    try {
      await navigator.clipboard.writeText(row.checksum)
      setCopied(row.id)
      window.setTimeout(() => setCopied((current) => (current === row.id ? null : current)), 2000)
    } catch {
      toast.info('Copy the checksum manually', row.checksum)
    }
  }

  const columns: Column<VersionRow>[] = [
    {
      key: 'version',
      header: 'Version',
      render: (row) => (
        <div style={{ minWidth: 150 }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
            <span style={{ fontWeight: 700 }}>v{row.version_number}</span>
            {row.is_current ? <StatusChip status="active" size="sm" /> : null}
          </div>
          <div style={{ fontSize: 11.5, color: 'var(--color-text-muted)', marginTop: 2 }}>
            {row.document_title}
            {row.filename ? ` · ${truncate(row.filename, 34)}` : ''}
          </div>
        </div>
      ),
    },
    {
      key: 'status',
      header: 'Status',
      render: (row) => (row.status ? <StatusChip status={row.status} size="sm" /> : '—'),
    },
    {
      key: 'uploader',
      header: 'Uploaded by',
      hideBelow: 'sm',
      render: (row) => row.uploaded_by_name ?? row.uploaded_by ?? '—',
    },
    {
      key: 'created',
      header: 'Uploaded',
      hideBelow: 'sm',
      render: (row) => formatDateTime(row.created_at),
    },
    {
      key: 'checksum',
      header: 'Checksum',
      hideBelow: 'lg',
      render: (row) =>
        row.checksum ? (
          <button
            type="button"
            onClick={() => void copyChecksum(row)}
            aria-label={`Copy the checksum of version ${row.version_number}`}
            style={{
              display: 'inline-flex',
              alignItems: 'center',
              gap: 5,
              background: 'none',
              border: 'none',
              padding: 0,
              cursor: 'pointer',
              fontSize: 11.5,
              fontFamily: 'ui-monospace, SFMono-Regular, Menlo, monospace',
              color: 'var(--color-text-secondary)',
            }}
          >
            {truncate(row.checksum, 14)}
            {copied === row.id ? (
              <Check size={12} aria-hidden style={{ color: 'var(--color-success)' }} />
            ) : (
              <Copy size={12} aria-hidden />
            )}
          </button>
        ) : (
          <span style={{ color: 'var(--color-text-subtle)' }}>—</span>
        ),
    },
    {
      key: 'compare',
      header: '',
      srLabel: 'Comparison selection',
      align: 'right',
      width: 170,
      render: (row) => (
        <div style={{ display: 'inline-flex', gap: 4 }}>
          <Button
            size="sm"
            variant={base === row.id ? 'primary' : 'ghost'}
            aria-pressed={base === row.id}
            onClick={() => setBase(row.id)}
          >
            Base
          </Button>
          <Button
            size="sm"
            variant={target === row.id ? 'primary' : 'ghost'}
            aria-pressed={target === row.id}
            onClick={() => setTarget(row.id)}
          >
            Compare
          </Button>
        </div>
      ),
    },
  ]

  if (documents.error) {
    return (
      <Card>
        <ErrorState
          title="Could not load the version history"
          detail={documents.error.message}
          onRetry={documents.reload}
        />
      </Card>
    )
  }

  if (!documents.loading && rows.length === 0) {
    return (
      <Card>
        <EmptyState
          icon={<History size={22} />}
          title="No versions yet"
          description="Every upload of the contract document is kept here with its uploader, date and checksum, so you can see what changed and when. Upload the first file from the Document tab."
        />
      </Card>
    )
  }

  const sameVersion = base !== null && base === target
  const canCompare = base !== null && target !== null && !sameVersion

  return (
    <div style={{ display: 'grid', gap: 16 }}>
      <Card padded={false}>
        <div
          style={{
            display: 'flex',
            alignItems: 'center',
            gap: 10,
            flexWrap: 'wrap',
            padding: '12px 14px',
            borderBottom: '1px solid rgb(var(--color-border))',
          }}
        >
          <div style={{ minWidth: 0, flex: '1 1 220px' }}>
            <h3 style={{ fontSize: 14, fontWeight: 700 }}>Version history</h3>
            <p style={{ fontSize: 12.5, color: 'var(--color-text-secondary)', marginTop: 2 }}>
              {rows.length} {rows.length === 1 ? 'version' : 'versions'} across{' '}
              {(documents.data ?? []).length}{' '}
              {(documents.data ?? []).length === 1 ? 'document' : 'documents'}.
            </p>
          </div>

          <p style={{ fontSize: 12.5, color: 'var(--color-text-secondary)' }} aria-live="polite">
            {sameVersion
              ? 'Choose two different versions to compare.'
              : canCompare
                ? `Comparing ${labelFor(base)} with ${labelFor(target)}`
                : 'Pick a base and a version to compare it with.'}
          </p>

          <Button
            variant="primary"
            size="sm"
            icon={<GitCompare size={14} />}
            disabled={!canCompare}
            onClick={() => {
              if (base !== null && target !== null) setComparing({ base, target })
            }}
          >
            Compare versions
          </Button>
        </div>

        <DataTable
          columns={columns}
          rows={rows}
          rowKey={(row) => row.id}
          loading={documents.loading}
          caption="Document versions on this contract"
        />
      </Card>

      {comparing ? (
        <VersionDiffViewer
          contractId={contractId}
          base={comparing.base}
          target={comparing.target}
          baseLabel={labelFor(comparing.base)}
          targetLabel={labelFor(comparing.target)}
          onClose={() => setComparing(null)}
        />
      ) : rows.length > 1 ? (
        <Card>
          <EmptyState
            compact
            icon={<FileDiff size={20} />}
            title="Compare two versions"
            description="Choose a base and a version to compare it with, then run the comparison to see a redline with the material changes called out."
          />
        </Card>
      ) : null}
    </div>
  )
}
