import {
  ChartFrame,
  LEVEL_COLOURS,
  colourAt,
  seriesTotal,
  type ChartSeriesPoint,
} from './BarChart'

/**
 * A split of a whole, as a ring with a labelled legend.
 *
 * The legend is the chart: it carries the label, the figure and the share as
 * text, so the ring is a shape to recognise rather than a code to decode. That
 * is also why the segments are separated by a hairline in the surface colour —
 * two adjacent segments that a colour-blind reader sees as one shade still read
 * as two.
 */

const SIZE = 132
const STROKE = 22
const RADIUS = (SIZE - STROKE) / 2

function polar(cx: number, cy: number, radius: number, angle: number): [number, number] {
  const radians = ((angle - 90) * Math.PI) / 180
  return [cx + radius * Math.cos(radians), cy + radius * Math.sin(radians)]
}

function arcPath(startAngle: number, endAngle: number): string {
  const centre = SIZE / 2
  const [startX, startY] = polar(centre, centre, RADIUS, endAngle)
  const [endX, endY] = polar(centre, centre, RADIUS, startAngle)
  const largeArc = endAngle - startAngle <= 180 ? 0 : 1
  return `M ${startX} ${startY} A ${RADIUS} ${RADIUS} 0 ${largeArc} 0 ${endX} ${endY}`
}

export function DonutChart({
  title,
  description,
  series,
  formatValue,
  valueHeader = 'Count',
  centreLabel,
  colourBy = 'palette',
}: {
  title: string
  description?: string
  series: ChartSeriesPoint[]
  formatValue: (value: number) => string
  valueHeader?: string
  /** Overrides the middle figure; defaults to the total of the series. */
  centreLabel?: string
  colourBy?: 'palette' | 'level'
}) {
  const total = seriesTotal(series)
  const ordered = [...series].sort((a, b) => b.value - a.value)

  const withColour = ordered.map((point, index) => ({
    ...point,
    colour:
      point.colour ?? (colourBy === 'level' ? LEVEL_COLOURS[point.key] ?? colourAt(index) : colourAt(index)),
    share: total > 0 ? point.value / total : 0,
  }))

  const summary =
    total === 0
      ? 'No data.'
      : withColour
          .map((point) => `${point.label} ${formatValue(point.value)} (${percent(point.share)})`)
          .join(', ')

  // A single non-zero segment is a full circle, and an arc whose start and end
  // coincide draws nothing at all — so that case is a plain ring.
  const positive = withColour.filter((point) => point.value > 0)
  const isFullRing = positive.length === 1

  let cursor = 0
  const drawn = positive.map((point) => {
    const start = cursor
    cursor += point.share * 360
    return { ...point, start, end: cursor }
  })

  return (
    <ChartFrame
      title={title}
      description={description}
      series={ordered}
      valueHeader={valueHeader}
      formatValue={formatValue}
      summary={summary}
      minWidth={260}
    >
      {({ titleId, descId }) => (
        <div style={{ display: 'flex', alignItems: 'center', gap: 18, flexWrap: 'wrap' }}>
          <div style={{ position: 'relative', width: SIZE, height: SIZE, flexShrink: 0 }}>
            <svg
              role="img"
              aria-labelledby={`${titleId} ${descId}`}
              viewBox={`0 0 ${SIZE} ${SIZE}`}
              style={{ display: 'block', width: SIZE, height: SIZE }}
            >
              <title id={titleId}>{title}</title>
              <desc id={descId}>{summary}</desc>

              <circle
                cx={SIZE / 2}
                cy={SIZE / 2}
                r={RADIUS}
                fill="none"
                strokeWidth={STROKE}
                style={{ stroke: 'rgb(var(--color-surface-3))' }}
              />

              {isFullRing ? (
                <circle
                  cx={SIZE / 2}
                  cy={SIZE / 2}
                  r={RADIUS}
                  fill="none"
                  strokeWidth={STROKE}
                  style={{ stroke: drawn[0].colour }}
                />
              ) : (
                <>
                  {drawn.map((point) => (
                    <path
                      key={point.key}
                      d={arcPath(point.start, point.end)}
                      fill="none"
                      strokeWidth={STROKE}
                      strokeLinecap="butt"
                      style={{ stroke: point.colour }}
                    />
                  ))}
                  {drawn.map((point) => {
                    const [innerX, innerY] = polar(SIZE / 2, SIZE / 2, RADIUS - STROKE / 2, point.start)
                    const [outerX, outerY] = polar(SIZE / 2, SIZE / 2, RADIUS + STROKE / 2, point.start)
                    return (
                      <line
                        key={`edge-${point.key}`}
                        x1={innerX}
                        y1={innerY}
                        x2={outerX}
                        y2={outerY}
                        strokeWidth={2}
                        style={{ stroke: 'rgb(var(--color-surface))' }}
                      />
                    )
                  })}
                </>
              )}
            </svg>

            <div
              aria-hidden
              style={{
                position: 'absolute',
                inset: 0,
                display: 'flex',
                flexDirection: 'column',
                alignItems: 'center',
                justifyContent: 'center',
              }}
            >
              <span style={{ fontSize: 18, fontWeight: 800, lineHeight: 1.1 }}>
                {centreLabel ?? formatValue(total)}
              </span>
              <span style={{ fontSize: 10, fontWeight: 700, color: 'var(--color-text-muted)' }}>
                Total
              </span>
            </div>
          </div>

          <ul
            aria-hidden
            style={{ listStyle: 'none', display: 'grid', gap: 7, minWidth: 140, flex: 1 }}
          >
            {withColour.map((point) => (
              <li
                key={point.key}
                style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 12 }}
              >
                <span
                  style={{
                    width: 10,
                    height: 10,
                    borderRadius: 3,
                    background: point.colour,
                    flexShrink: 0,
                  }}
                />
                <span style={{ flex: 1, minWidth: 0, color: 'var(--color-text-secondary)' }}>
                  {point.label}
                </span>
                <span
                  style={{
                    fontWeight: 700,
                    color: 'var(--color-text)',
                    fontVariantNumeric: 'tabular-nums',
                  }}
                >
                  {formatValue(point.value)}
                </span>
                <span
                  style={{
                    width: 40,
                    textAlign: 'right',
                    color: 'var(--color-text-muted)',
                    fontVariantNumeric: 'tabular-nums',
                  }}
                >
                  {percent(point.share)}
                </span>
              </li>
            ))}
          </ul>
        </div>
      )}
    </ChartFrame>
  )
}

function percent(share: number): string {
  if (share <= 0) return '0%'
  const value = share * 100
  return `${value < 1 ? value.toFixed(1) : Math.round(value)}%`
}
