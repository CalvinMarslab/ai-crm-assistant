import { useEffect, type ReactNode } from 'react'
import { Button } from './ui'

/**
 * Routine edits happen in an overlay rather than on a separate page, so the
 * owner never loses their place (UX rule: minimise clicks to update).
 */
export function Modal({
  open,
  title,
  description,
  onClose,
  children,
  footer,
  width = 'md',
}: {
  open: boolean
  title: string
  description?: string
  onClose: () => void
  children: ReactNode
  footer?: ReactNode
  width?: 'sm' | 'md' | 'lg'
}) {
  useEffect(() => {
    if (!open) return

    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') onClose()
    }

    document.addEventListener('keydown', onKey)
    document.body.style.overflow = 'hidden'

    return () => {
      document.removeEventListener('keydown', onKey)
      document.body.style.overflow = ''
    }
  }, [open, onClose])

  if (!open) return null

  const widths = { sm: 'max-w-md', md: 'max-w-xl', lg: 'max-w-3xl' }

  return (
    <div className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/40 p-4 sm:p-8">
      <div className="absolute inset-0" onClick={onClose} aria-hidden />
      <div className={`relative w-full ${widths[width]} rounded-xl bg-white shadow-xl ring-1 ring-slate-200`}>
        <div className="border-b border-slate-100 px-5 py-4">
          <h2 className="text-base font-semibold text-slate-900">{title}</h2>
          {description && <p className="mt-0.5 text-xs text-slate-500">{description}</p>}
        </div>
        <div className="max-h-[70vh] overflow-y-auto px-5 py-4">{children}</div>
        {footer && <div className="flex justify-end gap-2 border-t border-slate-100 px-5 py-3">{footer}</div>}
      </div>
    </div>
  )
}

export function ModalFooter({
  onCancel,
  onConfirm,
  confirmLabel = 'Save',
  pending,
  destructive,
}: {
  onCancel: () => void
  onConfirm: () => void
  confirmLabel?: string
  pending?: boolean
  destructive?: boolean
}) {
  return (
    <>
      <Button variant="secondary" onClick={onCancel} disabled={pending}>
        Cancel
      </Button>
      <Button variant={destructive ? 'danger' : 'primary'} onClick={onConfirm} disabled={pending}>
        {pending ? 'Saving…' : confirmLabel}
      </Button>
    </>
  )
}
