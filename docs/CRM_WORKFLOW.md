# CRM Workflow

## 1. Referral Lead Flow
1. Agent or owner creates a lead/opportunity.
2. System records source and referral agent.
3. Opportunity starts at New Lead.
4. Owner/salesperson is assigned.
5. Next action should be created immediately.
6. Task/follow-up is optionally created.
7. Timeline records creation event.

## 2. Sales Progression
New Lead
-> Contacted
-> Requirement Gathering
-> Qualified
-> Proposal / Quotation Preparation
-> Proposal Sent
-> Follow-up / Negotiation
-> Won / Lost / On Hold

Every stage change:
- records stage history
- records timeline activity
- may prompt for next action

## 3. Opportunity Hygiene Rules
System should warn if:
- no owner
- no next action
- no update for configured number of days
- proposal sent but no follow-up date
- expected close date has passed

## 4. Lost Flow
When marked Lost:
- require loss reason
- optionally require note
- close outstanding sales tasks or prompt user

## 5. Won Flow
When marked Won:
- capture final value
- capture won date
- prompt to create project
- assign PM
- generate project handover checklist
- preserve sales timeline

## 6. Project Handover
PM should receive:
- company details
- contact details
- opportunity summary
- requirements
- relevant documents
- quotation reference
- timeline history
- handover checklist

## 7. Agent Visibility
Agent sees simplified statuses:
- New
- In Discussion
- Proposal Stage
- Negotiation
- Won
- Lost
- Project In Progress
- Completed

Internal pipeline stages may map to agent-facing statuses.
