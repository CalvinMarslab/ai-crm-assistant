# User Roles & Permission

## Owner / Admin
Can:
- view all companies
- view all contacts
- view all opportunities
- create/edit/delete opportunities
- assign opportunity owners
- assign referral agents
- manage stages
- manage users
- manage agents
- view all tasks
- view dashboards and reports
- convert won opportunities to projects
- assign PMs
- view audit logs
- access AI assistant

## Referral Agent
Can:
- login
- view own agent profile
- submit a new referral lead
- view only opportunities linked to their agent profile
- view limited opportunity status
- view stage history intended for agent visibility
- view high-level project progress for their referrals when allowed

Cannot:
- view internal notes
- view other agents' leads
- view confidential quotation margin
- view internal tasks
- view internal audit logs
- edit pipeline stage directly unless specifically allowed

## Project Manager
Can:
- view assigned projects
- view related customer and opportunity history needed for delivery
- update project status
- add project notes
- create/update project tasks
- upload documents
- update handover checklist

Cannot:
- view unrelated sales opportunities unless granted
- change agent ownership
- manage organization settings

## Permission Design Requirement
Use granular permission codes even if the UI initially exposes only three standard roles.
