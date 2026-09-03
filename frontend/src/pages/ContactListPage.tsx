import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { companyApi, contactApi } from '@/api/endpoints'
import { errorMessage, validationErrors } from '@/api/client'
import { PageHeader } from '@/components/PageHeader'
import { Badge, Button, Card, EmptyState, ErrorState, Field, Input, Select, Spinner, Textarea } from '@/components/ui'
import { Modal, ModalFooter } from '@/components/Modal'
import { useAuth } from '@/hooks/useAuth'
import type { Contact } from '@/types'

export default function ContactListPage() {
  const { can } = useAuth()
  const [search, setSearch] = useState('')
  const [editing, setEditing] = useState<Contact | null>(null)
  const [creating, setCreating] = useState(false)

  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['contacts', search],
    queryFn: () => contactApi.list({ search, per_page: 50 }),
  })

  return (
    <>
      <PageHeader
        title="Contacts"
        subtitle={data ? `${data.meta.total} in total` : undefined}
        action={can('contact.manage') && <Button onClick={() => setCreating(true)}>+ New contact</Button>}
      />

      <div className="mb-4 max-w-sm">
        <Input placeholder="Search contacts…" value={search} onChange={(event) => setSearch(event.target.value)} />
      </div>

      {isLoading ? (
        <Spinner />
      ) : error ? (
        <ErrorState message={errorMessage(error)} onRetry={() => void refetch()} />
      ) : !data?.data.length ? (
        <Card>
          <EmptyState message="No contacts yet." />
        </Card>
      ) : (
        <Card className="overflow-hidden">
          <table className="w-full text-sm">
            <thead className="border-b border-slate-200 bg-slate-50 text-left text-xs font-medium text-slate-500">
              <tr>
                <th className="px-4 py-2.5">Name</th>
                <th className="px-4 py-2.5">Company</th>
                <th className="px-4 py-2.5">Email</th>
                <th className="px-4 py-2.5">Phone</th>
                <th className="px-4 py-2.5" />
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {data.data.map((contact) => (
                <tr key={contact.id} className="hover:bg-slate-50">
                  <td className="px-4 py-3">
                    <span className="flex items-center gap-2 font-medium text-slate-900">
                      {contact.name}
                      {contact.is_primary && <Badge tone="blue">Primary</Badge>}
                    </span>
                    <span className="block text-xs text-slate-500">{contact.job_title ?? '—'}</span>
                  </td>
                  <td className="px-4 py-3 text-slate-600">
                    {contact.company ? (
                      <Link to={`/companies/${contact.company.id}`} className="hover:text-brand-600 hover:underline">
                        {contact.company.name}
                      </Link>
                    ) : (
                      '—'
                    )}
                  </td>
                  <td className="px-4 py-3 text-slate-600">{contact.email ?? '—'}</td>
                  <td className="px-4 py-3 text-slate-600">{contact.phone ?? '—'}</td>
                  <td className="px-4 py-3 text-right">
                    {can('contact.manage') && (
                      <Button variant="ghost" size="sm" onClick={() => setEditing(contact)}>
                        Edit
                      </Button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </Card>
      )}

      <ContactFormModal open={creating} onClose={() => setCreating(false)} />
      {editing && <ContactFormModal open onClose={() => setEditing(null)} contact={editing} />}
    </>
  )
}

function ContactFormModal({ open, onClose, contact }: { open: boolean; onClose: () => void; contact?: Contact }) {
  const queryClient = useQueryClient()
  const [form, setForm] = useState({
    name: contact?.name ?? '',
    company_id: contact?.company?.id ?? '',
    job_title: contact?.job_title ?? '',
    email: contact?.email ?? '',
    phone: contact?.phone ?? '',
    notes: contact?.notes ?? '',
    is_primary: contact?.is_primary ?? false,
  })
  const [errors, setErrors] = useState<Record<string, string>>({})

  const { data: companies } = useQuery({
    queryKey: ['companies', 'options'],
    queryFn: () => companyApi.list({ per_page: 200 }),
    enabled: open,
  })

  const save = useMutation({
    mutationFn: () => {
      const payload = { ...form, company_id: form.company_id || null }
      return contact ? contactApi.update(contact.id, payload) : contactApi.create(payload)
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['contacts'] })
      void queryClient.invalidateQueries({ queryKey: ['company'] })
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
      title={contact ? 'Edit contact' : 'New contact'}
      footer={<ModalFooter onCancel={onClose} onConfirm={() => save.mutate()} pending={save.isPending} />}
    >
      <div className="grid gap-4 sm:grid-cols-2">
        <div className="sm:col-span-2">
          <Field label="Name" required error={errors.name}>
            <Input value={form.name} onChange={set('name')} autoFocus />
          </Field>
        </div>
        <Field label="Company" error={errors.company_id}>
          <Select value={form.company_id} onChange={set('company_id')}>
            <option value="">No company</option>
            {companies?.data.map((company) => (
              <option key={company.id} value={company.id}>
                {company.name}
              </option>
            ))}
          </Select>
        </Field>
        <Field label="Job title" error={errors.job_title}>
          <Input value={form.job_title} onChange={set('job_title')} />
        </Field>
        <Field label="Email" error={errors.email}>
          <Input type="email" value={form.email} onChange={set('email')} />
        </Field>
        <Field label="Phone" error={errors.phone}>
          <Input value={form.phone} onChange={set('phone')} />
        </Field>
        <div className="sm:col-span-2">
          <Field label="Notes" error={errors.notes}>
            <Textarea value={form.notes} onChange={set('notes')} rows={2} />
          </Field>
        </div>
        <label className="flex items-center gap-2 text-sm text-slate-700 sm:col-span-2">
          <input
            type="checkbox"
            checked={form.is_primary}
            onChange={(event) => setForm((current) => ({ ...current, is_primary: event.target.checked }))}
            className="size-4 rounded border-slate-300"
          />
          Primary contact for this company
        </label>
      </div>
    </Modal>
  )
}
