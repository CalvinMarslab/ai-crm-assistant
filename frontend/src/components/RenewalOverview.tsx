import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { serviceApi, type ServiceType } from '@/api/services'
import { useAuth } from '@/hooks/useAuth'
import { Card, ErrorState } from '@/components/ui'
import { errorMessage } from '@/api/client'

export function RenewalOverview() {
  return <>
    <ServiceRenewalOverview serviceType="server" />
    <ServiceRenewalOverview serviceType="maintenance" />
  </>
}

function ServiceRenewalOverview({ serviceType }: { serviceType: ServiceType }) {
  const { can } = useAuth()
  const canView = can('service.view') || can(`service.view.${serviceType}`)
  const query = useQuery({
    queryKey: ['renewal-summary', serviceType],
    queryFn: () => serviceApi.summary(serviceType),
    enabled: canView,
  })
  if (!canView) return null
  if (query.error)
    return (
      <div className="mb-4">
        <ErrorState
          message={errorMessage(query.error)}
          onRetry={() => void query.refetch()}
        />
      </div>
    )
  if (!query.data) return null
  const s = query.data
  return (
    <Card className="mb-5 p-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 className="text-sm font-semibold">{serviceType === 'maintenance' ? 'Maintenance' : 'Server & hosting'}</h2>
          <p className="mt-1 text-sm text-slate-600">
            {s.expiring} expiring within 15 days ·{' '}
            <span className={s.overdue ? 'font-semibold text-red-700' : ''}>
              {s.overdue} overdue
            </span>
          </p>
        </div>
        <Link
          className="text-sm font-semibold text-brand-700 hover:underline"
          to={serviceType === 'maintenance' ? '/maintenance' : '/services'}
        >
          Manage renewals →
        </Link>
      </div>
      {s.pending.length > 0 && (
        <p className="mt-2 text-xs text-slate-500">
          Pending collection:{' '}
          {s.pending
            .map((p) =>
              new Intl.NumberFormat('en-MY', {
                style: 'currency',
                currency: p.currency,
              }).format(Number(p.amount)),
            )
            .join(' · ')}
        </p>
      )}
      {s.pending_price_count > 0 && <p className="mt-2 text-xs text-amber-700">{s.pending_price_count} renewal(s) awaiting price</p>}
    </Card>
  )
}
