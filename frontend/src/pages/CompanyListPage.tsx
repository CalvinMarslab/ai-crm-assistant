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
  const [page, setPage] = useState(1)
  const [creating, setCreating] = useState(false)

  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['companies', search, page],
    queryFn: () => companyApi.list({ search, page, per_page: 50 }),
  })

  return (
    <>
      <PageHeader
        title="Companies"
        subtitle={data ? `${data.meta.total} in total` : undefined}
        action={can('company.manage') && <Button onClick={() => setCreating(true)}>+ New company</Button>}
      />

      <div className="mb-4 max-w-sm">
        <Input
          aria-label="Search companies"
          placeholder="Search company, email or phone…"
          value={search}
          onChange={(event) => {
            setSearch(event.target.value)
            setPage(1)
          }}
        />
      </div>

      {isLoading ? (
        <Spinner />
      ) : error ? (
        <ErrorState message={errorMessage(error)} onRetry={() => void refetch()} />
      ) : !data?.data.length ? (
        <Card>
          <EmptyState message={search ? 'No companies match your search.' : 'No companies yet.'} />
        </Card>
      ) : (
        <Card className="overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full min-w-3xl text-sm" aria-label="Companies">
              <thead className="border-b border-slate-200 bg-slate-50 text-left text-xs font-medium text-slate-500">
                <tr>
                  <th scope="col" className="px-4 py-2.5">Company</th>
                  <th scope="col" className="px-4 py-2.5">Email</th>
                  <th scope="col" className="px-4 py-2.5">Phone</th>
                  <th scope="col" className="px-4 py-2.5 text-right">Contacts</th>
                  <th scope="col" className="px-4 py-2.5 text-right">Opportunities</th>
                  <th scope="col" className="px-4 py-2.5 text-right">Open value</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {data.data.map((company) => (
                  <tr key={company.id} className="transition hover:bg-slate-50">
                    <td className="min-w-48 px-4 py-3">
                      <Link to={`/companies/${company.id}`} className="font-semibold text-brand-700 hover:underline">
                        {company.name}
                      </Link>
                      <p className="mt-0.5 text-xs text-slate-500">{company.industry || 'Industry not recorded'}</p>
                    </td>
                    <td className="max-w-64 break-words px-4 py-3 text-slate-600">{company.email || '—'}</td>
                    <td className="whitespace-nowrap px-4 py-3 text-slate-600">{company.phone || '—'}</td>
                    <td className="px-4 py-3 text-right tabular-nums text-slate-600">{company.contacts_count ?? 0}</td>
                    <td className="px-4 py-3 text-right tabular-nums text-slate-600">{company.opportunities_count ?? 0}</td>
                    <td className="whitespace-nowrap px-4 py-3 text-right font-medium tabular-nums text-slate-900">{money(company.open_opportunities_value)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Card>
      )}

      {data && data.meta.last_page > 1 && (
        <nav aria-label="Company pages" className="mt-4 flex items-center justify-end gap-3 text-sm">
          <Button variant="secondary" disabled={page === 1} onClick={() => setPage((current) => current - 1)}>
            Previous
          </Button>
          <span>Page {data.meta.current_page} of {data.meta.last_page}</span>
          <Button variant="secondary" disabled={page >= data.meta.last_page} onClick={() => setPage((current) => current + 1)}>
            Next
          </Button>
        </nav>
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
