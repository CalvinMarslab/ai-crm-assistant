<?php

namespace App\Domain\Integration\Telegram;

/**
 * Turns the daily brief into a Telegram message.
 *
 * A separate class from the brief itself: the brief is facts, this is
 * presentation for one channel, and the two change for different reasons.
 */
class TelegramBriefFormatter
{
    /**
     * @param  array<string, mixed>  $brief
     */
    public function format(array $brief): string
    {
        $counts = $brief['counts'];
        $lines = [];

        if (array_sum($counts) === 0) {
            return "Nothing needs your attention today. Nothing is overdue, and every open opportunity "
                ."has a next action.";
        }

        if ($brief['top_priorities'] !== []) {
            $lines[] = '<b>Start here</b>';

            foreach ($brief['top_priorities'] as $priority) {
                $lines[] = '• '.e($priority['what']).' — '.e(strtolower($priority['why']));
            }

            $lines[] = '';
        }

        $summary = array_filter([
            $counts['overdue_tasks'] ? $counts['overdue_tasks'].' overdue' : null,
            $counts['due_today'] ? $counts['due_today'].' due today' : null,
            $counts['follow_ups_due'] ? $counts['follow_ups_due'].' follow-ups due' : null,
            $counts['without_next_action'] ? $counts['without_next_action'].' with no next action' : null,
            $counts['proposals_awaiting_response'] ? $counts['proposals_awaiting_response'].' quotations waiting' : null,
            $counts['projects_requiring_update'] ? $counts['projects_requiring_update'].' projects need an update' : null,
        ]);

        if ($summary !== []) {
            $lines[] = '<b>Today</b>';
            $lines[] = implode(' · ', $summary);
        }

        if ($brief['suggested_actions'] !== []) {
            $lines[] = '';
            $lines[] = '<b>Suggested</b>';

            foreach (array_slice($brief['suggested_actions'], 0, 4) as $suggestion) {
                $lines[] = '• '.e($suggestion);
            }
        }

        return implode("\n", $lines);
    }
}
