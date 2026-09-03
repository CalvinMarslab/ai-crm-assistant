import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { companyApi } from '@/api/endpoints'
import { errorMessage } from '@/api/client'
import { Badge, Button, Card, EmptyState, ErrorState, Spinner, cx } from '@/components/ui'
import { OpportunityRow } from '@/components/OpportunityBits'
import { Timeline } from '@/components/Timeline'
import { CompanyFormModal } from './CompanyListPage'
import { OpportunityFormModal } from '@/features/OpportunityFormModal'
import { money } from '@/lib/format'
import { useAuth } from '@/hooks/useAuth'

type Tab = 'opportunities' | 'contacts' | 'timeline'

export default function CompanyDetailPage() {
  const { id = '' } = useParams()
  const { can } = useAuth()
  const [tab, setTab] = useState<Tab>('opportunities')
  const [editing, setEditing] = useState(false)
  const [creatingOpportunity, setCreatingOpportunity] = useState(false)

  const { data: company, isLoading, error, refetch } = useQuery({
    queryKey: ['company', id],
    queryFn: () => companyApi.get(id),
  })

  const { data: opportunities = [] } = useQuery({
    queryKey: ['company', id, 'opportunities'],
    queryFn: () => companyApi.opportunities(id),
  })

  const { data: timeline = [] } = useQuery({
    queryKey: ['company', id, 'timeline'],
    queryFn: () => companyApi.timeline(id),
    enabled: tab === 'timeline',
  })

  if (isLoading) return <Spinner />
  if (error) return <ErrorState message={errorMessage(error)} onRetry={() => void refetch()} />
  if (!company) return null

  const openValue = opportunities
    .filter((opportunity) => opportunity.status === 'open' || opportunity.status === 'hold')
    .reduce((sum, opportunity) => sum + (opportunity.estimated_value ?? 0), 0)

  return (
    <>
      <div className="mb-4">
        <Link to="/companies" className="text-xs font-medium text-slate-500 hover:text-slate-900">
          ← Companies
        </Link>
      </div>

      <Card className="mb-4 p-5">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h1 className="text-lg font-semibold text-slate-900">{company.name}</h1>
            <p className="mt-0.5 text-sm text-slate-500">
              {company.industry ?? 'Industry not recorded'}
              {company.registration_no && ` · ${company.registration_no}`}
            </p>
          </div>
          <div className="flex gap-2">
            {can('opportunity.create') && (
              <Button variant="secondary" onClick={() => setCreatingOpportunity(true)}>
                + Opportunity
              </Button>
            )}
            {can('company.manage') && <Button onClick={() => setEditing(true)}>Edit</Button>}
          </div>
        </div>

        <dl className="mt-4 grid gap-x-6 gap-y-3 border-t border-slate-100 pt-4 sm:grid-cols-2 lg:grid-cols-4">
          <div>
            <dt className="text-xs font-medium text-slate-500">Phone</dt>
            <dd className="mt-0.5 text-sm text-slate-900">{company.phone ?? '—'}</dd>
          </div>
          <div>
            <dt className="text-xs font-medium text-slate-500">Email</dt>
            <dd className="mt-0.5 truncate text-sm text-slate-900">{company.email ?? '—'}</dd>
          </div>
          <div>
            <dt className="text-xs font-medium text-slate-500">Open pipeline</dt>
            <dd className="mt-0.5 text-sm font-semibold text-slate-900">{money(openValue)}</dd>
          </div>
          <div>
            <dt className="text-xs font-medium text-slate-500">Opportunities</dt>
            <dd className="mt-0.5 text-sm text-slate-900">{opportunities.length}</dd>
          </div>
        </dl>

        {company.address && <p className="mt-3 text-sm text-slate-600">{company.address}</p>}
        {company.notes && (
          <p className="mt-3 rounded-lg bg-slate-50 px-3 py-2 text-sm whitespace-pre-wrap text-slate-700">
            {company.notes}
          </p>
        )}
      </Card>

      <Card>
        <nav className="flex gap-1 border-b border-slate-200 px-4">
          {(['opportunities', 'contacts', 'timeline'] as Tab[]).map((entry) => (
            <button
              key={entry}
              onClick={() => setTab(entry)}
              className={cx(
                '-mb-px border-b-2 px-3 py-2.5 text-sm font-medium capitalize transition',
                tab === entry ? 'border-brand-600 text-brand-700' : 'border-transparent text-slate-500 hover:text-slate-900',
              )}
            >
              {entry}
            </button>
          ))}
        </nav>

        <div className="p-4">
          {tab === 'opportunities' &&
            (!opportunities.length ? (
              <EmptyState message="No opportunities for this company yet." />
            ) : (
              <div className="-mx-1">
                {opportunities.map((opportunity) => (
                  <OpportunityRow key={opportunity.id} opportunity={opportunity} />
                ))}
              </div>
            ))}

          {tab === 'contacts' &&
            (!company.contacts?.length ? (
              <EmptyState message="No contacts recorded." />
            ) : (
              <ul className="divide-y divide-slate-100">
                {company.contacts.map((contact) => (
                  <li key={contact.id} className="flex flex-wrap items-center justify-between gap-2 py-3">
                    <div>
                      <p className="flex items-center gap-2 text-sm font-medium text-slate-900">
                        {contact.name}
                        {contact.is_primary && <Badge tone="blue">Primary</Badge>}
                      </p>
                      <p className="mt-0.5 text-xs text-slate-500">{contact.job_title ?? 'Role not recorded'}</p>
                    </div>
                    <div className="text-right text-xs text-slate-500">
                      {contact.email && <p>{contact.email}</p>}
                      {contact.phone && <p>{contact.phone}</p>}
                    </div>
                  </li>
                ))}
              </ul>
            ))}

          {tab === 'timeline' && <Timeline activities={timeline} />}
        </div>
      </Card>

      <CompanyFormModal open={editing} onClose={() => setEditing(false)} company={company} />
      <OpportunityFormModal
        open={creatingOpportunity}
        onClose={() => setCreatingOpportunity(false)}
        defaultCompanyId={company.id}
      />
    </>
  )
}
