import type { ReactNode } from 'react'
import { useId } from 'react'

import type { DashboardChartPoint } from '../../types/contracts'
import { humanise } from '../../utils/format'

/**
 * The horizontal bar chart, plus the primitives the other two charts share.
 *
 * The UI kit has no chart module and this group owns three chart files, so the
 * palette, the accessible frame and the payload normaliser live in the first of
 * them rather than being copied into all three.
 */

export interface ChartSeriesPoint {
  key: string
  label: string
  value: number
  /** Overrides the palette where the colour carries meaning, e.g. risk level. */
  colour?: string
}

/**
 * A categorical palette that survives every common form of colour blindness
 * (derived from Okabe–Ito) and holds its contrast on both the light and the
 * dark surface. Fixed hex rather than tokens: a chart series must keep the same
 * colour when the theme flips, or the legend a user memorised stops meaning
 * anything.
 *
 * Colour is never the only encoding here — every series is labelled in text and
 * every value is printed — so the palette is a scanning aid, not the message.
 */
export const CHART_COLOURS = [
  '#0072b2',
  '#e69f00',
  '#009e73',
  '#cc79a7',
  '#56b4e9',
  '#d55e00',
  '#7a5195',
  '#6b7a8f',
] as const

export function colourAt(index: number): string {
  return CHART_COLOURS[index % CHART_COLOURS.length]
}

/** Semantic colours for the series where the level, not the category, is the point. */
export const LEVEL_COLOURS: Record<string, string> = {
  low: 'var(--color-success)',
  medium: 'var(--color-warning)',
  high: 'var(--color-danger)',
  critical: '#7f1d1d',
  informational: 'var(--color-info)',
}

/**
 * Turn a raw chart payload into something drawable.
 *
 * The API labels its buckets differently per series, so this reads whichever
 * field arrived and drops the buckets that carry no usable number — a chart
 * with a `NaN` bar is worse than a chart with one fewer bar.
 */
export function toChartSeries(points?: DashboardChartPoint[] | null): ChartSeriesPoint[] {
  if (!Array.isArray(points)) return []

  return points.flatMap((point, index) => {
    const rawValue = point.value ?? point.count ?? point.total ?? point.amount
    const value = typeof rawValue === 'string' ? Number(rawValue) : rawValue

    if (value === null || value === undefined || !Number.isFinite(value)) return []

    const rawKey = point.key ?? point.month ?? point.period ?? point.label ?? point.name
    const key = rawKey === null || rawKey === undefined ? `bucket-${index}` : String(rawKey)
    const label = point.label ?? point.name ?? point.month ?? point.period ?? humanise(key)

    return [{ key, label, value }]
  })
}

export function seriesTotal(series: ChartSeriesPoint[]): number {
  return series.reduce((sum, point) => sum + point.value, 0)
}

/**
 * The accessible wrapper every chart on this page uses.
 *
 * A chart is a picture of a table, so the table is always there: `.ct-sr-only`
 * keeps it out of the way of people who can see the drawing and puts the exact
 * numbers in front of everyone else. The SVG carries its own `<title>` and
 * `<desc>` as well, because a screen reader that lands on the graphic itself
 * should not have to hunt for the table to learn what it is.
 */
export function ChartFrame({
  title,
  description,
  series,
  valueHeader = 'Count',
  formatValue,
  summary,
  minWidth,
  children,
}: {
  title: string
  description?: string
  series: ChartSeriesPoint[]
  valueHeader?: string
  formatValue: (value: number) => string
  /** The one-sentence text alternative. */
  summary: string
  /** Below this the drawing scrolls sideways instead of shrinking past legible. */
  minWidth?: number
  children: (ids: { titleId: string; descId: string }) => ReactNode
}) {
  const baseId = useId()
  const titleId = `${baseId}-title`
  const descId = `${baseId}-desc`

  return (
    <figure style={{ margin: 0 }}>
      <figcaption style={{ marginBottom: 10 }}>
        <span style={{ fontSize: 13.5, fontWeight: 700, color: 'var(--color-text)' }}>{title}</span>
        {description ? (
          <span
            style={{
              display: 'block',
              fontSize: 12,
              color: 'var(--color-text-secondary)',
              marginTop: 2,
            }}
          >
            {description}
          </span>
        ) : null}
      </figcaption>

      <div className="ct-scroll-x">
        <div style={{ minWidth }}>{children({ titleId, descId })}</div>
      </div>

      <table className="ct-sr-only">
        <caption>{`${title}. ${summary}`}</caption>
        <thead>
          <tr>
            <th scope="col">Category</th>
            <th scope="col">{valueHeader}</th>
          </tr>
        </thead>
        <tbody>
          {series.map((point) => (
            <tr key={point.key}>
              <th scope="row">{point.label}</th>
              <td>{formatValue(point.value)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </figure>
  )
}

const ROW_HEIGHT = 30
const VIEW_WIDTH = 360
const LABEL_WIDTH = 128
const VALUE_WIDTH = 62
const BAR_HEIGHT = 11

/**
 * Ranked categories as horizontal bars.
 *
 * Horizontal rather than vertical because the categories are words — contract
 * types and department names do not fit under a vertical axis without being
 * rotated, and rotated axis labels are the thing everyone squints at.
 */
export function BarChart({
  title,
  description,
  series,
  formatValue,
  valueHeader = 'Count',
  colourBy = 'palette',
  maxRows = 8,
}: {
  title: string
  description?: string
  series: ChartSeriesPoint[]
  formatValue: (value: number) => string
  valueHeader?: string
  /** `level` uses the semantic colours; a risk bar must not be an arbitrary blue. */
  colourBy?: 'palette' | 'level' | 'accent'
  maxRows?: number
}) {
  // Long tails are the norm here — thirty departments, most with one contract.
  // The chart shows the ranked head and the sr-only table keeps every row.
  const ranked = [...series].sort((a, b) => b.value - a.value)
  const shown = ranked.slice(0, maxRows)
  const max = Math.max(...shown.map((point) => point.value), 1)
  const barsWidth = VIEW_WIDTH - LABEL_WIDTH - VALUE_WIDTH
  const height = shown.length * ROW_HEIGHT + 6

  const fillFor = (point: ChartSeriesPoint, index: number) => {
    if (point.colour) return point.colour
    if (colourBy === 'accent') return 'rgb(var(--color-primary))'
    if (colourBy === 'level') return LEVEL_COLOURS[point.key] ?? colourAt(index)
    return colourAt(index)
  }

  const summary =
    ranked.length === 0
      ? 'No data.'
      : `${ranked.length} categories, led by ${ranked[0].label} at ${formatValue(ranked[0].value)}.` +
        (ranked.length > shown.length ? ` The chart shows the top ${shown.length}.` : '')

  return (
    <ChartFrame
      title={title}
      description={description}
      series={ranked}
      valueHeader={valueHeader}
      formatValue={formatValue}
      summary={summary}
      minWidth={300}
    >
      {({ titleId, descId }) => (
        <svg
          role="img"
          aria-labelledby={`${titleId} ${descId}`}
          viewBox={`0 0 ${VIEW_WIDTH} ${height}`}
          style={{ display: 'block', width: '100%', maxWidth: 520, height: 'auto' }}
        >
          <title id={titleId}>{title}</title>
          <desc id={descId}>{summary}</desc>

          {shown.map((point, index) => {
            const y = index * ROW_HEIGHT + 4
            const width = Math.max(2, (point.value / max) * barsWidth)
            return (
              <g key={point.key}>
                <text
                  x={0}
                  y={y + BAR_HEIGHT}
                  fontSize={11}
                  style={{ fill: 'var(--color-text-secondary)', fontWeight: 600 }}
                >
                  {truncateLabel(point.label, 19)}
                </text>
                <rect
                  x={LABEL_WIDTH}
                  y={y + 2}
                  width={barsWidth}
                  height={BAR_HEIGHT}
                  rx={3}
                  style={{ fill: 'rgb(var(--color-surface-3))' }}
                />
                <rect
                  x={LABEL_WIDTH}
                  y={y + 2}
                  width={width}
                  height={BAR_HEIGHT}
                  rx={3}
                  style={{ fill: fillFor(point, index) }}
                />
                <text
                  x={VIEW_WIDTH}
                  y={y + BAR_HEIGHT}
                  fontSize={11}
                  textAnchor="end"
                  style={{
                    fill: 'var(--color-text)',
                    fontWeight: 700,
                    fontVariantNumeric: 'tabular-nums',
                  }}
                >
                  {formatValue(point.value)}
                </text>
              </g>
            )
          })}
        </svg>
      )}
    </ChartFrame>
  )
}

/** SVG has no text-overflow, so the ellipsis has to be decided up front. */
export function truncateLabel(label: string, max: number): string {
  return label.length <= max ? label : `${label.slice(0, max - 1)}…`
}
