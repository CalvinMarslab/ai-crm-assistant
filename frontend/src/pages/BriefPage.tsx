import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { aiApi } from '@/api/endpoints'
import { errorMessage } from '@/api/client'
import { PageHeader } from '@/components/PageHeader'
import { Card, EmptyState, ErrorState, SectionCard, Spinner, cx } from '@/components/ui'
import { dateTime, money, shortDate } from '@/lib/format'
import type { BriefOpportunity, BriefTask } from '@/types'

/**
 * The daily brief. Every number here is counted from the user's own records —
 * no model is involved — so it is presented as fact, with the derived
 * suggestions kept visually separate.
 */
export default function BriefPage() {
  const { data: brief, isLoading, error, refetch } = useQuery({
    queryKey: ['ai', 'daily-brief'],
    queryFn: aiApi.dailyBrief,
  })

  if (isLoading) return <Spinner label="Working out your day…" />
  if (error) return <ErrorState message={errorMessage(error)} onRetry={() => void refetch()} />
  if (!brief) return null

  const total = Object.values(brief.counts).reduce((sum, count) => sum + count, 0)
  const { sections } = brief

  return (
    <>
      <PageHeader
        title="Your daily brief"
        subtitle={
          total === 0
            ? 'Nothing needs your attention today.'
            : `${total} item${total === 1 ? '' : 's'} across your pipeline and delivery.`
        }
      />

      {total === 0 ? (
        <Card className="p-8 text-center">
          <p className="text-sm font-medium text-slate-900">You are clear.</p>
          <p className="mt-1 text-sm text-slate-500">
            Nothing is overdue, and every open opportunity has a next action.
          </p>
        </Card>
      ) : (
        <>
          {brief.top_priorities.length > 0 && (
            <Card className="mb-4 p-5">
              <h2 className="text-sm font-semibold text-slate-900">Start here</h2>
              <ol className="mt-3 space-y-2.5">
                {brief.top_priorities.map((priority, index) => (
                  <li key={`${priority.reference}-${index}`} className="flex items-start gap-3">
                    <span className="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full bg-brand-100 text-xs font-semibold text-brand-700">
                      {index + 1}
                    </span>
                    <div className="min-w-0">
                      <p className="text-sm font-medium text-slate-900">
                        {priority.type === 'opportunity' ? (
                          <Link to={`/opportunities/${priority.reference}`} className="hover:text-brand-600 hover:underline">
                            {priority.what}
                          </Link>
                        ) : (
                          priority.what
                        )}
                      </p>
                      <p className="text-xs text-slate-500">
                        <span className="font-medium text-amber-700">{priority.why}</span>
                        {priority.detail && <span> · {priority.detail}</span>}
                      </p>
                    </div>
                  </li>
                ))}
              </ol>
            </Card>
          )}

          <div className="grid gap-4 lg:grid-cols-2">
            <SectionCard title="Overdue tasks" count={sections.overdue_tasks.length} tone="danger">
              {sections.overdue_tasks.length === 0 ? (
                <EmptyState message="Nothing overdue." />
              ) : (
                sections.overdue_tasks.map((task) => <TaskLine key={task.reference} task={task} />)
              )}
            </SectionCard>

            <SectionCard title="Due today" count={sections.tasks_due_today.length} tone="warning">
              {sections.tasks_due_today.length === 0 ? (
                <EmptyState message="Nothing due today." />
              ) : (
                sections.tasks_due_today.map((task) => <TaskLine key={task.reference} task={task} />)
              )}
            </SectionCard>

            <SectionCard title="Follow-ups due" count={sections.follow_ups_due.length} tone="warning">
              {sections.follow_ups_due.length === 0 ? (
                <EmptyState message="No follow-ups due." />
              ) : (
                sections.follow_ups_due.map((o) => <OpportunityLine key={o.reference} opportunity={o} />)
              )}
            </SectionCard>

            <SectionCard title="No next action" count={sections.opportunities_without_next_action.length} tone="danger">
              {sections.opportunities_without_next_action.length === 0 ? (
                <EmptyState message="Every open deal has a next step." />
              ) : (
                sections.opportunities_without_next_action.map((o) => (
                  <OpportunityLine key={o.reference} opportunity={o} />
                ))
              )}
            </SectionCard>

            <SectionCard title="Quotations waiting" count={sections.proposals_awaiting_response.length} tone="warning">
              {sections.proposals_awaiting_response.length === 0 ? (
                <EmptyState message="No quotations awaiting a reply." />
              ) : (
                sections.proposals_awaiting_response.map((o) => <OpportunityLine key={o.reference} opportunity={o} />)
              )}
            </SectionCard>

            <SectionCard title="Projects needing an update" count={sections.projects_requiring_update.length}>
              {sections.projects_requiring_update.length === 0 ? (
                <EmptyState message="Delivery is up to date." />
              ) : (
                sections.projects_requiring_update.map((project) => (
                  <Link
                    key={project.reference}
                    to={`/projects/${project.reference}`}
                    className="block rounded-lg px-3 py-2.5 transition hover:bg-slate-50"
                  >
                    <p className="truncate text-sm font-medium text-slate-900">{project.name}</p>
                    <p className="mt-0.5 truncate text-xs text-slate-500">
                      <span className="text-amber-700">{project.reason}</span>
                      {project.manager && <span> · {project.manager}</span>}
                    </p>
                  </Link>
                ))
              )}
            </SectionCard>
          </div>

          {brief.suggested_actions.length > 0 && (
            <Card className="mt-4 p-5">
              {/* Kept apart from the sections above: those are records, these
                  are inferences from them. */}
              <h2 className="text-sm font-semibold text-slate-900">Suggested</h2>
              <p className="mt-0.5 text-xs text-slate-500">
                Worked out from the items above, not recorded in the CRM.
              </p>
              <ul className="mt-3 space-y-2">
                {brief.suggested_actions.map((suggestion, index) => (
                  <li key={index} className="flex gap-2 text-sm text-slate-700">
                    <span className="text-slate-300">—</span>
                    {suggestion}
                  </li>
                ))}
              </ul>
            </Card>
          )}
        </>
      )}

      <p className="mt-4 text-xs text-slate-400">
        Counted from your records at {dateTime(brief.generated_at)} ({brief.timezone}).
      </p>
    </>
  )
}

function TaskLine({ task }: { task: BriefTask }) {
  return (
    <div className="flex items-start justify-between gap-3 px-3 py-2.5">
      <div className="min-w-0">
        <p className="truncate text-sm font-medium text-slate-900">{task.title}</p>
        <p className="mt-0.5 truncate text-xs text-slate-500">
          {task.attached_to ?? 'No linked record'}
          {task.assignee && <span> · {task.assignee}</span>}
        </p>
      </div>
      <span className={cx('shrink-0 text-xs', task.overdue_by ? 'font-medium text-red-600' : 'text-slate-500')}>
        {task.overdue_by ?? shortDate(task.due_at)}
      </span>
    </div>
  )
}

function OpportunityLine({ opportunity }: { opportunity: BriefOpportunity }) {
  return (
    <Link
      to={`/opportunities/${opportunity.reference}`}
      className="flex items-start justify-between gap-3 rounded-lg px-3 py-2.5 transition hover:bg-slate-50"
    >
      <div className="min-w-0">
        <p className="truncate text-sm font-medium text-slate-900">{opportunity.title}</p>
        <p className="mt-0.5 truncate text-xs text-slate-500">
          {opportunity.company}
          {opportunity.next_action ? (
            <span> · {opportunity.next_action}</span>
          ) : (
            <span className="text-red-600"> · no next action</span>
          )}
        </p>
      </div>
      {opportunity.estimated_value !== null && (
        <span className="shrink-0 text-sm font-semibold text-slate-900">{money(opportunity.estimated_value)}</span>
      )}
    </Link>
  )
}
