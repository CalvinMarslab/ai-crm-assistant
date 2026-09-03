<?php

namespace App\Http\Requests\User;

use App\Domain\Identity\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    /** Authorization runs before validation, so a denied caller gets 403, not 422. */
    public function authorize(): bool
    {
        return $this->user()?->can('create', User::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique(User::class, 'email')],
            'phone' => ['nullable', 'string', 'max:50'],
            'password' => ['required', 'string', Password::defaults()],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', Rule::exists(Role::class, 'code')],
        ];
    }
}
