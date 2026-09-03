import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { auditApi, pipelineApi, userApi } from '@/api/endpoints'
import { errorMessage, validationErrors } from '@/api/client'
import { PageHeader } from '@/components/PageHeader'
import { Badge, Button, Card, EmptyState, ErrorState, Field, Input, Select, Spinner, cx } from '@/components/ui'
import { Modal, ModalFooter } from '@/components/Modal'
import { dateTime, titleCase } from '@/lib/format'
import { useAuth } from '@/hooks/useAuth'

type Tab = 'users' | 'pipeline' | 'audit'

export default function SettingsPage() {
  const { can } = useAuth()
  const [tab, setTab] = useState<Tab>('users')

  const tabs: { key: Tab; label: string; visible: boolean }[] = [
    { key: 'users', label: 'Users & roles', visible: can('user.view.all') },
    { key: 'pipeline', label: 'Pipeline', visible: true },
    { key: 'audit', label: 'Audit log', visible: can('audit.view') },
  ]

  const visible = tabs.filter((entry) => entry.visible)

  return (
    <>
      <PageHeader title="Settings" />

      <Card>
        <nav className="flex gap-1 border-b border-slate-200 px-4">
          {visible.map((entry) => (
            <button
              key={entry.key}
              onClick={() => setTab(entry.key)}
              className={cx(
                '-mb-px border-b-2 px-3 py-2.5 text-sm font-medium transition',
                tab === entry.key
                  ? 'border-brand-600 text-brand-700'
                  : 'border-transparent text-slate-500 hover:text-slate-900',
              )}
            >
              {entry.label}
            </button>
          ))}
        </nav>

        <div className="p-4">
          {tab === 'users' && <UsersPanel />}
          {tab === 'pipeline' && <PipelinePanel />}
          {tab === 'audit' && <AuditPanel />}
        </div>
      </Card>
    </>
  )
}

function UsersPanel() {
  const { can } = useAuth()
  const [creating, setCreating] = useState(false)

  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['users'],
    queryFn: () => userApi.list({ per_page: 100 }),
  })

  if (isLoading) return <Spinner />
  if (error) return <ErrorState message={errorMessage(error)} onRetry={() => void refetch()} />

  return (
    <>
      <div className="mb-3 flex justify-end">
        {can('user.manage') && (
          <Button size="sm" onClick={() => setCreating(true)}>
            + New user
          </Button>
        )}
      </div>

      <table className="w-full text-sm">
        <thead className="border-b border-slate-200 text-left text-xs font-medium text-slate-500">
          <tr>
            <th className="py-2">Name</th>
            <th className="py-2">Email</th>
            <th className="py-2">Roles</th>
            <th className="py-2">Status</th>
            <th className="py-2">Last login</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-slate-100">
          {data?.data.map((user) => (
            <tr key={user.id}>
              <td className="py-2.5 font-medium text-slate-900">{user.name}</td>
              <td className="py-2.5 text-slate-600">{user.email}</td>
              <td className="py-2.5">
                <span className="flex flex-wrap gap-1">
                  {user.roles?.map((role) => (
                    <Badge key={role.code} tone="blue">
                      {role.name}
                    </Badge>
                  ))}
                </span>
              </td>
              <td className="py-2.5">
                <Badge tone={user.status === 'active' ? 'green' : 'slate'}>{user.status}</Badge>
              </td>
              <td className="py-2.5 text-xs text-slate-400">
                {user.last_login_at ? dateTime(user.last_login_at) : 'Never'}
              </td>
            </tr>
          ))}
        </tbody>
      </table>

      <UserFormModal open={creating} onClose={() => setCreating(false)} />
    </>
  )
}

function UserFormModal({ open, onClose }: { open: boolean; onClose: () => void }) {
  const queryClient = useQueryClient()
  const [form, setForm] = useState({ name: '', email: '', phone: '', password: '', role: 'owner' })
  const [errors, setErrors] = useState<Record<string, string>>({})

  const { data: roles } = useQuery({ queryKey: ['roles'], queryFn: userApi.roles, enabled: open })

  const save = useMutation({
    mutationFn: () =>
      userApi.create({
        name: form.name,
        email: form.email,
        phone: form.phone || null,
        password: form.password,
        roles: [form.role],
      }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['users'] })
      setForm({ name: '', email: '', phone: '', password: '', role: 'owner' })
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
      title="New user"
      footer={<ModalFooter onCancel={onClose} onConfirm={() => save.mutate()} confirmLabel="Create" pending={save.isPending} />}
    >
      <div className="grid gap-4 sm:grid-cols-2">
        <div className="sm:col-span-2">
          <Field label="Name" required error={errors.name}>
            <Input value={form.name} onChange={set('name')} autoFocus />
          </Field>
        </div>
        <Field label="Email" required error={errors.email}>
          <Input type="email" value={form.email} onChange={set('email')} />
        </Field>
        <Field label="Phone" error={errors.phone}>
          <Input value={form.phone} onChange={set('phone')} />
        </Field>
        <Field label="Password" required error={errors.password}>
          <Input type="password" value={form.password} onChange={set('password')} autoComplete="new-password" />
        </Field>
        <Field label="Role" required error={errors.roles}>
          <Select value={form.role} onChange={set('role')}>
            {roles?.map((role) => (
              <option key={role.code} value={role.code}>
                {role.name}
              </option>
            ))}
          </Select>
        </Field>
      </div>
    </Modal>
  )
}

function PipelinePanel() {
  const { data, isLoading, error, refetch } = useQuery({ queryKey: ['pipelines'], queryFn: pipelineApi.list })

  if (isLoading) return <Spinner />
  if (error) return <ErrorState message={errorMessage(error)} onRetry={() => void refetch()} />

  return (
    <div className="space-y-6">
      {data?.map((pipeline) => (
        <div key={pipeline.id}>
          <h3 className="mb-2 flex items-center gap-2 text-sm font-semibold text-slate-900">
            {pipeline.name}
            {pipeline.is_default && <Badge tone="blue">Default</Badge>}
          </h3>
          <table className="w-full text-sm">
            <thead className="border-b border-slate-200 text-left text-xs font-medium text-slate-500">
              <tr>
                <th className="py-2">#</th>
                <th className="py-2">Stage</th>
                <th className="py-2">Type</th>
                <th className="py-2">Shown to agents as</th>
                <th className="py-2 text-right">Default probability</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {pipeline.stages.map((stage, index) => (
                <tr key={stage.id}>
                  <td className="py-2 text-xs text-slate-400">{index + 1}</td>
                  <td className="py-2 font-medium text-slate-900">{stage.name}</td>
                  <td className="py-2">
                    <Badge
                      tone={
                        stage.stage_type === 'won'
                          ? 'green'
                          : stage.stage_type === 'lost'
                            ? 'red'
                            : stage.stage_type === 'hold'
                              ? 'amber'
                              : 'slate'
                      }
                    >
                      {titleCase(stage.stage_type)}
                    </Badge>
                  </td>
                  <td className="py-2 text-slate-600">{stage.agent_facing_status ?? '—'}</td>
                  <td className="py-2 text-right text-slate-600">
                    {stage.probability_default === null || stage.probability_default === undefined
                      ? '—'
                      : `${Number(stage.probability_default)}%`}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ))}
      <p className="text-xs text-slate-400">
        Stages are seeded from the default pipeline. Editing them from the UI arrives with configurable pipelines in a
        later phase.
      </p>
    </div>
  )
}

function AuditPanel() {
  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['audit-logs'],
    queryFn: () => auditApi.list({ per_page: 100 }),
  })

  if (isLoading) return <Spinner />
  if (error) return <ErrorState message={errorMessage(error)} onRetry={() => void refetch()} />
  if (!data?.data.length) return <EmptyState message="No audited changes yet." />

  return (
    <div className="overflow-x-auto">
      <table className="w-full min-w-2xl text-sm">
        <thead className="border-b border-slate-200 text-left text-xs font-medium text-slate-500">
          <tr>
            <th className="py-2">When</th>
            <th className="py-2">Who</th>
            <th className="py-2">Action</th>
            <th className="py-2">Changed</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-slate-100">
          {data.data.map((entry) => (
            <tr key={entry.id}>
              <td className="py-2.5 text-xs whitespace-nowrap text-slate-500">{dateTime(entry.created_at)}</td>
              <td className="py-2.5 text-slate-700">{entry.user?.name ?? 'System'}</td>
              <td className="py-2.5">
                <code className="rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-700">{entry.action}</code>
              </td>
              <td className="max-w-md py-2.5 text-xs text-slate-500">
                {entry.after_data ? Object.keys(entry.after_data).slice(0, 6).join(', ') : '—'}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
