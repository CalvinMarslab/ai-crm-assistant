import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { agentApi, opportunityApi, projectApi, taskApi, userApi } from '@/api/endpoints'
import { errorMessage, validationErrors } from '@/api/client'
import { Badge, Button, Card, EmptyState, ErrorState, Field, Input, Select, Spinner, Textarea, cx } from '@/components/ui'
import { Modal, ModalFooter } from '@/components/Modal'
import { StageBadge, TaskRow, WarningBadges } from '@/components/OpportunityBits'
import { Timeline } from '@/components/Timeline'
import { Documents } from '@/components/Documents'
import { OpportunityFormModal } from '@/features/OpportunityFormModal'
import { StageChangeModal } from '@/features/StageChangeModal'
import { dateTime, money, relative, shortDate, titleCase } from '@/lib/format'
import { useAuth } from '@/hooks/useAuth'
import type { Task } from '@/types'

type Tab = 'timeline' | 'tasks' | 'documents' | 'history'

export default function OpportunityDetailPage() {
  const { id = '' } = useParams()
  const { can } = useAuth()
  const queryClient = useQueryClient()

  const [tab, setTab] = useState<Tab>('timeline')
  const [editing, setEditing] = useState(false)
  const [changingStage, setChangingStage] = useState(false)
  const [editingNextAction, setEditingNextAction] = useState(false)
  const [addingNote, setAddingNote] = useState(false)
  const [addingTask, setAddingTask] = useState(false)
  const [reassigning, setReassigning] = useState<'owner' | 'agent' | null>(null)
  const [converting, setConverting] = useState(false)

  const { data: opportunity, isLoading, error, refetch } = useQuery({
    queryKey: ['opportunity', id],
    queryFn: () => opportunityApi.get(id),
  })

  const { data: timeline = [] } = useQuery({
    queryKey: ['timeline', id],
    queryFn: () => opportunityApi.timeline(id),
    enabled: tab === 'timeline',
  })

  const { data: history = [] } = useQuery({
    queryKey: ['stage-history', id],
    queryFn: () => opportunityApi.stageHistory(id),
    enabled: tab === 'history',
  })

  const { data: tasks } = useQuery({
    queryKey: ['tasks', 'for-opportunity', id],
    queryFn: () => taskApi.list({ subject_type: 'opportunity', subject_id: id, per_page: 100 }),
    enabled: tab === 'tasks' && (can('task.view.all') || can('task.view.own')),
  })

  const completeTask = useMutation({
    mutationFn: (task: Task) => taskApi.complete(task.id),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['tasks'] })
      void queryClient.invalidateQueries({ queryKey: ['timeline', id] })
      void queryClient.invalidateQueries({ queryKey: ['opportunity', id] })
    },
  })

  if (isLoading) return <Spinner />
  if (error) return <ErrorState message={errorMessage(error)} onRetry={() => void refetch()} />
  if (!opportunity) return null

  const canUpdate = can('opportunity.update')
  // Returned by the API once a won deal has been converted.
  const project = opportunity.project ?? null

  return (
    <>
      <div className="mb-4">
        <Link to="/opportunities" className="text-xs font-medium text-slate-500 hover:text-slate-900">
          ← Opportunities
        </Link>
      </div>

      {/* The header carries stage, owner, agent, next action, follow-up and value
          together, as the UX rules require. */}
      <Card className="mb-4 p-5">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div className="min-w-0">
            <div className="flex flex-wrap items-center gap-2">
              <h1 className="text-lg font-semibold text-slate-900">{opportunity.title}</h1>
              {opportunity.stage && (
                <StageBadge name={opportunity.stage.name} type={opportunity.stage.stage_type} />
              )}
              <Badge tone={opportunity.priority === 'urgent' ? 'red' : opportunity.priority === 'high' ? 'amber' : 'slate'}>
                {titleCase(opportunity.priority)}
              </Badge>
            </div>
            <p className="mt-1 text-sm text-slate-500">
              {opportunity.company && (
                <Link to={`/companies/${opportunity.company.id}`} className="hover:text-brand-600 hover:underline">
                  {opportunity.company.name}
                </Link>
              )}
              {opportunity.primary_contact && <span> · {opportunity.primary_contact.name}</span>}
            </p>
          </div>

          <div className="flex gap-2">
            {can('opportunity.stage.change') && (
              <Button variant="secondary" onClick={() => setChangingStage(true)}>
                Change stage
              </Button>
            )}
            {canUpdate && <Button onClick={() => setEditing(true)}>Edit</Button>}
          </div>
        </div>

        <div className="mt-4">
          <WarningBadges warnings={opportunity.warnings} />
        </div>

        <dl className="mt-4 grid gap-x-6 gap-y-3 border-t border-slate-100 pt-4 sm:grid-cols-2 lg:grid-cols-4">
          <Fact label="Estimated value" value={money(opportunity.estimated_value)} />
          <Fact
            label="Owner"
            value={
              <span className="flex items-center gap-1.5">
                {opportunity.owner?.name ?? 'Unassigned'}
                {can('opportunity.assign.owner') && (
                  <button
                    onClick={() => setReassigning('owner')}
                    className="text-xs font-medium text-brand-600 hover:underline"
                  >
                    Change
                  </button>
                )}
              </span>
            }
          />
          <Fact
            label="Referral agent"
            value={
              <span className="flex items-center gap-1.5">
                {opportunity.referral_agent ? (
                  <Link to={`/agents/${opportunity.referral_agent.id}`} className="hover:text-brand-600 hover:underline">
                    {opportunity.referral_agent.name}
                  </Link>
                ) : (
                  'None'
                )}
                {can('opportunity.assign.agent') && (
                  <button
                    onClick={() => setReassigning('agent')}
                    className="text-xs font-medium text-brand-600 hover:underline"
                  >
                    Change
                  </button>
                )}
              </span>
            }
          />
          <Fact label="Source" value={opportunity.lead_source?.name ?? 'Not recorded'} />
          <Fact label="Expected close" value={shortDate(opportunity.expected_close_date)} />
          <Fact
            label="Quotation"
            value={
              opportunity.quotation_status
                ? `${titleCase(opportunity.quotation_status)}${opportunity.quotation_amount ? ` · ${money(opportunity.quotation_amount)}` : ''}`
                : 'Not started'
            }
          />
          <Fact label="Last contact" value={opportunity.last_contact_at ? relative(opportunity.last_contact_at) : 'Never'} />
          <Fact
            label="Probability"
            value={opportunity.probability === null ? '—' : `${Number(opportunity.probability)}%`}
          />
        </dl>

        {/* Next action gets its own band: it is the single most important field. */}
        <div
          className={cx(
            'mt-4 flex flex-wrap items-center justify-between gap-3 rounded-lg px-4 py-3',
            opportunity.has_next_action ? 'bg-slate-50' : 'bg-red-50 ring-1 ring-red-200',
          )}
        >
          <div className="min-w-0">
            <p className="text-xs font-medium text-slate-500">Next action</p>
            {opportunity.next_action ? (
              <p className="mt-0.5 text-sm font-medium text-slate-900">{opportunity.next_action}</p>
            ) : opportunity.no_action_reason ? (
              <p className="mt-0.5 text-sm text-slate-600 italic">{opportunity.no_action_reason}</p>
            ) : (
              <p className="mt-0.5 text-sm font-medium text-red-700">Nothing recorded — this deal will drift.</p>
            )}
            {opportunity.next_follow_up_at && (
              <p className="mt-0.5 text-xs text-slate-500">Follow up {dateTime(opportunity.next_follow_up_at)}</p>
            )}
          </div>
          {canUpdate && (
            <Button variant="secondary" size="sm" onClick={() => setEditingNextAction(true)}>
              {opportunity.has_next_action ? 'Update' : 'Set next action'}
            </Button>
          )}
        </div>

        {opportunity.status === 'lost' && opportunity.loss_reason && (
          <p className="mt-3 rounded-lg bg-red-50 px-4 py-2 text-sm text-red-800 ring-1 ring-red-200">
            <span className="font-medium">Lost:</span> {opportunity.loss_reason}
            {opportunity.loss_note && ` — ${opportunity.loss_note}`}
          </p>
        )}
        {opportunity.status === 'won' && (
          <div className="mt-3 flex flex-wrap items-center justify-between gap-3 rounded-lg bg-emerald-50 px-4 py-3 ring-1 ring-emerald-200">
            <p className="text-sm text-emerald-800">
              <span className="font-medium">Won</span> {shortDate(opportunity.won_at)} at{' '}
              {money(opportunity.final_value)}
            </p>
            {project ? (
              <Link
                to={`/projects/${project.id}`}
                className="text-sm font-medium text-emerald-800 underline hover:text-emerald-900"
              >
                Open project →
              </Link>
            ) : (
              can('project.create') && (
                <Button size="sm" onClick={() => setConverting(true)}>
                  Convert to project
                </Button>
              )
            )}
          </div>
        )}
      </Card>

      {(opportunity.summary || opportunity.requirements) && (
        <Card className="mb-4 p-5">
          {opportunity.summary && (
            <>
              <h2 className="text-xs font-medium text-slate-500">Summary</h2>
              <p className="mt-1 text-sm whitespace-pre-wrap text-slate-700">{opportunity.summary}</p>
            </>
          )}
          {opportunity.requirements && (
            <>
              <h2 className={cx('text-xs font-medium text-slate-500', opportunity.summary && 'mt-4')}>Requirements</h2>
              <p className="mt-1 text-sm whitespace-pre-wrap text-slate-700">{opportunity.requirements}</p>
            </>
          )}
        </Card>
      )}

      <Card>
        <div className="flex items-center justify-between border-b border-slate-200 px-4">
          <nav className="flex gap-1">
            {(['timeline', 'tasks', 'documents', 'history'] as Tab[]).map((entry) => (
              <button
                key={entry}
                onClick={() => setTab(entry)}
                className={cx(
                  '-mb-px border-b-2 px-3 py-2.5 text-sm font-medium transition',
                  tab === entry
                    ? 'border-brand-600 text-brand-700'
                    : 'border-transparent text-slate-500 hover:text-slate-900',
                )}
              >
                {entry === 'timeline'
                  ? 'Timeline'
                  : entry === 'history'
                    ? 'Stage history'
                    : entry === 'documents'
                      ? 'Documents'
                      : 'Tasks'}
                {entry === 'tasks' && (opportunity.open_tasks_count ?? 0) > 0 && (
                  <span className="ml-1.5 rounded-full bg-slate-100 px-1.5 py-0.5 text-xs text-slate-600">
                    {opportunity.open_tasks_count}
                  </span>
                )}
              </button>
            ))}
          </nav>

          <div className="flex gap-2 py-2">
            {tab === 'timeline' && canUpdate && (
              <Button variant="secondary" size="sm" onClick={() => setAddingNote(true)}>
                + Log activity
              </Button>
            )}
            {tab === 'tasks' && can('task.manage') && (
              <Button variant="secondary" size="sm" onClick={() => setAddingTask(true)}>
                + Add task
              </Button>
            )}
          </div>
        </div>

        <div className="p-5">
          {tab === 'timeline' && <Timeline activities={timeline} />}

          {tab === 'tasks' &&
            (!tasks?.data.length ? (
              <EmptyState message="No tasks on this opportunity." />
            ) : (
              <div className="-mx-2">
                {tasks.data.map((task) => (
                  <TaskRow
                    key={task.id}
                    task={task}
                    onComplete={task.status === 'done' ? undefined : (t) => completeTask.mutate(t)}
                  />
                ))}
              </div>
            ))}

          {tab === 'documents' && <Documents subjectType="opportunity" subjectId={opportunity.id} />}

          {tab === 'history' &&
            (!history.length ? (
              <EmptyState message="No stage changes recorded." />
            ) : (
              <ol className="space-y-3">
                {history.map((entry, index) => (
                  <li key={index} className="flex flex-wrap items-baseline gap-2 text-sm">
                    <span className="text-slate-400">{entry.from_stage?.name ?? 'Created'}</span>
                    <span className="text-slate-300">→</span>
                    <span className="font-medium text-slate-900">{entry.to_stage.name}</span>
                    <span className="text-xs text-slate-400">
                      {dateTime(entry.changed_at)}
                      {entry.changed_by && ` · ${entry.changed_by.name}`}
                    </span>
                    {entry.note && <span className="w-full text-xs text-slate-500">{entry.note}</span>}
                  </li>
                ))}
              </ol>
            ))}
        </div>
      </Card>

      <OpportunityFormModal open={editing} onClose={() => setEditing(false)} opportunity={opportunity} />
      <StageChangeModal open={changingStage} onClose={() => setChangingStage(false)} opportunity={opportunity} />
      <NextActionModal
        open={editingNextAction}
        onClose={() => setEditingNextAction(false)}
        opportunityId={opportunity.id}
        currentAction={opportunity.next_action}
        currentFollowUp={opportunity.next_follow_up_at}
        currentReason={opportunity.no_action_reason}
      />
      <LogActivityModal open={addingNote} onClose={() => setAddingNote(false)} opportunityId={opportunity.id} />
      <QuickTaskModal open={addingTask} onClose={() => setAddingTask(false)} opportunityId={opportunity.id} />
      <ConvertToProjectModal
        open={converting}
        onClose={() => setConverting(false)}
        opportunityId={opportunity.id}
        defaultName={opportunity.title}
      />
      {reassigning && (
        <ReassignModal
          kind={reassigning}
          onClose={() => setReassigning(null)}
          opportunityId={opportunity.id}
          currentId={reassigning === 'owner' ? opportunity.owner?.id : opportunity.referral_agent?.id}
        />
      )}
    </>
  )
}

/**
 * Converting a won deal into a project (PRD section 14). Company, contact,
 * requirements and the commercial reference are copied by the server; the
 * opportunity keeps its own timeline.
 */
function ConvertToProjectModal({
  open,
  onClose,
  opportunityId,
  defaultName,
}: {
  open: boolean
  onClose: () => void
  opportunityId: string
  defaultName: string
}) {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [name, setName] = useState(defaultName)
  const [managerId, setManagerId] = useState('')
  const [startDate, setStartDate] = useState('')
  const [targetEnd, setTargetEnd] = useState('')
  const [errors, setErrors] = useState<Record<string, string>>({})

  // Only users the server will accept as a project manager.
  const { data: users } = useQuery({
    queryKey: ['users', 'project-managers'],
    queryFn: () => userApi.list({ per_page: 200, role: 'project_manager', active: true }),
    enabled: open,
  })

  const convert = useMutation({
    mutationFn: () =>
      projectApi.convert(opportunityId, {
        name,
        project_manager_id: managerId || null,
        start_date: startDate || null,
        target_end_date: targetEnd || null,
      }),
    onSuccess: (project) => {
      void queryClient.invalidateQueries({ queryKey: ['opportunity', opportunityId] })
      void queryClient.invalidateQueries({ queryKey: ['projects'] })
      onClose()
      navigate(`/projects/${project.id}`)
    },
    onError: (error) => setErrors(validationErrors(error)),
  })

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Convert to project"
      description="Company, contact, requirements and the quotation reference are carried over. A handover checklist is created."
      footer={
        <ModalFooter
          onCancel={onClose}
          onConfirm={() => convert.mutate()}
          confirmLabel="Create project"
          pending={convert.isPending}
        />
      }
    >
      <div className="grid gap-4 sm:grid-cols-2">
        <div className="sm:col-span-2">
          <Field label="Project name" required error={errors.name}>
            <Input value={name} onChange={(event) => setName(event.target.value)} autoFocus />
          </Field>
        </div>

        <div className="sm:col-span-2">
          <Field
            label="Project manager"
            error={errors.project_manager_id}
            hint={
              users?.data.length === 0
                ? 'No project managers exist yet. You can assign one later from the project.'
                : 'They are notified and inherit the handover checklist.'
            }
          >
            <Select value={managerId} onChange={(event) => setManagerId(event.target.value)}>
              <option value="">Assign later</option>
              {users?.data.map((user) => (
                <option key={user.id} value={user.id}>
                  {user.name}
                </option>
              ))}
            </Select>
          </Field>
        </div>

        <Field label="Start date" error={errors.start_date}>
          <Input type="date" value={startDate} onChange={(event) => setStartDate(event.target.value)} />
        </Field>

        <Field label="Target end date" error={errors.target_end_date}>
          <Input type="date" value={targetEnd} onChange={(event) => setTargetEnd(event.target.value)} />
        </Field>
      </div>
    </Modal>
  )
}

/** Owner and referral agent reassignment, both audited server-side. */
function ReassignModal({
  kind,
  onClose,
  opportunityId,
  currentId,
}: {
  kind: 'owner' | 'agent'
  onClose: () => void
  opportunityId: string
  currentId?: string
}) {
  const queryClient = useQueryClient()
  const [selected, setSelected] = useState(currentId ?? '')

  const { data: users } = useQuery({
    queryKey: ['users', 'options'],
    queryFn: () => userApi.list({ per_page: 200 }),
    enabled: kind === 'owner',
  })
  const { data: agents } = useQuery({
    queryKey: ['agents', 'options'],
    queryFn: () => agentApi.list({ per_page: 200 }),
    enabled: kind === 'agent',
  })

  const save = useMutation({
    mutationFn: () =>
      kind === 'owner'
        ? opportunityApi.assignOwner(opportunityId, selected)
        : opportunityApi.assignAgent(opportunityId, selected || null),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['opportunity', opportunityId] })
      void queryClient.invalidateQueries({ queryKey: ['timeline', opportunityId] })
      void queryClient.invalidateQueries({ queryKey: ['opportunities'] })
      void queryClient.invalidateQueries({ queryKey: ['dashboard'] })
      onClose()
    },
  })

  return (
    <Modal
      open
      onClose={onClose}
      title={kind === 'owner' ? 'Change owner' : 'Change referral agent'}
      description="The change is recorded on the timeline and in the audit log."
      width="sm"
      footer={
        <ModalFooter
          onCancel={onClose}
          onConfirm={() => save.mutate()}
          confirmLabel="Reassign"
          pending={save.isPending}
        />
      }
    >
      <Field label={kind === 'owner' ? 'Owner' : 'Referral agent'} required={kind === 'owner'}>
        <Select value={selected} onChange={(event) => setSelected(event.target.value)} autoFocus>
          {kind === 'agent' && <option value="">None</option>}
          {kind === 'owner' && <option value="">Select a user…</option>}
          {(kind === 'owner' ? users?.data : agents?.data)?.map((entry) => (
            <option key={entry.id} value={entry.id}>
              {entry.name}
            </option>
          ))}
        </Select>
      </Field>
    </Modal>
  )
}

function Fact({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div>
      <dt className="text-xs font-medium text-slate-500">{label}</dt>
      <dd className="mt-0.5 text-sm text-slate-900">{value}</dd>
    </div>
  )
}

function NextActionModal({
  open,
  onClose,
  opportunityId,
  currentAction,
  currentFollowUp,
  currentReason,
}: {
  open: boolean
  onClose: () => void
  opportunityId: string
  currentAction: string | null
  currentFollowUp: string | null
  currentReason: string | null
}) {
  const queryClient = useQueryClient()
  const [action, setAction] = useState(currentAction ?? '')
  const [followUp, setFollowUp] = useState(currentFollowUp?.slice(0, 10) ?? '')
  const [reason, setReason] = useState(currentReason ?? '')
  const [errors, setErrors] = useState<Record<string, string>>({})

  const save = useMutation({
    mutationFn: () =>
      opportunityApi.setNextAction(opportunityId, {
        next_action: action || null,
        next_follow_up_at: followUp || null,
        no_action_reason: action ? null : reason || null,
      }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['opportunity', opportunityId] })
      void queryClient.invalidateQueries({ queryKey: ['timeline', opportunityId] })
      void queryClient.invalidateQueries({ queryKey: ['dashboard'] })
      onClose()
    },
    onError: (error) => setErrors(validationErrors(error)),
  })

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Next action"
      description="Record what happens next, or say why nothing does."
      footer={<ModalFooter onCancel={onClose} onConfirm={() => save.mutate()} pending={save.isPending} />}
    >
      <div className="space-y-4">
        <Field label="Next action" error={errors.next_action}>
          <Input
            value={action}
            onChange={(event) => setAction(event.target.value)}
            placeholder="Call to confirm the revised scope"
            autoFocus
          />
        </Field>

        <Field label="Follow up on" error={errors.next_follow_up_at}>
          <Input type="date" value={followUp} onChange={(event) => setFollowUp(event.target.value)} />
        </Field>

        {!action && (
          <Field
            label="Reason there is no next action"
            required
            error={errors.no_action_reason}
            hint="Required so the opportunity is deliberately paused rather than forgotten."
          >
            <Textarea
              value={reason}
              onChange={(event) => setReason(event.target.value)}
              rows={2}
              placeholder="Customer asked us to revisit in Q3."
            />
          </Field>
        )}
      </div>
    </Modal>
  )
}

function LogActivityModal({
  open,
  onClose,
  opportunityId,
}: {
  open: boolean
  onClose: () => void
  opportunityId: string
}) {
  const queryClient = useQueryClient()
  const [type, setType] = useState('note.added')
  const [body, setBody] = useState('')
  const [internal, setInternal] = useState(false)
  const [errors, setErrors] = useState<Record<string, string>>({})

  const save = useMutation({
    mutationFn: () => opportunityApi.addNote(opportunityId, { body, type, is_internal: internal }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['timeline', opportunityId] })
      void queryClient.invalidateQueries({ queryKey: ['opportunity', opportunityId] })
      void queryClient.invalidateQueries({ queryKey: ['dashboard'] })
      setBody('')
      setInternal(false)
      onClose()
    },
    onError: (error) => setErrors(validationErrors(error)),
  })

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Log activity"
      footer={<ModalFooter onCancel={onClose} onConfirm={() => save.mutate()} confirmLabel="Log" pending={save.isPending} />}
    >
      <div className="space-y-4">
        <Field label="Type">
          <Select value={type} onChange={(event) => setType(event.target.value)}>
            <option value="note.added">Note</option>
            <option value="call.logged">Call</option>
            <option value="meeting.logged">Meeting</option>
            <option value="customer.reply_noted">Customer reply</option>
          </Select>
        </Field>

        <Field label="Details" required error={errors.body}>
          <Textarea value={body} onChange={(event) => setBody(event.target.value)} rows={4} autoFocus />
        </Field>

        <label className="flex items-start gap-2 text-sm text-slate-700">
          <input
            type="checkbox"
            checked={internal}
            onChange={(event) => setInternal(event.target.checked)}
            className="mt-0.5 size-4 rounded border-slate-300"
          />
          <span>
            Internal only
            <span className="block text-xs text-slate-500">Hidden from referral agents.</span>
          </span>
        </label>
      </div>
    </Modal>
  )
}

function QuickTaskModal({
  open,
  onClose,
  opportunityId,
}: {
  open: boolean
  onClose: () => void
  opportunityId: string
}) {
  const queryClient = useQueryClient()
  const [title, setTitle] = useState('')
  const [dueAt, setDueAt] = useState('')
  const [priority, setPriority] = useState('normal')
  const [errors, setErrors] = useState<Record<string, string>>({})

  const save = useMutation({
    mutationFn: () =>
      taskApi.create({
        title,
        due_at: dueAt || null,
        priority,
        subject_type: 'opportunity',
        subject_id: opportunityId,
      }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['tasks'] })
      void queryClient.invalidateQueries({ queryKey: ['timeline', opportunityId] })
      void queryClient.invalidateQueries({ queryKey: ['dashboard'] })
      setTitle('')
      setDueAt('')
      onClose()
    },
    onError: (error) => setErrors(validationErrors(error)),
  })

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Add task"
      footer={<ModalFooter onCancel={onClose} onConfirm={() => save.mutate()} confirmLabel="Add" pending={save.isPending} />}
    >
      <div className="space-y-4">
        <Field label="Task" required error={errors.title}>
          <Input value={title} onChange={(event) => setTitle(event.target.value)} autoFocus />
        </Field>
        <Field label="Due" error={errors.due_at}>
          <Input type="date" value={dueAt} onChange={(event) => setDueAt(event.target.value)} />
        </Field>
        <Field label="Priority">
          <Select value={priority} onChange={(event) => setPriority(event.target.value)}>
            <option value="low">Low</option>
            <option value="normal">Normal</option>
            <option value="high">High</option>
            <option value="urgent">Urgent</option>
          </Select>
        </Field>
      </div>
    </Modal>
  )
}
