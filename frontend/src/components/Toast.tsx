import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react'
import { onApiError } from '@/api/client'
import { cx } from './ui'

interface Toast {
  id: number
  message: string
  tone: 'error' | 'success'
}

interface ToastContextValue {
  notify: (message: string, tone?: Toast['tone']) => void
}

const ToastContext = createContext<ToastContextValue | null>(null)

let nextId = 0

export function ToastProvider({ children }: { children: ReactNode }) {
  const [toasts, setToasts] = useState<Toast[]>([])

  const dismiss = useCallback((id: number) => {
    setToasts((current) => current.filter((toast) => toast.id !== id))
  }, [])

  const notify = useCallback(
    (message: string, tone: Toast['tone'] = 'error') => {
      const id = nextId++

      setToasts((current) => {
        // Repeated failures of the same action should not stack up.
        if (current.some((toast) => toast.message === message)) return current

        return [...current, { id, message, tone }]
      })

      setTimeout(() => dismiss(id), tone === 'error' ? 8000 : 4000)
    },
    [dismiss],
  )

  // Any request failure that is not field validation arrives here.
  useEffect(() => onApiError((message) => notify(message, 'error')), [notify])

  const value = useMemo(() => ({ notify }), [notify])

  return (
    <ToastContext.Provider value={value}>
      {children}

      <div
        className="pointer-events-none fixed inset-x-0 bottom-0 z-[60] flex flex-col items-center gap-2 p-4"
        role="status"
        aria-live="polite"
      >
        {toasts.map((toast) => (
          <div
            key={toast.id}
            className={cx(
              'pointer-events-auto flex w-full max-w-md items-start gap-3 rounded-lg px-4 py-3 text-sm shadow-lg ring-1',
              toast.tone === 'error'
                ? 'bg-red-600 text-white ring-red-700'
                : 'bg-emerald-600 text-white ring-emerald-700',
            )}
          >
            <span className="flex-1">{toast.message}</span>
            <button
              onClick={() => dismiss(toast.id)}
              className="shrink-0 text-white/70 transition hover:text-white"
              aria-label="Dismiss"
            >
              ✕
            </button>
          </div>
        ))}
      </div>
    </ToastContext.Provider>
  )
}

export function useToast(): ToastContextValue {
  const context = useContext(ToastContext)

  if (!context) {
    throw new Error('useToast must be used inside a ToastProvider.')
  }

  return context
}
