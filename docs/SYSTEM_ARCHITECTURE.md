# System Architecture

## 1. Architecture Style
Modular monolith for V1.

Reason:
- faster to build
- easier to maintain
- suitable for small-to-medium internal team
- can later extract services if needed

## 2. Components

### Frontend
React + TypeScript admin application.

### Backend
Laravel REST API.

### Database
MySQL.

### Queue
Redis + Laravel queue.

### Storage
Local/S3-compatible abstraction.

### AI Service
Dedicated backend service layer with provider adapter.

### Notification Service
In-app notifications first, Telegram later.

## 3. Domain Modules
- Organization
- User
- Role / Permission
- Company
- Contact
- Agent
- Opportunity
- Pipeline
- Activity
- Task
- Project
- Document
- Notification
- AI Assistant
- Integration
- Audit

## 4. Multi-Tenant Preparation
Every business-owned table should include `organization_id` unless globally shared.

V1 may have only one organization but queries should still be scoped by organization.

## 5. AI Safety Architecture
AI cannot connect directly to the database.

Flow:
User -> AI Assistant -> Tool Request -> Backend Authorization -> Domain Service -> Database

All AI write operations must:
- identify requesting user
- validate permission
- validate input
- produce confirmation step when required
- log action

## 6. Integration Layer
Create interfaces for:
- Telegram
- Email
- WhatsApp
- Calendar
- LLM provider

Do not tightly couple business logic to third-party APIs.

## 7. Auditability
Critical state changes should create both:
- audit log
- user-visible activity timeline when relevant

Examples:
- opportunity stage changed
- owner changed
- agent changed
- amount changed
- task completed
- project PM assigned
