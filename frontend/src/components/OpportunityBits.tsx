import { Link } from 'react-router-dom'
import { Badge, cx } from './ui'
import { dueLabel, money, shortDate } from '@/lib/format'
import type { HygieneWarning, Opportunity, StageType, Task } from '@/types'

export function StageBadge({ name, type }: { name: string; type: StageType }) {
  const tone = { open: 'blue', won: 'green', lost: 'red', hold: 'amber' } as const

  return <Badge tone={tone[type]}>{name}</Badge>
}

export function PriorityDot({ priority }: { priority: string | null }) {
  const colors: Record<string, string> = {
    low: 'bg-slate-300',
    normal: 'bg-brand-400',
    high: 'bg-amber-500',
    urgent: 'bg-red-500',
  }

  return (
    <span
      className={cx('inline-block size-2 shrink-0 rounded-full', colors[priority ?? 'normal'])}
      title={`${priority ?? 'normal'} priority`}
    />
  )
}

/** The hygiene rules from CRM_WORKFLOW.md, surfaced where the user can act on them. */
export function WarningBadges({ warnings }: { warnings?: HygieneWarning[] }) {
  if (!warnings?.length) return null

  const labels: Record<string, string> = {
    no_owner: 'No owner',
    no_next_action: 'No next action',
    stale: 'Gone quiet',
    proposal_without_follow_up: 'No follow-up on proposal',
    close_date_passed: 'Close date passed',
    follow_up_overdue: 'Follow-up overdue',
  }

  return (
    <div className="flex flex-wrap gap-1">
      {warnings.map((warning) => (
        <span key={warning.code} title={warning.message}>
          <Badge tone={warning.code === 'no_next_action' || warning.code === 'no_owner' ? 'red' : 'amber'}>
            {labels[warning.code] ?? warning.code}
          </Badge>
        </span>
      ))}
    </div>
  )
}

export function OpportunityRow({ opportunity, showValue = true }: { opportunity: Opportunity; showValue?: boolean }) {
  const follow = dueLabel(opportunity.next_follow_up_at)

  return (
    <Link
      to={`/opportunities/${opportunity.id}`}
      className="flex items-start justify-between gap-3 rounded-lg px-3 py-2.5 transition hover:bg-slate-50"
    >
      <div className="min-w-0 flex-1">
        <div className="flex items-center gap-2">
          <PriorityDot priority={opportunity.priority} />
          <span className="truncate text-sm font-medium text-slate-900">{opportunity.title}</span>
        </div>
        <p className="mt-0.5 truncate text-xs text-slate-500">
          {opportunity.company?.name}
          {opportunity.owner && <span> · {opportunity.owner.name}</span>}
        </p>
        {opportunity.next_action ? (
          <p className="mt-1 truncate text-xs text-slate-600">
            <span className="font-medium text-slate-500">Next:</span> {opportunity.next_action}
          </p>
        ) : (
          <p className="mt-1 text-xs text-red-600">No next action recorded</p>
        )}
      </div>
      <div className="flex shrink-0 flex-col items-end gap-1">
        {showValue && opportunity.estimated_value !== undefined && (
          <span className="text-sm font-semibold text-slate-900">{money(opportunity.estimated_value)}</span>
        )}
        {opportunity.stage && <StageBadge name={opportunity.stage.name} type={opportunity.stage.stage_type} />}
        {opportunity.next_follow_up_at && (
          <span
            className={cx(
              'text-xs',
              follow.tone === 'overdue' ? 'font-medium text-red-600' : follow.tone === 'today' ? 'font-medium text-amber-600' : 'text-slate-500',
            )}
          >
            {follow.label}
          </span>
        )}
      </div>
    </Link>
  )
}

export function TaskRow({ task, onComplete }: { task: Task; onComplete?: (task: Task) => void }) {
  const due = dueLabel(task.due_at)

  return (
    <div className="flex items-start gap-3 rounded-lg px-3 py-2.5 transition hover:bg-slate-50">
      {onComplete && (
        <button
          onClick={() => onComplete(task)}
          className="mt-0.5 size-4 shrink-0 rounded border-2 border-slate-300 transition hover:border-brand-600 hover:bg-brand-50"
          aria-label={`Complete ${task.title}`}
          title="Mark complete"
        />
      )}
      <div className="min-w-0 flex-1">
        <p className="truncate text-sm font-medium text-slate-900">{task.title}</p>
        <p className="mt-0.5 truncate text-xs text-slate-500">
          {task.subject?.label ? (
            <Link to={`/opportunities/${task.subject.id}`} className="hover:text-brand-600 hover:underline">
              {task.subject.label}
            </Link>
          ) : (
            'No linked record'
          )}
          {task.assignee && <span> · {task.assignee.name}</span>}
        </p>
      </div>
      <div className="flex shrink-0 flex-col items-end gap-1">
        <span
          className={cx(
            'text-xs',
            due.tone === 'overdue' ? 'font-medium text-red-600' : due.tone === 'today' ? 'font-medium text-amber-600' : 'text-slate-500',
          )}
        >
          {due.label}
        </span>
        <span className="text-xs text-slate-400">{shortDate(task.due_at)}</span>
      </div>
    </div>
  )
}
