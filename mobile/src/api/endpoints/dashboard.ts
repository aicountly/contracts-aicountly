import { api } from '../client';
import type { DashboardKpis, DashboardCharts, MyActions, ActivityEntry } from '../../types/contracts';

export function getDashboardKpis(): Promise<DashboardKpis> {
  return api.get<DashboardKpis>('/dashboard/kpis');
}

export function getDashboardCharts(): Promise<DashboardCharts> {
  return api.get<DashboardCharts>('/dashboard/charts');
}

export function getMyActions(): Promise<MyActions> {
  return api.get<MyActions>('/dashboard/my-actions');
}

export function getDashboardActivity(): Promise<ActivityEntry[]> {
  return api.get<ActivityEntry[]>('/dashboard/activity');
}
