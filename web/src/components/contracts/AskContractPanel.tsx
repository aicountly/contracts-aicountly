import { useEffect, useRef, useState } from 'react'
import { CornerDownLeft, MessageCircleQuestion, Quote, RotateCcw } from 'lucide-react'

import { Button, Card, CardHeader, Chip, ErrorState, Skeleton } from '../ui'
import { AiDisclaimer } from './AiDisclaimer'
import { useToast } from '../../context/ToastProvider'
import { useApiResource } from '../../hooks/useApiResource'
import { api } from '../../services/apiClient'
import { formatDateTime, truncate } from '../../utils/format'

/**
 * Grounded questions about one contract.
 *
 * Every answer carries the page or clause it came from, and an answer the
 * contract does not support says so in as many words. Without both of those an
 * assistant over a legal document is a liability: the useful question is not
 * "what does the model think" but "where in this agreement does it say that".
 */

interface Citation {
  page?: number | null
  clause_id?: number | null
  clause_number?: string | null
  heading?: string | null
  label?: string | null
  excerpt?: string | null
  quote?: string | null
}

interface AiMessage {
  id: number | string
  role: 'user' | 'assistant' | 'system'
  content: string
  citations: Citation[]
  grounded: boolean
  created_at: string
}

interface Conversation {
  id: number
  title: string | null
  created_at: string
  updated_at: string
}

interface AskResponse {
  answer: string
  citations: Citation[] | null
  grounded: boolean
  disclaimer?: string | null
  conversation_id: number
}

function citationLabel(citation: Citation): string {
  if (citation.label?.trim()) return citation.label
  const parts: string[] = []
  if (citation.clause_number) parts.push(`Clause ${citation.clause_number}`)
  if (citation.heading) parts.push(citation.heading)
  if (citation.page !== null && citation.page !== undefined) parts.push(`page ${citation.page}`)
  return parts.length > 0 ? parts.join(' · ') : 'Cited from the contract'
}

export function AskContractPanel({
  contractId,
  contractTitle,
  disclaimer,
  enabled = true,
  disabledReason,
}: {
  contractId: number
  contractTitle: string
  disclaimer?: string | null
  /** False when AI is not configured for the company or the user lacks `ai.use`. */
  enabled?: boolean
  disabledReason?: string
}) {
  const toast = useToast()
  const [question, setQuestion] = useState('')
  const [thread, setThread] = useState<AiMessage[] | null>(null)
  const [conversationId, setConversationId] = useState<number | null>(null)
  const [asking, setAsking] = useState(false)
  const endRef = useRef<HTMLDivElement>(null)

  const history = useApiResource<{ conversationId: number | null; messages: AiMessage[] }>(
    async (signal) => {
      const conversations = await api.get<Conversation[]>(
        `/ai/contracts/${contractId}/conversations`,
        undefined,
        signal,
      )
      const latest = (conversations ?? [])[0] ?? null
      if (!latest) return { conversationId: null, messages: [] }

      const messages = await api.get<AiMessage[]>(`/ai/conversations/${latest.id}/messages`, undefined, signal)
      return { conversationId: latest.id, messages: messages ?? [] }
    },
    [contractId],
    { enabled },
  )

  useEffect(() => {
    if (!history.data) return
    setThread(history.data.messages)
    setConversationId(history.data.conversationId)
  }, [history.data])

  const messages = thread ?? []

  useEffect(() => {
    if (messages.length > 0) endRef.current?.scrollIntoView({ block: 'nearest' })
  }, [messages.length])

  const ask = async () => {
    const asked = question.trim()
    if (asked === '' || asking) return

    const optimistic: AiMessage = {
      id: `local-${Date.now()}`,
      role: 'user',
      content: asked,
      citations: [],
      grounded: true,
      created_at: new Date().toISOString(),
    }

    setThread((current) => [...(current ?? []), optimistic])
    setQuestion('')
    setAsking(true)

    try {
      const response = await api.post<AskResponse>(`/ai/contracts/${contractId}/ask`, {
        question: asked,
        conversation_id: conversationId ?? undefined,
      })

      setThread((current) => [
        ...(current ?? []),
        {
          id: `answer-${Date.now()}`,
          role: 'assistant',
          content: response.answer,
          citations: response.citations ?? [],
          grounded: response.grounded,
          created_at: new Date().toISOString(),
        },
      ])
      setConversationId(response.conversation_id)
    } catch (err) {
      // The question never reached the model, so leaving it in the transcript
      // would show a conversation that did not happen. It goes back in the box.
      setThread((current) => (current ?? []).filter((message) => message.id !== optimistic.id))
      setQuestion(asked)
      toast.error('That question did not go through', err instanceof Error ? err.message : undefined)
    } finally {
      setAsking(false)
    }
  }

  return (
    <Card>
      <CardHeader
        level={3}
        title="Ask this contract"
        description="Answers are drawn from this document only, and every one shows where it came from."
        action={
          messages.length > 0 ? (
            <Button
              size="sm"
              variant="ghost"
              icon={<RotateCcw size={13} />}
              onClick={() => {
                setThread([])
                setConversationId(null)
              }}
            >
              New conversation
            </Button>
          ) : undefined
        }
      />

      {!enabled ? (
        <p
          style={{
            fontSize: 13,
            color: 'var(--color-text-secondary)',
            padding: '12px 14px',
            borderRadius: 'var(--radius-md)',
            background: 'var(--color-bg-subtle)',
            lineHeight: 1.6,
          }}
        >
          {disabledReason ??
            'AI is not available on this company. An administrator can connect a provider in Console, and answers will be grounded in this contract from then on.'}
        </p>
      ) : (
        <>
          <div
            aria-live="polite"
            aria-atomic="false"
            style={{
              display: 'grid',
              gap: 14,
              maxHeight: 460,
              overflowY: 'auto',
              paddingRight: 4,
            }}
          >
            {history.loading ? (
              <div style={{ display: 'grid', gap: 10 }}>
                <Skeleton height={42} radius={10} />
                <Skeleton height={64} radius={10} />
              </div>
            ) : history.error ? (
              <ErrorState
                compact
                title="Earlier questions did not load"
                detail={history.error.message}
                onRetry={history.reload}
              />
            ) : messages.length === 0 ? (
              <div
                style={{
                  textAlign: 'center',
                  padding: '22px 16px',
                  color: 'var(--color-text-secondary)',
                  fontSize: 13,
                  lineHeight: 1.65,
                }}
              >
                <MessageCircleQuestion size={22} aria-hidden style={{ color: 'var(--color-text-subtle)' }} />
                <p style={{ marginTop: 8 }}>
                  Ask what {truncate(contractTitle, 60)} says — the liability cap, the notice period,
                  whether it auto-renews. If the contract does not answer, the reply will say so.
                </p>
              </div>
            ) : (
              messages.map((message) => <MessageBubble key={message.id} message={message} />)
            )}

            {asking ? (
              <div style={{ display: 'flex', gap: 8, alignItems: 'center', fontSize: 12.5, color: 'var(--color-text-muted)' }}>
                <Skeleton width={120} height={11} />
                <span>Reading the contract…</span>
              </div>
            ) : null}

            <div ref={endRef} />
          </div>

          <form
            onSubmit={(event) => {
              event.preventDefault()
              void ask()
            }}
            style={{ display: 'flex', gap: 8, alignItems: 'flex-end', marginTop: 14 }}
          >
            <div style={{ flex: 1 }}>
              <label htmlFor="ask-contract-input" className="ct-sr-only">
                Ask a question about this contract
              </label>
              <textarea
                id="ask-contract-input"
                rows={2}
                value={question}
                disabled={asking}
                placeholder="What is our liability cap?"
                onChange={(event) => setQuestion(event.target.value)}
                onKeyDown={(event) => {
                  // Enter sends, Shift+Enter starts a new line: this is a
                  // question box, not a document editor.
                  if (event.key === 'Enter' && !event.shiftKey) {
                    event.preventDefault()
                    void ask()
                  }
                }}
                style={{
                  width: '100%',
                  padding: '9px 11px',
                  borderRadius: 'var(--radius-md)',
                  border: '1px solid rgb(var(--color-border-strong))',
                  background: 'var(--color-bg-card)',
                  color: 'var(--color-text)',
                  fontSize: 13.5,
                  lineHeight: 1.55,
                  resize: 'vertical',
                }}
              />
            </div>
            <Button type="submit" variant="primary" loading={asking} icon={<CornerDownLeft size={14} />}>
              Ask
            </Button>
          </form>

          <AiDisclaimer text={disclaimer} compact />
        </>
      )}
    </Card>
  )
}

function MessageBubble({ message }: { message: AiMessage }) {
  const isUser = message.role === 'user'
  const citations = message.citations ?? []

  return (
    <article
      style={{
        justifySelf: isUser ? 'end' : 'start',
        maxWidth: '92%',
        padding: '11px 13px',
        borderRadius: 'var(--radius-md)',
        background: isUser ? 'var(--color-primary-muted)' : 'var(--color-bg-subtle)',
        border: `1px solid ${isUser ? 'var(--color-primary-border)' : 'rgb(var(--color-border))'}`,
      }}
    >
      <p
        style={{
          fontSize: 11,
          fontWeight: 700,
          textTransform: 'uppercase',
          letterSpacing: '.03em',
          color: 'var(--color-text-muted)',
          marginBottom: 5,
        }}
      >
        {isUser ? 'You' : 'Answer'} · {formatDateTime(message.created_at)}
      </p>

      <p style={{ fontSize: 13.5, lineHeight: 1.7, whiteSpace: 'pre-wrap' }}>{message.content}</p>

      {!isUser ? (
        citations.length > 0 ? (
          <div style={{ marginTop: 10, display: 'grid', gap: 7 }}>
            {citations.map((citation, index) => (
              <div key={index} style={{ display: 'grid', gap: 4 }}>
                <Chip tone="info" size="sm">
                  <Quote size={10} aria-hidden />
                  {citationLabel(citation)}
                </Chip>
                {citation.excerpt || citation.quote ? (
                  <p
                    style={{
                      fontSize: 12,
                      color: 'var(--color-text-secondary)',
                      lineHeight: 1.6,
                      borderLeft: '2px solid rgb(var(--color-border-strong))',
                      paddingLeft: 9,
                    }}
                  >
                    “{truncate(String(citation.excerpt ?? citation.quote), 240)}”
                  </p>
                ) : null}
              </div>
            ))}
          </div>
        ) : (
          <p
            style={{
              marginTop: 10,
              fontSize: 12,
              color: 'var(--color-warning-text)',
              background: 'var(--color-warning-bg)',
              border: '1px solid var(--color-warning-border)',
              borderRadius: 'var(--radius-sm)',
              padding: '7px 9px',
              lineHeight: 1.55,
            }}
          >
            {message.grounded
              ? 'No passage was cited for this answer — check it against the document before relying on it.'
              : 'This contract does not answer that question. The reply above is general and is not drawn from this document.'}
          </p>
        )
      ) : null}
    </article>
  )
}
