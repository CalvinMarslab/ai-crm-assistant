import { Link } from 'react-router-dom'
import { Badge, cx } from './ui'
import { shortDate } from '@/lib/format'
import type { HandoverItem, Project, ProjectStatus } from '@/types'

const statusTone: Record<ProjectStatus, 'slate' | 'blue' | 'green' | 'amber' | 'purple'> = {
  pending_handover: 'amber',
  planning: 'blue',
  in_progress: 'blue',
  waiting_customer: 'amber',
  internal_review: 'purple',
  completed: 'green',
  on_hold: 'slate',
}

export function ProjectStatusBadge({ project }: { project: Project }) {
  return <Badge tone={statusTone[project.status]}>{project.status_label}</Badge>
}

/** How far through the handover checklist a project is. */
export function HandoverProgress({ items }: { items: HandoverItem[] }) {
  if (!items.length) return null

  const settled = items.filter((item) => item.is_settled).length
  const percent = Math.round((settled / items.length) * 100)
  const complete = settled === items.length

  return (
    <div>
      <div className="mb-1 flex items-baseline justify-between text-xs">
        <span className="font-medium text-slate-600">Handover checklist</span>
        <span className={cx(complete ? 'font-medium text-emerald-700' : 'text-slate-500')}>
          {settled} of {items.length}
        </span>
      </div>
      <div className="h-1.5 overflow-hidden rounded-full bg-slate-100">
        <div
          className={cx('h-full rounded-full transition-all', complete ? 'bg-emerald-500' : 'bg-brand-500')}
          style={{ width: `${Math.max(percent, 2)}%` }}
        />
      </div>
    </div>
  )
}

export function ProjectRow({ project }: { project: Project }) {
  return (
    <Link
      to={`/projects/${project.id}`}
      className="flex items-start justify-between gap-3 rounded-lg px-3 py-2.5 transition hover:bg-slate-50"
    >
      <div className="min-w-0 flex-1">
        <p className="truncate text-sm font-medium text-slate-900">{project.name}</p>
        <p className="mt-0.5 truncate text-xs text-slate-500">
          {project.company?.name}
          {project.manager ? <span> · {project.manager.name}</span> : <span className="text-amber-600"> · No PM</span>}
        </p>
      </div>
      <div className="flex shrink-0 flex-col items-end gap-1">
        <ProjectStatusBadge project={project} />
        {project.target_end_date && (
          <span className="text-xs text-slate-400">Due {shortDate(project.target_end_date)}</span>
        )}
      </div>
    </Link>
  )
}
