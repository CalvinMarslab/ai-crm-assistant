import { api } from './client'
import type { Paginated, UserSummary } from '@/types'

export interface ServiceSubscription {
  id: string
  cycle_id: string
  name: string
  type: 'maintenance' | 'server'
  company: { id: string; name: string } | null
  owner: UserSummary | null
  customer_email: string | null
  internal_email: string
  amount: string | null
  currency: string | null
  renewal_months: number | null
  missing_details: string[]
  expires_on: string
  status: 'active' | 'paused' | 'cancelled'
  renewal_status: 'active' | 'expiring' | 'overdue' | 'paused' | 'cancelled'
  notes: string | null
  payments?: {
    id: string
    amount: string
    currency: string
    paid_on: string
    previous_expires_on: string
    next_expires_on: string
    reference: string | null
  }[]
  deliveries?: {
    id: string
    audience: string
    channel: string
    recipient: string
    status: string
    sent_at: string | null
    error: string | null
    cycle_id: string
  }[]
}

export interface RenewalSummary {
  expiring: number
  overdue: number
  active: number
  pending: { currency: string; amount: string }[]
  email_enabled: boolean
  default_internal_email: string
}

export const serviceApi = {
  list: (params: Record<string, string | number>) =>
    api
      .get<Paginated<ServiceSubscription>>('/services', { params })
      .then((r) => r.data),
  summary: () =>
    api
      .get<{ data: RenewalSummary }>('/services/summary')
      .then((r) => r.data.data),
  get: (id: string) =>
    api
      .get<{ data: ServiceSubscription }>(`/services/${id}`)
      .then((r) => r.data.data),
  save: (id: string | undefined, payload: Record<string, unknown>) =>
    id ? api.patch(`/services/${id}`, payload) : api.post('/services', payload),
  renew: (id: string, payload: Record<string, unknown>) =>
    api.post(`/services/${id}/renew`, payload),
  retry: (id: string, deliveryId: string) =>
    api.post(`/services/${id}/deliveries/${deliveryId}/retry`),
}
