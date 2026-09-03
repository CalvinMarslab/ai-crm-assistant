# Telegram Integration

## Goal
Use Telegram as the first external notification channel.

## Initial Scope
System -> User notifications only.

Examples:
- 9:00 AM daily work summary
- follow-up due today
- task overdue
- high-value lead inactive
- project waiting for update

## Later Scope
Allow authorized users to send commands to the AI assistant through Telegram.

Examples:
- /today
- /pipeline
- /followups
- "Update ABC app project: customer asked for revised quotation next Friday"

The system should parse the request and ask for confirmation before modifying data.

## Security
- link Telegram user ID to internal user
- only allow authorized linked accounts
- all commands follow existing permissions
- log commands and resulting actions
