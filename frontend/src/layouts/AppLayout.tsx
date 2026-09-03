import { useState } from 'react'
import { Link, NavLink, Outlet, useLocation } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { useAuth } from '@/hooks/useAuth'
import { notificationApi } from '@/api/endpoints'
import { cx } from '@/components/ui'
import { initials } from '@/lib/format'

interface NavItem {
  to: string
  label: string
  icon: string
  /** Hidden when the signed-in user lacks this permission code. */
  permission?: string
}

const navigation: NavItem[] = [
  { to: '/', label: 'Dashboard', icon: '◫' },
  { to: '/pipeline', label: 'Pipeline', icon: '▤' },
  { to: '/opportunities', label: 'Opportunities', icon: '◈' },
  { to: '/tasks', label: 'Tasks', icon: '✓', permission: 'task.view.own' },
  { to: '/companies', label: 'Companies', icon: '⌂', permission: 'company.view.all' },
  { to: '/contacts', label: 'Contacts', icon: '☺', permission: 'contact.view.all' },
  { to: '/agents', label: 'Agents', icon: '⇄' },
  { to: '/settings', label: 'Settings', icon: '⚙', permission: 'user.view.all' },
]

export function AppLayout() {
  const { user, logout, can } = useAuth()
  const location = useLocation()
  const [menuOpen, setMenuOpen] = useState(false)

  const { data: unreadCount = 0 } = useQuery({
    queryKey: ['notifications', 'unread-count'],
    queryFn: notificationApi.unreadCount,
    refetchInterval: 60_000,
  })

  const visible = navigation.filter((item) => {
    if (!item.permission) return true
    // Task visibility is granted by either of two codes.
    if (item.permission === 'task.view.own') return can('task.view.own') || can('task.view.all')
    return can(item.permission)
  })

  return (
    <div className="flex min-h-full">
      <aside className="hidden w-56 shrink-0 flex-col border-r border-slate-200 bg-white lg:flex">
        <div className="flex items-center gap-2 px-4 py-4">
          <span className="flex size-8 items-center justify-center rounded-lg bg-brand-600 text-sm font-bold text-white">
            AI
          </span>
          <span className="text-sm font-semibold text-slate-900">CRM Assistant</span>
        </div>

        <nav className="flex-1 space-y-0.5 px-2 py-2">
          {visible.map((item) => (
            <NavLink
              key={item.to}
              to={item.to}
              end={item.to === '/'}
              className={({ isActive }) =>
                cx(
                  'flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm font-medium transition',
                  isActive ? 'bg-brand-50 text-brand-700' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900',
                )
              }
            >
              <span className="w-4 text-center text-slate-400">{item.icon}</span>
              {item.label}
            </NavLink>
          ))}
        </nav>

        <div className="border-t border-slate-100 p-2">
          <Link
            to="/notifications"
            className={cx(
              'flex items-center justify-between rounded-lg px-3 py-2 text-sm font-medium transition',
              location.pathname === '/notifications'
                ? 'bg-brand-50 text-brand-700'
                : 'text-slate-600 hover:bg-slate-50',
            )}
          >
            <span className="flex items-center gap-2.5">
              <span className="w-4 text-center text-slate-400">◔</span>
              Notifications
            </span>
            {unreadCount > 0 && (
              <span className="rounded-full bg-red-500 px-1.5 py-0.5 text-xs font-semibold text-white">
                {unreadCount}
              </span>
            )}
          </Link>
        </div>
      </aside>

      <div className="flex min-w-0 flex-1 flex-col">
        <header className="flex items-center justify-between gap-4 border-b border-slate-200 bg-white px-4 py-3">
          <div className="flex items-center gap-2 lg:hidden">
            <button
              onClick={() => setMenuOpen((open) => !open)}
              className="rounded-lg px-2 py-1 text-slate-600 hover:bg-slate-100"
              aria-label="Toggle navigation"
            >
              ☰
            </button>
            <span className="text-sm font-semibold text-slate-900">CRM Assistant</span>
          </div>

          <div className="ml-auto flex items-center gap-3">
            <Link to="/notifications" className="relative rounded-lg p-1.5 text-slate-500 hover:bg-slate-100">
              ◔
              {unreadCount > 0 && <span className="absolute top-1 right-1 size-2 rounded-full bg-red-500" />}
            </Link>
            <div className="flex items-center gap-2">
              <span className="flex size-7 items-center justify-center rounded-full bg-slate-200 text-xs font-semibold text-slate-600">
                {user ? initials(user.name) : '?'}
              </span>
              <div className="hidden text-right sm:block">
                <p className="text-xs font-medium text-slate-900">{user?.name}</p>
                <p className="text-xs text-slate-500">{user?.roles?.[0]?.name}</p>
              </div>
            </div>
            <button onClick={() => void logout()} className="text-xs font-medium text-slate-500 hover:text-slate-900">
              Sign out
            </button>
          </div>
        </header>

        {menuOpen && (
          <nav className="space-y-0.5 border-b border-slate-200 bg-white p-2 lg:hidden">
            {visible.map((item) => (
              <NavLink
                key={item.to}
                to={item.to}
                end={item.to === '/'}
                onClick={() => setMenuOpen(false)}
                className={({ isActive }) =>
                  cx(
                    'flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm font-medium',
                    isActive ? 'bg-brand-50 text-brand-700' : 'text-slate-600',
                  )
                }
              >
                <span className="w-4 text-center text-slate-400">{item.icon}</span>
                {item.label}
              </NavLink>
            ))}
          </nav>
        )}

        <main className="flex-1 overflow-x-hidden p-4 sm:p-6">
          <Outlet />
        </main>
      </div>
    </div>
  )
}
