# Database Schema

This is a logical schema. Claude Code may adjust implementation details while preserving relationships.

## organizations
- id
- uuid
- name
- status
- timezone
- created_at
- updated_at

## users
- id
- uuid
- organization_id
- name
- email
- phone
- password
- status
- last_login_at
- created_at
- updated_at

## roles
- id
- organization_id nullable
- name
- code

## permissions
- id
- name
- code

## role_user
- role_id
- user_id

## permission_role
- permission_id
- role_id

## agents
- id
- uuid
- organization_id
- user_id nullable
- name
- company_name nullable
- email nullable
- phone nullable
- status
- notes nullable
- joined_at nullable
- created_at
- updated_at

## companies
- id
- uuid
- organization_id
- name
- registration_no nullable
- industry nullable
- website nullable
- phone nullable
- email nullable
- address nullable
- notes nullable
- created_at
- updated_at
- deleted_at

## contacts
- id
- uuid
- organization_id
- company_id nullable
- name
- job_title nullable
- phone nullable
- email nullable
- is_primary boolean
- notes nullable
- created_at
- updated_at
- deleted_at

## lead_sources
- id
- organization_id
- name
- code
- is_active

Examples:
- Referral Agent
- Friend Referral
- BNI
- Facebook
- Website
- WhatsApp
- Existing Customer
- Other

## pipelines
- id
- organization_id
- name
- is_default

## pipeline_stages
- id
- pipeline_id
- name
- code
- sequence
- stage_type
- probability_default nullable
- is_active

stage_type examples:
- open
- won
- lost
- hold

## opportunities
- id
- uuid
- organization_id
- company_id
- primary_contact_id nullable
- pipeline_id
- stage_id
- owner_user_id
- referral_agent_id nullable
- lead_source_id nullable
- title
- summary nullable
- requirements longtext nullable
- estimated_value decimal nullable
- quotation_amount decimal nullable
- quotation_status nullable
- probability decimal nullable
- priority
- expected_close_date nullable
- last_contact_at nullable
- next_follow_up_at nullable
- next_action nullable
- loss_reason nullable
- status
- won_at nullable
- lost_at nullable
- created_at
- updated_at
- deleted_at

## opportunity_stage_history
- id
- opportunity_id
- from_stage_id nullable
- to_stage_id
- changed_by_user_id
- changed_at
- note nullable

## activities
- id
- uuid
- organization_id
- actor_user_id nullable
- activity_type
- subject_type
- subject_id
- title
- body nullable
- metadata json nullable
- occurred_at
- created_at

## tasks
- id
- uuid
- organization_id
- assigned_user_id nullable
- created_by_user_id
- subject_type nullable
- subject_id nullable
- title
- description nullable
- priority
- status
- due_at nullable
- reminder_at nullable
- completed_at nullable
- source
- created_at
- updated_at
- deleted_at

## projects
- id
- uuid
- organization_id
- opportunity_id nullable
- company_id
- project_manager_user_id nullable
- name
- status
- summary nullable
- requirements longtext nullable
- start_date nullable
- target_end_date nullable
- completed_at nullable
- created_at
- updated_at

## project_handover_items
- id
- project_id
- title
- description nullable
- status
- assigned_user_id nullable
- due_at nullable

## documents
- id
- uuid
- organization_id
- uploaded_by_user_id
- subject_type
- subject_id
- document_type nullable
- name
- storage_path
- mime_type nullable
- file_size nullable
- created_at

## notifications
- id
- organization_id
- user_id
- type
- title
- body
- data json nullable
- read_at nullable
- created_at

## audit_logs
- id
- organization_id
- user_id nullable
- action
- subject_type
- subject_id nullable
- before_data json nullable
- after_data json nullable
- ip_address nullable
- user_agent nullable
- created_at

## ai_conversations
- id
- uuid
- organization_id
- user_id
- title nullable
- created_at
- updated_at

## ai_messages
- id
- conversation_id
- role
- content longtext
- tool_calls json nullable
- created_at

## ai_action_requests
- id
- organization_id
- user_id
- conversation_id nullable
- action_name
- action_payload json
- status
- confirmation_required boolean
- confirmed_at nullable
- executed_at nullable
- execution_result json nullable
- created_at
