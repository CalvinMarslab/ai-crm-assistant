import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { opportunityApi, pipelineApi } from '@/api/endpoints'
import { errorMessage } from '@/api/client'
import { PageHeader } from '@/components/PageHeader'
import { Button, Card, ErrorState, Spinner, cx } from '@/components/ui'
import { PriorityDot, WarningBadges } from '@/components/OpportunityBits'
import { StageChangeModal } from '@/features/StageChangeModal'
import { OpportunityFormModal } from '@/features/OpportunityFormModal'
import { dueLabel, money } from '@/lib/format'
import { useAuth } from '@/hooks/useAuth'
import type { Opportunity } from '@/types'

/**
 * A column per stage. Cards carry the facts needed to decide what to do next,
 * and the stage control is one click away without leaving the board.
 */
export default function OpportunityBoardPage() {
  const { can } = useAuth()
  const [moving, setMoving] = useState<Opportunity | null>(null)
  const [creating, setCreating] = useState(false)

  const { data: pipelines, isLoading: loadingPipelines } = useQuery({
    queryKey: ['pipelines'],
    queryFn: pipelineApi.list,
  })

  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['opportunities', 'board'],
    queryFn: () => opportunityApi.list({ status: 'open', per_page: 200 }),
  })

  if (isLoading || loadingPipelines) return <Spinner label="Loading the pipeline…" />
  if (error) return <ErrorState message={errorMessage(error)} onRetry={() => void refetch()} />

  const pipeline = pipelines?.find((entry) => entry.is_default) ?? pipelines?.[0]
  const openStages = pipeline?.stages.filter((stage) => stage.stage_type === 'open' && stage.is_active !== false) ?? []
  const opportunities = data?.data ?? []

  const totalValue = opportunities.reduce((sum, item) => sum + (item.estimated_value ?? 0), 0)

  return (
    <>
      <PageHeader
        title="Pipeline"
        subtitle={`${opportunities.length} open · ${money(totalValue)} in play`}
        action={can('opportunity.create') && <Button onClick={() => setCreating(true)}>+ New opportunity</Button>}
      />

      <div className="-mx-4 overflow-x-auto px-4 pb-4 sm:-mx-6 sm:px-6">
        <div className="flex gap-3">
          {openStages.map((stage) => {
            const cards = opportunities.filter((item) => item.stage?.id === stage.id)
            const value = cards.reduce((sum, card) => sum + (card.estimated_value ?? 0), 0)

            return (
              <div key={stage.id} className="flex w-72 shrink-0 flex-col">
                <div className="mb-2 flex items-baseline justify-between px-1">
                  <h2 className="truncate text-xs font-semibold text-slate-700">{stage.name}</h2>
                  <span className="text-xs text-slate-400">{cards.length}</span>
                </div>
                <p className="mb-2 px-1 text-xs text-slate-400">{money(value)}</p>

                <div className="flex-1 space-y-2 rounded-xl bg-slate-100/70 p-2">
                  {cards.length === 0 ? (
                    <p className="px-2 py-6 text-center text-xs text-slate-400">Empty</p>
                  ) : (
                    cards.map((opportunity) => {
                      const follow = dueLabel(opportunity.next_follow_up_at)

                      return (
                        <Card key={opportunity.id} className="p-3">
                          <Link to={`/opportunities/${opportunity.id}`} className="block">
                            <div className="flex items-start gap-2">
                              <span className="pt-1.5">
                                <PriorityDot priority={opportunity.priority} />
                              </span>
                              <span className="min-w-0 flex-1">
                                <span className="block truncate text-sm font-medium text-slate-900">
                                  {opportunity.title}
                                </span>
                                <span className="block truncate text-xs text-slate-500">
                                  {opportunity.company?.name}
                                </span>
                              </span>
                            </div>

                            <p className="mt-2 text-sm font-semibold text-slate-900">
                              {money(opportunity.estimated_value)}
                            </p>

                            {opportunity.next_action ? (
                              <p className="mt-1.5 line-clamp-2 text-xs text-slate-600">{opportunity.next_action}</p>
                            ) : (
                              <p className="mt-1.5 text-xs font-medium text-red-600">No next action</p>
                            )}

                            {opportunity.next_follow_up_at && (
                              <p
                                className={cx(
                                  'mt-1 text-xs',
                                  follow.tone === 'overdue'
                                    ? 'font-medium text-red-600'
                                    : follow.tone === 'today'
                                      ? 'font-medium text-amber-600'
                                      : 'text-slate-400',
                                )}
                              >
                                {follow.label}
                              </p>
                            )}

                            <div className="mt-2">
                              <WarningBadges warnings={opportunity.warnings?.slice(0, 2)} />
                            </div>
                          </Link>

                          {can('opportunity.stage.change') && (
                            <Button
                              variant="secondary"
                              size="sm"
                              className="mt-2 w-full"
                              onClick={() => setMoving(opportunity)}
                            >
                              Move stage
                            </Button>
                          )}
                        </Card>
                      )
                    })
                  )}
                </div>
              </div>
            )
          })}
        </div>
      </div>

      {moving && (
        <StageChangeModal open onClose={() => setMoving(null)} opportunity={moving} />
      )}
      <OpportunityFormModal open={creating} onClose={() => setCreating(false)} />
    </>
  )
}
