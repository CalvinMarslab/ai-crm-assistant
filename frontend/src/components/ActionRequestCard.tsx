import { useMutation, useQueryClient } from '@tanstack/react-query'
import { aiApi } from '@/api/endpoints'
import { Badge, Button, cx } from './ui'
import { relative } from '@/lib/format'
import { useAuth } from '@/hooks/useAuth'
import type { AiActionRequest } from '@/types'

/**
 * A change the assistant proposed. Nothing has happened yet — the card is the
 * confirmation step, so it states plainly what will be done and stays visibly
 * unfinished until the user decides.
 */
export function ActionRequestCard({ request }: { request: AiActionRequest }) {
  const { can } = useAuth()
  const queryClient = useQueryClient()

  function invalidate() {
    void queryClient.invalidateQueries({ queryKey: ['ai', 'action-requests'] })
    void queryClient.invalidateQueries({ queryKey: ['dashboard'] })
    void queryClient.invalidateQueries({ queryKey: ['opportunities'] })
    void queryClient.invalidateQueries({ queryKey: ['tasks'] })
  }

  const confirm = useMutation({ mutationFn: () => aiApi.confirmAction(request.id), onSuccess: invalidate })
  const reject = useMutation({ mutationFn: () => aiApi.rejectAction(request.id), onSuccess: invalidate })

  const pending = confirm.isPending || reject.isPending
  const tone = {
    pending: 'bg-amber-50 ring-amber-200',
    executed: 'bg-emerald-50 ring-emerald-200',
    rejected: 'bg-slate-50 ring-slate-200',
    failed: 'bg-red-50 ring-red-200',
    expired: 'bg-slate-50 ring-slate-200',
  }[request.status]

  return (
    <div className={cx('rounded-lg px-4 py-3 ring-1', tone)}>
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div className="min-w-0 flex-1">
          <p className="text-xs font-medium text-slate-500">
            {request.status === 'pending' ? 'Waiting for your confirmation' : 'Proposed change'}
          </p>
          <p className="mt-0.5 text-sm font-medium text-slate-900">
            {request.summary ?? request.action.replace(/_/g, ' ')}
          </p>
        </div>

        {request.status !== 'pending' && (
          <Badge
            tone={
              request.status === 'executed'
                ? 'green'
                : request.status === 'failed'
                  ? 'red'
                  : 'slate'
            }
          >
            {request.status_label}
          </Badge>
        )}
      </div>

      {request.status === 'pending' && request.is_actionable && (
        <div className="mt-3 flex items-center gap-2">
          {can('ai.write.execute') ? (
            <Button size="sm" onClick={() => confirm.mutate()} disabled={pending}>
              {confirm.isPending ? 'Applying…' : 'Confirm'}
            </Button>
          ) : (
            <span className="text-xs text-slate-500">You do not have permission to apply changes.</span>
          )}
          <Button variant="secondary" size="sm" onClick={() => reject.mutate()} disabled={pending}>
            Discard
          </Button>
          {request.expires_at && (
            <span className="ml-auto text-xs text-slate-400">Expires {relative(request.expires_at)}</span>
          )}
        </div>
      )}

      {request.status === 'pending' && !request.is_actionable && (
        <p className="mt-2 text-xs text-slate-500">This suggestion has expired. Ask the assistant again.</p>
      )}

      {request.status === 'failed' && request.result?.error !== undefined && (
        <p className="mt-2 text-xs text-red-700">{String(request.result.error)}</p>
      )}
    </div>
  )
}
