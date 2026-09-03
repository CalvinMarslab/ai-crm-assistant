import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { companyApi } from '@/api/endpoints'
import { errorMessage, validationErrors } from '@/api/client'
import { PageHeader } from '@/components/PageHeader'
import { Button, Card, EmptyState, ErrorState, Field, Input, Spinner, Textarea } from '@/components/ui'
import { Modal, ModalFooter } from '@/components/Modal'
import { money } from '@/lib/format'
import { useAuth } from '@/hooks/useAuth'

export default function CompanyListPage() {
  const { can } = useAuth()
  const [search, setSearch] = useState('')
  const [creating, setCreating] = useState(false)

  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['companies', search],
    queryFn: () => companyApi.list({ search, per_page: 50 }),
  })

  return (
    <>
      <PageHeader
        title="Companies"
        subtitle={data ? `${data.meta.total} in total` : undefined}
        action={can('company.manage') && <Button onClick={() => setCreating(true)}>+ New company</Button>}
      />

      <div className="mb-4 max-w-sm">
        <Input placeholder="Search companies…" value={search} onChange={(event) => setSearch(event.target.value)} />
      </div>

      {isLoading ? (
        <Spinner />
      ) : error ? (
        <ErrorState message={errorMessage(error)} onRetry={() => void refetch()} />
      ) : !data?.data.length ? (
        <Card>
          <EmptyState message="No companies yet." />
        </Card>
      ) : (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {data.data.map((company) => (
            <Link key={company.id} to={`/companies/${company.id}`}>
              <Card className="h-full p-4 transition hover:shadow-md">
                <h2 className="truncate text-sm font-semibold text-slate-900">{company.name}</h2>
                <p className="mt-0.5 truncate text-xs text-slate-500">{company.industry ?? 'Industry not recorded'}</p>

                <dl className="mt-3 flex gap-4 border-t border-slate-100 pt-3 text-xs">
                  <div>
                    <dt className="text-slate-400">Opportunities</dt>
                    <dd className="mt-0.5 font-semibold text-slate-900">{company.opportunities_count ?? 0}</dd>
                  </div>
                  <div>
                    <dt className="text-slate-400">Contacts</dt>
                    <dd className="mt-0.5 font-semibold text-slate-900">{company.contacts_count ?? 0}</dd>
                  </div>
                  <div className="ml-auto text-right">
                    <dt className="text-slate-400">Open value</dt>
                    <dd className="mt-0.5 font-semibold text-slate-900">{money(company.open_opportunities_value)}</dd>
                  </div>
                </dl>
              </Card>
            </Link>
          ))}
        </div>
      )}

      <CompanyFormModal open={creating} onClose={() => setCreating(false)} />
    </>
  )
}

export function CompanyFormModal({
  open,
  onClose,
  company,
}: {
  open: boolean
  onClose: () => void
  company?: { id: string; name: string; industry: string | null; phone: string | null; email: string | null; website: string | null; registration_no: string | null; address: string | null; notes: string | null }
}) {
  const queryClient = useQueryClient()
  const [form, setForm] = useState({
    name: company?.name ?? '',
    industry: company?.industry ?? '',
    registration_no: company?.registration_no ?? '',
    phone: company?.phone ?? '',
    email: company?.email ?? '',
    website: company?.website ?? '',
    address: company?.address ?? '',
    notes: company?.notes ?? '',
  })
  const [errors, setErrors] = useState<Record<string, string>>({})

  const save = useMutation({
    mutationFn: () => (company ? companyApi.update(company.id, form) : companyApi.create(form)),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['companies'] })
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
      title={company ? 'Edit company' : 'New company'}
      footer={<ModalFooter onCancel={onClose} onConfirm={() => save.mutate()} pending={save.isPending} />}
    >
      <div className="grid gap-4 sm:grid-cols-2">
        <div className="sm:col-span-2">
          <Field label="Name" required error={errors.name}>
            <Input value={form.name} onChange={set('name')} autoFocus />
          </Field>
        </div>
        <Field label="Industry" error={errors.industry}>
          <Input value={form.industry} onChange={set('industry')} />
        </Field>
        <Field label="Registration no." error={errors.registration_no}>
          <Input value={form.registration_no} onChange={set('registration_no')} />
        </Field>
        <Field label="Phone" error={errors.phone}>
          <Input value={form.phone} onChange={set('phone')} />
        </Field>
        <Field label="Email" error={errors.email}>
          <Input type="email" value={form.email} onChange={set('email')} />
        </Field>
        <div className="sm:col-span-2">
          <Field label="Website" error={errors.website}>
            <Input value={form.website} onChange={set('website')} />
          </Field>
        </div>
        <div className="sm:col-span-2">
          <Field label="Address" error={errors.address}>
            <Textarea value={form.address} onChange={set('address')} rows={2} />
          </Field>
        </div>
        <div className="sm:col-span-2">
          <Field label="Notes" error={errors.notes}>
            <Textarea value={form.notes} onChange={set('notes')} rows={2} />
          </Field>
        </div>
      </div>
    </Modal>
  )
}
