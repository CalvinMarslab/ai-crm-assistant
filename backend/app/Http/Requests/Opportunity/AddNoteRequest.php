<?php

namespace App\Http\Requests\Opportunity;

use App\Domain\Activity\Enums\ActivityType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddNoteRequest extends FormRequest
{
    private const ALLOWED_TYPES = [
        ActivityType::NoteAdded,
        ActivityType::CallLogged,
        ActivityType::MeetingLogged,
        ActivityType::CustomerReplyNoted,
    ];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:10000'],
            'type' => ['sometimes', Rule::in(array_map(fn ($type) => $type->value, self::ALLOWED_TYPES))],
            'is_internal' => ['sometimes', 'boolean'],
        ];
    }

    public function activityType(): ActivityType
    {
        return ActivityType::from($this->input('type', ActivityType::NoteAdded->value));
    }
}
