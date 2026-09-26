<?php

return [
    /*
     * Which provider adapter to use. "openai_compatible" covers OpenAI itself
     * and the many endpoints that mirror its chat-completions API. Leave the
     * key blank and the assistant reports itself unavailable — the daily brief
     * and risk detection are computed without a model and keep working.
     */
    'provider' => env('AI_PROVIDER', 'openai_compatible'),

    'openai_compatible' => [
        'base_url' => env('AI_BASE_URL', 'https://api.openai.com/v1'),
        'api_key' => env('AI_API_KEY', ''),
        'model' => env('AI_MODEL', 'gpt-4o-mini'),
        'timeout' => (int) env('AI_TIMEOUT', 60),
    ],

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN', ''),
        // The @username of the bot created with @BotFather, without the @.
        'bot_username' => env('TELEGRAM_BOT_USERNAME', ''),
        // Set via Telegram's setWebhook API; verified on every inbound update.
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET', ''),
        // Hour of the day, in each organization's own timezone, for the brief.
        'daily_brief_hour' => (int) env('TELEGRAM_DAILY_BRIEF_HOUR', 9),
    ],

    'hermes' => [
        'webhook_secret' => env('HERMES_WEBHOOK_SECRET', ''),
    ],
];
