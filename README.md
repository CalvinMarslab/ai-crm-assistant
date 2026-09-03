# AI CRM Assistant — Phase 1

An AI-assisted CRM and business execution system. Phase 1 delivers the foundation
and core CRM: the owner can run the full lead lifecycle from referral to won/lost
without keeping a separate to-do list.

Built strictly to `DEVELOPMENT_PHASES.md` Phase 1. The AI assistant, Telegram,
project handover, and the agent portal belong to later phases and are not built here.

## Stack

| Layer | Choice |
|---|---|
| Backend | Laravel 13, PHP 8.3+, REST API, Sanctum tokens |
| Database | MySQL 8 |
| Frontend | React 19 + TypeScript, Vite, Tailwind CSS 4, TanStack Query |
| Queue/cache | `database` driver (swap to Redis via `.env` when available) |

## Setup

Requirements: PHP 8.3+, Composer, Node 20+, MySQL 8.

```bash
mysql -u root -e "CREATE DATABASE ai_crm CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

### Backend

```bash
cd backend
composer install
cp .env.example .env   # then set DB_DATABASE=ai_crm and your DB credentials
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

The API listens on `http://127.0.0.1:8000`.

`migrate --seed` creates the permissions, the three system roles, one organization,
the default 10-stage pipeline, the eight lead sources, an owner account, and — in
`local` only — a set of demo companies, agents, opportunities and tasks shaped so
every dashboard section has something in it.

To skip the demo content, run `php artisan migrate` then
`php artisan db:seed --class=PermissionSeeder` (and `RoleSeeder`, `OrganizationSeeder`).

**Seeded login:** `owner@aicrm.test` / `password` (change before any real deployment).

### Frontend

```bash
cd frontend
npm install
npm run dev
```

Open `http://localhost:5173`. Vite proxies `/api` to the backend, so there is no
CORS setup in development.

### Tests

```bash
mysql -u root -e "CREATE DATABASE ai_crm_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
cd backend && php artisan test
```

58 feature tests, written directly against `ACCEPTANCE_TEST.md`.

## Architecture

Modular monolith (`SYSTEM_ARCHITECTURE.md` §1). Business logic lives in
`app/Domain/<Module>`; HTTP concerns stay in `app/Http`.

```
backend/app/
├── Domain/
│   ├── Organization/  Organization
│   ├── Identity/      Role, Permission, PermissionCode + RoleCode enums
│   ├── Company/       Company, Contact
│   ├── Agent/         Agent + AgentStatsService
│   ├── Pipeline/      Pipeline, PipelineStage, StageType
│   ├── Opportunity/   Opportunity, StageHistory, LeadSource
│   │                  + OpportunityService, StageTransitionService,
│   │                    OpportunityHygieneService
│   ├── Task/          Task + TaskService
│   ├── Activity/      Activity + ActivityRecorder      (user-visible timeline)
│   ├── Notification/  AppNotification + Notifier
│   ├── Audit/         AuditLog + AuditRecorder         (forensic trail)
│   └── Dashboard/     DashboardService
├── Http/{Controllers/Api/V1, Requests, Resources, Middleware}
├── Policies/          One per entity, permission-code driven
└── Models/Concerns/   BelongsToOrganization, HasUuid
```

### Decisions worth knowing

**Organization scoping is structural.** `BelongsToOrganization` adds a global scope
and stamps `organization_id` on create, so cross-tenant leakage is not something
each query has to remember. V1 runs one organization; the scoping is already there.

**Permissions are codes, not roles.** `PermissionCode` is a PHP enum seeded into
the `permissions` table. Policies check codes only. The UI exposes three roles
(Owner, Referral Agent, Project Manager) but adding a fourth needs no policy edits.

**Two records per change.** `AuditRecorder` writes the forensic before/after entry;
`ActivityRecorder` writes the readable timeline entry. They are deliberately
separate — the audit log is complete, the timeline is curated.

**Stage changes have one door.** `StageTransitionService` is the only path. It
writes stage history, writes a timeline entry, mirrors the stage type onto
`opportunities.status`, and enforces the Won/Lost rules. A generic `PATCH` cannot
move a stage.

**Won and Lost demand their facts.** Lost requires a loss reason and cancels the
opportunity's outstanding tasks. Won requires a final value and stamps `won_at`.
Both are enforced server-side and mirrored in the UI so the user is never
surprised by a rejection.

**Next action or an explicit reason.** Architecture rule 7. `next_action` and
`no_action_reason` are separate columns; an opportunity with neither is flagged
on the dashboard. Clearing the next action without a reason is a validation error.

**UUIDs at the boundary.** Every externally addressed entity is reached by UUID.
Auto-increment ids never leave the API, and polymorphic `subject_type` columns
store morph aliases (`opportunity`, `company`) rather than class names.

**Services, not controllers, hold the rules.** `DashboardService` and
`OpportunityHygieneService` are plain typed services. The Phase 3 AI tool layer
(`get_pipeline_summary`, `get_overdue_tasks`, the daily brief) will call these
rather than re-query, so the AI can never disagree with the dashboard.

## Data model

18 tables. `projects`, `documents` and the `ai_*` tables are deliberately absent —
they belong to Phases 2 and 3.

organizations · users · permissions · roles · permission_role · role_user ·
agents · companies · contacts · lead_sources · pipelines · pipeline_stages ·
opportunities · opportunity_stage_history · activities · tasks ·
app_notifications · audit_logs

Two additions to the logical schema in `DATABASE_SCHEMA.md`, both driven by spec text:

- `opportunities.no_action_reason` — architecture rule 7 requires a next action
  **or** an explicit reason there is none.
- `pipeline_stages.agent_facing_status` — the agent-facing status mapping from
  `CRM_WORKFLOW.md` §7. Seeded now, consumed by the agent portal in Phase 2.

The notification table is named `app_notifications` so Laravel's own
`notifications` table stays free for the Telegram and email channels in later phases.

## API

All routes are under `/api/v1`, Sanctum-authenticated, organization-scoped and
policy-gated. Entities are addressed by UUID.

| Area | Endpoints |
|---|---|
| Auth | `POST /auth/login` · `POST /auth/logout` · `GET /auth/me` |
| Dashboard | `GET /dashboard` · `GET /dashboard/metrics` |
| Companies | `GET|POST /companies` · `GET|PATCH|DELETE /companies/{uuid}` · `/contacts` · `/opportunities` · `/timeline` |
| Contacts | `GET|POST /contacts` · `GET|PATCH|DELETE /contacts/{uuid}` |
| Agents | `GET|POST /agents` · `GET|PATCH|DELETE /agents/{uuid}` · `/stats` · `/opportunities` |
| Opportunities | `GET|POST /opportunities` · `GET|PATCH|DELETE /opportunities/{uuid}` |
| ” intent routes | `POST /{uuid}/stage` · `/next-action` · `/notes` · `/owner` · `/agent` |
| ” reads | `GET /{uuid}/timeline` · `/stage-history` |
| Tasks | `GET|POST /tasks` · `GET|PATCH|DELETE /tasks/{uuid}` · `POST /{uuid}/complete` · `/reopen` |
| Pipeline | `GET /pipelines` · `GET /lead-sources` |
| Notifications | `GET /notifications` · `/unread-count` · `POST /{uuid}/read` · `/read-all` |
| Audit | `GET /audit-logs` · `GET /audit-logs/{subjectType}/{uuid}` |
| Users | `GET|POST /users` · `PATCH /users/{uuid}` · `GET /roles` |

Opportunity list filters answer the execution questions directly:
`without_next_action`, `follow_up_due`, `awaiting_quotation_response`,
`inactive_days`, plus `stage_code`, `status`, `owner_id`, `agent_id`,
`source_code`, `priority`, `search`.

Task filters: `overdue`, `due_today`, `upcoming`, `unassigned`, `open`,
`status`, `priority`, `assignee_id`, `subject_type`+`subject_id`.

## Screens

| Route | Purpose |
|---|---|
| `/` | Dashboard — action sections first, then metrics, stage chart, activity |
| `/pipeline` | Kanban board by stage, stage change without leaving the board |
| `/opportunities` | Filterable table with hygiene-warning badges |
| `/opportunities/:id` | Header carries stage · owner · agent · next action · follow-up · value; tabs for Timeline / Tasks / Stage history |
| `/companies`, `/companies/:id` | Detail shows contacts, opportunities and the unified timeline |
| `/contacts` | List with modal create/edit, single-primary enforcement |
| `/agents`, `/agents/:id` | Detail shows performance stats derived from linked opportunities |
| `/tasks` | Grouped Overdue / Due today / Upcoming / No due date |
| `/notifications` | In-app notification centre with unread badge |
| `/settings` | Users & roles, pipeline stages, audit log |

Every create and edit happens in a modal, so updating an opportunity never costs a
page navigation.

## Permissions

Three seeded roles, built from granular codes:

- **Owner / Admin** — every permission code.
- **Referral Agent** — `agent.view.own`, `opportunity.view.own_referrals`,
  `opportunity.create`. Sees only opportunities linked to their own agent profile,
  with financial fields and internal notes stripped from the response and the
  simplified agent-facing status in place of the internal stage name.
- **Project Manager** — company/contact read, own opportunities, task management,
  pipeline read. No agent reassignment, no user management, no audit log.

Isolation is enforced in policies and in the query scope, and covered by tests.

## Configuration

`config/crm.php`:

- `inactivity_threshold_days` (default 7) — days without contact before an open
  opportunity counts as inactive. Overridable per organization via
  `organizations.settings`, so it is not hard-coded in UI logic.
- `currency` (default MYR).

## What Phase 1 does not include

Per `DEVELOPMENT_PHASES.md`: project handover and the agent portal (Phase 2);
the AI assistant and Telegram (Phase 3); email, calendar and WhatsApp (Phase 4);
SaaS onboarding and billing (Phase 5).

The seams for those exist — `agent_facing_status` on stages, the `Notifier` entry
point, organization scoping, and domain services with typed inputs — but no
speculative code was written for them.
