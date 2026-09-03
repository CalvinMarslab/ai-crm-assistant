import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { agentApi } from '@/api/endpoints'
import { errorMessage } from '@/api/client'
import { Badge, Button, Card, EmptyState, ErrorState, Spinner } from '@/components/ui'
import { OpportunityRow } from '@/components/OpportunityBits'
import { AgentFormModal } from './AgentListPage'
import { money, shortDate } from '@/lib/format'
import { useAuth } from '@/hooks/useAuth'

export default function AgentDetailPage() {
  const { id = '' } = useParams()
  const { can } = useAuth()
  const [editing, setEditing] = useState(false)

  const { data: agent, isLoading, error, refetch } = useQuery({
    queryKey: ['agent', id],
    queryFn: () => agentApi.get(id),
  })

  const { data: opportunities = [] } = useQuery({
    queryKey: ['agent', id, 'opportunities'],
    queryFn: () => agentApi.opportunities(id),
  })

  if (isLoading) return <Spinner />
  if (error) return <ErrorState message={errorMessage(error)} onRetry={() => void refetch()} />
  if (!agent) return null

  const stats = agent.stats

  return (
    <>
      <div className="mb-4">
        <Link to="/agents" className="text-xs font-medium text-slate-500 hover:text-slate-900">
          ← Agents
        </Link>
      </div>

      <Card className="mb-4 p-5">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <div className="flex items-center gap-2">
              <h1 className="text-lg font-semibold text-slate-900">{agent.name}</h1>
              <Badge tone={agent.status === 'active' ? 'green' : 'slate'}>{agent.status}</Badge>
              {agent.has_portal_access && <Badge tone="blue">Portal access</Badge>}
            </div>
            <p className="mt-0.5 text-sm text-slate-500">{agent.company_name ?? 'Independent'}</p>
          </div>
          {can('agent.manage') && <Button onClick={() => setEditing(true)}>Edit</Button>}
        </div>

        <dl className="mt-4 grid gap-x-6 gap-y-3 border-t border-slate-100 pt-4 sm:grid-cols-3">
          <div>
            <dt className="text-xs font-medium text-slate-500">Email</dt>
            <dd className="mt-0.5 truncate text-sm text-slate-900">{agent.email ?? '—'}</dd>
          </div>
          <div>
            <dt className="text-xs font-medium text-slate-500">Phone</dt>
            <dd className="mt-0.5 text-sm text-slate-900">{agent.phone ?? '—'}</dd>
          </div>
          <div>
            <dt className="text-xs font-medium text-slate-500">Joined</dt>
            <dd className="mt-0.5 text-sm text-slate-900">{shortDate(agent.joined_at)}</dd>
          </div>
        </dl>

        {agent.notes && (
          <p className="mt-3 rounded-lg bg-slate-50 px-3 py-2 text-sm whitespace-pre-wrap text-slate-700">
            {agent.notes}
          </p>
        )}
      </Card>

      {stats && (
        <>
          <div className="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <Stat label="Introduced" value={String(stats.introduced)} sub={`${stats.active} still active`} />
            <Stat label="Won" value={String(stats.won)} sub={money(stats.won_value)} tone="green" />
            <Stat label="Lost" value={String(stats.lost)} />
            <Stat
              label="Conversion"
              value={stats.conversion_rate === null ? '—' : `${stats.conversion_rate}%`}
              sub={`${money(stats.estimated_value)} in play`}
            />
          </div>

          {stats.by_stage.length > 0 && (
            <Card className="mb-4 p-4">
              <h2 className="mb-3 text-sm font-semibold text-slate-900">Open pipeline by stage</h2>
              <div className="space-y-1.5">
                {stats.by_stage.map((row) => (
                  <div key={row.code} className="flex items-center gap-3 text-xs">
                    <span className="w-44 shrink-0 truncate text-slate-600">{row.stage}</span>
                    <span className="h-4 flex-1 overflow-hidden rounded bg-slate-100">
                      <span
                        className="block h-full rounded bg-brand-500"
                        style={{
                          width: `${Math.max((row.count / Math.max(...stats.by_stage.map((s) => s.count))) * 100, 8)}%`,
                        }}
                      />
                    </span>
                    <span className="w-6 text-right font-semibold text-slate-700">{row.count}</span>
                    <span className="w-24 text-right text-slate-400">{money(row.value)}</span>
                  </div>
                ))}
              </div>
            </Card>
          )}
        </>
      )}

      <Card>
        <h2 className="border-b border-slate-100 px-4 py-3 text-sm font-semibold text-slate-900">
          Referred opportunities
        </h2>
        <div className="p-2">
          {!opportunities.length ? (
            <EmptyState message="This agent has not introduced any opportunities yet." />
          ) : (
            opportunities.map((opportunity) => <OpportunityRow key={opportunity.id} opportunity={opportunity} />)
          )}
        </div>
      </Card>

      <AgentFormModal open={editing} onClose={() => setEditing(false)} agent={agent} />
    </>
  )
}

function Stat({ label, value, sub, tone }: { label: string; value: string; sub?: string; tone?: 'green' }) {
  return (
    <Card className="p-4">
      <p className="text-xs font-medium text-slate-500">{label}</p>
      <p className={`mt-1 text-xl font-semibold ${tone === 'green' ? 'text-emerald-700' : 'text-slate-900'}`}>{value}</p>
      {sub && <p className="mt-0.5 text-xs text-slate-400">{sub}</p>}
    </Card>
  )
}
