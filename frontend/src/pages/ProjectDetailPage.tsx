import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { projectApi, taskApi, userApi } from '@/api/endpoints'
import { errorMessage, validationErrors } from '@/api/client'
import { Badge, Button, Card, EmptyState, ErrorState, Field, Input, Select, Spinner, Textarea, cx } from '@/components/ui'
import { Modal, ModalFooter } from '@/components/Modal'
import { HandoverProgress, ProjectStatusBadge } from '@/components/ProjectBits'
import { TaskRow } from '@/components/OpportunityBits'
import { Timeline } from '@/components/Timeline'
import { Documents } from '@/components/Documents'
import { dateTime, money, shortDate } from '@/lib/format'
import { useAuth } from '@/hooks/useAuth'
import type { HandoverItem, HandoverItemStatus, Task } from '@/types'

type Tab = 'handover' | 'tasks' | 'documents' | 'timeline' | 'sales'

const STATUSES = [
  ['pending_handover', 'Pending Handover'],
  ['planning', 'Planning'],
  ['in_progress', 'In Progress'],
  ['waiting_customer', 'Waiting for Customer'],
  ['internal_review', 'Internal Review'],
  ['completed', 'Completed'],
  ['on_hold', 'On Hold'],
] as const

export default function ProjectDetailPage() {
  const { id = '' } = useParams()
  const { can } = useAuth()
  const queryClient = useQueryClient()

  const [tab, setTab] = useState<Tab>('handover')
  const [changingStatus, setChangingStatus] = useState(false)
  const [reassigning, setReassigning] = useState(false)
  const [addingNote, setAddingNote] = useState(false)
  const [addingTask, setAddingTask] = useState(false)

  const { data: project, isLoading, error, refetch } = useQuery({
    queryKey: ['project', id],
    queryFn: () => projectApi.get(id),
  })

  const { data: tasks = [] } = useQuery({
    queryKey: ['project', id, 'tasks'],
    queryFn: () => projectApi.tasks(id),
    enabled: tab === 'tasks',
  })

  const { data: timeline = [] } = useQuery({
    queryKey: ['project', id, 'timeline'],
    queryFn: () => projectApi.timeline(id),
    enabled: tab === 'timeline',
  })

  const { data: brief } = useQuery({
    queryKey: ['project', id, 'brief'],
    queryFn: () => projectApi.handoverBrief(id),
    enabled: tab === 'sales',
  })

  function invalidate() {
    void queryClient.invalidateQueries({ queryKey: ['project', id] })
    void queryClient.invalidateQueries({ queryKey: ['projects'] })
  }

  const setItemStatus = useMutation({
    mutationFn: ({ item, status }: { item: HandoverItem; status: HandoverItemStatus }) =>
      projectApi.updateHandoverItem(id, item.id, { status }),
    onSuccess: invalidate,
  })

  const completeTask = useMutation({
    mutationFn: (task: Task) => taskApi.complete(task.id),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['project', id, 'tasks'] })
      invalidate()
    },
  })

  if (isLoading) return <Spinner />
  if (error) return <ErrorState message={errorMessage(error)} onRetry={() => void refetch()} />
  if (!project) return null

  const items = project.handover_items ?? []
  const canManageHandover = can('project.handover.manage')
  const stillPendingHandover = project.status === 'pending_handover'

  return (
    <>
      <div className="mb-4">
        <Link to="/projects" className="text-xs font-medium text-slate-500 hover:text-slate-900">
          ← Projects
        </Link>
      </div>

      <Card className="mb-4 p-5">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div className="min-w-0">
            <div className="flex flex-wrap items-center gap-2">
              <h1 className="text-lg font-semibold text-slate-900">{project.name}</h1>
              <ProjectStatusBadge project={project} />
              {project.is_blocked && <Badge tone="amber">Blocked</Badge>}
            </div>
            <p className="mt-1 text-sm text-slate-500">
              {project.company && (
                <Link to={`/companies/${project.company.id}`} className="hover:text-brand-600 hover:underline">
                  {project.company.name}
                </Link>
              )}
              {project.primary_contact && <span> · {project.primary_contact.name}</span>}
            </p>
          </div>

          <div className="flex gap-2">
            {can('project.assign.manager') && (
              <Button variant="secondary" onClick={() => setReassigning(true)}>
                {project.manager ? 'Change PM' : 'Assign PM'}
              </Button>
            )}
            {can('project.status.update') && <Button onClick={() => setChangingStatus(true)}>Change status</Button>}
          </div>
        </div>

        <dl className="mt-4 grid gap-x-6 gap-y-3 border-t border-slate-100 pt-4 sm:grid-cols-2 lg:grid-cols-4">
          <Fact label="Project manager" value={project.manager?.name ?? 'Unassigned'} />
          {project.contract_value !== undefined && (
            <Fact label="Contract value" value={money(project.contract_value)} />
          )}
          <Fact label="Start" value={shortDate(project.start_date)} />
          <Fact label="Target end" value={shortDate(project.target_end_date)} />
          {project.opportunity && (
            <Fact
              label="From opportunity"
              value={
                <Link to={`/opportunities/${project.opportunity.id}`} className="hover:text-brand-600 hover:underline">
                  {project.opportunity.title}
                </Link>
              }
            />
          )}
          {project.quotation_reference && <Fact label="Quotation" value={project.quotation_reference} />}
          <Fact label="Handed over" value={project.handed_over_at ? dateTime(project.handed_over_at) : 'Not yet'} />
          {project.completed_at && <Fact label="Completed" value={dateTime(project.completed_at)} />}
        </dl>

        {items.length > 0 && (
          <div
            className={cx(
              'mt-4 rounded-lg px-4 py-3',
              stillPendingHandover && !project.handover_complete ? 'bg-amber-50 ring-1 ring-amber-200' : 'bg-slate-50',
            )}
          >
            <HandoverProgress items={items} />
            {stillPendingHandover && !project.handover_complete && (
              <p className="mt-2 text-xs text-amber-800">
                Finish the checklist before moving this project out of Pending Handover.
              </p>
            )}
          </div>
        )}

        {project.summary && <p className="mt-4 text-sm whitespace-pre-wrap text-slate-700">{project.summary}</p>}
      </Card>

      <Card>
        <div className="flex items-center justify-between border-b border-slate-200 px-4">
          <nav className="flex gap-1">
            {(['handover', 'tasks', 'documents', 'timeline', 'sales'] as Tab[]).map((entry) => (
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
                {entry === 'handover'
                  ? 'Handover'
                  : entry === 'tasks'
                    ? 'Tasks'
                    : entry === 'documents'
                      ? 'Documents'
                      : entry === 'timeline'
                        ? 'Timeline'
                        : 'Sales history'}
                {entry === 'tasks' && (project.open_tasks_count ?? 0) > 0 && (
                  <span className="ml-1.5 rounded-full bg-slate-100 px-1.5 py-0.5 text-xs text-slate-600">
                    {project.open_tasks_count}
                  </span>
                )}
              </button>
            ))}
          </nav>

          <div className="flex gap-2 py-2">
            {tab === 'timeline' && can('project.update') && (
              <Button variant="secondary" size="sm" onClick={() => setAddingNote(true)}>
                + Add note
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
          {tab === 'handover' &&
            (items.length === 0 ? (
              <EmptyState message="No handover checklist on this project." />
            ) : (
              <ul className="space-y-2">
                {items.map((item) => (
                  <li
                    key={item.id}
                    className={cx(
                      'flex items-start gap-3 rounded-lg px-3 py-3 ring-1',
                      item.is_settled ? 'bg-emerald-50/50 ring-emerald-100' : 'bg-white ring-slate-200',
                    )}
                  >
                    <button
                      disabled={!canManageHandover || setItemStatus.isPending}
                      onClick={() =>
                        setItemStatus.mutate({ item, status: item.status === 'done' ? 'pending' : 'done' })
                      }
                      aria-label={item.status === 'done' ? `Reopen ${item.title}` : `Complete ${item.title}`}
                      className={cx(
                        'mt-0.5 flex size-5 shrink-0 items-center justify-center rounded border-2 text-xs transition',
                        item.status === 'done'
                          ? 'border-emerald-500 bg-emerald-500 text-white'
                          : 'border-slate-300 hover:border-brand-600',
                        !canManageHandover && 'cursor-not-allowed opacity-50',
                      )}
                    >
                      {item.status === 'done' ? '✓' : ''}
                    </button>

                    <div className="min-w-0 flex-1">
                      <p
                        className={cx(
                          'text-sm font-medium',
                          item.is_settled ? 'text-slate-500 line-through' : 'text-slate-900',
                        )}
                      >
                        {item.title}
                      </p>
                      {item.description && <p className="mt-0.5 text-xs text-slate-500">{item.description}</p>}
                    </div>

                    {canManageHandover && (
                      // Width constrained by the wrapper: Select is w-full by
                      // design, so sizing it here avoids a utility clash that
                      // would squeeze the title to one character per line.
                      <div className="w-36 shrink-0">
                        <Select
                          value={item.status}
                          onChange={(event) =>
                            setItemStatus.mutate({ item, status: event.target.value as HandoverItemStatus })
                          }
                          className="py-1 text-xs"
                        >
                          <option value="pending">Pending</option>
                          <option value="in_progress">In Progress</option>
                          <option value="done">Done</option>
                          <option value="not_applicable">Not Applicable</option>
                        </Select>
                      </div>
                    )}
                  </li>
                ))}
              </ul>
            ))}

          {tab === 'tasks' &&
            (!tasks.length ? (
              <EmptyState message="No tasks on this project." />
            ) : (
              <div className="-mx-2">
                {tasks.map((task) => (
                  <TaskRow
                    key={task.id}
                    task={task}
                    onComplete={task.status === 'done' ? undefined : (t) => completeTask.mutate(t)}
                  />
                ))}
              </div>
            ))}

          {tab === 'documents' && <Documents subjectType="project" subjectId={project.id} />}

          {tab === 'timeline' && <Timeline activities={timeline} />}

          {tab === 'sales' &&
            (!brief ? (
              <Spinner />
            ) : !brief.opportunity_timeline.length ? (
              <EmptyState message="This project was not created from an opportunity." />
            ) : (
              <>
                <p className="mb-4 rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600">
                  The sales history behind this project, so the customer is not asked to repeat themselves.
                </p>
                <Timeline activities={brief.opportunity_timeline} />
              </>
            ))}
        </div>
      </Card>

      <StatusModal
        open={changingStatus}
        onClose={() => setChangingStatus(false)}
        projectId={project.id}
        current={project.status}
        handoverComplete={project.handover_complete ?? true}
      />
      <ManagerModal
        open={reassigning}
        onClose={() => setReassigning(false)}
        projectId={project.id}
        currentId={project.manager?.id}
      />
      <NoteModal open={addingNote} onClose={() => setAddingNote(false)} projectId={project.id} />
      <ProjectTaskModal open={addingTask} onClose={() => setAddingTask(false)} projectId={project.id} />
    </>
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

function StatusModal({
  open,
  onClose,
  projectId,
  current,
  handoverComplete,
}: {
  open: boolean
  onClose: () => void
  projectId: string
  current: string
  handoverComplete: boolean
}) {
  const queryClient = useQueryClient()
  const [status, setStatus] = useState(current)
  const [note, setNote] = useState('')
  const [errors, setErrors] = useState<Record<string, string>>({})

  const save = useMutation({
    mutationFn: () => projectApi.changeStatus(projectId, { status, note: note || null }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['project', projectId] })
      void queryClient.invalidateQueries({ queryKey: ['projects'] })
      onClose()
    },
    onError: (error) => setErrors(validationErrors(error)),
  })

  const blocked = current === 'pending_handover' && !handoverComplete

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Change project status"
      footer={<ModalFooter onCancel={onClose} onConfirm={() => save.mutate()} pending={save.isPending} />}
    >
      <div className="space-y-4">
        {blocked && (
          <p className="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800 ring-1 ring-amber-200">
            The handover checklist is not finished. Complete it before moving off Pending Handover.
          </p>
        )}

        <Field label="Status" required error={errors.status}>
          <Select value={status} onChange={(event) => setStatus(event.target.value)} autoFocus>
            {STATUSES.map(([value, label]) => (
              <option key={value} value={value}>
                {label}
              </option>
            ))}
          </Select>
        </Field>

        <Field label="Note" error={errors.note}>
          <Textarea value={note} onChange={(event) => setNote(event.target.value)} rows={2} placeholder="What changed?" />
        </Field>
      </div>
    </Modal>
  )
}

function ManagerModal({
  open,
  onClose,
  projectId,
  currentId,
}: {
  open: boolean
  onClose: () => void
  projectId: string
  currentId?: string
}) {
  const queryClient = useQueryClient()
  const [selected, setSelected] = useState(currentId ?? '')

  // Only users the server will accept as a project manager.
  const { data: users } = useQuery({
    queryKey: ['users', 'project-managers'],
    queryFn: () => userApi.list({ per_page: 200, role: 'project_manager', active: true }),
    enabled: open,
  })

  const save = useMutation({
    mutationFn: () => projectApi.assignManager(projectId, selected || null),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['project', projectId] })
      void queryClient.invalidateQueries({ queryKey: ['projects'] })
      onClose()
    },
  })

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Project manager"
      description="Unassigned checklist items move to the new manager."
      width="sm"
      footer={<ModalFooter onCancel={onClose} onConfirm={() => save.mutate()} confirmLabel="Assign" pending={save.isPending} />}
    >
      <Field label="Manager">
        <Select value={selected} onChange={(event) => setSelected(event.target.value)} autoFocus>
          <option value="">Unassigned</option>
          {users?.data.map((user) => (
            <option key={user.id} value={user.id}>
              {user.name}
            </option>
          ))}
        </Select>
      </Field>
    </Modal>
  )
}

function NoteModal({ open, onClose, projectId }: { open: boolean; onClose: () => void; projectId: string }) {
  const queryClient = useQueryClient()
  const [body, setBody] = useState('')
  const [internal, setInternal] = useState(false)
  const [errors, setErrors] = useState<Record<string, string>>({})

  const save = useMutation({
    mutationFn: () => projectApi.addNote(projectId, { body, is_internal: internal }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['project', projectId, 'timeline'] })
      setBody('')
      onClose()
    },
    onError: (error) => setErrors(validationErrors(error)),
  })

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Add note"
      footer={<ModalFooter onCancel={onClose} onConfirm={() => save.mutate()} confirmLabel="Add" pending={save.isPending} />}
    >
      <div className="space-y-4">
        <Field label="Note" required error={errors.body}>
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

function ProjectTaskModal({ open, onClose, projectId }: { open: boolean; onClose: () => void; projectId: string }) {
  const queryClient = useQueryClient()
  const [title, setTitle] = useState('')
  const [dueAt, setDueAt] = useState('')
  const [errors, setErrors] = useState<Record<string, string>>({})

  const save = useMutation({
    mutationFn: () =>
      taskApi.create({ title, due_at: dueAt || null, subject_type: 'project', subject_id: projectId }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['project', projectId] })
      void queryClient.invalidateQueries({ queryKey: ['tasks'] })
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
      title="Add project task"
      footer={<ModalFooter onCancel={onClose} onConfirm={() => save.mutate()} confirmLabel="Add" pending={save.isPending} />}
    >
      <div className="space-y-4">
        <Field label="Task" required error={errors.title}>
          <Input value={title} onChange={(event) => setTitle(event.target.value)} autoFocus />
        </Field>
        <Field label="Due" error={errors.due_at}>
          <Input type="date" value={dueAt} onChange={(event) => setDueAt(event.target.value)} />
        </Field>
      </div>
    </Modal>
  )
}
