import { Navigate, Route, Routes } from 'react-router-dom'
import { useAuth } from '@/hooks/useAuth'
import { AppLayout } from '@/layouts/AppLayout'
import { Spinner } from '@/components/ui'
import LoginPage from '@/pages/LoginPage'
import DashboardPage from '@/pages/DashboardPage'
import OpportunityBoardPage from '@/pages/OpportunityBoardPage'
import OpportunityListPage from '@/pages/OpportunityListPage'
import OpportunityDetailPage from '@/pages/OpportunityDetailPage'
import CompanyListPage from '@/pages/CompanyListPage'
import CompanyDetailPage from '@/pages/CompanyDetailPage'
import ContactListPage from '@/pages/ContactListPage'
import AgentListPage from '@/pages/AgentListPage'
import AgentDetailPage from '@/pages/AgentDetailPage'
import TaskListPage from '@/pages/TaskListPage'
import NotificationsPage from '@/pages/NotificationsPage'
import SettingsPage from '@/pages/SettingsPage'
import ProjectListPage from '@/pages/ProjectListPage'
import ProjectDetailPage from '@/pages/ProjectDetailPage'
import PortalPage from '@/pages/PortalPage'

export default function App() {
  const { user, loading, can } = useAuth()

  if (loading) {
    return <Spinner label="Starting up…" />
  }

  if (!user) {
    return (
      <Routes>
        <Route path="/login" element={<LoginPage />} />
        <Route path="*" element={<Navigate to="/login" replace />} />
      </Routes>
    )
  }

  // A referral agent's whole application is the portal. Routing them here
  // rather than hiding menu items means an internal URL cannot be typed in.
  if (can('portal.access') && !can('opportunity.view.all')) {
    return (
      <Routes>
        <Route element={<AppLayout />}>
          <Route path="/" element={<PortalPage />} />
          <Route path="/notifications" element={<NotificationsPage />} />
          <Route path="*" element={<Navigate to="/" replace />} />
        </Route>
      </Routes>
    )
  }

  return (
    <Routes>
      <Route path="/login" element={<Navigate to="/" replace />} />
      <Route element={<AppLayout />}>
        <Route path="/" element={<DashboardPage />} />
        <Route path="/pipeline" element={<OpportunityBoardPage />} />
        <Route path="/opportunities" element={<OpportunityListPage />} />
        <Route path="/opportunities/:id" element={<OpportunityDetailPage />} />
        <Route path="/companies" element={<CompanyListPage />} />
        <Route path="/companies/:id" element={<CompanyDetailPage />} />
        <Route path="/contacts" element={<ContactListPage />} />
        <Route path="/agents" element={<AgentListPage />} />
        <Route path="/agents/:id" element={<AgentDetailPage />} />
        <Route path="/projects" element={<ProjectListPage />} />
        <Route path="/projects/:id" element={<ProjectDetailPage />} />
        <Route path="/tasks" element={<TaskListPage />} />
        <Route path="/notifications" element={<NotificationsPage />} />
        <Route path="/settings" element={<SettingsPage />} />
        <Route path="*" element={<Navigate to="/" replace />} />
      </Route>
    </Routes>
  )
}
