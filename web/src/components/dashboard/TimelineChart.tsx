import { ChartFrame, colourAt, type ChartSeriesPoint } from './BarChart'

/**
 * A measure across time — months of expiries, obligations falling due, deals
 * executed.
 *
 * Every bucket prints its own figure above the mark, so the shape is a summary
 * and the numbers are still exact. Buckets the caller marks as `tone` (the
 * overdue column, the month a cliff of contracts expires) are drawn with a
 * hatch as well as a colour, because "the red one" is not a distinction
 * everybody can make.
 */

const WIDTH = 680
const HEIGHT = 210
const PAD_LEFT = 40
const PAD_RIGHT = 10
const PAD_TOP = 22
const PAD_BOTTOM = 34

export interface TimelinePoint extends ChartSeriesPoint {
  /** Draws the bucket as a warning or a hazard rather than a neutral column. */
  tone?: 'warning' | 'danger'
}

const TONE_COLOUR: Record<'warning' | 'danger', string> = {
  warning: 'var(--color-warning)',
  danger: 'var(--color-danger)',
}

/** `useId` produces punctuation that has no business in a `url(#…)` reference. */
function hatchId(base: string): string {
  return `hatch-${base.replace(/[^a-zA-Z0-9]/g, '')}`
}

export function TimelineChart({
  title,
  description,
  series,
  formatValue,
  valueHeader = 'Count',
  variant = 'bars',
  colourIndex = 0,
}: {
  title: string
  description?: string
  series: TimelinePoint[]
  formatValue: (value: number) => string
  valueHeader?: string
  variant?: 'bars' | 'line'
  /** Which palette entry this series takes, so two timelines are not the same blue. */
  colourIndex?: number
}) {
  const plotWidth = WIDTH - PAD_LEFT - PAD_RIGHT
  const plotHeight = HEIGHT - PAD_TOP - PAD_BOTTOM
  const max = Math.max(...series.map((point) => point.value), 1)
  const slot = series.length > 0 ? plotWidth / series.length : plotWidth
  const barWidth = Math.min(38, slot * 0.58)
  const accent = colourAt(colourIndex)

  const y = (value: number) => PAD_TOP + plotHeight - (value / max) * plotHeight
  const centreX = (index: number) => PAD_LEFT + slot * index + slot / 2

  const peak = series.reduce<TimelinePoint | null>(
    (best, point) => (best === null || point.value > best.value ? point : best),
    null,
  )
  const total = series.reduce((sum, point) => sum + point.value, 0)
  const summary =
    series.length === 0
      ? 'No data.'
      : `${formatValue(total)} across ${series.length} periods, ${
          peak ? `peaking in ${peak.label} at ${formatValue(peak.value)}` : 'evenly spread'
        }.`

  const linePath = series
    .map((point, index) => `${index === 0 ? 'M' : 'L'} ${centreX(index)} ${y(point.value)}`)
    .join(' ')

  return (
    <ChartFrame
      title={title}
      description={description}
      series={series}
      valueHeader={valueHeader}
      formatValue={formatValue}
      summary={summary}
      minWidth={560}
    >
      {({ titleId, descId }) => (
        <svg
          role="img"
          aria-labelledby={`${titleId} ${descId}`}
          viewBox={`0 0 ${WIDTH} ${HEIGHT}`}
          style={{ display: 'block', width: '100%', height: 'auto' }}
        >
          <title id={titleId}>{title}</title>
          <desc id={descId}>{summary}</desc>

          <defs>
            <pattern
              id={hatchId(titleId)}
              width={6}
              height={6}
              patternTransform="rotate(45)"
              patternUnits="userSpaceOnUse"
            >
              <line
                x1={0}
                y1={0}
                x2={0}
                y2={6}
                strokeWidth={2.5}
                style={{ stroke: 'rgb(var(--color-surface))' }}
              />
            </pattern>
          </defs>

          {[0, 0.5, 1].map((fraction) => {
            const value = max * fraction
            const lineY = y(value)
            return (
              <g key={fraction}>
                <line
                  x1={PAD_LEFT}
                  y1={lineY}
                  x2={WIDTH - PAD_RIGHT}
                  y2={lineY}
                  strokeWidth={1}
                  style={{ stroke: 'rgb(var(--color-border))' }}
                />
                <text
                  x={PAD_LEFT - 7}
                  y={lineY + 3.5}
                  fontSize={9.5}
                  textAnchor="end"
                  style={{ fill: 'var(--color-text-subtle)', fontVariantNumeric: 'tabular-nums' }}
                >
                  {formatValue(Math.round(value))}
                </text>
              </g>
            )
          })}

          {variant === 'line' ? (
            <path
              d={linePath}
              fill="none"
              strokeWidth={2.5}
              strokeLinejoin="round"
              strokeLinecap="round"
              style={{ stroke: accent }}
            />
          ) : null}

          {series.map((point, index) => {
            const barX = centreX(index) - barWidth / 2
            const barY = y(point.value)
            const colour = point.tone ? TONE_COLOUR[point.tone] : accent
            return (
              <g key={point.key}>
                {variant === 'bars' ? (
                  <>
                    <rect
                      x={barX}
                      y={barY}
                      width={barWidth}
                      height={Math.max(1, PAD_TOP + plotHeight - barY)}
                      rx={3}
                      style={{ fill: colour }}
                    />
                    {point.tone ? (
                      <rect
                        x={barX}
                        y={barY}
                        width={barWidth}
                        height={Math.max(1, PAD_TOP + plotHeight - barY)}
                        rx={3}
                        style={{ fill: `url(#${hatchId(titleId)})` }}
                      />
                    ) : null}
                  </>
                ) : (
                  <circle cx={centreX(index)} cy={barY} r={3.5} style={{ fill: colour }} />
                )}

                <text
                  x={centreX(index)}
                  y={barY - 6}
                  fontSize={9.5}
                  textAnchor="middle"
                  style={{
                    fill: 'var(--color-text)',
                    fontWeight: 700,
                    fontVariantNumeric: 'tabular-nums',
                  }}
                >
                  {point.value === 0 ? '' : formatValue(point.value)}
                </text>

                <text
                  x={centreX(index)}
                  y={HEIGHT - PAD_BOTTOM + 15}
                  fontSize={9.5}
                  textAnchor="middle"
                  style={{ fill: 'var(--color-text-secondary)', fontWeight: 600 }}
                >
                  {point.label}
                </text>
                {point.tone ? (
                  <text
                    x={centreX(index)}
                    y={HEIGHT - PAD_BOTTOM + 26}
                    fontSize={8.5}
                    textAnchor="middle"
                    style={{ fill: colour, fontWeight: 700 }}
                  >
                    {point.tone === 'danger' ? 'overdue' : 'due'}
                  </text>
                ) : null}
              </g>
            )
          })}
        </svg>
      )}
    </ChartFrame>
  )
}
