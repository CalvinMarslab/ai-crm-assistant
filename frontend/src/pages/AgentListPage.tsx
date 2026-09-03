import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { agentApi } from '@/api/endpoints'
import { errorMessage, validationErrors } from '@/api/client'
import { PageHeader } from '@/components/PageHeader'
import { Badge, Button, Card, EmptyState, ErrorState, Field, Input, Select, Spinner, Textarea } from '@/components/ui'
import { Modal, ModalFooter } from '@/components/Modal'
import { shortDate } from '@/lib/format'
import { useAuth } from '@/hooks/useAuth'

export default function AgentListPage() {
  const { can } = useAuth()
  const [search, setSearch] = useState('')
  const [creating, setCreating] = useState(false)

  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['agents', search],
    queryFn: () => agentApi.list({ search, per_page: 50 }),
  })

  return (
    <>
      <PageHeader
        title="Referral agents"
        subtitle={data ? `${data.meta.total} on record` : undefined}
        action={can('agent.manage') && <Button onClick={() => setCreating(true)}>+ New agent</Button>}
      />

      <div className="mb-4 max-w-sm">
        <Input placeholder="Search agents…" value={search} onChange={(event) => setSearch(event.target.value)} />
      </div>

      {isLoading ? (
        <Spinner />
      ) : error ? (
        <ErrorState message={errorMessage(error)} onRetry={() => void refetch()} />
      ) : !data?.data.length ? (
        <Card>
          <EmptyState message="No agents yet." />
        </Card>
      ) : (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {data.data.map((agent) => (
            <Link key={agent.id} to={`/agents/${agent.id}`}>
              <Card className="h-full p-4 transition hover:shadow-md">
                <div className="flex items-start justify-between gap-2">
                  <div className="min-w-0">
                    <h2 className="truncate text-sm font-semibold text-slate-900">{agent.name}</h2>
                    <p className="truncate text-xs text-slate-500">{agent.company_name ?? 'Independent'}</p>
                  </div>
                  <Badge tone={agent.status === 'active' ? 'green' : 'slate'}>{agent.status}</Badge>
                </div>

                <dl className="mt-3 flex items-end justify-between border-t border-slate-100 pt-3 text-xs">
                  <div>
                    <dt className="text-slate-400">Referrals</dt>
                    <dd className="mt-0.5 font-semibold text-slate-900">{agent.opportunities_count ?? 0}</dd>
                  </div>
                  <div className="text-right">
                    <dt className="text-slate-400">Joined</dt>
                    <dd className="mt-0.5 text-slate-600">{shortDate(agent.joined_at)}</dd>
                  </div>
                </dl>
              </Card>
            </Link>
          ))}
        </div>
      )}

      <AgentFormModal open={creating} onClose={() => setCreating(false)} />
    </>
  )
}

export function AgentFormModal({
  open,
  onClose,
  agent,
}: {
  open: boolean
  onClose: () => void
  agent?: { id: string; name: string; company_name: string | null; email: string | null; phone: string | null; status: string; notes: string | null; joined_at: string | null }
}) {
  const queryClient = useQueryClient()
  const [form, setForm] = useState({
    name: agent?.name ?? '',
    company_name: agent?.company_name ?? '',
    email: agent?.email ?? '',
    phone: agent?.phone ?? '',
    status: agent?.status ?? 'active',
    joined_at: agent?.joined_at ?? '',
    notes: agent?.notes ?? '',
  })
  const [errors, setErrors] = useState<Record<string, string>>({})

  const save = useMutation({
    mutationFn: () => {
      const payload = { ...form, joined_at: form.joined_at || null }
      return agent ? agentApi.update(agent.id, payload) : agentApi.create(payload)
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['agents'] })
      void queryClient.invalidateQueries({ queryKey: ['agent'] })
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
      title={agent ? 'Edit agent' : 'New referral agent'}
      footer={<ModalFooter onCancel={onClose} onConfirm={() => save.mutate()} pending={save.isPending} />}
    >
      <div className="grid gap-4 sm:grid-cols-2">
        <div className="sm:col-span-2">
          <Field label="Name" required error={errors.name}>
            <Input value={form.name} onChange={set('name')} autoFocus />
          </Field>
        </div>
        <Field label="Company" error={errors.company_name}>
          <Input value={form.company_name} onChange={set('company_name')} />
        </Field>
        <Field label="Status" error={errors.status}>
          <Select value={form.status} onChange={set('status')}>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </Select>
        </Field>
        <Field label="Email" error={errors.email}>
          <Input type="email" value={form.email} onChange={set('email')} />
        </Field>
        <Field label="Phone" error={errors.phone}>
          <Input value={form.phone} onChange={set('phone')} />
        </Field>
        <Field label="Joined" error={errors.joined_at}>
          <Input type="date" value={form.joined_at} onChange={set('joined_at')} />
        </Field>
        <div className="sm:col-span-2">
          <Field label="Notes" error={errors.notes}>
            <Textarea value={form.notes} onChange={set('notes')} rows={2} />
          </Field>
        </div>
      </div>
    </Modal>
  )
}
