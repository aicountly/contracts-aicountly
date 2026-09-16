/** Mirrors web/src/services/apiClient.ts's ApiError shape exactly, so error-handling code reads the same on both platforms. */
export class ApiError extends Error {
  constructor(
    message: string,
    readonly status: number,
    readonly code: string = 'UNKNOWN',
    readonly fieldErrors: Record<string, string> = {},
  ) {
    super(message);
    this.name = 'ApiError';
  }

  /** A 422 with per-field messages — the caller should highlight fields, not toast. */
  get isValidation(): boolean {
    return this.status === 422 && Object.keys(this.fieldErrors).length > 0;
  }

  get isPermission(): boolean {
    return this.status === 403;
  }

  get isNotFound(): boolean {
    return this.status === 404;
  }

  /** Worth offering a retry button for; a 4xx is not. */
  get isTransient(): boolean {
    return this.status === 0 || this.status === 429 || this.status >= 500;
  }
}

/** The auth_token is missing, or the portal rejected it. Navigation should fall back to the login screen. */
export class SessionExpiredError extends ApiError {
  constructor() {
    super('Your session has expired. Please sign in again.', 401, 'SESSION_EXPIRED');
    this.name = 'SessionExpiredError';
  }
}

/** A request needed cmp_id/fy_id/bo_id and none was selected yet. */
export class MissingCompanyContextError extends ApiError {
  constructor() {
    super('Select a company first.', 400, 'MISSING_COMPANY_CONTEXT');
    this.name = 'MissingCompanyContextError';
  }
}
