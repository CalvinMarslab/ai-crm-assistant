import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { portalApi } from '@/api/endpoints'
import { errorMessage } from '@/api/client'
import { PageHeader } from '@/components/PageHeader'
import { Badge, Card, EmptyState, ErrorState, Spinner, cx } from '@/components/ui'
import { Modal } from '@/components/Modal'
import { relative, shortDate } from '@/lib/format'
import { useAuth } from '@/hooks/useAuth'

/**
 * The referral agent's whole application. Deliberately narrow: the statuses
 * they see are the simplified vocabulary from CRM_WORKFLOW.md section 7, and
 * nothing here exposes internal stages, values, owners, or notes.
 */
const STATUS_TONE: Record<string, 'slate' | 'blue' | 'green' | 'red' | 'amber' | 'purple'> = {
  New: 'slate',
  'In Discussion': 'blue',
  'Proposal Stage': 'purple',
  Negotiation: 'amber',
  Won: 'green',
  Lost: 'red',
  'Project In Progress': 'blue',
  Completed: 'green',
}

export default function PortalPage() {
  const { user } = useAuth()
  const [openId, setOpenId] = useState<string | null>(null)

  const { data: summary, isLoading, error, refetch } = useQuery({
    queryKey: ['portal', 'summary'],
    queryFn: portalApi.summary,
  })

  const { data: opportunities } = useQuery({
    queryKey: ['portal', 'opportunities'],
    queryFn: () => portalApi.opportunities({ per_page: 100 }),
  })

  if (isLoading) return <Spinner label="Loading your referrals…" />
  if (error) return <ErrorState message={errorMessage(error)} onRetry={() => void refetch()} />
  if (!summary) return null

  const rows = opportunities?.data ?? []
  const breakdown = Object.entries(summary.status_breakdown)

  return (
    <>
      <PageHeader
        title={`Hello, ${user?.name.split(' ')[0] ?? 'there'}`}
        subtitle={`${summary.performance.introduced} referral${summary.performance.introduced === 1 ? '' : 's'} introduced`}
      />

      <div className="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <Stat label="Introduced" value={summary.performance.introduced} />
        <Stat label="Still active" value={summary.performance.active} />
        <Stat label="Won" value={summary.performance.won} tone="green" />
        <Stat
          label="Conversion"
          value={summary.performance.conversion_rate === null ? '—' : `${summary.performance.conversion_rate}%`}
        />
      </div>

      {breakdown.length > 0 && (
        <Card className="mb-4 p-4">
          <h2 className="mb-3 text-sm font-semibold text-slate-900">Where your referrals stand</h2>
          <div className="flex flex-wrap gap-2">
            {breakdown.map(([status, count]) => (
              <span key={status} className="flex items-center gap-1.5 rounded-lg bg-slate-50 px-3 py-1.5">
                <Badge tone={STATUS_TONE[status] ?? 'slate'}>{status}</Badge>
                <span className="text-sm font-semibold text-slate-900">{count}</span>
              </span>
            ))}
          </div>
        </Card>
      )}

      <Card>
        <h2 className="border-b border-slate-100 px-4 py-3 text-sm font-semibold text-slate-900">Your referrals</h2>
        {rows.length === 0 ? (
          <EmptyState message="You have not introduced any leads yet." />
        ) : (
          <ul className="divide-y divide-slate-100">
            {rows.map((row) => (
              <li key={row.id}>
                <button
                  onClick={() => setOpenId(row.id)}
                  className="flex w-full items-start justify-between gap-3 px-4 py-3 text-left transition hover:bg-slate-50"
                >
                  <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-medium text-slate-900">{row.title}</p>
                    <p className="mt-0.5 truncate text-xs text-slate-500">
                      {row.company ?? 'Company not recorded'} · submitted {shortDate(row.submitted_at)}
                    </p>
                  </div>
                  <div className="flex shrink-0 flex-col items-end gap-1">
                    <Badge tone={STATUS_TONE[row.status] ?? 'slate'}>{row.status}</Badge>
                    <span className="text-xs text-slate-400">Updated {relative(row.last_update_at)}</span>
                  </div>
                </button>
              </li>
            ))}
          </ul>
        )}
      </Card>

      {openId && <ProgressModal id={openId} onClose={() => setOpenId(null)} />}
    </>
  )
}

function Stat({ label, value, tone }: { label: string; value: number | string; tone?: 'green' }) {
  return (
    <Card className="p-4">
      <p className="text-xs font-medium text-slate-500">{label}</p>
      <p className={cx('mt-1 text-xl font-semibold', tone === 'green' ? 'text-emerald-700' : 'text-slate-900')}>
        {value}
      </p>
    </Card>
  )
}

function ProgressModal({ id, onClose }: { id: string; onClose: () => void }) {
  const { data, isLoading } = useQuery({
    queryKey: ['portal', 'opportunity', id],
    queryFn: () => portalApi.show(id),
  })

  return (
    <Modal open onClose={onClose} title={data?.opportunity.title ?? 'Referral'} width="sm">
      {isLoading || !data ? (
        <Spinner />
      ) : (
        <>
          <div className="mb-4 flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2">
            <span className="text-xs text-slate-500">{data.opportunity.company}</span>
            <Badge tone={STATUS_TONE[data.opportunity.status] ?? 'slate'}>{data.opportunity.status}</Badge>
          </div>

          <h3 className="mb-2 text-xs font-medium text-slate-500">Progress</h3>
          <ol className="space-y-0">
            {data.progress.map((entry, index) => (
              <li key={`${entry.status}-${index}`} className="relative flex gap-3 pb-4">
                {index < data.progress.length - 1 && (
                  <span className="absolute top-6 bottom-0 left-2.5 w-px bg-slate-200" aria-hidden />
                )}
                <span className="z-10 mt-1 size-5 shrink-0 rounded-full bg-brand-100 ring-4 ring-white" />
                <div>
                  <p className="text-sm font-medium text-slate-900">{entry.status}</p>
                  <p className="text-xs text-slate-400">{shortDate(entry.changed_at)}</p>
                </div>
              </li>
            ))}
          </ol>

          <p className="mt-2 text-xs text-slate-400">
            For anything beyond this, please contact the team directly.
          </p>
        </>
      )}
    </Modal>
  )
}
