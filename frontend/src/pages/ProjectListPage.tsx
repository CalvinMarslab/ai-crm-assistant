import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { projectApi } from '@/api/endpoints'
import { errorMessage } from '@/api/client'
import { PageHeader } from '@/components/PageHeader'
import { Card, EmptyState, ErrorState, Input, Select, Spinner, Button } from '@/components/ui'
import { ProjectStatusBadge } from '@/components/ProjectBits'
import { money, shortDate } from '@/lib/format'

const STATUSES = [
  ['pending_handover', 'Pending Handover'],
  ['planning', 'Planning'],
  ['in_progress', 'In Progress'],
  ['waiting_customer', 'Waiting for Customer'],
  ['internal_review', 'Internal Review'],
  ['completed', 'Completed'],
  ['on_hold', 'On Hold'],
] as const

export default function ProjectListPage() {
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')
  const [openOnly, setOpenOnly] = useState(true)

  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['projects', search, status, openOnly],
    queryFn: () => projectApi.list({ search, status, open: openOnly || undefined, per_page: 50 }),
  })

  return (
    <>
      <PageHeader
        title="Projects"
        subtitle={data ? `${data.meta.total} ${openOnly ? 'active' : 'total'}` : undefined}
      />

      <Card className="mb-4 p-3">
        <div className="flex flex-wrap items-center gap-2">
          <div className="min-w-48 flex-1">
            <Input placeholder="Search projects…" value={search} onChange={(e) => setSearch(e.target.value)} />
          </div>
          <Select value={status} onChange={(e) => setStatus(e.target.value)} className="w-auto">
            <option value="">All statuses</option>
            {STATUSES.map(([value, label]) => (
              <option key={value} value={value}>
                {label}
              </option>
            ))}
          </Select>
          <Button variant={openOnly ? 'primary' : 'secondary'} size="sm" onClick={() => setOpenOnly((v) => !v)}>
            {openOnly ? 'Active only' : 'Including completed'}
          </Button>
        </div>
      </Card>

      {isLoading ? (
        <Spinner />
      ) : error ? (
        <ErrorState message={errorMessage(error)} onRetry={() => void refetch()} />
      ) : !data?.data.length ? (
        <Card>
          <EmptyState message="No projects yet. Win an opportunity and convert it to start one." />
        </Card>
      ) : (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {data.data.map((project) => (
            <Link key={project.id} to={`/projects/${project.id}`}>
              <Card className="flex h-full flex-col p-4 transition hover:shadow-md">
                <div className="flex items-start justify-between gap-2">
                  <div className="min-w-0">
                    <h2 className="truncate text-sm font-semibold text-slate-900">{project.name}</h2>
                    <p className="truncate text-xs text-slate-500">{project.company?.name}</p>
                  </div>
                  <ProjectStatusBadge project={project} />
                </div>

                <dl className="mt-3 flex justify-between text-xs">
                  <div>
                    <dt className="text-slate-400">Manager</dt>
                    <dd className="mt-0.5 text-slate-700">
                      {project.manager?.name ?? <span className="text-amber-600">Unassigned</span>}
                    </dd>
                  </div>
                  {project.contract_value !== undefined && (
                    <div className="text-right">
                      <dt className="text-slate-400">Value</dt>
                      <dd className="mt-0.5 font-semibold text-slate-900">{money(project.contract_value)}</dd>
                    </div>
                  )}
                </dl>

                <div className="mt-auto pt-3 text-xs text-slate-400">
                  {project.target_end_date ? `Target ${shortDate(project.target_end_date)}` : 'No target date'}
                  {(project.open_tasks_count ?? 0) > 0 && <span> · {project.open_tasks_count} open tasks</span>}
                </div>
              </Card>
            </Link>
          ))}
        </div>
      )}
    </>
  )
}
