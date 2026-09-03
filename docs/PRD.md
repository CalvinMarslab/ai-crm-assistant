# Product Requirements Document

## 1. Product Overview

### Working Name
AI CRM Assistant

### Product Category
AI-assisted CRM + business execution assistant.

### Primary Goal
Prevent leads, follow-ups, tasks, and project handovers from being missed while giving the owner a clear daily view of what requires attention.

### Long-Term Goal
Evolve the system into a configurable multi-industry SaaS product.

## 2. Core Problems

### Lead information is scattered
Leads may come from referral agents, friends, networking, WhatsApp, websites, Facebook, email, or other sources.

### Follow-up actions are missed
When many opportunities are active, follow-ups are often forgotten or stored in separate personal to-do lists.

### Opportunity status is unclear
The owner cannot quickly see:
- what stage each opportunity is in
- who is responsible
- what has happened
- what must happen next
- whether a quotation was sent
- whether the customer has replied

### Project handover is disconnected from sales
After a deal is won, the project manager needs enough context to continue without losing the original customer history.

### Referral agents lack visibility
Referral agents should be able to see the progress of opportunities they introduced without seeing confidential internal information.

### Owner lacks a daily execution assistant
The system should eventually summarize what needs attention and generate a practical daily work plan.

## 3. Product Positioning

The product should be described as an AI business assistant with CRM capabilities, rather than only a CRM.

The AI should initially operate in semi-automatic mode:
- analyze
- summarize
- prioritize
- recommend actions
- prepare updates
- require confirmation before making important changes

The AI should not automatically contact customers in V1.

## 4. Target Users

### Owner / Admin
Full visibility across leads, pipeline, agents, follow-ups, won deals, project handovers, and daily work.

### Referral Agent
Can submit/referral leads and view the progress of only their own introduced opportunities.

### Project Manager
Can access won opportunities/projects assigned to them and view relevant customer requirements, history, files, tasks, and status.

## 5. Product Structure

### Company / Customer
A company/customer can have multiple contacts and multiple opportunities over time.

### Contact
An individual belonging to a company/customer.

### Opportunity
Each commercial requirement or possible project is stored separately.

Example:
Company ABC
- Website Revamp 2026
- Mobile App 2027
- Maintenance Contract 2027

Each is a separate opportunity.

### Project
A won opportunity may be converted into a project or linked to a project record.

## 6. Opportunity Pipeline

Default stages:
1. New Lead
2. Contacted
3. Requirement Gathering
4. Qualified
5. Proposal / Quotation Preparation
6. Proposal Sent
7. Follow-up / Negotiation
8. Won
9. Lost
10. On Hold

These stages should eventually be configurable.

## 7. Opportunity Information

Each opportunity should support:
- title
- company/customer
- contact person
- phone
- email
- source
- referral agent
- owner / salesperson
- stage
- estimated value
- probability
- expected close date
- priority
- summary
- requirements
- quotation status
- quotation amount
- last contact date
- next follow-up date
- next action
- loss reason
- tags
- created date
- updated date

## 8. Agent Management

Agents must have a complete module.

Agent profile:
- name
- company
- phone
- email
- status
- join date
- notes

Agent dashboard should eventually show:
- leads introduced
- active opportunities
- won opportunities
- lost opportunities
- total estimated value
- total won value
- conversion rate
- pipeline by stage

Commission fields may exist in the schema but payout automation is not required in V1.

## 9. Unified Timeline

Each company and opportunity should have a timeline containing events such as:
- lead created
- stage changed
- note added
- call logged
- meeting logged
- task created
- task completed
- quotation uploaded
- quotation sent
- customer reply noted
- follow-up changed
- project created
- PM assigned

Timeline entries should include:
- timestamp
- actor
- event type
- summary
- related object

## 10. Task / Follow-up Management

Tasks should be connected to companies, opportunities, projects, or users.

Task fields:
- title
- description
- assigned user
- related entity
- due date
- priority
- status
- reminder time
- source
- created by

Task status:
- To Do
- In Progress
- Waiting
- Done
- Cancelled

The system should clearly identify:
- overdue tasks
- due today
- upcoming
- unassigned
- opportunities with no next action

## 11. Dashboard

Owner dashboard should prioritize execution.

Sections:
- Today
- Overdue
- Leads requiring follow-up
- Opportunities without next action
- Proposal pending response
- High value opportunities
- Recently inactive opportunities
- Projects requiring attention
- Recent activity

Metrics:
- leads this month
- active opportunities
- pipeline value
- won value
- lost value
- win rate
- average sales cycle
- opportunities by source
- opportunities by agent

## 12. Daily Planner

Future AI daily planner should combine:
- overdue tasks
- today's tasks
- next follow-ups
- opportunity risk
- calendar events
- unresponded items

Output:
- top priorities
- recommended order of work
- overdue warnings
- waiting-on-customer list
- waiting-on-internal-team list

## 13. Notifications

Phase 1:
- in-app notifications

Later:
- Telegram notification bot

Examples:
- follow-up due today
- task overdue
- quotation not followed up for X days
- opportunity has no next action
- project waiting for PM update

## 14. Project Handover

When an opportunity is marked Won:
- user can convert to project
- assign project manager
- copy company/contact
- copy requirements
- copy documents
- retain original opportunity timeline
- create project handover checklist

Basic project statuses:
- Pending Handover
- Planning
- In Progress
- Waiting for Customer
- Internal Review
- Completed
- On Hold

## 15. AI Assistant Scope

The AI should eventually support natural language queries such as:
- What should I follow up today?
- Show all leads with no update in 5 days.
- Which agent brought the most won value this month?
- Summarize the ABC Mobile App opportunity.
- What projects are blocked?
- Prepare my daily priorities.

Write actions should initially require confirmation.

## 16. Success Metrics

V1 should aim for:
- every active opportunity has an owner
- every active opportunity has a stage
- every active opportunity has a next action or recorded exception
- owner can understand pipeline status within 30 seconds
- no follow-up should rely only on personal memory
- all agent referrals are traceable
- project handover history is preserved

## 17. Assumptions

- First production deployment is for one organization.
- SaaS architecture should be anticipated but billing is not required.
- WhatsApp chat ingestion is not required in V1.
- Telegram notification integration is Phase 3.
- AI daily planner is Phase 3.
