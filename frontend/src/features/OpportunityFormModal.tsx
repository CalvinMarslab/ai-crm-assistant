import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { agentApi, companyApi, opportunityApi, pipelineApi, userApi } from '@/api/endpoints'
import { validationErrors } from '@/api/client'
import { Modal, ModalFooter } from '@/components/Modal'
import { Field, Input, Select, Textarea } from '@/components/ui'
import type { Opportunity } from '@/types'

interface Props {
  open: boolean
  onClose: () => void
  /** Present when editing; absent when creating. */
  opportunity?: Opportunity
  defaultCompanyId?: string
}

export function OpportunityFormModal({ open, onClose, opportunity, defaultCompanyId }: Props) {
  const queryClient = useQueryClient()
  const editing = Boolean(opportunity)

  const [form, setForm] = useState({
    title: '',
    company_id: defaultCompanyId ?? '',
    owner_id: '',
    referral_agent_id: '',
    lead_source_code: '',
    estimated_value: '',
    priority: 'normal',
    expected_close_date: '',
    next_action: '',
    next_follow_up_at: '',
    summary: '',
    requirements: '',
  })
  const [errors, setErrors] = useState<Record<string, string>>({})

  useEffect(() => {
    if (!open) return

    setErrors({})
    setForm({
      title: opportunity?.title ?? '',
      company_id: opportunity?.company?.id ?? defaultCompanyId ?? '',
      owner_id: opportunity?.owner?.id ?? '',
      referral_agent_id: opportunity?.referral_agent?.id ?? '',
      lead_source_code: opportunity?.lead_source?.code ?? '',
      estimated_value: opportunity?.estimated_value?.toString() ?? '',
      priority: opportunity?.priority ?? 'normal',
      expected_close_date: opportunity?.expected_close_date ?? '',
      next_action: opportunity?.next_action ?? '',
      next_follow_up_at: opportunity?.next_follow_up_at?.slice(0, 10) ?? '',
      summary: opportunity?.summary ?? '',
      requirements: opportunity?.requirements ?? '',
    })
  }, [open, opportunity, defaultCompanyId])

  const { data: companies } = useQuery({
    queryKey: ['companies', 'options'],
    queryFn: () => companyApi.list({ per_page: 200 }),
    enabled: open,
  })
  const { data: agents } = useQuery({
    queryKey: ['agents', 'options'],
    queryFn: () => agentApi.list({ per_page: 200 }),
    enabled: open,
  })
  const { data: users } = useQuery({
    queryKey: ['users', 'options'],
    queryFn: () => userApi.list({ per_page: 200 }),
    enabled: open,
  })
  const { data: sources } = useQuery({
    queryKey: ['lead-sources'],
    queryFn: pipelineApi.leadSources,
    enabled: open,
  })

  const save = useMutation({
    mutationFn: (payload: Record<string, unknown>) =>
      editing ? opportunityApi.update(opportunity!.id, payload) : opportunityApi.create(payload),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['opportunities'] })
      void queryClient.invalidateQueries({ queryKey: ['opportunity'] })
      void queryClient.invalidateQueries({ queryKey: ['dashboard'] })
      void queryClient.invalidateQueries({ queryKey: ['company'] })
      onClose()
    },
    onError: (error) => setErrors(validationErrors(error)),
  })

  function submit() {
    setErrors({})

    save.mutate({
      title: form.title,
      company_id: form.company_id,
      owner_id: form.owner_id || undefined,
      referral_agent_id: form.referral_agent_id || null,
      lead_source_code: form.lead_source_code || null,
      estimated_value: form.estimated_value === '' ? null : Number(form.estimated_value),
      priority: form.priority,
      expected_close_date: form.expected_close_date || null,
      next_action: form.next_action || null,
      next_follow_up_at: form.next_follow_up_at || null,
      summary: form.summary || null,
      requirements: form.requirements || null,
    })
  }

  const set = (key: keyof typeof form) => (event: { target: { value: string } }) =>
    setForm((current) => ({ ...current, [key]: event.target.value }))

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={editing ? 'Edit opportunity' : 'New opportunity'}
      description="Every opportunity needs a company, an owner, and a next action."
      width="lg"
      footer={<ModalFooter onCancel={onClose} onConfirm={submit} pending={save.isPending} />}
    >
      <div className="grid gap-4 sm:grid-cols-2">
        <div className="sm:col-span-2">
          <Field label="Title" required error={errors.title}>
            <Input value={form.title} onChange={set('title')} placeholder="Website Revamp 2026" autoFocus />
          </Field>
        </div>

        <Field label="Company" required error={errors.company_id}>
          <Select value={form.company_id} onChange={set('company_id')}>
            <option value="">Select a company…</option>
            {companies?.data.map((company) => (
              <option key={company.id} value={company.id}>
                {company.name}
              </option>
            ))}
          </Select>
        </Field>

        <Field label="Owner" error={errors.owner_id} hint="Defaults to you.">
          <Select value={form.owner_id} onChange={set('owner_id')}>
            <option value="">Me</option>
            {users?.data.map((user) => (
              <option key={user.id} value={user.id}>
                {user.name}
              </option>
            ))}
          </Select>
        </Field>

        <Field label="Referral agent" error={errors.referral_agent_id}>
          <Select value={form.referral_agent_id} onChange={set('referral_agent_id')}>
            <option value="">None</option>
            {agents?.data.map((agent) => (
              <option key={agent.id} value={agent.id}>
                {agent.name}
              </option>
            ))}
          </Select>
        </Field>

        <Field label="Lead source" error={errors.lead_source_code}>
          <Select value={form.lead_source_code} onChange={set('lead_source_code')}>
            <option value="">Not recorded</option>
            {sources?.map((source) => (
              <option key={source.code} value={source.code}>
                {source.name}
              </option>
            ))}
          </Select>
        </Field>

        <Field label="Estimated value" error={errors.estimated_value}>
          <Input type="number" min="0" step="0.01" value={form.estimated_value} onChange={set('estimated_value')} />
        </Field>

        <Field label="Priority" error={errors.priority}>
          <Select value={form.priority} onChange={set('priority')}>
            <option value="low">Low</option>
            <option value="normal">Normal</option>
            <option value="high">High</option>
            <option value="urgent">Urgent</option>
          </Select>
        </Field>

        <Field label="Expected close date" error={errors.expected_close_date}>
          <Input type="date" value={form.expected_close_date} onChange={set('expected_close_date')} />
        </Field>

        <Field label="Next follow-up" error={errors.next_follow_up_at}>
          <Input type="date" value={form.next_follow_up_at} onChange={set('next_follow_up_at')} />
        </Field>

        <div className="sm:col-span-2">
          <Field
            label="Next action"
            error={errors.next_action}
            hint="What has to happen next? Leaving this blank flags the opportunity on the dashboard."
          >
            <Input value={form.next_action} onChange={set('next_action')} placeholder="Call to confirm requirements" />
          </Field>
        </div>

        <div className="sm:col-span-2">
          <Field label="Summary" error={errors.summary}>
            <Textarea value={form.summary} onChange={set('summary')} rows={2} />
          </Field>
        </div>

        <div className="sm:col-span-2">
          <Field label="Requirements" error={errors.requirements}>
            <Textarea value={form.requirements} onChange={set('requirements')} rows={3} />
          </Field>
        </div>
      </div>
    </Modal>
  )
}
