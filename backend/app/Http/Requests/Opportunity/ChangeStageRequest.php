<?php

namespace App\Http\Requests\Opportunity;

use App\Domain\Pipeline\Models\PipelineStage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeStageRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'stage_id' => ['required', 'integer', Rule::exists(PipelineStage::class, 'id')],
            'note' => ['nullable', 'string', 'max:2000'],
            // Required for terminal stages; StageTransitionService enforces that.
            'loss_reason' => ['nullable', 'string', 'max:255'],
            'loss_note' => ['nullable', 'string', 'max:2000'],
            'final_value' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'next_action' => ['nullable', 'string', 'max:255'],
            'next_follow_up_at' => ['nullable', 'date'],
        ];
    }
}
