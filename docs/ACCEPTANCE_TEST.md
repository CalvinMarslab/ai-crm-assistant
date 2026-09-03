# Acceptance Test

## Authentication
- user can login
- unauthorized user cannot access application data
- organization data is scoped correctly

## Company
- owner can create company
- company can contain multiple contacts
- company page displays related opportunities
- company page displays timeline

## Agent
- owner can create agent
- opportunity can be linked to agent
- agent statistics can be calculated from linked opportunities

## Opportunity
- owner can create opportunity
- opportunity requires company, owner, and stage
- opportunity can change stage
- stage change creates history record
- stage change creates activity timeline record
- opportunity can store next action and follow-up date
- won opportunity stores won date
- lost opportunity requires loss reason

## Task
- user can create task linked to opportunity
- task can be assigned
- overdue tasks are displayed
- completed task records completion date
- task actions appear in timeline when relevant

## Dashboard
- shows overdue tasks
- shows today's follow-ups
- shows opportunities without next action
- shows pipeline value
- shows stage distribution
- shows recent activity

## Permissions
- agent cannot see other agents' opportunities
- agent cannot see internal notes
- PM cannot access unrelated opportunities by default
- owner can view all records

## Audit
- important changes are recorded
- stage change is auditable
- owner reassignment is auditable

## UX
- opportunity can be updated without excessive page navigation
- opportunity page shows stage, owner, agent, next action, follow-up, value, and recent activity prominently
- owner dashboard gives actionable information immediately
