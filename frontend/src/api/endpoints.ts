import { api } from './client'
import type {
  Activity, Agent, AgentStats, AppNotification, AuditLogEntry, Company, Contact,
  Dashboard, DocumentFile, HandoverBrief, HandoverItem, LeadSource, Opportunity, Paginated,
  PortalOpportunity, PortalProgressEntry, PortalSummary, Pipeline, Project, Role,
  StageHistoryEntry, Task, User,
} from '@/types'

type Query = Record<string, string | number | boolean | undefined | null>

/** Drops empty filters so the URL only carries what the user actually chose. */
function params(query: Query = {}) {
  return Object.fromEntries(
    Object.entries(query).filter(([, value]) => value !== undefined && value !== null && value !== ''),
  )
}

export const authApi = {
  login: (email: string, password: string) =>
    api.post<{ token: string; user: User }>('/auth/login', { email, password }).then((r) => r.data),
  me: () => api.get<{ data: User }>('/auth/me').then((r) => r.data.data),
  logout: () => api.post('/auth/logout').then(() => undefined),
}

export const dashboardApi = {
  get: () => api.get<{ data: Dashboard }>('/dashboard').then((r) => r.data.data),
}

export const companyApi = {
  list: (query?: Query) => api.get<Paginated<Company>>('/companies', { params: params(query) }).then((r) => r.data),
  get: (id: string) => api.get<{ data: Company }>(`/companies/${id}`).then((r) => r.data.data),
  create: (payload: Partial<Company>) => api.post<{ data: Company }>('/companies', payload).then((r) => r.data.data),
  update: (id: string, payload: Partial<Company>) =>
    api.patch<{ data: Company }>(`/companies/${id}`, payload).then((r) => r.data.data),
  remove: (id: string) => api.delete(`/companies/${id}`).then(() => undefined),
  contacts: (id: string) => api.get<{ data: Contact[] }>(`/companies/${id}/contacts`).then((r) => r.data.data),
  opportunities: (id: string) =>
    api.get<{ data: Opportunity[] }>(`/companies/${id}/opportunities`).then((r) => r.data.data),
  timeline: (id: string) => api.get<Paginated<Activity>>(`/companies/${id}/timeline`).then((r) => r.data.data),
}

export const contactApi = {
  list: (query?: Query) => api.get<Paginated<Contact>>('/contacts', { params: params(query) }).then((r) => r.data),
  create: (payload: Record<string, unknown>) =>
    api.post<{ data: Contact }>('/contacts', payload).then((r) => r.data.data),
  update: (id: string, payload: Record<string, unknown>) =>
    api.patch<{ data: Contact }>(`/contacts/${id}`, payload).then((r) => r.data.data),
  remove: (id: string) => api.delete(`/contacts/${id}`).then(() => undefined),
}

export const agentApi = {
  list: (query?: Query) => api.get<Paginated<Agent>>('/agents', { params: params(query) }).then((r) => r.data),
  get: (id: string) => api.get<{ data: Agent }>(`/agents/${id}`).then((r) => r.data.data),
  create: (payload: Record<string, unknown>) => api.post<{ data: Agent }>('/agents', payload).then((r) => r.data.data),
  update: (id: string, payload: Record<string, unknown>) =>
    api.patch<{ data: Agent }>(`/agents/${id}`, payload).then((r) => r.data.data),
  stats: (id: string) => api.get<{ data: AgentStats }>(`/agents/${id}/stats`).then((r) => r.data.data),
  opportunities: (id: string) =>
    api.get<{ data: Opportunity[] }>(`/agents/${id}/opportunities`).then((r) => r.data.data),
}

export const opportunityApi = {
  list: (query?: Query) =>
    api.get<Paginated<Opportunity>>('/opportunities', { params: params(query) }).then((r) => r.data),
  get: (id: string) => api.get<{ data: Opportunity }>(`/opportunities/${id}`).then((r) => r.data.data),
  create: (payload: Record<string, unknown>) =>
    api.post<{ data: Opportunity }>('/opportunities', payload).then((r) => r.data.data),
  update: (id: string, payload: Record<string, unknown>) =>
    api.patch<{ data: Opportunity }>(`/opportunities/${id}`, payload).then((r) => r.data.data),
  remove: (id: string) => api.delete(`/opportunities/${id}`).then(() => undefined),
  changeStage: (id: string, payload: Record<string, unknown>) =>
    api.post<{ data: Opportunity }>(`/opportunities/${id}/stage`, payload).then((r) => r.data.data),
  setNextAction: (id: string, payload: Record<string, unknown>) =>
    api.post<{ data: Opportunity }>(`/opportunities/${id}/next-action`, payload).then((r) => r.data.data),
  addNote: (id: string, payload: Record<string, unknown>) =>
    api.post(`/opportunities/${id}/notes`, payload).then(() => undefined),
  assignOwner: (id: string, ownerId: string) =>
    api.post<{ data: Opportunity }>(`/opportunities/${id}/owner`, { owner_id: ownerId }).then((r) => r.data.data),
  assignAgent: (id: string, agentId: string | null) =>
    api.post<{ data: Opportunity }>(`/opportunities/${id}/agent`, { agent_id: agentId }).then((r) => r.data.data),
  timeline: (id: string) => api.get<Paginated<Activity>>(`/opportunities/${id}/timeline`).then((r) => r.data.data),
  stageHistory: (id: string) =>
    api.get<{ data: StageHistoryEntry[] }>(`/opportunities/${id}/stage-history`).then((r) => r.data.data),
}

export const projectApi = {
  list: (query?: Query) => api.get<Paginated<Project>>('/projects', { params: params(query) }).then((r) => r.data),
  get: (id: string) => api.get<{ data: Project }>(`/projects/${id}`).then((r) => r.data.data),
  update: (id: string, payload: Record<string, unknown>) =>
    api.patch<{ data: Project }>(`/projects/${id}`, payload).then((r) => r.data.data),
  remove: (id: string) => api.delete(`/projects/${id}`).then(() => undefined),
  convert: (opportunityId: string, payload: Record<string, unknown>) =>
    api.post<{ data: Project }>(`/opportunities/${opportunityId}/convert-to-project`, payload).then((r) => r.data.data),
  changeStatus: (id: string, payload: Record<string, unknown>) =>
    api.post<{ data: Project }>(`/projects/${id}/status`, payload).then((r) => r.data.data),
  assignManager: (id: string, managerId: string | null) =>
    api.post<{ data: Project }>(`/projects/${id}/manager`, { manager_id: managerId }).then((r) => r.data.data),
  addNote: (id: string, payload: Record<string, unknown>) =>
    api.post(`/projects/${id}/notes`, payload).then(() => undefined),
  handoverItems: (id: string) =>
    api.get<{ data: HandoverItem[] }>(`/projects/${id}/handover-items`).then((r) => r.data.data),
  addHandoverItem: (id: string, payload: Record<string, unknown>) =>
    api.post<{ data: HandoverItem }>(`/projects/${id}/handover-items`, payload).then((r) => r.data.data),
  updateHandoverItem: (id: string, itemId: string, payload: Record<string, unknown>) =>
    api.patch<{ data: HandoverItem }>(`/projects/${id}/handover-items/${itemId}`, payload).then((r) => r.data.data),
  handoverBrief: (id: string) =>
    api.get<{ data: HandoverBrief }>(`/projects/${id}/handover-brief`).then((r) => r.data.data),
  timeline: (id: string) => api.get<Paginated<Activity>>(`/projects/${id}/timeline`).then((r) => r.data.data),
  tasks: (id: string) => api.get<{ data: Task[] }>(`/projects/${id}/tasks`).then((r) => r.data.data),
}

/** Referral agent portal. Everything is scoped to the caller server-side. */
export const portalApi = {
  summary: () => api.get<{ data: PortalSummary }>('/portal/summary').then((r) => r.data.data),
  opportunities: (query?: Query) =>
    api.get<Paginated<PortalOpportunity>>('/portal/opportunities', { params: params(query) }).then((r) => r.data),
  show: (id: string) =>
    api
      .get<{ data: { opportunity: PortalOpportunity; progress: PortalProgressEntry[] } }>(`/portal/opportunities/${id}`)
      .then((r) => r.data.data),
}

export const documentApi = {
  list: (subjectType: string, subjectId: string) =>
    api
      .get<{ data: DocumentFile[] }>('/documents', { params: { subject_type: subjectType, subject_id: subjectId } })
      .then((r) => r.data.data),
  upload: (form: FormData) =>
    api.post<{ data: DocumentFile }>('/documents', form).then((r) => r.data.data),
  remove: (id: string) => api.delete(`/documents/${id}`).then(() => undefined),
  /** Downloads stream through the API, so the blob is fetched with the auth header. */
  download: async (doc: DocumentFile) => {
    const response = await api.get(`/documents/${doc.id}/download`, { responseType: 'blob' })
    const url = URL.createObjectURL(response.data as Blob)
    const link = document.createElement('a')
    link.href = url
    link.download = doc.name
    link.click()
    URL.revokeObjectURL(url)
  },
}

export const taskApi = {
  list: (query?: Query) => api.get<Paginated<Task>>('/tasks', { params: params(query) }).then((r) => r.data),
  create: (payload: Record<string, unknown>) => api.post<{ data: Task }>('/tasks', payload).then((r) => r.data.data),
  update: (id: string, payload: Record<string, unknown>) =>
    api.patch<{ data: Task }>(`/tasks/${id}`, payload).then((r) => r.data.data),
  remove: (id: string) => api.delete(`/tasks/${id}`).then(() => undefined),
  complete: (id: string) => api.post<{ data: Task }>(`/tasks/${id}/complete`).then((r) => r.data.data),
  reopen: (id: string) => api.post<{ data: Task }>(`/tasks/${id}/reopen`).then((r) => r.data.data),
}

export const pipelineApi = {
  list: () => api.get<{ data: Pipeline[] }>('/pipelines').then((r) => r.data.data),
  leadSources: () => api.get<{ data: LeadSource[] }>('/lead-sources').then((r) => r.data.data),
}

export const notificationApi = {
  list: (query?: Query) =>
    api.get<Paginated<AppNotification>>('/notifications', { params: params(query) }).then((r) => r.data),
  unreadCount: () => api.get<{ data: { count: number } }>('/notifications/unread-count').then((r) => r.data.data.count),
  markRead: (id: string) => api.post(`/notifications/${id}/read`).then(() => undefined),
  markAllRead: () => api.post('/notifications/read-all').then(() => undefined),
}

export const auditApi = {
  list: (query?: Query) =>
    api.get<Paginated<AuditLogEntry>>('/audit-logs', { params: params(query) }).then((r) => r.data),
}

export const userApi = {
  list: (query?: Query) => api.get<Paginated<User>>('/users', { params: params(query) }).then((r) => r.data),
  create: (payload: Record<string, unknown>) => api.post<{ data: User }>('/users', payload).then((r) => r.data.data),
  update: (id: string, payload: Record<string, unknown>) =>
    api.patch<{ data: User }>(`/users/${id}`, payload).then((r) => r.data.data),
  roles: () => api.get<{ data: (Role & { permissions: string[] })[] }>('/roles').then((r) => r.data.data),
}
