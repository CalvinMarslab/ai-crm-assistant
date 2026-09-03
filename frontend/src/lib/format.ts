import { differenceInCalendarDays, format, formatDistanceToNowStrict, isToday, parseISO } from 'date-fns'

const currency = new Intl.NumberFormat('en-MY', {
  style: 'currency',
  currency: 'MYR',
  maximumFractionDigits: 0,
})

export function money(value: number | null | undefined): string {
  if (value === null || value === undefined) return '—'

  return currency.format(value)
}

export function shortDate(value: string | null | undefined): string {
  if (!value) return '—'

  return format(parseISO(value), 'd MMM yyyy')
}

export function dateTime(value: string | null | undefined): string {
  if (!value) return '—'

  return format(parseISO(value), 'd MMM yyyy, HH:mm')
}

export function relative(value: string | null | undefined): string {
  if (!value) return '—'

  return `${formatDistanceToNowStrict(parseISO(value))} ago`
}

/**
 * Due dates carry the most meaning when read relative to today, which is how
 * the owner scans the dashboard.
 */
export function dueLabel(value: string | null | undefined): { label: string; tone: 'overdue' | 'today' | 'future' | 'none' } {
  if (!value) return { label: 'No due date', tone: 'none' }

  const date = parseISO(value)
  const days = differenceInCalendarDays(date, new Date())

  if (isToday(date)) return { label: 'Due today', tone: 'today' }
  if (days < 0) return { label: `${Math.abs(days)}d overdue`, tone: 'overdue' }
  if (days === 1) return { label: 'Due tomorrow', tone: 'future' }

  return { label: `Due in ${days}d`, tone: 'future' }
}

export function titleCase(value: string | null | undefined): string {
  if (!value) return '—'

  return value.replace(/[._]/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())
}

export function initials(name: string): string {
  return name
    .split(' ')
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase() ?? '')
    .join('')
}
