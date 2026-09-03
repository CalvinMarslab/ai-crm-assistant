import { type ButtonHTMLAttributes, type InputHTMLAttributes, type ReactNode, type SelectHTMLAttributes, type TextareaHTMLAttributes } from 'react'

export function cx(...classes: (string | false | null | undefined)[]): string {
  return classes.filter(Boolean).join(' ')
}

// ---- Buttons ----

type ButtonVariant = 'primary' | 'secondary' | 'ghost' | 'danger'

const buttonStyles: Record<ButtonVariant, string> = {
  primary: 'bg-brand-600 text-white hover:bg-brand-700 focus-visible:outline-brand-600',
  secondary: 'bg-white text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50 focus-visible:outline-slate-400',
  ghost: 'text-slate-600 hover:bg-slate-100 focus-visible:outline-slate-400',
  danger: 'bg-red-600 text-white hover:bg-red-700 focus-visible:outline-red-600',
}

export function Button({
  variant = 'primary',
  size = 'md',
  className,
  ...props
}: ButtonHTMLAttributes<HTMLButtonElement> & { variant?: ButtonVariant; size?: 'sm' | 'md' }) {
  return (
    <button
      className={cx(
        'inline-flex items-center justify-center gap-1.5 rounded-lg font-medium transition',
        'focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2',
        'disabled:cursor-not-allowed disabled:opacity-50',
        size === 'sm' ? 'px-2.5 py-1.5 text-xs' : 'px-3.5 py-2 text-sm',
        buttonStyles[variant],
        className,
      )}
      {...props}
    />
  )
}

// ---- Form fields ----

export function Field({
  label,
  error,
  hint,
  required,
  children,
}: {
  label: string
  error?: string
  hint?: string
  required?: boolean
  children: ReactNode
}) {
  return (
    <label className="block">
      <span className="mb-1 block text-xs font-medium text-slate-700">
        {label}
        {required && <span className="ml-0.5 text-red-500">*</span>}
      </span>
      {children}
      {hint && !error && <span className="mt-1 block text-xs text-slate-500">{hint}</span>}
      {error && <span className="mt-1 block text-xs text-red-600">{error}</span>}
    </label>
  )
}

const controlClass =
  'w-full rounded-lg border-0 bg-white px-3 py-2 text-sm text-slate-900 ring-1 ring-slate-300 ' +
  'placeholder:text-slate-400 focus:ring-2 focus:ring-brand-500 focus:outline-none disabled:bg-slate-50'

export function Input({ className, ...props }: InputHTMLAttributes<HTMLInputElement>) {
  return <input className={cx(controlClass, className)} {...props} />
}

export function Textarea({ className, ...props }: TextareaHTMLAttributes<HTMLTextAreaElement>) {
  return <textarea className={cx(controlClass, 'min-h-20 resize-y', className)} {...props} />
}

export function Select({ className, ...props }: SelectHTMLAttributes<HTMLSelectElement>) {
  return <select className={cx(controlClass, 'pr-8', className)} {...props} />
}

// ---- Surfaces ----

export function Card({ className, children }: { className?: string; children: ReactNode }) {
  return (
    <div className={cx('rounded-xl bg-white shadow-sm ring-1 ring-slate-200', className)}>{children}</div>
  )
}

export function SectionCard({
  title,
  count,
  tone = 'neutral',
  action,
  children,
}: {
  title: string
  count?: number
  tone?: 'neutral' | 'danger' | 'warning'
  action?: ReactNode
  children: ReactNode
}) {
  const toneClass = {
    neutral: 'text-slate-900',
    danger: 'text-red-700',
    warning: 'text-amber-700',
  }[tone]

  return (
    <Card>
      <div className="flex items-center justify-between border-b border-slate-100 px-4 py-3">
        <h2 className={cx('flex items-center gap-2 text-sm font-semibold', toneClass)}>
          {title}
          {count !== undefined && (
            <span
              className={cx(
                'rounded-full px-2 py-0.5 text-xs font-semibold',
                count === 0
                  ? 'bg-slate-100 text-slate-500'
                  : tone === 'danger'
                    ? 'bg-red-100 text-red-700'
                    : tone === 'warning'
                      ? 'bg-amber-100 text-amber-700'
                      : 'bg-slate-100 text-slate-700',
              )}
            >
              {count}
            </span>
          )}
        </h2>
        {action}
      </div>
      <div className="p-2">{children}</div>
    </Card>
  )
}

// ---- Feedback ----

export function Badge({
  children,
  tone = 'slate',
}: {
  children: ReactNode
  tone?: 'slate' | 'green' | 'red' | 'amber' | 'blue' | 'purple'
}) {
  const tones = {
    slate: 'bg-slate-100 text-slate-700 ring-slate-200',
    green: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    red: 'bg-red-50 text-red-700 ring-red-200',
    amber: 'bg-amber-50 text-amber-800 ring-amber-200',
    blue: 'bg-brand-50 text-brand-700 ring-brand-200',
    purple: 'bg-violet-50 text-violet-700 ring-violet-200',
  }

  return (
    <span
      className={cx(
        'inline-flex items-center rounded-md px-1.5 py-0.5 text-xs font-medium ring-1 ring-inset whitespace-nowrap',
        tones[tone],
      )}
    >
      {children}
    </span>
  )
}

export function EmptyState({ message }: { message: string }) {
  return <p className="px-3 py-6 text-center text-sm text-slate-400">{message}</p>
}

export function Spinner({ label = 'Loading…' }: { label?: string }) {
  return (
    <div className="flex items-center justify-center gap-2 py-10 text-sm text-slate-500">
      <span className="size-4 animate-spin rounded-full border-2 border-slate-300 border-t-brand-600" />
      {label}
    </div>
  )
}

export function ErrorState({ message, onRetry }: { message: string; onRetry?: () => void }) {
  return (
    <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-red-200">
      <p>{message}</p>
      {onRetry && (
        <button className="mt-2 text-xs font-semibold underline" onClick={onRetry}>
          Try again
        </button>
      )}
    </div>
  )
}
