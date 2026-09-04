export interface Paginated<T> {
  data: T[]
  meta: { current_page: number; last_page: number; per_page: number; total: number }
}

export interface Role {
  code: string
  name: string
}

export interface User {
  id: string
  name: string
  email: string
  phone: string | null
  status: string
  last_login_at: string | null
  roles?: Role[]
  /** Present only on the authenticated user's own record. */
  permissions?: string[]
}

export interface UserSummary {
  id: string
  name: string
}

export interface Company {
  id: string
  name: string
  registration_no: string | null
  industry: string | null
  website: string | null
  phone: string | null
  email: string | null
  address: string | null
  notes: string | null
  contacts_count?: number
  opportunities_count?: number
  open_opportunities_value?: number
  contacts?: Contact[]
  created_at: string
}

export interface Contact {
  id: string
  name: string
  job_title: string | null
  phone: string | null
  email: string | null
  is_primary: boolean
  notes: string | null
  company?: { id: string; name: string }
}

export interface AgentStats {
  introduced: number
  active: number
  won: number
  lost: number
  estimated_value: number
  won_value: number
  conversion_rate: number | null
  by_stage: { stage: string; code: string; count: number; value: number }[]
}

export interface Agent {
  id: string
  name: string
  company_name: string | null
  email: string | null
  phone: string | null
  status: string
  notes: string | null
  joined_at: string | null
  has_portal_access: boolean
  opportunities_count?: number
  stats?: AgentStats
}

export type StageType = 'open' | 'won' | 'lost' | 'hold'

export interface Stage {
  id: number
  name: string
  code: string
  sequence: number
  stage_type: StageType
  agent_facing_status: string | null
  probability_default?: number | null
  is_active?: boolean
}

export interface Pipeline {
  id: number
  name: string
  is_default: boolean
  stages: Stage[]
}

export interface LeadSource {
  code: string
  name: string
}

export interface HygieneWarning {
  code: string
  message: string
}

export interface Opportunity {
  id: string
  title: string
  summary: string | null
  requirements?: string | null
  company?: { id: string; name: string }
  primary_contact?: Contact | null
  owner?: UserSummary
  referral_agent?: { id: string; name: string } | null
  lead_source?: LeadSource | null
  stage?: Stage
  pipeline_id: number
  status: StageType
  priority: string | null
  probability: number | null
  /** Withheld from users without the financials permission. */
  estimated_value?: number | null
  quotation_amount?: number | null
  final_value?: number | null
  quotation_status: string | null
  quotation_sent_at: string | null
  expected_close_date: string | null
  last_contact_at: string | null
  next_follow_up_at: string | null
  next_action: string | null
  no_action_reason: string | null
  has_next_action: boolean
  loss_reason: string | null
  loss_note?: string | null
  won_at: string | null
  lost_at: string | null
  open_tasks_count?: number
  /** Present once a won opportunity has been converted. */
  project?: { id: string; name: string; status: ProjectStatus; status_label: string } | null
  warnings?: HygieneWarning[]
  created_at: string
  updated_at: string
}

export type TaskStatus = 'to_do' | 'in_progress' | 'waiting' | 'done' | 'cancelled'

export interface Task {
  id: string
  title: string
  description: string | null
  status: TaskStatus
  priority: string
  due_at: string | null
  reminder_at: string | null
  completed_at: string | null
  is_overdue: boolean
  source: string
  assignee?: UserSummary | null
  creator?: UserSummary | null
  subject?: { type: string; id: string; label: string | null } | null
  created_at: string
}

export interface Activity {
  id: string
  type: string
  type_label: string
  title: string
  body: string | null
  is_internal: boolean
  metadata: Record<string, unknown> | null
  actor?: UserSummary
  subject: { type: string; id?: string }
  company?: { id: string; name: string }
  occurred_at: string
}

export interface StageHistoryEntry {
  from_stage: { name: string; code: string } | null
  to_stage: { name: string; code: string; agent_facing_status: string | null }
  changed_by?: UserSummary
  changed_at: string
  note: string | null
}

export interface AppNotification {
  id: string
  type: string
  title: string
  body: string | null
  data: Record<string, unknown> | null
  read_at: string | null
  created_at: string
}

export interface AuditLogEntry {
  id: number
  action: string
  subject_type: string
  subject_id: number | null
  before_data: Record<string, unknown> | null
  after_data: Record<string, unknown> | null
  user?: UserSummary
  ip_address: string | null
  created_at: string
}

export interface DashboardMetrics {
  leads_this_month: number
  active_opportunities: number
  pipeline_value: number
  won_value: number
  lost_value: number
  won_count: number
  lost_count: number
  win_rate: number | null
  average_sales_cycle_days: number | null
  overdue_task_count: number
  without_next_action_count: number
}

export interface Dashboard {
  sections: {
    overdue_tasks: Task[]
    tasks_due_today: Task[]
    follow_ups_due: Opportunity[]
    without_next_action: Opportunity[]
    proposals_awaiting_response: Opportunity[]
    high_value_at_risk: Opportunity[]
    top_value_open: Opportunity[]
    recently_inactive: Opportunity[]
  }
  metrics: DashboardMetrics
  stage_distribution: { stage: string; code: string; stage_type: StageType; count: number; value: number }[]
  recent_activity: Activity[]
  meta: { inactivity_threshold_days: number; timezone: string; generated_at: string }
}

// ---- Phase 2: projects, handover, agent portal ----

export type ProjectStatus =
  | 'pending_handover'
  | 'planning'
  | 'in_progress'
  | 'waiting_customer'
  | 'internal_review'
  | 'completed'
  | 'on_hold'

export type HandoverItemStatus = 'pending' | 'in_progress' | 'done' | 'not_applicable'

export interface HandoverItem {
  id: string
  title: string
  description: string | null
  status: HandoverItemStatus
  status_label: string
  is_settled: boolean
  assignee?: UserSummary | null
  due_at: string | null
  completed_at: string | null
  sequence: number
}

export interface Project {
  id: string
  name: string
  status: ProjectStatus
  status_label: string
  agent_facing_status: string
  is_blocked: boolean
  summary: string | null
  /** Withheld from users without the project financials permission. */
  requirements?: string | null
  company?: { id: string; name: string }
  primary_contact?: Contact | null
  manager?: UserSummary | null
  opportunity?: { id: string; title: string } | null
  contract_value?: number | null
  quotation_reference?: string | null
  start_date: string | null
  target_end_date: string | null
  completed_at: string | null
  handed_over_at: string | null
  handover_items?: HandoverItem[]
  handover_complete?: boolean
  open_tasks_count?: number
  created_at: string
  updated_at: string
}

export interface HandoverBrief {
  project: Project
  opportunity_timeline: Activity[]
}

/** The narrowed shape a referral agent receives; no stage, owner, or value. */
export interface PortalOpportunity {
  id: string
  title: string
  company?: string
  status: string
  is_closed: boolean
  outcome: string | null
  submitted_at: string
  last_update_at: string
  expected_close_date: string | null
}

export interface PortalSummary {
  agent: {
    id: string
    name: string
    company_name: string | null
    status: string
    joined_at: string | null
  }
  performance: {
    introduced: number
    active: number
    won: number
    lost: number
    conversion_rate: number | null
  }
  status_breakdown: Record<string, number>
}

export interface PortalProgressEntry {
  status: string
  changed_at: string
}

export interface DocumentFile {
  id: string
  name: string
  document_type: string | null
  mime_type: string | null
  file_size: number | null
  is_internal: boolean
  uploader?: UserSummary
  subject: { type: string }
  created_at: string
}
