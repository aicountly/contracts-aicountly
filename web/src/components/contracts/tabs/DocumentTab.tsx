import { useEffect, useMemo, useState } from 'react'
import {
  Download,
  ExternalLink,
  FileText,
  FileUp,
  ShieldCheck,
  X,
} from 'lucide-react'

import { Button, Card, CardHeader, Chip, EmptyState, ErrorState, Skeleton, StatusChip } from '../../ui'
import { DocumentUploader } from '../DocumentUploader'
import { useSession } from '../../../context/SessionProvider'
import { useToast } from '../../../context/ToastProvider'
import { useApiResource } from '../../../hooks/useApiResource'
import { api } from '../../../services/apiClient'
import { PERMISSION } from '../../../types/permissions'
import type { Contract, ContractDocument, DocumentVersion, VersionUrl } from '../../../types/contracts'
import { formatDateTime, humanise } from '../../../utils/format'

/**
 * The contract as a document.
 *
 * The file itself is what most people came for, so the preview takes the space
 * and everything else sits beside it. The preview is an iframe over a
 * short-lived signed URL rather than a PDF renderer bundled into the app: the
 * browser already has one, it handles Word and images too, and a 60 MB scan
 * would otherwise have to travel through JavaScript before anyone saw a page.
 *
 * The link out and the download stay visible whether or not the frame renders,
 * because a browser that refuses to display a content type inline gives no
 * error anyone can see — it just shows nothing.
 */

interface Props {
  contractId: number
  contract: Contract
  onChanged: () => void
  /** Set by the workspace when the header's "upload executed copy" was used. */
  uploadIntent?: 'executed' | 'version' | null
  onUploadIntentHandled?: () => void
}

const EXECUTED_STATUSES = new Set(['executed', 'signed'])

function byRecency(a: DocumentVersion, b: DocumentVersion): number {
  if (a.version_number !== b.version_number) return b.version_number - a.version_number
  return (b.created_at ?? '').localeCompare(a.created_at ?? '')
}

function allVersions(documents: ContractDocument[]): DocumentVersion[] {
  return documents.flatMap((document) => document.versions ?? [])
}

/** The version a reader means by "the contract": current, else executed, else newest. */
function pickPrimary(documents: ContractDocument[]): DocumentVersion | null {
  const versions = allVersions(documents)
  if (versions.length === 0) return null

  return (
    versions.find((version) => version.is_current) ??
    versions.find((version) => EXECUTED_STATUSES.has((version.status ?? '').toLowerCase())) ??
    [...versions].sort(byRecency)[0] ??
    null
  )
}

function sizeLabel(bytes: number | null): string | null {
  if (bytes === null || bytes === undefined) return null
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

export function DocumentTab({
  contractId,
  contract,
  onChanged,
  uploadIntent = null,
  onUploadIntentHandled,
}: Props) {
  const toast = useToast()
  const { can } = useSession()

  const canUpload = can(PERMISSION.DOCUMENT_UPLOAD)
  const canDownload = can(PERMISSION.DOCUMENT_DOWNLOAD)
  const canMarkExecuted = can(PERMISSION.CONTRACT_EDIT)

  const [selectedId, setSelectedId] = useState<number | null>(null)
  const [uploaderOpen, setUploaderOpen] = useState(false)
  const [executedFor, setExecutedFor] = useState<number | null>(null)

  const documents = useApiResource<ContractDocument[]>(
    (signal) => api.get<ContractDocument[]>(`/contracts/${contractId}/documents`, undefined, signal),
    [contractId],
  )

  const list = useMemo(() => documents.data ?? [], [documents.data])
  const versions = useMemo(() => allVersions(list), [list])

  const selected = useMemo(
    () => versions.find((version) => version.id === selectedId) ?? pickPrimary(list),
    [versions, selectedId, list],
  )

  const preview = useApiResource<VersionUrl>(
    (signal) =>
      api.get<VersionUrl>(`/versions/${selected?.id}/url`, { inline: 1 }, signal),
    [selected?.id],
    { enabled: selected != null },
  )

  useEffect(() => {
    if (!uploadIntent) return
    setUploaderOpen(true)
    onUploadIntentHandled?.()
  }, [uploadIntent, onUploadIntentHandled])

  const markExecuted = async (version: DocumentVersion) => {
    setExecutedFor(version.id)
    try {
      await api.post<{ version: DocumentVersion }>(`/versions/${version.id}/executed`)
      toast.success('Marked as the executed copy', `Version ${version.version_number} is now the signed original.`)
      documents.reload()
      onChanged()
    } catch (err) {
      toast.error('Could not mark that version', err instanceof Error ? err.message : undefined)
    } finally {
      setExecutedFor(null)
    }
  }

  const documentOf = (version: DocumentVersion): ContractDocument | undefined =>
    list.find((document) => document.id === version.document_id)

  if (documents.loading) {
    return (
      <div className="ct-doc-grid">
        <DocumentStyles />
        <div style={{ display: 'grid', gap: 10 }} role="status" aria-label="Loading documents">
          <Skeleton height={38} />
          <Skeleton height={38} />
          <Skeleton height={38} />
        </div>
        <Skeleton height={420} radius={14} />
      </div>
    )
  }

  if (documents.error) {
    return (
      <Card>
        <ErrorState
          title="Could not load the documents"
          detail={documents.error.message}
          onRetry={documents.reload}
        />
      </Card>
    )
  }

  if (versions.length === 0) {
    return (
      <Card>
        {uploaderOpen && canUpload ? (
          <>
            <CardHeader
              level={3}
              title="Upload the contract document"
              description="The first version becomes the working copy everyone reads."
              action={
                <Button variant="ghost" size="sm" icon={<X size={14} />} onClick={() => setUploaderOpen(false)}>
                  Cancel
                </Button>
              }
            />
            <DocumentUploader
              contractId={contractId}
              defaultDocKind={uploadIntent === 'executed' ? 'signed_copy' : 'contract'}
              defaultVersionStatus={uploadIntent === 'executed' ? 'executed' : 'draft'}
              onUploaded={(result) => {
                setUploaderOpen(false)
                setSelectedId(result.version.id)
                documents.reload()
                onChanged()
                toast.success('Document uploaded', `Version ${result.version.version_number} is now on the contract.`)
              }}
              onCancel={() => setUploaderOpen(false)}
            />
          </>
        ) : (
          <EmptyState
            icon={<FileText size={22} />}
            title="No document yet"
            description={
              canUpload
                ? 'The signed agreement, the draft under negotiation, the annexures — every file for this contract lives here, with a version history behind it.'
                : 'Nothing has been uploaded for this contract yet. Someone with upload rights can add the agreement here.'
            }
            action={
              canUpload ? (
                <Button variant="primary" icon={<FileUp size={15} />} onClick={() => setUploaderOpen(true)}>
                  Upload a document
                </Button>
              ) : undefined
            }
          />
        )}
      </Card>
    )
  }

  const previewUrl = preview.data?.url ?? null
  const selectedExecuted = selected ? EXECUTED_STATUSES.has((selected.status ?? '').toLowerCase()) : false

  return (
    <div className="ct-doc-grid">
      <DocumentStyles />

      <div style={{ display: 'grid', gap: 12, minWidth: 0 }}>
        <Card padded={false}>
          <div style={{ padding: '12px 14px', borderBottom: '1px solid rgb(var(--color-border))' }}>
            <h3 style={{ fontSize: 13.5, fontWeight: 700 }}>Files</h3>
            <p style={{ fontSize: 11.5, color: 'var(--color-text-muted)', marginTop: 2 }}>
              {contract.tabs.documents} {contract.tabs.documents === 1 ? 'document' : 'documents'} ·{' '}
              {versions.length} {versions.length === 1 ? 'version' : 'versions'}
            </p>
          </div>

          <div style={{ maxHeight: 420, overflowY: 'auto' }}>
            {list.map((document) => (
              <section key={document.id} aria-label={document.title ?? 'Document'}>
                <h4
                  style={{
                    display: 'flex',
                    alignItems: 'center',
                    gap: 6,
                    padding: '9px 14px 5px',
                    fontSize: 11,
                    fontWeight: 700,
                    textTransform: 'uppercase',
                    letterSpacing: '.03em',
                    color: 'var(--color-text-muted)',
                  }}
                >
                  {document.title ?? 'Untitled document'}
                  {document.doc_kind ? (
                    <Chip size="sm" tone="neutral">
                      {humanise(document.doc_kind)}
                    </Chip>
                  ) : null}
                </h4>

                <ul style={{ listStyle: 'none' }}>
                  {[...(document.versions ?? [])].sort(byRecency).map((version) => {
                    const active = selected?.id === version.id
                    return (
                      <li key={version.id}>
                        <button
                          type="button"
                          aria-current={active ? 'true' : undefined}
                          onClick={() => setSelectedId(version.id)}
                          style={{
                            display: 'flex',
                            alignItems: 'center',
                            gap: 8,
                            width: '100%',
                            textAlign: 'left',
                            padding: '8px 14px',
                            background: active ? 'var(--color-primary-muted)' : 'transparent',
                            border: 'none',
                            borderLeft: `2px solid ${active ? 'rgb(var(--color-primary))' : 'transparent'}`,
                            cursor: 'pointer',
                            fontSize: 12.5,
                          }}
                        >
                          <span style={{ fontWeight: 700, color: 'var(--color-text)' }}>
                            v{version.version_number}
                          </span>
                          <span style={{ flex: 1, minWidth: 0, color: 'var(--color-text-secondary)' }}>
                            <span
                              style={{
                                display: 'block',
                                overflow: 'hidden',
                                textOverflow: 'ellipsis',
                                whiteSpace: 'nowrap',
                              }}
                            >
                              {version.filename ?? 'File'}
                            </span>
                            <span style={{ fontSize: 11, color: 'var(--color-text-muted)' }}>
                              {formatDateTime(version.created_at)}
                            </span>
                          </span>
                          {version.status ? <StatusChip status={version.status} size="sm" /> : null}
                        </button>
                      </li>
                    )
                  })}
                </ul>
              </section>
            ))}
          </div>

          {canUpload ? (
            <div style={{ padding: 12, borderTop: '1px solid rgb(var(--color-border))' }}>
              <Button
                block
                variant="secondary"
                icon={<FileUp size={14} />}
                onClick={() => setUploaderOpen((open) => !open)}
                aria-expanded={uploaderOpen}
              >
                {uploaderOpen ? 'Close uploader' : 'Upload a new version'}
              </Button>
            </div>
          ) : null}
        </Card>

        {uploaderOpen && canUpload ? (
          <Card>
            <CardHeader
              level={3}
              title="New version"
              description="Adds to the history — nothing is overwritten."
            />
            <DocumentUploader
              contractId={contractId}
              documentId={selected ? (documentOf(selected)?.id ?? null) : null}
              defaultDocKind={uploadIntent === 'executed' ? 'signed_copy' : 'contract'}
              defaultVersionStatus={uploadIntent === 'executed' ? 'executed' : 'draft'}
              onUploaded={(result) => {
                setUploaderOpen(false)
                setSelectedId(result.version.id)
                documents.reload()
                onChanged()
                toast.success('Version uploaded', `Version ${result.version.version_number} added.`)
              }}
              onCancel={() => setUploaderOpen(false)}
            />
          </Card>
        ) : null}
      </div>

      <Card padded={false} style={{ minWidth: 0 }}>
        <div
          style={{
            display: 'flex',
            alignItems: 'center',
            gap: 10,
            flexWrap: 'wrap',
            padding: '11px 14px',
            borderBottom: '1px solid rgb(var(--color-border))',
          }}
        >
          <div style={{ minWidth: 0, flex: '1 1 200px' }}>
            <h3
              style={{
                fontSize: 13.5,
                fontWeight: 700,
                overflow: 'hidden',
                textOverflow: 'ellipsis',
                whiteSpace: 'nowrap',
              }}
            >
              {selected?.filename ?? 'Document'}
            </h3>
            <p style={{ fontSize: 11.5, color: 'var(--color-text-muted)', marginTop: 2 }}>
              Version {selected?.version_number} ·{' '}
              {selected?.uploaded_by_name ?? selected?.uploaded_by ?? 'Unknown uploader'} ·{' '}
              {formatDateTime(selected?.created_at)}
              {sizeLabel(selected?.size_bytes ?? null) ? ` · ${sizeLabel(selected?.size_bytes ?? null)}` : ''}
            </p>
          </div>

          {selected?.status ? <StatusChip status={selected.status} size="sm" /> : null}

          {selected && canMarkExecuted && !selectedExecuted ? (
            <Button
              size="sm"
              variant="secondary"
              icon={<ShieldCheck size={13} />}
              loading={executedFor === selected.id}
              onClick={() => void markExecuted(selected)}
            >
              Mark as executed
            </Button>
          ) : null}

          {previewUrl ? (
            <>
              <a
                href={previewUrl}
                target="_blank"
                rel="noopener noreferrer"
                style={linkButtonStyle}
              >
                <ExternalLink size={13} aria-hidden />
                Open
              </a>
              {canDownload ? (
                <a
                  href={previewUrl}
                  download={selected?.filename ?? undefined}
                  style={linkButtonStyle}
                >
                  <Download size={13} aria-hidden />
                  Download
                </a>
              ) : null}
            </>
          ) : null}
        </div>

        {preview.loading ? (
          <div style={{ padding: 16 }} role="status" aria-label="Loading preview">
            <Skeleton height={460} radius={10} />
          </div>
        ) : preview.error ? (
          <ErrorState
            title="Could not open this file"
            detail={preview.error.message}
            onRetry={preview.reload}
          />
        ) : previewUrl ? (
          <div style={{ background: 'var(--color-bg-inset)' }}>
            <iframe
              key={previewUrl}
              title={`Preview of ${selected?.filename ?? 'the contract document'}`}
              src={previewUrl}
              style={{ width: '100%', height: 'min(72vh, 760px)', border: 'none', display: 'block' }}
            />
            <p style={{ fontSize: 11.5, color: 'var(--color-text-muted)', padding: '8px 14px' }}>
              Nothing showing? Some file types cannot be displayed in a page — use Open to view it in a
              new tab.
            </p>
          </div>
        ) : (
          <EmptyState compact title="Select a version to preview it" />
        )}
      </Card>
    </div>
  )
}

const linkButtonStyle = {
  display: 'inline-flex',
  alignItems: 'center',
  gap: 6,
  height: 30,
  padding: '0 10px',
  borderRadius: 'var(--radius-md)',
  border: '1px solid rgb(var(--color-border-strong))',
  background: 'var(--color-bg-card)',
  color: 'var(--color-text)',
  fontSize: 12.5,
  fontWeight: 600,
} as const

function DocumentStyles() {
  return (
    <style>{`
      .ct-doc-grid { display: grid; gap: 16px; grid-template-columns: minmax(0, 320px) minmax(0, 1fr); align-items: start; }
      @media (max-width: 900px) { .ct-doc-grid { grid-template-columns: minmax(0, 1fr); } }
    `}</style>
  )
}
