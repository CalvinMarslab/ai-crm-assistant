import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { notificationApi } from '@/api/endpoints'
import { errorMessage } from '@/api/client'
import { PageHeader } from '@/components/PageHeader'
import { Button, Card, EmptyState, ErrorState, Spinner, cx } from '@/components/ui'
import { relative } from '@/lib/format'

export default function NotificationsPage() {
  const queryClient = useQueryClient()

  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['notifications'],
    queryFn: () => notificationApi.list({ per_page: 50 }),
  })

  function invalidate() {
    void queryClient.invalidateQueries({ queryKey: ['notifications'] })
  }

  const markRead = useMutation({ mutationFn: notificationApi.markRead, onSuccess: invalidate })
  const markAllRead = useMutation({ mutationFn: notificationApi.markAllRead, onSuccess: invalidate })

  const unread = data?.data.filter((notification) => !notification.read_at).length ?? 0

  return (
    <>
      <PageHeader
        title="Notifications"
        subtitle={unread > 0 ? `${unread} unread` : 'All caught up'}
        action={
          unread > 0 && (
            <Button variant="secondary" onClick={() => markAllRead.mutate()} disabled={markAllRead.isPending}>
              Mark all read
            </Button>
          )
        }
      />

      {isLoading ? (
        <Spinner />
      ) : error ? (
        <ErrorState message={errorMessage(error)} onRetry={() => void refetch()} />
      ) : !data?.data.length ? (
        <Card>
          <EmptyState message="No notifications yet." />
        </Card>
      ) : (
        <Card className="divide-y divide-slate-100">
          {data.data.map((notification) => (
            <div
              key={notification.id}
              className={cx('flex items-start gap-3 px-4 py-3', !notification.read_at && 'bg-brand-50/40')}
            >
              <span
                className={cx(
                  'mt-1.5 size-2 shrink-0 rounded-full',
                  notification.read_at ? 'bg-slate-200' : 'bg-brand-500',
                )}
              />
              <div className="min-w-0 flex-1">
                <p className="text-sm font-medium text-slate-900">{notification.title}</p>
                {notification.body && <p className="mt-0.5 text-sm text-slate-600">{notification.body}</p>}
                <p className="mt-1 text-xs text-slate-400">{relative(notification.created_at)}</p>
              </div>
              {!notification.read_at && (
                <Button variant="ghost" size="sm" onClick={() => markRead.mutate(notification.id)}>
                  Mark read
                </Button>
              )}
            </div>
          ))}
        </Card>
      )}
    </>
  )
}
