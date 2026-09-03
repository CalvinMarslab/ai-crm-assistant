import { useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { agentApi, opportunityApi, pipelineApi } from '@/api/endpoints'
import { errorMessage } from '@/api/client'
import { PageHeader } from '@/components/PageHeader'
import { Button, Card, EmptyState, ErrorState, Input, Select, Spinner, cx } from '@/components/ui'
import { PriorityDot, StageBadge, WarningBadges } from '@/components/OpportunityBits'
import { OpportunityFormModal } from '@/features/OpportunityFormModal'
import { dueLabel, money, relative } from '@/lib/format'
import { useAuth } from '@/hooks/useAuth'

export default function OpportunityListPage() {
  const { can } = useAuth()
  const [searchParams, setSearchParams] = useSearchParams()
  const [creating, setCreating] = useState(false)

  const filters = {
    search: searchParams.get('search') ?? '',
    stage_code: searchParams.get('stage_code') ?? '',
    status: searchParams.get('status') ?? '',
    agent_id: searchParams.get('agent_id') ?? '',
    without_next_action: searchParams.get('without_next_action') ?? '',
    follow_up_due: searchParams.get('follow_up_due') ?? '',
  }

  function setFilter(key: string, value: string) {
    const next = new URLSearchParams(searchParams)
    value ? next.set(key, value) : next.delete(key)
    setSearchParams(next, { replace: true })
  }

  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['opportunities', filters],
    queryFn: () => opportunityApi.list({ ...filters, per_page: 50 }),
  })

  const { data: pipelines } = useQuery({ queryKey: ['pipelines'], queryFn: pipelineApi.list })
  const { data: agents } = useQuery({ queryKey: ['agents', 'options'], queryFn: () => agentApi.list({ per_page: 200 }) })

  const stages = pipelines?.find((pipeline) => pipeline.is_default)?.stages ?? []
  const activeQuickFilter = filters.without_next_action || filters.follow_up_due

  return (
    <>
      <PageHeader
        title="Opportunities"
        subtitle={data ? `${data.meta.total} matching` : undefined}
        action={
          can('opportunity.create') && (
            <Button onClick={() => setCreating(true)}>+ New opportunity</Button>
          )
        }
      />

      <Card className="mb-4 p-3">
        <div className="flex flex-wrap items-end gap-2">
          <div className="min-w-48 flex-1">
            <Input
              placeholder="Search title or company…"
              value={filters.search}
              onChange={(event) => setFilter('search', event.target.value)}
            />
          </div>

          <Select value={filters.stage_code} onChange={(event) => setFilter('stage_code', event.target.value)} className="w-auto">
            <option value="">All stages</option>
            {stages.map((stage) => (
              <option key={stage.code} value={stage.code}>
                {stage.name}
              </option>
            ))}
          </Select>

          <Select value={filters.status} onChange={(event) => setFilter('status', event.target.value)} className="w-auto">
            <option value="">Any status</option>
            <option value="open">Open</option>
            <option value="won">Won</option>
            <option value="lost">Lost</option>
            <option value="hold">On hold</option>
          </Select>

          <Select value={filters.agent_id} onChange={(event) => setFilter('agent_id', event.target.value)} className="w-auto">
            <option value="">Any agent</option>
            {agents?.data.map((agent) => (
              <option key={agent.id} value={agent.id}>
                {agent.name}
              </option>
            ))}
          </Select>

          <Button
            variant={filters.without_next_action ? 'primary' : 'secondary'}
            size="sm"
            onClick={() => setFilter('without_next_action', filters.without_next_action ? '' : '1')}
          >
            No next action
          </Button>
          <Button
            variant={filters.follow_up_due ? 'primary' : 'secondary'}
            size="sm"
            onClick={() => setFilter('follow_up_due', filters.follow_up_due ? '' : '1')}
          >
            Follow-up due
          </Button>

          {(activeQuickFilter || filters.search || filters.stage_code || filters.status || filters.agent_id) && (
            <Button variant="ghost" size="sm" onClick={() => setSearchParams({}, { replace: true })}>
              Clear
            </Button>
          )}
        </div>
      </Card>

      {isLoading ? (
        <Spinner />
      ) : error ? (
        <ErrorState message={errorMessage(error)} onRetry={() => void refetch()} />
      ) : !data?.data.length ? (
        <Card>
          <EmptyState message="No opportunities match these filters." />
        </Card>
      ) : (
        <Card className="overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full min-w-3xl text-sm">
              <thead className="border-b border-slate-200 bg-slate-50 text-left text-xs font-medium text-slate-500">
                <tr>
                  <th className="px-4 py-2.5">Opportunity</th>
                  <th className="px-4 py-2.5">Stage</th>
                  <th className="px-4 py-2.5">Next action</th>
                  <th className="px-4 py-2.5 text-right">Value</th>
                  <th className="px-4 py-2.5">Follow-up</th>
                  <th className="px-4 py-2.5">Updated</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {data.data.map((opportunity) => {
                  const follow = dueLabel(opportunity.next_follow_up_at)

                  return (
                    <tr key={opportunity.id} className="transition hover:bg-slate-50">
                      <td className="px-4 py-3">
                        <Link to={`/opportunities/${opportunity.id}`} className="flex items-start gap-2">
                          <span className="pt-1.5">
                            <PriorityDot priority={opportunity.priority} />
                          </span>
                          <span className="min-w-0">
                            <span className="block truncate font-medium text-slate-900">{opportunity.title}</span>
                            <span className="block truncate text-xs text-slate-500">
                              {opportunity.company?.name}
                              {opportunity.referral_agent && <span> · via {opportunity.referral_agent.name}</span>}
                            </span>
                            <span className="mt-1 block">
                              <WarningBadges warnings={opportunity.warnings} />
                            </span>
                          </span>
                        </Link>
                      </td>
                      <td className="px-4 py-3">
                        {opportunity.stage && (
                          <StageBadge name={opportunity.stage.name} type={opportunity.stage.stage_type} />
                        )}
                      </td>
                      <td className="max-w-48 px-4 py-3">
                        {opportunity.next_action ? (
                          <span className="block truncate text-slate-700">{opportunity.next_action}</span>
                        ) : opportunity.no_action_reason ? (
                          <span className="block truncate text-xs text-slate-400 italic">
                            {opportunity.no_action_reason}
                          </span>
                        ) : (
                          <span className="text-xs font-medium text-red-600">Not set</span>
                        )}
                      </td>
                      <td className="px-4 py-3 text-right font-medium text-slate-900">
                        {money(opportunity.estimated_value)}
                      </td>
                      <td className="px-4 py-3">
                        <span
                          className={cx(
                            'text-xs',
                            follow.tone === 'overdue'
                              ? 'font-medium text-red-600'
                              : follow.tone === 'today'
                                ? 'font-medium text-amber-600'
                                : 'text-slate-500',
                          )}
                        >
                          {follow.label}
                        </span>
                      </td>
                      <td className="px-4 py-3 text-xs text-slate-400">{relative(opportunity.updated_at)}</td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        </Card>
      )}

      <OpportunityFormModal open={creating} onClose={() => setCreating(false)} />
    </>
  )
}
