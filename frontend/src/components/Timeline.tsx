import { Badge } from './ui'
import { dateTime } from '@/lib/format'
import { EmptyState } from './ui'
import type { Activity } from '@/types'

const iconFor: Record<string, string> = {
  'opportunity.created': '✦',
  'opportunity.stage_changed': '→',
  'opportunity.won': '★',
  'opportunity.lost': '×',
  'opportunity.owner_changed': '⇄',
  'opportunity.agent_changed': '⇄',
  'opportunity.next_action_changed': '⌁',
  'opportunity.follow_up_changed': '⏱',
  'opportunity.quotation_updated': '₴',
  'note.added': '✎',
  'call.logged': '☎',
  'meeting.logged': '⌂',
  'customer.reply_noted': '↩',
  'task.created': '□',
  'task.completed': '✓',
  'task.reopened': '↺',
  'company.created': '⌂',
  'contact.created': '☺',
}

export function Timeline({ activities }: { activities: Activity[] }) {
  if (!activities.length) {
    return <EmptyState message="Nothing has happened here yet." />
  }

  return (
    <ol className="relative space-y-0">
      {activities.map((activity, index) => (
        <li key={activity.id} className="relative flex gap-3 pb-4">
          {index < activities.length - 1 && (
            <span className="absolute top-7 bottom-0 left-3.5 w-px bg-slate-200" aria-hidden />
          )}
          <span className="z-10 flex size-7 shrink-0 items-center justify-center rounded-full bg-slate-100 text-xs text-slate-600 ring-4 ring-white">
            {iconFor[activity.type] ?? '•'}
          </span>
          <div className="min-w-0 flex-1 pt-0.5">
            <div className="flex flex-wrap items-center gap-2">
              <p className="text-sm font-medium text-slate-900">{activity.title}</p>
              {activity.is_internal && <Badge tone="purple">Internal</Badge>}
            </div>
            {activity.body && <p className="mt-1 text-sm whitespace-pre-wrap text-slate-600">{activity.body}</p>}
            <p className="mt-1 text-xs text-slate-400">
              {dateTime(activity.occurred_at)}
              {activity.actor && <span> · {activity.actor.name}</span>}
            </p>
          </div>
        </li>
      ))}
    </ol>
  )
}
