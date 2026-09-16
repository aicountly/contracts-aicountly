import { api } from '../client';
import type { NotificationPage } from '../../types/contracts';

export interface ListNotificationsParams {
  page?: number;
  perPage?: number;
  unreadOnly?: boolean;
}

export function listNotifications(params: ListNotificationsParams = {}): Promise<NotificationPage> {
  return api.get<NotificationPage>('/notifications', {
    page: params.page,
    per_page: params.perPage ?? 25,
    unread_only: params.unreadOnly ? '1' : undefined,
  });
}

export function markNotificationRead(id: number): Promise<void> {
  return api.post<void>(`/notifications/${id}/read`);
}

export function markAllNotificationsRead(): Promise<{ read: number }> {
  return api.post<{ read: number }>('/notifications/read-all');
}
