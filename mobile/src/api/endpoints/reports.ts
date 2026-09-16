import { api, getCompanyContext, ensureSesKey } from '../client';
import { getApiOrigin } from '../../config/env';
import { ApiError, MissingCompanyContextError } from '../errors';
import type { ReportDefinition, ReportFilterValues, ReportResult } from '../../types/contracts';

export function listReportDefinitions(): Promise<ReportDefinition[]> {
  return api.get<ReportDefinition[]>('/reports');
}

export interface RunReportParams {
  filters?: ReportFilterValues;
  page?: number;
  perPage?: number;
}

export function runReport(key: string, params: RunReportParams = {}): Promise<ReportResult> {
  const f = params.filters ?? {};
  return api.get<ReportResult>(`/reports/${key}`, {
    ...f,
    page: params.page,
    per_page: params.perPage,
  });
}

/** Raw authenticated fetch, not the `{data:T}`-unwrapping api client — the endpoint answers `text/csv`. */
export async function exportReportCsv(key: string, filters: ReportFilterValues = {}): Promise<string> {
  const context = getCompanyContext();
  if (!context) throw new MissingCompanyContextError();

  const sesKey = await ensureSesKey();
  const url = new URL(`${getApiOrigin()}/api/reports/${key}/export`);
  for (const [name, value] of Object.entries(filters)) {
    if (value) url.searchParams.set(name, value);
  }

  const response = await fetch(url.toString(), {
    headers: {
      Authorization: `Bearer ${sesKey}`,
      Accept: 'text/csv',
      'X-AIC-CMP-ID': context.cmp_id,
      'X-AIC-FY-ID': context.fy_id,
      'X-AIC-BO-ID': context.bo_id,
    },
  });

  if (!response.ok) {
    throw new ApiError(
      response.status === 403
        ? 'You do not have permission to export reports.'
        : response.status === 429
          ? 'Too many exports in a short time. Wait a few minutes and try again.'
          : 'The export could not be produced.',
      response.status,
    );
  }

  return response.text();
}
