# Hermes Agent Integration

Hermes may propose CRM updates through a signed webhook. It never connects to
the database and cannot bypass the same tool permissions used by the web and
Telegram assistants.

## Endpoints

- `POST /api/v1/integrations/hermes/actions` — propose an update
- `POST /api/v1/integrations/hermes/actions/{proposal_uuid}/confirm` — execute after user approval
- `POST /api/v1/integrations/hermes/actions/{proposal_uuid}/reject` — cancel

Every request must include:

- `X-Hermes-Timestamp`: current Unix timestamp (accepted skew: five minutes)
- `X-Hermes-Signature`: `sha256=` followed by
  `HMAC-SHA256(timestamp + "." + exact_raw_json_body, HERMES_WEBHOOK_SECRET)`

The propose body contains a globally unique `event_id`, the sender's Telegram
user ID as `telegram_user_id`, an allowed write-tool name, and that tool's structured payload.
Reusing the same event ID returns the original proposal rather than duplicating
it.

```json
{
  "event_id": "telegram:12345:message:987",
  "telegram_user_id": "123456789",
  "action": "create_task",
  "payload": {
    "title": "Follow up quotation",
    "due_at": "2026-09-28",
    "subject_type": "opportunity",
    "subject_reference": "OPPORTUNITY-UUID"
  }
}
```

The response includes `data.id`, `data.summary`, `data.status` and
`data.expires_at`. Hermes should show the summary to the Telegram user and only
call the confirm endpoint after the user presses its approval button. Confirm
and reject bodies contain the same `telegram_user_id` and are signed in the same way.

## Allowed actions

Actions are resolved from the CRM's permission-filtered AI tool registry. The
current write set is:

- `create_opportunity`
- `create_task`
- `complete_task`
- `add_note`
- `update_opportunity_next_action`
- `update_opportunity_stage`
- `ingest_lead` — atomically resolves/creates the referral agent and client,
  creates the opportunity with requirements, and assigns the Telegram user a
  “Prepare proposal” task

### Lead intake example

```json
{
  "event_id": "telegram:12345:message:990",
  "telegram_user_id": "123456789",
  "action": "ingest_lead",
  "payload": {
    "agent_name": "Jane Referrer",
    "agent_email": "jane@example.com",
    "company_name": "Example Client Sdn Bhd",
    "company_registration_no": "202601234567",
    "contact_name": "John Client",
    "contact_email": "john@example.com",
    "lead_title": "Example Client CRM rollout",
    "requirements": "Needs CRM deployment for 20 sales users.",
    "proposal_due_at": "2026-09-29"
  }
}
```

Agent matching uses exact email, then phone. Company matching uses registration
number, email, phone, then exact full name. The integration never performs fuzzy
matching; ambiguous results are rejected so Hermes can ask for more information.

An action not available to the actor's CRM role is rejected. Permissions are
checked again at confirmation time.

## Hermes configuration

Set a long random value as `HERMES_WEBHOOK_SECRET` in the CRM backend and store
the same value only in the Hermes server's secret store. Implement these three
calls as a small Hermes plugin or MCP server. Do not put the secret in prompts,
skill files, Telegram messages, or logs.

Files are intentionally not accepted by this JSON webhook. Hermes should use a
separate, scoped document-upload integration so MIME type, size, ownership and
subject authorization remain enforced by the existing Documents service.
