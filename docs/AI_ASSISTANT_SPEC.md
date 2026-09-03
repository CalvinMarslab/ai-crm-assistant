# AI Assistant Specification

## 1. Positioning
AI is a semi-automatic business assistant.

It should first help the owner understand and prioritize work.

## 2. Initial AI Capabilities
- summarize opportunity history
- summarize company history
- identify overdue follow-ups
- identify inactive opportunities
- identify opportunities without next action
- generate daily priorities
- answer pipeline questions
- analyze agent performance
- draft recommended next actions

## 3. Natural Language Examples
- What should I focus on today?
- Show me all quotations waiting more than 3 days.
- Which leads have not been contacted this week?
- Summarize ABC Company's latest opportunity.
- What did Brian refer to us this month?
- Which opportunities are likely to close soon?
- Which projects are waiting for customer feedback?

## 4. AI Tooling Model
AI must call backend tools.

Suggested tools:
- search_companies
- search_opportunities
- get_opportunity
- get_company_timeline
- get_tasks
- get_overdue_tasks
- get_agent_performance
- get_pipeline_summary
- create_task
- update_opportunity_next_action
- update_opportunity_stage
- add_note

Write tools require confirmation initially.

## 5. Daily Brief
Daily brief should contain:
- top priorities
- overdue tasks
- follow-ups due
- high-value opportunities at risk
- proposals awaiting response
- projects requiring update
- suggested next actions

## 6. AI Guardrails
- never bypass permissions
- never invent customer data
- clearly distinguish facts from recommendations
- write actions require confirmation by default
- every executed write action must be logged
- no automatic customer communication in V1
