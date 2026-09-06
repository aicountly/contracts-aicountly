import { act } from 'react'
import type { ReactElement } from 'react'
import { createRoot } from 'react-dom/client'
import { afterEach, describe, expect, it } from 'vitest'

import { BarChart, toChartSeries } from '../BarChart'
import { DonutChart } from '../DonutChart'
import { TimelineChart } from '../TimelineChart'
import {
  DEFAULT_DASHBOARD_FILTERS,
  dashboardQuery,
  filterSearch,
  resolvePeriod,
} from '../DashboardFilters'
import type { DashboardChartPoint } from '../../../types/contracts'

/**
 * Rendered with React's own root rather than Testing Library: this project
 * installs with `legacy-peer-deps`, so `@testing-library/dom` — a peer of
 * `@testing-library/react` — is not on disk, and the charts are worth testing
 * more than they are worth a new dependency.
 */

declare global {
  var IS_REACT_ACT_ENVIRONMENT: boolean | undefined
}

globalThis.IS_REACT_ACT_ENVIRONMENT = true

const mounted: (() => void)[] = []

function renderChart(ui: ReactElement): HTMLElement {
  const container = document.createElement('div')
  document.body.appendChild(container)
  const root = createRoot(container)

  act(() => {
    root.render(ui)
  })

  mounted.push(() => {
    act(() => root.unmount())
    container.remove()
  })

  return container
}

afterEach(() => {
  while (mounted.length > 0) mounted.pop()?.()
})

/** The text of every leaf element, which is where chart labels end up. */
function leafTexts(container: HTMLElement): string[] {
  return Array.from(container.querySelectorAll('*'))
    .filter((element) => element.children.length === 0)
    .map((element) => element.textContent?.trim() ?? '')
}

const countFormat = (value: number) => String(value)

describe('toChartSeries', () => {
  it('reads whichever value field the series arrived with', () => {
    const points: DashboardChartPoint[] = [
      { key: 'active', label: 'Active', count: 12 },
      { key: 'draft', label: 'Draft', value: 4 },
      { month: '2026-03', total: '7' },
      { key: 'legal', name: 'Legal', amount: 250000 },
    ]

    expect(toChartSeries(points)).toEqual([
      { key: 'active', label: 'Active', value: 12 },
      { key: 'draft', label: 'Draft', value: 4 },
      { key: '2026-03', label: '2026-03', value: 7 },
      { key: 'legal', label: 'Legal', value: 250000 },
    ])
  })

  it('drops buckets with no usable number rather than charting NaN', () => {
    expect(toChartSeries([{ key: 'a', value: null }, { key: 'b', value: 'not a number' }])).toEqual(
      [],
    )
  })

  it('survives a missing series', () => {
    expect(toChartSeries(undefined)).toEqual([])
    expect(toChartSeries(null)).toEqual([])
  })
})

describe('BarChart', () => {
  const series = Array.from({ length: 10 }, (_, index) => ({
    key: `type-${index}`,
    label: `Type ${index}`,
    value: 10 - index,
  }))

  it('keeps every row in the screen-reader table even when the drawing is capped', () => {
    const container = renderChart(
      <BarChart title="Contracts by type" series={series} formatValue={countFormat} maxRows={3} />,
    )

    // Three bars are drawn, each a track plus a fill.
    expect(container.querySelectorAll('svg rect')).toHaveLength(6)

    const rowHeaders = Array.from(container.querySelectorAll('tbody th'))
    expect(rowHeaders).toHaveLength(10)
    expect(rowHeaders[0].textContent).toBe('Type 0')
    expect(rowHeaders[9].textContent).toBe('Type 9')
  })

  it('titles the graphic and describes it in words', () => {
    const container = renderChart(
      <BarChart title="Contracts by type" series={series} formatValue={countFormat} />,
    )

    const svg = container.querySelector('svg')
    expect(svg?.querySelector('title')?.textContent).toBe('Contracts by type')
    expect(svg?.querySelector('desc')?.textContent).toContain('led by Type 0 at 10')
    // The graphic names itself through both of them.
    expect(svg?.getAttribute('aria-labelledby')).toContain(
      svg?.querySelector('title')?.getAttribute('id') ?? '',
    )
  })

  it('ranks the drawn bars by value, not by the order they arrived', () => {
    const container = renderChart(
      <BarChart
        title="Contracts by department"
        series={[
          { key: 'a', label: 'Alpha', value: 1 },
          { key: 'b', label: 'Beta', value: 9 },
        ]}
        formatValue={countFormat}
        maxRows={1}
      />,
    )

    expect(container.querySelector('svg text')?.textContent).toBe('Beta')
  })
})

describe('DonutChart', () => {
  it('labels every segment with its figure and its share', () => {
    const container = renderChart(
      <DonutChart
        title="Customer versus vendor"
        series={[
          { key: 'customer', label: 'Customer', value: 30 },
          { key: 'vendor', label: 'Vendor', value: 10 },
        ]}
        formatValue={countFormat}
      />,
    )

    const texts = leafTexts(container)
    expect(texts).toContain('Customer')
    expect(texts).toContain('75%')
    expect(texts).toContain('25%')
  })

  it('draws a whole ring when one segment is the entire total', () => {
    const container = renderChart(
      <DonutChart
        title="Risk distribution"
        series={[{ key: 'low', label: 'Low', value: 6 }]}
        formatValue={countFormat}
        colourBy="level"
      />,
    )

    // An arc whose start and end coincide renders nothing, so the single-segment
    // case has to be a circle: the track plus the ring, and no path at all.
    expect(container.querySelectorAll('svg circle')).toHaveLength(2)
    expect(container.querySelectorAll('svg path')).toHaveLength(0)
  })
})

describe('TimelineChart', () => {
  const series = [
    { key: 'overdue', label: 'Overdue', value: 3, tone: 'danger' as const },
    { key: '2026-04', label: 'Apr 26', value: 5 },
    { key: '2026-05', label: 'May 26', value: 0 },
  ]

  it('prints each bucket figure so the shape is not the only encoding', () => {
    const container = renderChart(
      <TimelineChart title="Obligations due" series={series} formatValue={countFormat} />,
    )

    const texts = leafTexts(container)
    expect(texts).toContain('Apr 26')
    expect(texts).toContain('May 26')
    expect(texts.filter((text) => text === '5').length).toBeGreaterThan(0)
  })

  it('marks a toned bucket with a hatch and a word, not just a colour', () => {
    const container = renderChart(
      <TimelineChart title="Obligations due" series={series} formatValue={countFormat} />,
    )

    expect(leafTexts(container)).toContain('overdue')

    const pattern = container.querySelector('pattern')
    expect(pattern).not.toBeNull()
    // `useId` punctuation would break the `url(#…)` reference.
    expect(pattern?.getAttribute('id')).toMatch(/^hatch-[a-zA-Z0-9]+$/)
  })
})

describe('dashboard filter query', () => {
  it('sends nothing when nothing is filtered', () => {
    expect(dashboardQuery(DEFAULT_DASHBOARD_FILTERS)).toEqual({})
  })

  it('trims a counterparty and drops the empty controls', () => {
    expect(
      dashboardQuery({
        ...DEFAULT_DASHBOARD_FILTERS,
        counterparty: '  Acme  ',
        status: 'active',
      }),
    ).toEqual({ counterparty: 'Acme', status: 'active' })
  })

  it('resolves a period to the dates the API filters on', () => {
    expect(resolvePeriod('all')).toEqual({})

    expect(
      resolvePeriod('financial_year', { start_date: '2026-04-01', end_date: '2027-03-31' }),
    ).toEqual({ effective_from: '2026-04-01', effective_to: '2027-03-31' })

    // No financial year means no date filter, rather than a window of guesses.
    expect(resolvePeriod('financial_year', null)).toEqual({})

    expect(resolvePeriod('last_30').effective_from).toMatch(/^\d{4}-\d{2}-\d{2}$/)
  })

  it('lets a tile override the dashboard filters in its drill-through link', () => {
    const search = filterSearch({ ...DEFAULT_DASHBOARD_FILTERS, status: 'draft' }, null, {
      status: 'active',
    })

    expect(search).toBe('?status=active')
  })
})
