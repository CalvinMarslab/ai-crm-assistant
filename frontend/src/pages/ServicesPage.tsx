import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { serviceApi, type ServiceSubscription } from '@/api/services'
import { companyApi } from '@/api/endpoints'
import { errorMessage, validationErrors } from '@/api/client'
import { useAuth } from '@/hooks/useAuth'
import { PageHeader } from '@/components/PageHeader'
import { Modal, ModalFooter } from '@/components/Modal'
import {
  Badge,
  Button,
  Card,
  EmptyState,
  ErrorState,
  Field,
  Input,
  Select,
  Spinner,
  Textarea,
} from '@/components/ui'

const amount = (value: string | null, currency: string | null) =>
  value === null
    ? 'Price needed'
    : !currency
      ? `${Number(value).toFixed(2)} (currency needed)`
      : new Intl.NumberFormat('en-MY', { style: 'currency', currency }).format(Number(value))

const detailLabels: Record<string, string> = {
  customer_email: 'customer email', amount: 'price', currency: 'currency', renewal_months: 'renewal period',
}

export default function ServicesPage() {
  const { can } = useAuth()
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)
  const [editing, setEditing] = useState<ServiceSubscription | 'new' | null>(
    null,
  )
  const [detailId, setDetailId] = useState<string | null>(null)
  const list = useQuery({
    queryKey: ['services', search, status, page],
    queryFn: () => serviceApi.list({ search, status, page }),
    enabled: can('service.view'),
  })
  const summary = useQuery({
    queryKey: ['renewal-summary'],
    queryFn: serviceApi.summary,
    enabled: can('service.view'),
  })

  if (!can('service.view'))
    return <ErrorState message="You do not have permission to view services." />

  return (
    <>
      <PageHeader
        title="Services & renewals"
        subtitle="Maintenance and server renewals · Email reminders 15 days before expiry"
        action={
          can('service.manage') && (
            <Button onClick={() => setEditing('new')}>+ New service</Button>
          )
        }
      />
      {summary.error && (
        <ErrorState
          message={errorMessage(summary.error)}
          onRetry={() => void summary.refetch()}
        />
      )}
      {summary.data && (
        <>
          <div className="mb-5 grid gap-3 sm:grid-cols-3">
            {(
              [
                ['Expiring in 15 days', summary.data.expiring],
                ['Overdue', summary.data.overdue],
                ['Active services', summary.data.active],
              ] as const
            ).map(([label, count]) => (
              <Card key={label} className="p-4">
                <p className="text-xs text-slate-500">{label}</p>
                <p className="mt-2 text-2xl font-semibold">{count}</p>
              </Card>
            ))}
          </div>
          <p className="mb-4 text-sm text-slate-600">
            Pending collection (due within 15 days and overdue):{' '}
            {summary.data.pending.length
              ? summary.data.pending
                  .map((p) => amount(p.amount, p.currency))
                  .join(' · ')
              : 'None'}
          </p>
          {!summary.data.email_enabled && (
            <div className="mb-4 rounded-lg bg-amber-50 p-3 text-sm text-amber-900">
              Email sending is not enabled. Records and collection tasks work;
              customer emails will start after the mail service is configured.
            </div>
          )}
        </>
      )}
      <div className="mb-4 flex flex-wrap gap-3">
        <Input
          className="max-w-sm"
          placeholder="Search service or company…"
          aria-label="Search services"
          value={search}
          onChange={(e) => {
            setSearch(e.target.value)
            setPage(1)
          }}
        />
        <Select
          className="max-w-xs"
          aria-label="Filter services"
          value={status}
          onChange={(e) => {
            setStatus(e.target.value)
            setPage(1)
          }}
        >
          <option value="">All services</option>
          <option value="expiring">Expiring in 15 days</option>
          <option value="overdue">Overdue</option>
          <option value="active">Active</option>
          <option value="paused">Paused</option>
          <option value="cancelled">Cancelled</option>
        </Select>
      </div>
      {list.data && <p className="mb-3 text-sm text-slate-500">{list.data.meta.total} service(s)</p>}
      {list.isLoading ? (
        <Spinner />
      ) : list.error ? (
        <ErrorState
          message={errorMessage(list.error)}
          onRetry={() => void list.refetch()}
        />
      ) : !list.data?.data.length ? (
        <Card>
          <EmptyState message="No services match. Add a maintenance or server service to start tracking renewals." />
        </Card>
      ) : (
        <Card>
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead className="border-b border-slate-100 text-xs text-slate-500">
                <tr>
                  {[
                    'Service / customer',
                    'Expiry',
                    'Renewal fee',
                    'Status',
                    '',
                  ].map((h, i) => (
                    <th key={i} className="px-4 py-3">
                      {h}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {list.data.data.map((service) => (
                  <tr
                    key={service.id}
                    className="border-b border-slate-100 last:border-0"
                  >
                    <td className="px-4 py-4">
                      <button
                        className="font-semibold text-brand-700 hover:underline"
                        onClick={() => setDetailId(service.id)}
                      >
                        {service.name}
                      </button>
                      <p className="mt-1 text-xs text-slate-500">
                        {service.company?.name ?? 'Company unavailable'} ·{' '}
                        {service.type}
                      </p>
                    </td>
                    <td className="px-4 py-4">{service.expires_on}</td>
                    <td className="px-4 py-4">
                      {amount(service.amount, service.currency)}
                      <p className="text-xs text-slate-500">
                        {service.renewal_months ? `Every ${service.renewal_months} month(s)` : 'Renewal period needed'}
                      </p>
                    </td>
                    <td className="px-4 py-4">
                      <Badge
                        tone={
                          service.renewal_status === 'overdue'
                            ? 'red'
                            : service.renewal_status === 'expiring'
                              ? 'amber'
                              : service.renewal_status === 'active'
                                ? 'green'
                                : 'slate'
                        }
                      >
                        {service.renewal_status}
                      </Badge>
                      {!!service.missing_details?.length && <p className="mt-1 text-xs text-amber-700">Needs details</p>}
                    </td>
                    <td className="px-4 py-4">
                      <Button
                        variant="secondary"
                        size="sm"
                        onClick={() => setDetailId(service.id)}
                      >
                        View / collect
                      </Button>
                      {can('service.manage') && (
                        <Button
                          variant="ghost"
                          size="sm"
                          onClick={() => setEditing(service)}
                        >
                          Edit
                        </Button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Card>
      )}
      {list.data && list.data.meta.last_page > 1 && (
        <div className="mt-4 flex items-center justify-end gap-3 text-sm">
          <Button
            variant="secondary"
            disabled={page === 1}
            onClick={() => setPage(page - 1)}
          >
            Previous
          </Button>
          <span>
            {page} / {list.data.meta.last_page}
          </span>
          <Button
            variant="secondary"
            disabled={page === list.data.meta.last_page}
            onClick={() => setPage(page + 1)}
          >
            Next
          </Button>
        </div>
      )}
      {editing && (
        <ServiceForm
          key={editing === 'new' ? 'new' : editing.id}
          service={editing === 'new' ? undefined : editing}
          defaultEmail={
            summary.data?.default_internal_email ?? 'support@techweiser.my'
          }
          onClose={() => setEditing(null)}
        />
      )}
      {detailId && (
        <ServiceDetail id={detailId} onClose={() => setDetailId(null)} />
      )}
    </>
  )
}

function ServiceForm({
  service,
  defaultEmail,
  onClose,
}: {
  service?: ServiceSubscription
  defaultEmail: string
  onClose: () => void
}) {
  const cache = useQueryClient()
  const [companySearch, setCompanySearch] = useState('')
  const companies = useQuery({
    queryKey: ['companies', 'service-picker', companySearch],
    queryFn: () => companyApi.list({ search: companySearch, per_page: 50 }),
  })
  const [form, setForm] = useState({
    name: service?.name ?? '',
    company_id: service?.company?.id ?? '',
    type: service?.type ?? 'maintenance',
    customer_email: service?.customer_email ?? '',
    internal_email: service?.internal_email ?? defaultEmail,
    amount: service?.amount ?? '',
    currency: service ? (service.currency ?? '') : 'MYR',
    renewal_months: service ? (service.renewal_months === null ? '' : String(service.renewal_months)) : '12',
    expires_on: service?.expires_on ?? '',
    status: service?.status ?? 'active',
    notes: service?.notes ?? '',
  })
  const save = useMutation({
    mutationFn: () =>
      serviceApi.save(service?.id, {
        ...form,
        customer_email: form.customer_email || null,
        amount: form.amount === '' ? null : form.amount,
        currency: form.currency || null,
        renewal_months: form.renewal_months === '' ? null : Number(form.renewal_months),
        ...(service ? { cycle_id: service.cycle_id } : {}),
      }),
    onSuccess: () => {
      void cache.invalidateQueries({ queryKey: ['services'] })
      void cache.invalidateQueries({ queryKey: ['renewal-summary'] })
      onClose()
    },
  })
  const errors = validationErrors(save.error)
  const field = (key: keyof typeof form) => ({
    value: form[key],
    onChange: (e: { target: { value: string } }) =>
      setForm((f) => ({ ...f, [key]: e.target.value })),
  })
  return (
    <Modal
      open
      title={service ? 'Edit service' : 'New service'}
      onClose={onClose}
      footer={
        <ModalFooter
          onCancel={onClose}
          onConfirm={() => save.mutate()}
          pending={save.isPending}
        />
      }
    >
      {save.error && (
        <div className="mb-4">
          <ErrorState message={errorMessage(save.error)} />
        </div>
      )}
      <div className="grid gap-4 sm:grid-cols-2">
        <div className="sm:col-span-2">
          <Field label="Service name" required error={errors.name}>
            <Input
              {...field('name')}
              placeholder="e.g. Website server hosting"
            />
          </Field>
        </div>
        <div className="sm:col-span-2">
          <Field label="Find company">
            <Input
              value={companySearch}
              onChange={(e) => setCompanySearch(e.target.value)}
              placeholder="Search existing companies…"
            />
          </Field>
        </div>
        <div className="sm:col-span-2">
          <Field label="Company" required error={errors.company_id}>
            <Select {...field('company_id')} disabled={!!service}>
              <option value="">Select company</option>
              {service?.company &&
                !companies.data?.data.some(
                  (c) => c.id === service.company?.id,
                ) && (
                  <option value={service.company.id}>
                    {service.company.name}
                  </option>
                )}
              {companies.data?.data.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name}
                </option>
              ))}
            </Select>
          </Field>
          {companies.error && (
            <ErrorState message={errorMessage(companies.error)} />
          )}
        </div>
        <Field label="Service type" error={errors.type}>
          <Select {...field('type')}>
            <option value="maintenance">Maintenance</option>
            <option value="server">Server</option>
          </Select>
        </Field>
        <Field label="Status" error={errors.status}>
          <Select {...field('status')}>
            <option value="active">Active</option>
            <option value="paused">Paused — stop reminders</option>
            <option value="cancelled">Cancelled — stop reminders</option>
          </Select>
        </Field>
        {form.status !== 'active' && <p className="sm:col-span-2 text-sm text-amber-800">Reminders are stopped. Missing renewal details can be saved and completed before activating.</p>}
        <Field label="Customer email" required={form.status === 'active'} error={errors.customer_email}>
          <Input type="email" {...field('customer_email')} />
        </Field>
        <Field
          label="Internal collection email"
          required
          error={errors.internal_email}
        >
          <Input type="email" {...field('internal_email')} />
        </Field>
        <Field label="Renewal amount" required={form.status === 'active'} error={errors.amount}>
          <Input type="number" min="0" step="0.01" {...field('amount')} />
        </Field>
        <Field label="Currency" error={errors.currency}>
          <Select {...field('currency')}>
            <option value="">Not confirmed</option>
            <option>MYR</option>
            <option>SGD</option>
            <option>USD</option>
          </Select>
        </Field>
        <Field
          label="Expiry date"
          required
          error={errors.expires_on}
          hint="Renewal starts from this date, even for late payments."
        >
          <Input
            type="date"
            {...field('expires_on')}
            onInput={(e) => {
              const expiresOn = e.currentTarget.value
              setForm((f) => ({ ...f, expires_on: expiresOn }))
            }}
          />
        </Field>
        <Field
          label="Renew every (months)"
          required={form.status === 'active'}
          error={errors.renewal_months}
        >
          <Input type="number" min="1" max="120" {...field('renewal_months')} />
        </Field>
        <div className="sm:col-span-2">
          <Field label="Internal notes" error={errors.notes}>
            <Textarea {...field('notes')} />
          </Field>
        </div>
      </div>
    </Modal>
  )
}

function ServiceDetail({ id, onClose }: { id: string; onClose: () => void }) {
  const { can } = useAuth()
  const cache = useQueryClient()
  const detail = useQuery({
    queryKey: ['services', 'detail', id],
    queryFn: () => serviceApi.get(id),
  })
  const [paying, setPaying] = useState(false)
  const [reference, setReference] = useState('')
  const [paidOn, setPaidOn] = useState(
    new Intl.DateTimeFormat('en-CA', {
      timeZone: 'Asia/Kuala_Lumpur',
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
    }).format(new Date()),
  )
  const refresh = () => {
    void cache.invalidateQueries({ queryKey: ['services'] })
    void cache.invalidateQueries({ queryKey: ['renewal-summary'] })
    void cache.invalidateQueries({ queryKey: ['dashboard'] })
    void cache.invalidateQueries({ queryKey: ['tasks'] })
  }
  const renew = useMutation({
    mutationFn: () =>
      serviceApi.renew(id, {
        cycle_id: detail.data?.cycle_id,
        expected_amount: detail.data?.amount,
        expected_currency: detail.data?.currency,
        paid_on: paidOn,
        reference,
      }),
    onSuccess: () => {
      refresh()
      setPaying(false)
    },
  })
  const retry = useMutation({
    mutationFn: (deliveryId: string) => serviceApi.retry(id, deliveryId),
    onSuccess: refresh,
  })
  const s = detail.data
  return (
    <Modal
      open
      width="lg"
      title={s?.name ?? 'Service details'}
      onClose={onClose}
      footer={
        <Button variant="secondary" onClick={onClose}>
          Close
        </Button>
      }
    >
      {detail.isLoading ? (
        <Spinner />
      ) : detail.error ? (
        <ErrorState message={errorMessage(detail.error)} />
      ) : (
        s && (
          <div className="space-y-5">
            <div>
              <Link
                className="text-brand-700 underline"
                to={`/companies/${s.company?.id}`}
              >
                {s.company?.name ?? 'Company unavailable'}
              </Link>
              <p className="mt-2 text-sm">
                {s.type} · Expires {s.expires_on} ·{' '}
                {amount(s.amount, s.currency)} · {s.renewal_months ? `Every ${s.renewal_months} month(s)` : 'Renewal period needed'}
              </p>
              <p className="mt-1 text-xs text-slate-500">
                Customer: {s.customer_email || 'Email needed'} · Internal: {s.internal_email}
              </p>
            </div>
            {!!s.missing_details?.length && <p className="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">Needs details: {s.missing_details.map((key) => detailLabels[key] ?? key).join(', ')}. Complete these in Edit before activating reminders or recording payment.</p>}
            {s.notes && (
              <p className="whitespace-pre-wrap text-sm text-slate-600">
                {s.notes}
              </p>
            )}
            {can('service.manage') &&
              s.status === 'active' &&
              !s.missing_details?.length &&
              (!paying ? (
                <Button onClick={() => setPaying(true)}>
                  Record payment & renew
                </Button>
              ) : (
                <Card className="space-y-3 p-4">
                  <p className="text-sm">
                    Confirm receipt of{' '}
                    <strong>{amount(s.amount, s.currency)}</strong>. This
                    records a full payment and extends the current expiry by{' '}
                    {s.renewal_months} month(s).
                  </p>
                  <Field label="Payment received date">
                    <Input
                      type="date"
                      value={paidOn}
                      onChange={(e) => setPaidOn(e.target.value)}
                      onInput={(e) => setPaidOn(e.currentTarget.value)}
                    />
                  </Field>
                  <Field label="Receipt / bank reference (optional)">
                    <Input
                      value={reference}
                      onChange={(e) => setReference(e.target.value)}
                    />
                  </Field>
                  {renew.error && (
                    <ErrorState message={errorMessage(renew.error)} />
                  )}
                  <div className="flex gap-2">
                    <Button
                      disabled={renew.isPending}
                      onClick={() => renew.mutate()}
                    >
                      Confirm received & renew
                    </Button>
                    <Button
                      variant="secondary"
                      disabled={renew.isPending}
                      onClick={() => setPaying(false)}
                    >
                      Cancel
                    </Button>
                  </div>
                </Card>
              ))}
            <section>
              <h3 className="mb-2 text-sm font-semibold">Payment history</h3>
              {!s.payments?.length ? (
                <EmptyState message="No renewal payments recorded." />
              ) : (
                s.payments.map((p) => (
                  <div
                    className="border-b border-slate-100 py-3 text-sm"
                    key={p.id}
                  >
                    <p>
                      {p.paid_on} · {amount(p.amount, p.currency)} ·{' '}
                      {p.reference ?? 'No reference'}
                    </p>
                    <p className="text-xs text-slate-500">
                      Expiry: {p.previous_expires_on} → {p.next_expires_on}
                    </p>
                  </div>
                ))
              )}
            </section>
            <section>
              <h3 className="mb-2 text-sm font-semibold">Email history</h3>
              {retry.error && (
                <ErrorState message={errorMessage(retry.error)} />
              )}
              <p className="mb-2 text-xs text-slate-500">
                Sent means accepted by the mail service. Check the recipient
                before retrying an uncertain delivery; it may already have
                arrived.
              </p>
              {!s.deliveries?.length ? (
                <EmptyState message="No reminders yet. The scheduler checks services hourly and creates reminders from 15 days before expiry." />
              ) : (
                s.deliveries.map((d) => (
                  <div
                    className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 py-3 text-sm"
                    key={d.id}
                  >
                    <div>
                      <p>
                        {d.audience} · {d.recipient}
                      </p>
                      <p className="text-xs text-slate-500">
                        {d.status}
                        {d.sent_at ? ` · ${d.sent_at}` : ''}
                        {d.error ? ` · ${d.error}` : ''}
                      </p>
                    </div>
                    {can('service.manage') &&
                      s.status === 'active' &&
                      d.cycle_id === s.cycle_id &&
                      ['failed', 'uncertain'].includes(d.status) && (
                        <Button
                          variant="secondary"
                          size="sm"
                          disabled={retry.isPending}
                          onClick={() => {
                            if (
                              d.status !== 'uncertain' ||
                              window.confirm(
                                'This email may already have arrived. Send it again?',
                              )
                            )
                              retry.mutate(d.id)
                          }}
                        >
                          Retry email
                        </Button>
                      )}
                  </div>
                ))
              )}
            </section>
          </div>
        )
      )}
    </Modal>
  )
}
