<?php

namespace App\Domain\Ai\Tools;

use App\Domain\Activity\Enums\ActivityType;
use App\Domain\Ai\Data\ToolDefinition;
use App\Domain\Identity\Enums\PermissionCode;
use App\Domain\Opportunity\Models\Opportunity;
use App\Domain\Opportunity\Services\OpportunityService;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AddNoteTool extends WriteTool
{
    public function __construct(private readonly OpportunityService $opportunities) {}

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'add_note',
            description: 'Record a note, call, meeting or customer reply on an opportunity timeline. '
                .'Logging a call or meeting also updates when the customer was last contacted. '
                .'The user confirms before anything is saved.',
            parameters: [
                'type' => 'object',
                'properties' => [
                    'reference' => ['type' => 'string'],
                    'body' => ['type' => 'string'],
                    'type' => [
                        'type' => 'string',
                        'enum' => ['note.added', 'call.logged', 'meeting.logged', 'customer.reply_noted'],
                        'description' => 'Default note.added.',
                    ],
                    'is_internal' => ['type' => 'boolean', 'description' => 'Internal notes are hidden from referral agents.'],
                ],
                'required' => ['reference', 'body'],
            ],
        );
    }

    public function permission(): ?string
    {
        return PermissionCode::OpportunityUpdate->value;
    }

    public function summarise(User $user, array $arguments): string
    {
        $opportunity = $this->find($arguments);
        $type = str_replace(['.', '_'], ' ', $arguments['type'] ?? 'note.added');

        return 'Add a '.trim($type).' to "'.$opportunity->title.'": '.$arguments['body'];
    }

    public function execute(User $user, array $arguments): array
    {
        $opportunity = $this->find($arguments);

        Gate::forUser($user)->authorize('update', $opportunity);
        Auth::setUser($user);

        $this->opportunities->addNote(
            $opportunity,
            $arguments['body'],
            (bool) ($arguments['is_internal'] ?? false),
            ActivityType::from($arguments['type'] ?? ActivityType::NoteAdded->value),
        );

        return ['recorded' => true, 'reference' => $opportunity->uuid];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function find(array $arguments): Opportunity
    {
        $opportunity = Opportunity::query()->where('uuid', $arguments['reference'] ?? '')->first();

        if ($opportunity === null) {
            throw ValidationException::withMessages(['reference' => 'No opportunity with that reference.']);
        }

        return $opportunity;
    }
}
