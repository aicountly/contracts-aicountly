import { Sparkles } from 'lucide-react'

/**
 * The wording the API returns alongside every AI answer, verbatim.
 *
 * It is a constant here as well as a field on the payload because a screen must
 * never render AI output with no disclaimer at all — if `/ai/status` has not
 * been read yet, or an endpoint omits the field, the fallback is the same
 * sentence rather than silence.
 */
export const AI_DISCLAIMER =
  'AI-generated contract analysis is provided for assistance and information. It does not ' +
  'constitute legal advice and should be reviewed by an authorized legal or professional ' +
  'reviewer before reliance.'

/**
 * Shown once per AI surface, at the foot of it.
 *
 * Repeating it beside every generated sentence is how a caveat stops being
 * read; one quiet note under the panel it applies to is the version people
 * actually take in.
 */
export function AiDisclaimer({ text, compact = false }: { text?: string | null; compact?: boolean }) {
  return (
    <p
      role="note"
      style={{
        display: 'flex',
        alignItems: 'flex-start',
        gap: 7,
        marginTop: compact ? 10 : 14,
        paddingTop: compact ? 8 : 12,
        borderTop: '1px solid var(--color-border-light)',
        fontSize: 11.5,
        lineHeight: 1.6,
        color: 'var(--color-text-muted)',
      }}
    >
      <Sparkles size={13} aria-hidden style={{ flexShrink: 0, marginTop: 2 }} />
      <span>{text?.trim() ? text : AI_DISCLAIMER}</span>
    </p>
  )
}
