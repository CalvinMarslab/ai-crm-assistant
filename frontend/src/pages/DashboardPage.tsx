import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { dashboardApi, taskApi } from '@/api/endpoints'
import { errorMessage } from '@/api/client'
import { PageHeader } from '@/components/PageHeader'
import { Card, EmptyState, ErrorState, SectionCard, Spinner, cx } from '@/components/ui'
import { OpportunityRow, TaskRow } from '@/components/OpportunityBits'
import { Timeline } from '@/components/Timeline'
import { money } from '@/lib/format'
import { useAuth } from '@/hooks/useAuth'
import type { Task } from '@/types'

export default function DashboardPage() {
  const { user } = useAuth()
  const queryClient = useQueryClient()

  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['dashboard'],
    queryFn: dashboardApi.get,
  })

  const completeTask = useMutation({
    mutationFn: (task: Task) => taskApi.complete(task.id),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['dashboard'] })
      void queryClient.invalidateQueries({ queryKey: ['tasks'] })
    },
  })

  if (isLoading) return <Spinner label="Loading your day…" />
  if (error) return <ErrorState message={errorMessage(error)} onRetry={() => void refetch()} />
  if (!data) return null

  const { sections, metrics, stage_distribution: stages, recent_activity: activity, meta } = data
  const firstName = user?.name.split(' ')[0] ?? 'there'
  const needsAttention =
    sections.overdue_tasks.length + sections.follow_ups_due.length + sections.without_next_action.length

  return (
    <>
      <PageHeader
        title={`Good day, ${firstName}`}
        subtitle={
          needsAttention === 0
            ? 'Nothing is overdue and every opportunity has a next action.'
            : `${needsAttention} item${needsAttention === 1 ? '' : 's'} need your attention today.`
        }
      />

      {/* Actions first, metrics second — the dashboard prioritises execution. */}
      <div className="grid gap-4 lg:grid-cols-2 xl:grid-cols-3">
        <SectionCard title="Overdue tasks" count={sections.overdue_tasks.length} tone="danger">
          {sections.overdue_tasks.length === 0 ? (
            <EmptyState message="Nothing overdue. " />
          ) : (
            sections.overdue_tasks.map((task) => (
              <TaskRow key={task.id} task={task} onComplete={(t) => completeTask.mutate(t)} />
            ))
          )}
        </SectionCard>

        <SectionCard title="Follow-ups due" count={sections.follow_ups_due.length} tone="warning">
          {sections.follow_ups_due.length === 0 ? (
            <EmptyState message="No follow-ups due today." />
          ) : (
            sections.follow_ups_due.map((opportunity) => (
              <OpportunityRow key={opportunity.id} opportunity={opportunity} />
            ))
          )}
        </SectionCard>

        <SectionCard title="No next action" count={sections.without_next_action.length} tone="danger">
          {sections.without_next_action.length === 0 ? (
            <EmptyState message="Every open opportunity has a next action." />
          ) : (
            sections.without_next_action.map((opportunity) => (
              <OpportunityRow key={opportunity.id} opportunity={opportunity} />
            ))
          )}
        </SectionCard>

        <SectionCard title="Due today" count={sections.tasks_due_today.length}>
          {sections.tasks_due_today.length === 0 ? (
            <EmptyState message="No tasks due today." />
          ) : (
            sections.tasks_due_today.map((task) => (
              <TaskRow key={task.id} task={task} onComplete={(t) => completeTask.mutate(t)} />
            ))
          )}
        </SectionCard>

        <SectionCard title="Proposals awaiting reply" count={sections.proposals_awaiting_response.length} tone="warning">
          {sections.proposals_awaiting_response.length === 0 ? (
            <EmptyState message="No proposals are waiting on a customer." />
          ) : (
            sections.proposals_awaiting_response.map((opportunity) => (
              <OpportunityRow key={opportunity.id} opportunity={opportunity} />
            ))
          )}
        </SectionCard>

        <SectionCard
          title={`Quiet for ${meta.inactivity_threshold_days}+ days`}
          count={sections.recently_inactive.length}
          tone="warning"
        >
          {sections.recently_inactive.length === 0 ? (
            <EmptyState message="Everything has been touched recently." />
          ) : (
            sections.recently_inactive.map((opportunity) => (
              <OpportunityRow key={opportunity.id} opportunity={opportunity} />
            ))
          )}
        </SectionCard>
      </div>

      <h2 className="mt-8 mb-3 text-sm font-semibold text-slate-900">Pipeline health</h2>

      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <Metric label="Pipeline value" value={money(metrics.pipeline_value)} sub={`${metrics.active_opportunities} active`} />
        <Metric label="Won value" value={money(metrics.won_value)} sub={`${metrics.won_count} deals`} tone="green" />
        <Metric
          label="Win rate"
          value={metrics.win_rate === null ? '—' : `${metrics.win_rate}%`}
          sub={`${metrics.won_count} won / ${metrics.lost_count} lost`}
        />
        <Metric
          label="Leads this month"
          value={String(metrics.leads_this_month)}
          sub={
            metrics.average_sales_cycle_days === null
              ? 'No closed deals yet'
              : `${metrics.average_sales_cycle_days}d average cycle`
          }
        />
      </div>

      <div className="mt-4 grid gap-4 lg:grid-cols-5">
        <Card className="p-4 lg:col-span-3">
          <h3 className="mb-3 text-sm font-semibold text-slate-900">Opportunities by stage</h3>
          <StageChart stages={stages} />
        </Card>

        <Card className="p-4 lg:col-span-2">
          <div className="mb-3 flex items-center justify-between">
            <h3 className="text-sm font-semibold text-slate-900">Recent activity</h3>
            <Link to="/opportunities" className="text-xs font-medium text-brand-600 hover:underline">
              View all
            </Link>
          </div>
          <div className="max-h-96 overflow-y-auto pr-1">
            <Timeline activities={activity.slice(0, 12)} />
          </div>
        </Card>
      </div>
    </>
  )
}

function Metric({
  label,
  value,
  sub,
  tone = 'slate',
}: {
  label: string
  value: string
  sub?: string
  tone?: 'slate' | 'green'
}) {
  return (
    <Card className="p-4">
      <p className="text-xs font-medium text-slate-500">{label}</p>
      <p className={cx('mt-1 text-xl font-semibold', tone === 'green' ? 'text-emerald-700' : 'text-slate-900')}>
        {value}
      </p>
      {sub && <p className="mt-0.5 text-xs text-slate-400">{sub}</p>}
    </Card>
  )
}

function StageChart({
  stages,
}: {
  stages: { stage: string; code: string; count: number; value: number }[]
}) {
  if (!stages.length) {
    return <EmptyState message="No open opportunities yet." />
  }

  const max = Math.max(...stages.map((stage) => stage.count))

  return (
    <div className="space-y-2">
      {stages.map((stage) => (
        <Link
          key={stage.code}
          to={`/opportunities?stage_code=${stage.code}`}
          className="group flex items-center gap-3 rounded-lg px-1 py-1 transition hover:bg-slate-50"
        >
          <span className="w-44 shrink-0 truncate text-xs text-slate-600">{stage.stage}</span>
          <span className="h-5 flex-1 overflow-hidden rounded bg-slate-100">
            <span
              className="block h-full rounded bg-brand-500 transition group-hover:bg-brand-600"
              style={{ width: `${Math.max((stage.count / max) * 100, 6)}%` }}
            />
          </span>
          <span className="w-6 shrink-0 text-right text-xs font-semibold text-slate-700">{stage.count}</span>
          <span className="w-24 shrink-0 text-right text-xs text-slate-400">{money(stage.value)}</span>
        </Link>
      ))}
    </div>
  )
}
