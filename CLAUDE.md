# CLAUDE.md

## Project Name
AI CRM Assistant (working title)

## Mission
Build an AI-assisted CRM and business execution assistant that helps owners, referral agents, and project managers manage leads, opportunities, follow-ups, tasks, project handover, and daily priorities from one system.

The product should begin as an internal system but be architected so it can later become a multi-tenant SaaS product usable across industries.

## Core Product Principle
This is not only a CRM database. It is an AI-assisted execution system.

The system should answer:
- What leads need attention today?
- What follow-ups are overdue?
- What opportunities have no next action?
- Which quotations are waiting for customer response?
- What tasks are blocked?
- What projects are at risk?
- Which agent introduced which opportunities and what is their progress?
- What should the owner focus on today?

## Development Instruction
Before writing code, read all files in this specification folder.

Do not implement the whole platform at once.
Implement strictly according to `DEVELOPMENT_PHASES.md`.

Start with Phase 1 only unless explicitly instructed otherwise.

## Recommended Stack
Backend:
- Laravel 12+
- PHP 8.3+
- REST API
- Laravel Sanctum for authentication
- MySQL 8+
- Queue workers for notifications, AI jobs, integrations
- Redis recommended for queues/cache

Frontend:
- React + TypeScript
- Vite
- Tailwind CSS
- Modern admin-style responsive UI

Future mobile:
- Flutter consuming the same REST API

AI layer:
- Provider-agnostic service interface
- OpenAI-compatible LLM initially
- Tool/function calling through backend-controlled actions
- AI must never write directly to the database

Notifications:
- In-app notification center
- Telegram integration in later phase

## Architecture Rules
1. Use service/repository/domain separation where practical.
2. All state changes must be auditable.
3. Important entities should support soft delete where appropriate.
4. Use UUIDs for externally exposed identifiers where practical.
5. Prepare for multi-tenancy even if V1 runs as one organization.
6. Do not hard-code statuses in UI logic when they can be stored/configured.
7. Every opportunity must have:
   - owner
   - stage
   - source
   - next action or explicit no-action reason
8. Every important user action should create an activity timeline record.
9. AI suggestions are advisory by default and require user confirmation for write actions.
10. Build permissions from the start.

## UX Rules
- Owner should understand current business status within 30 seconds.
- Main dashboard must prioritize actions, not vanity metrics.
- Minimize clicks to update an opportunity.
- Every customer/company page should show a unified timeline.
- Every opportunity should show current stage, owner, next action, source, agent, expected value, and recent activity.
- Avoid cluttered enterprise-CRM complexity in V1.

## Core Roles
- Owner / Admin
- Referral Agent
- Project Manager

See `USER_ROLES_PERMISSION.md`.

## Core V1 Modules
- Authentication
- Organization foundation
- Users and roles
- Companies / Customers
- Contacts
- Referral Agents
- Leads / Opportunities
- Sales Pipeline
- Tasks / Follow-ups
- Unified Timeline / Activities
- Project handover status
- Dashboard
- Notifications
- Audit log

## Do Not Build in Phase 1
- Automated WhatsApp replies
- Automatic customer outreach
- AI auto-send email
- Commission payout automation
- Advanced accounting
- Full SaaS billing
- Mobile app
- Complex workflow builder

## Code Quality
- Use migrations and seeders.
- Use Form Requests / DTO validation.
- Use policies or equivalent authorization layer.
- Add feature tests for critical flows.
- Do not expose sensitive internal IDs unnecessarily.
- Keep AI integration behind a dedicated interface.
- Add clear README setup instructions.

## First Deliverable
Create Phase 1 according to `DEVELOPMENT_PHASES.md`, with database structure based on `DATABASE_SCHEMA.md`, workflows based on `CRM_WORKFLOW.md`, and acceptance criteria from `ACCEPTANCE_TEST.md`.
