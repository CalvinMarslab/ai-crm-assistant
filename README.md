# AI CRM Assistant — Phases 1–2

An AI-assisted CRM and business execution system.

**Phase 1** delivers the foundation and core CRM: the owner runs the full lead
lifecycle from referral to won/lost without keeping a separate to-do list.

**Phase 2** carries a won deal into delivery and opens the door to referral
agents: convert to project, assign a PM, work a handover checklist, track
project status and tasks, attach documents, and give agents a portal showing
their own referrals in simplified language.

Built to `DEVELOPMENT_PHASES.md`. The AI assistant, Telegram, email, calendar
and SaaS billing belong to later phases and are not built here.

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

121 feature tests: the `ACCEPTANCE_TEST.md` criteria, the end-to-end lead
lifecycle, tenant and role isolation, opportunity state rules, timezone handling,
production-safety checks, project handover, the agent portal, and document
storage security.

The suite freezes the clock (`Tests\TestCase::NOW`) because much of this domain
is time-relative; without that, results depend on what time of day the suite runs.

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
| **Projects** | `POST /opportunities/{uuid}/convert-to-project` · `GET /projects` · `GET|PATCH|DELETE /projects/{uuid}` |
| ” actions | `POST /{uuid}/status` · `/manager` · `/notes` |
| ” handover | `GET|POST /{uuid}/handover-items` · `PATCH /{uuid}/handover-items/{uuid}` · `GET /{uuid}/handover-brief` |
| ” reads | `GET /{uuid}/timeline` · `/tasks` |
| **Documents** | `GET|POST /documents` · `GET /documents/{uuid}/download` · `DELETE /documents/{uuid}` |
| **Agent portal** | `GET /portal/summary` · `/portal/opportunities` · `/portal/opportunities/{uuid}` |

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
| `/projects`, `/projects/:id` | Handover checklist, tasks, documents, timeline, and the sales history behind the project |
| `/settings` | Users & roles, pipeline stages, audit log |

A referral agent signs in to a portal instead of the CRM: their own referrals in
simplified language, their performance, and a progress trail. It is a separate
route tree, not the staff app with menu items hidden, so an internal URL cannot
be reached by typing it.

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

## Before real customer data

Phase 1 has been through an audit and stabilization pass, but a few things are
deployment decisions rather than code:

**Keep the repository private.** It is private today. Treat that as a
requirement, not a preference, once real customer names, deal values, or
production credentials exist — including anything that reaches the repo through
a seeder, a fixture, a screenshot, or a support dump. Making it public is a
one-way door: anything ever pushed stays in the git history and in forks even
after a later commit removes it.

**Production checklist**

| Item | What to do |
|---|---|
| `APP_DEBUG` | Set to `false`. The example file ships `true` for local work; leaving it on returns stack traces to users. |
| `APP_ENV` | Set to `production`. `DemoDataSeeder` refuses to run outside `local`/`testing`, and this is what enforces it. |
| `APP_KEY` | Generate a fresh one per environment (`php artisan key:generate`). Never share it across environments. |
| `FRONTEND_URL` | Set to the real frontend origin. CORS is derived from it; a wrong value silently blocks the app. |
| Seeded owner | `owner@aicrm.test` / `password` is a development convenience. Change the password, or delete the account, before anyone else can reach the system. |
| `.env` | Never commit it. It is git-ignored; verify with `git check-ignore backend/.env`. |
| Database | Use a dedicated user with rights to the CRM schema only, not `root`. |
| HTTPS | Terminate TLS in front of the API. Tokens travel in the `Authorization` header. |
| Backups | `opportunities`, `activities`, `audit_logs` and `opportunity_stage_history` are the irreplaceable tables. |

**What is already enforced in code:** organization scoping on every query and at
the authorization layer; login rate limiting (5/min per IP and per email) plus a
general API limit (120/min); CORS restricted to `FRONTEND_URL`; passwords and
tokens excluded from the audit trail; internal ids never exposed through the API.

## Phase 2 notes

**Handover is a gate, not a label.** A project cannot leave Pending Handover
until every checklist item is settled. Skipping it is exactly how delivery
loses the sales context, so the rule is enforced server-side.

**The sale keeps its own history.** Conversion copies company, contact,
requirements and the commercial reference onto the project. It does not move
the opportunity's timeline — that stays where it happened, and the project links
back to it.

**Agents see a different vocabulary, not a filtered view.** The portal has its
own resource (`AgentFacingOpportunityResource`) rather than flags on the
internal one, so the agent-facing shape is explicit and cannot widen by
accident. Internal stage names, values, owners and notes are absent from the
payload, not hidden in the UI.

**Documents are streamed, never served.** Files are stored outside the web root
under generated names, and every download passes the same authorization as the
record it hangs off. Uploads are an extension allow-list.

`DEVELOPMENT_PHASES.md` does not list documents under Phase 2, but
`USER_ROLES_PERMISSION.md` gives the PM "upload documents",
`CRM_WORKFLOW.md` §6 requires the PM to receive "relevant documents", and
PRD §14 says "copy documents" — so they are built here as part of handover.

## What is not included yet

Per `DEVELOPMENT_PHASES.md`: the AI assistant and Telegram (Phase 3); email,
calendar and WhatsApp (Phase 4); SaaS onboarding and billing (Phase 5).

The seams exist — the `Notifier` entry point, organization scoping, and domain
services with typed inputs the Phase 3 tool layer can call — but no speculative
code was written for them.
