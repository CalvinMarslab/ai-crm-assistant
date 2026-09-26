# Telegram Integration

## Goal
Use Telegram as the first external notification channel.

## Implemented Scope
System notifications and linked-user AI conversations.

Examples:
- 9:00 AM daily work summary
- follow-up due today
- task overdue
- high-value lead inactive
- project waiting for update

Authorized users may send natural-language requests to the AI assistant through
private Telegram chats.

Examples:
- /today
- /pipeline
- /followups
- "Update ABC app project: customer asked for revised quotation next Friday"

The system asks for confirmation with inline buttons before modifying data.
Supported writes include creating and completing tasks, creating opportunities,
adding notes, and updating opportunity next actions or stages.

## Security
- link Telegram user ID to internal user
- only allow authorized linked accounts
- all commands follow existing permissions
- log commands and resulting actions
- accept messages and callbacks only from the linked private-chat owner
- make repeated Telegram deliveries and confirmations idempotent
