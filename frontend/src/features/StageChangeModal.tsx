import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { opportunityApi, pipelineApi } from '@/api/endpoints'
import { validationErrors } from '@/api/client'
import { Modal, ModalFooter } from '@/components/Modal'
import { Field, Input, Select, Textarea } from '@/components/ui'
import type { Opportunity, Stage } from '@/types'

const LOSS_REASONS = ['Price', 'Timing', 'Went with a competitor', 'No budget', 'No response', 'Not a fit', 'Other']

/**
 * Won and Lost demand extra facts before the transition is allowed. Asking here
 * mirrors the server rules so the user is never surprised by a rejection.
 */
export function StageChangeModal({
  open,
  onClose,
  opportunity,
  presetStageId,
}: {
  open: boolean
  onClose: () => void
  opportunity: Opportunity
  presetStageId?: number
}) {
  const queryClient = useQueryClient()
  const [stageId, setStageId] = useState<number | ''>('')
  const [note, setNote] = useState('')
  const [lossReason, setLossReason] = useState(LOSS_REASONS[0])
  const [lossNote, setLossNote] = useState('')
  const [finalValue, setFinalValue] = useState('')
  const [nextAction, setNextAction] = useState('')
  const [errors, setErrors] = useState<Record<string, string>>({})

  const { data: pipelines } = useQuery({ queryKey: ['pipelines'], queryFn: pipelineApi.list, enabled: open })

  const stages: Stage[] =
    pipelines?.find((pipeline) => pipeline.id === opportunity.pipeline_id)?.stages.filter((s) => s.is_active !== false) ??
    []

  useEffect(() => {
    if (!open) return

    setErrors({})
    setNote('')
    setLossNote('')
    setNextAction('')
    setLossReason(LOSS_REASONS[0])
    setStageId(presetStageId ?? '')
    setFinalValue(opportunity.estimated_value?.toString() ?? '')
  }, [open, presetStageId, opportunity.estimated_value])

  const target = stages.find((stage) => stage.id === stageId)

  const change = useMutation({
    mutationFn: (payload: Record<string, unknown>) => opportunityApi.changeStage(opportunity.id, payload),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['opportunity', opportunity.id] })
      void queryClient.invalidateQueries({ queryKey: ['opportunities'] })
      void queryClient.invalidateQueries({ queryKey: ['dashboard'] })
      void queryClient.invalidateQueries({ queryKey: ['timeline', opportunity.id] })
      void queryClient.invalidateQueries({ queryKey: ['stage-history', opportunity.id] })
      onClose()
    },
    onError: (error) => setErrors(validationErrors(error)),
  })

  function submit() {
    if (stageId === '') {
      setErrors({ stage_id: 'Choose a stage.' })
      return
    }

    setErrors({})

    change.mutate({
      stage_id: stageId,
      note: note || null,
      loss_reason: target?.stage_type === 'lost' ? lossReason : null,
      loss_note: target?.stage_type === 'lost' ? lossNote || null : null,
      final_value: target?.stage_type === 'won' && finalValue !== '' ? Number(finalValue) : null,
      next_action: nextAction || null,
    })
  }

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Change stage"
      description={opportunity.title}
      footer={
        <ModalFooter
          onCancel={onClose}
          onConfirm={submit}
          confirmLabel="Change stage"
          pending={change.isPending}
          destructive={target?.stage_type === 'lost'}
        />
      }
    >
      <div className="space-y-4">
        <Field label="Move to" required error={errors.stage_id}>
          <Select value={stageId} onChange={(event) => setStageId(Number(event.target.value))}>
            <option value="">Select a stage…</option>
            {stages.map((stage) => (
              <option key={stage.id} value={stage.id} disabled={stage.id === opportunity.stage?.id}>
                {stage.name}
                {stage.id === opportunity.stage?.id ? ' (current)' : ''}
              </option>
            ))}
          </Select>
        </Field>

        {target?.stage_type === 'won' && (
          <Field label="Final value" required error={errors.final_value} hint="Recorded against the won deal.">
            <Input type="number" min="0" step="0.01" value={finalValue} onChange={(e) => setFinalValue(e.target.value)} />
          </Field>
        )}

        {target?.stage_type === 'lost' && (
          <>
            <Field label="Loss reason" required error={errors.loss_reason}>
              <Select value={lossReason} onChange={(event) => setLossReason(event.target.value)}>
                {LOSS_REASONS.map((reason) => (
                  <option key={reason} value={reason}>
                    {reason}
                  </option>
                ))}
              </Select>
            </Field>
            <Field label="Loss note" error={errors.loss_note}>
              <Textarea value={lossNote} onChange={(event) => setLossNote(event.target.value)} rows={2} />
            </Field>
            <p className="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 ring-1 ring-amber-200">
              Outstanding tasks on this opportunity will be cancelled.
            </p>
          </>
        )}

        {target && target.stage_type === 'open' && (
          <Field label="Next action" error={errors.next_action} hint="Optional, but keeps the deal off the risk list.">
            <Input value={nextAction} onChange={(event) => setNextAction(event.target.value)} />
          </Field>
        )}

        <Field label="Note" error={errors.note}>
          <Textarea value={note} onChange={(event) => setNote(event.target.value)} rows={2} placeholder="What changed?" />
        </Field>
      </div>
    </Modal>
  )
}
