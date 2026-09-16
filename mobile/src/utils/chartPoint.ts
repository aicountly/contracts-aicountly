import type { DashboardChartPoint } from '../types/contracts';

/** The API names bucket fields per series — read whichever arrived. See DashboardChartPoint's own doc comment in types/contracts.ts. */
export function pointLabel(point: DashboardChartPoint): string {
  const raw = point.label ?? point.name ?? point.month ?? point.period ?? point.key;
  return raw === null || raw === undefined ? '—' : String(raw);
}

export function pointValue(point: DashboardChartPoint): number {
  const raw = point.value ?? point.count ?? point.total ?? point.amount;
  const num = typeof raw === 'string' ? Number(raw) : raw;
  return typeof num === 'number' && !Number.isNaN(num) ? num : 0;
}
