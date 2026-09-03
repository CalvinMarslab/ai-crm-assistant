import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { taskApi, userApi } from '@/api/endpoints'
import { errorMessage, validationErrors } from '@/api/client'
import { PageHeader } from '@/components/PageHeader'
import { Button, EmptyState, ErrorState, Field, Input, SectionCard, Select, Spinner, Textarea } from '@/components/ui'
import { Modal, ModalFooter } from '@/components/Modal'
import { TaskRow } from '@/components/OpportunityBits'
import { useAuth } from '@/hooks/useAuth'
import type { Task } from '@/types'

/**
 * Grouped by urgency rather than listed flat, so the answer to "what now?" is
 * the first thing on screen.
 */
export default function TaskListPage() {
  const { can } = useAuth()
  const queryClient = useQueryClient()
  const [creating, setCreating] = useState(false)
  const [showDone, setShowDone] = useState(false)

  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['tasks', 'all', showDone],
    queryFn: () => taskApi.list({ per_page: 200, open: showDone ? undefined : true }),
  })

  const complete = useMutation({
    mutationFn: (task: Task) => taskApi.complete(task.id),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['tasks'] })
      void queryClient.invalidateQueries({ queryKey: ['dashboard'] })
    },
  })

  if (isLoading) return <Spinner />
  if (error) return <ErrorState message={errorMessage(error)} onRetry={() => void refetch()} />

  const tasks = data?.data ?? []
  const now = new Date()
  const endOfToday = new Date(now.getFullYear(), now.getMonth(), now.getDate(), 23, 59, 59)

  const open = tasks.filter((task) => task.status !== 'done' && task.status !== 'cancelled')
  const overdue = open.filter((task) => task.is_overdue)
  const today = open.filter((task) => {
    if (task.is_overdue || !task.due_at) return false
    return new Date(task.due_at) <= endOfToday
  })
  const upcoming = open.filter((task) => task.due_at && new Date(task.due_at) > endOfToday)
  const undated = open.filter((task) => !task.due_at)
  const closed = tasks.filter((task) => task.status === 'done' || task.status === 'cancelled')

  return (
    <>
      <PageHeader
        title="Tasks"
        subtitle={`${open.length} open · ${overdue.length} overdue`}
        action={
          <div className="flex gap-2">
            <Button variant="secondary" onClick={() => setShowDone((value) => !value)}>
              {showDone ? 'Hide completed' : 'Show completed'}
            </Button>
            {can('task.manage') && <Button onClick={() => setCreating(true)}>+ New task</Button>}
          </div>
        }
      />

      <div className="grid gap-4 lg:grid-cols-2">
        <SectionCard title="Overdue" count={overdue.length} tone="danger">
          {overdue.length === 0 ? (
            <EmptyState message="Nothing overdue." />
          ) : (
            overdue.map((task) => <TaskRow key={task.id} task={task} onComplete={(t) => complete.mutate(t)} />)
          )}
        </SectionCard>

        <SectionCard title="Due today" count={today.length} tone="warning">
          {today.length === 0 ? (
            <EmptyState message="Nothing due today." />
          ) : (
            today.map((task) => <TaskRow key={task.id} task={task} onComplete={(t) => complete.mutate(t)} />)
          )}
        </SectionCard>

        <SectionCard title="Upcoming" count={upcoming.length}>
          {upcoming.length === 0 ? (
            <EmptyState message="Nothing scheduled." />
          ) : (
            upcoming.map((task) => <TaskRow key={task.id} task={task} onComplete={(t) => complete.mutate(t)} />)
          )}
        </SectionCard>

        <SectionCard title="No due date" count={undated.length}>
          {undated.length === 0 ? (
            <EmptyState message="Every task has a due date." />
          ) : (
            undated.map((task) => <TaskRow key={task.id} task={task} onComplete={(t) => complete.mutate(t)} />)
          )}
        </SectionCard>

        {showDone && (
          <div className="lg:col-span-2">
            <SectionCard title="Completed" count={closed.length}>
              {closed.length === 0 ? (
                <EmptyState message="Nothing completed yet." />
              ) : (
                closed.map((task) => <TaskRow key={task.id} task={task} />)
              )}
            </SectionCard>
          </div>
        )}
      </div>

      <TaskFormModal open={creating} onClose={() => setCreating(false)} />
    </>
  )
}

function TaskFormModal({ open, onClose }: { open: boolean; onClose: () => void }) {
  const queryClient = useQueryClient()
  const [form, setForm] = useState({
    title: '',
    description: '',
    assigned_user_id: '',
    due_at: '',
    priority: 'normal',
  })
  const [errors, setErrors] = useState<Record<string, string>>({})

  const { data: users } = useQuery({
    queryKey: ['users', 'options'],
    queryFn: () => userApi.list({ per_page: 200 }),
    enabled: open,
  })

  const save = useMutation({
    mutationFn: () =>
      taskApi.create({
        ...form,
        assigned_user_id: form.assigned_user_id || null,
        due_at: form.due_at || null,
        description: form.description || null,
      }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['tasks'] })
      void queryClient.invalidateQueries({ queryKey: ['dashboard'] })
      setForm({ title: '', description: '', assigned_user_id: '', due_at: '', priority: 'normal' })
      onClose()
    },
    onError: (error) => setErrors(validationErrors(error)),
  })

  const set = (key: keyof typeof form) => (event: { target: { value: string } }) =>
    setForm((current) => ({ ...current, [key]: event.target.value }))

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="New task"
      footer={<ModalFooter onCancel={onClose} onConfirm={() => save.mutate()} pending={save.isPending} />}
    >
      <div className="grid gap-4 sm:grid-cols-2">
        <div className="sm:col-span-2">
          <Field label="Task" required error={errors.title}>
            <Input value={form.title} onChange={set('title')} autoFocus />
          </Field>
        </div>
        <Field label="Assign to" error={errors.assigned_user_id}>
          <Select value={form.assigned_user_id} onChange={set('assigned_user_id')}>
            <option value="">Unassigned</option>
            {users?.data.map((user) => (
              <option key={user.id} value={user.id}>
                {user.name}
              </option>
            ))}
          </Select>
        </Field>
        <Field label="Due" error={errors.due_at}>
          <Input type="date" value={form.due_at} onChange={set('due_at')} />
        </Field>
        <Field label="Priority" error={errors.priority}>
          <Select value={form.priority} onChange={set('priority')}>
            <option value="low">Low</option>
            <option value="normal">Normal</option>
            <option value="high">High</option>
            <option value="urgent">Urgent</option>
          </Select>
        </Field>
        <div className="sm:col-span-2">
          <Field label="Description" error={errors.description}>
            <Textarea value={form.description} onChange={set('description')} rows={3} />
          </Field>
        </div>
      </div>
    </Modal>
  )
}
