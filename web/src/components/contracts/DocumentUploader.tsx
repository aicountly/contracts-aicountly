import { useId, useRef, useState } from 'react'
import { FileUp, Paperclip, X } from 'lucide-react'

import { Button, Field, Select, Textarea } from '../ui'
import { ApiError, api } from '../../services/apiClient'
import type { FieldErrors } from '../../services/apiClient'
import type { UploadResult, UploadSession } from '../../types/contracts'
import { formatNumber } from '../../utils/format'

/**
 * Upload a new document version.
 *
 * The upload is a four-step conversation with the API — create a session, PUT
 * the bytes to the URL it hands back, tell it the bytes arrived, then finalize
 * into a version row. It is spelled out here rather than hidden behind a single
 * call because each step can fail differently: a rejected session is a
 * validation problem the user can fix, a failed PUT is the storage provider,
 * and a failed finalize leaves an orphan session that has to be aborted.
 *
 * The PUT goes through XMLHttpRequest rather than fetch for one reason: fetch
 * cannot report upload progress, and a 40 MB scanned agreement over an office
 * connection is a minute of a progress bar that would otherwise be a lie.
 */

export const DOC_KINDS: { value: string; label: string }[] = [
  { value: 'contract', label: 'Contract document' },
  { value: 'signed_copy', label: 'Executed copy' },
  { value: 'amendment', label: 'Amendment' },
  { value: 'annexure', label: 'Annexure or schedule' },
  { value: 'supporting', label: 'Supporting document' },
  { value: 'correspondence', label: 'Correspondence' },
]

export const VERSION_STATUSES: { value: string; label: string }[] = [
  { value: 'draft', label: 'Draft' },
  { value: 'review', label: 'Under review' },
  { value: 'final', label: 'Final' },
  { value: 'executed', label: 'Executed' },
]

type Stage = 'idle' | 'preparing' | 'uploading' | 'completing' | 'finalizing'

const STAGE_LABEL: Record<Exclude<Stage, 'idle'>, string> = {
  preparing: 'Preparing the upload…',
  uploading: 'Sending the file…',
  completing: 'Confirming the transfer…',
  finalizing: 'Recording the version…',
}

function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

function hasHeader(headers: Record<string, string>, name: string): boolean {
  return Object.keys(headers).some((key) => key.toLowerCase() === name.toLowerCase())
}

/** PUT the file to the storage URL the session gave us, reporting real progress. */
function sendFile(
  session: UploadSession,
  file: File,
  onProgress: (fraction: number) => void,
  register: (xhr: XMLHttpRequest | null) => void,
): Promise<void> {
  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest()
    register(xhr)

    xhr.open(session.method || 'PUT', session.upload_url, true)

    const headers = { ...(session.headers ?? {}) }
    if (file.type && !hasHeader(headers, 'content-type')) headers['Content-Type'] = file.type
    for (const [key, value] of Object.entries(headers)) xhr.setRequestHeader(key, value)

    xhr.upload.onprogress = (event) => {
      if (event.lengthComputable) onProgress(event.loaded / event.total)
    }
    xhr.onload = () => {
      register(null)
      if (xhr.status >= 200 && xhr.status < 300) {
        onProgress(1)
        resolve()
        return
      }
      reject(new Error(`Storage refused the file (HTTP ${xhr.status}).`))
    }
    xhr.onerror = () => {
      register(null)
      reject(new Error('The file could not be sent to storage. Check your connection and try again.'))
    }
    xhr.onabort = () => {
      register(null)
      reject(new DOMException('Upload cancelled', 'AbortError'))
    }

    xhr.send(file)
  })
}

export function DocumentUploader({
  contractId,
  documentId = null,
  defaultDocKind = 'contract',
  defaultVersionStatus = 'draft',
  onUploaded,
  onCancel,
}: {
  contractId: number
  /** Set to add a version to an existing document rather than starting a new one. */
  documentId?: number | null
  defaultDocKind?: string
  defaultVersionStatus?: string
  onUploaded: (result: UploadResult) => void
  onCancel?: () => void
}) {
  const fileInputId = useId()
  const fileInputRef = useRef<HTMLInputElement>(null)
  const xhrRef = useRef<XMLHttpRequest | null>(null)

  const [file, setFile] = useState<File | null>(null)
  const [docKind, setDocKind] = useState(defaultDocKind)
  const [versionStatus, setVersionStatus] = useState(defaultVersionStatus)
  const [notes, setNotes] = useState('')
  const [dragging, setDragging] = useState(false)
  const [stage, setStage] = useState<Stage>('idle')
  const [progress, setProgress] = useState(0)
  const [errors, setErrors] = useState<FieldErrors>({})
  const [failure, setFailure] = useState<string | null>(null)

  const busy = stage !== 'idle'

  const chooseFile = (next: File | null) => {
    setFile(next)
    setErrors((current) => ({ ...current, file: '', filename: '', size_bytes: '' }))
    setFailure(null)
  }

  const cancelUpload = () => {
    xhrRef.current?.abort()
    xhrRef.current = null
  }

  const upload = async () => {
    if (!file) {
      setErrors({ file: 'Choose a file to upload.' })
      fileInputRef.current?.focus()
      return
    }

    setErrors({})
    setFailure(null)
    setProgress(0)
    setStage('preparing')

    let session: UploadSession | null = null

    try {
      session = await api.post<UploadSession>('/uploads/sessions', {
        contract_id: contractId,
        document_id: documentId ?? undefined,
        filename: file.name,
        content_type: file.type || 'application/octet-stream',
        size_bytes: file.size,
        doc_kind: docKind,
        version_status: versionStatus,
      })

      setStage('uploading')
      await sendFile(session, file, setProgress, (xhr) => {
        xhrRef.current = xhr
      })

      setStage('completing')
      await api.post(`/uploads/sessions/${session.session_id}/complete`)

      setStage('finalizing')
      const result = await api.post<UploadResult>(`/uploads/sessions/${session.session_id}/finalize`, {
        notes: notes.trim() || null,
        version_status: versionStatus,
      })

      setStage('idle')
      setFile(null)
      setNotes('')
      setProgress(0)
      if (fileInputRef.current) fileInputRef.current.value = ''
      onUploaded(result)
    } catch (err) {
      setStage('idle')
      setProgress(0)

      // A session that was opened but never finished holds a storage slot the
      // server has to clean up on a timer; telling it now is cheap and keeps
      // the contract's document list free of half-finished versions.
      if (session) {
        void api.post(`/uploads/sessions/${session.session_id}/abort`).catch(() => undefined)
      }

      if (err instanceof DOMException && err.name === 'AbortError') {
        setFailure('Upload cancelled.')
        return
      }
      if (err instanceof ApiError && err.isValidation) {
        setErrors(err.fieldErrors)
        return
      }
      setFailure(err instanceof Error ? err.message : 'The upload could not be completed.')
    }
  }

  const percent = Math.round(progress * 100)

  return (
    <div style={{ display: 'grid', gap: 14 }}>
      <Field
        label="File"
        htmlFor={fileInputId}
        required
        error={errors.file || errors.filename || errors.size_bytes}
        hint={file ? undefined : 'PDF, Word or an image of a signed page. Up to the size your plan allows.'}
        describedById={`${fileInputId}-desc`}
      >
        <div
          onDragOver={(event) => {
            event.preventDefault()
            if (!busy) setDragging(true)
          }}
          onDragLeave={() => setDragging(false)}
          onDrop={(event) => {
            event.preventDefault()
            setDragging(false)
            if (busy) return
            const dropped = event.dataTransfer.files?.[0]
            if (dropped) chooseFile(dropped)
          }}
          style={{
            display: 'flex',
            alignItems: 'center',
            gap: 12,
            padding: 14,
            borderRadius: 'var(--radius-md)',
            border: `1px dashed ${
              dragging ? 'rgb(var(--color-primary))' : 'rgb(var(--color-border-strong))'
            }`,
            background: dragging ? 'var(--color-primary-muted)' : 'var(--color-bg-subtle)',
          }}
        >
          <span
            aria-hidden
            style={{
              display: 'inline-flex',
              alignItems: 'center',
              justifyContent: 'center',
              width: 38,
              height: 38,
              borderRadius: '50%',
              background: 'var(--color-bg-card)',
              border: '1px solid rgb(var(--color-border))',
              color: 'var(--color-text-muted)',
              flexShrink: 0,
            }}
          >
            {file ? <Paperclip size={17} /> : <FileUp size={17} />}
          </span>

          <div style={{ minWidth: 0, flex: 1 }}>
            <input
              ref={fileInputRef}
              id={fileInputId}
              type="file"
              disabled={busy}
              aria-describedby={`${fileInputId}-desc`}
              onChange={(event) => chooseFile(event.target.files?.[0] ?? null)}
              style={{ fontSize: 12.5, maxWidth: '100%' }}
            />
            {file ? (
              <p style={{ fontSize: 12, color: 'var(--color-text-secondary)', marginTop: 4 }}>
                {file.name} · {formatBytes(file.size)}
              </p>
            ) : (
              <p style={{ fontSize: 12, color: 'var(--color-text-muted)', marginTop: 4 }}>
                Or drop a file here.
              </p>
            )}
          </div>
        </div>
      </Field>

      <div
        style={{
          display: 'grid',
          gap: 12,
          gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))',
        }}
      >
        {/* A version inherits its document's kind, so offering the choice here
            would imply it could differ from the document it is filed under. */}
        {documentId === null ? (
          <Select
            label="Document kind"
            value={docKind}
            disabled={busy}
            error={errors.doc_kind}
            onChange={(event) => setDocKind(event.target.value)}
            options={DOC_KINDS}
          />
        ) : null}
        <Select
          label="Version status"
          value={versionStatus}
          disabled={busy}
          error={errors.version_status}
          onChange={(event) => setVersionStatus(event.target.value)}
          options={VERSION_STATUSES}
        />
      </div>

      <Textarea
        label="Notes"
        rows={2}
        value={notes}
        disabled={busy}
        error={errors.notes}
        hint="What changed in this version — the reviewers reading the history will thank you."
        onChange={(event) => setNotes(event.target.value)}
      />

      <p aria-live="polite" style={{ fontSize: 12.5, color: 'var(--color-text-secondary)', margin: 0 }}>
        {stage === 'idle' ? '' : STAGE_LABEL[stage]}
      </p>

      {busy ? (
        <div>
          <div
            role="progressbar"
            aria-valuenow={stage === 'uploading' ? percent : undefined}
            aria-valuemin={0}
            aria-valuemax={100}
            aria-label="Upload progress"
            style={{
              height: 8,
              borderRadius: 999,
              background: 'var(--color-bg-inset)',
              overflow: 'hidden',
            }}
          >
            <div
              style={{
                height: '100%',
                width: stage === 'uploading' ? `${percent}%` : '100%',
                background: 'rgb(var(--color-primary))',
                opacity: stage === 'uploading' ? 1 : 0.5,
                transition: 'width .15s linear',
              }}
            />
          </div>
          {stage === 'uploading' && file ? (
            <p style={{ fontSize: 11.5, color: 'var(--color-text-muted)', marginTop: 5 }}>
              {percent}% of {formatBytes(file.size)} ({formatNumber(Math.round(progress * file.size))} bytes)
            </p>
          ) : null}
        </div>
      ) : null}

      {failure ? (
        <p
          role="alert"
          style={{
            fontSize: 12.5,
            color: 'var(--color-danger)',
            background: 'var(--color-danger-bg)',
            border: '1px solid var(--color-danger-border)',
            borderRadius: 'var(--radius-md)',
            padding: '8px 10px',
          }}
        >
          {failure}
        </p>
      ) : null}

      <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', flexWrap: 'wrap' }}>
        {busy ? (
          <Button variant="secondary" icon={<X size={14} />} onClick={cancelUpload}>
            Cancel upload
          </Button>
        ) : onCancel ? (
          <Button variant="ghost" onClick={onCancel}>
            Cancel
          </Button>
        ) : null}
        <Button variant="primary" icon={<FileUp size={15} />} loading={busy} onClick={() => void upload()}>
          Upload version
        </Button>
      </div>
    </div>
  )
}
