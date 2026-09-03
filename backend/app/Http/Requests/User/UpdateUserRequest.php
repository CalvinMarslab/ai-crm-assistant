<?php

namespace App\Http\Requests\User;

use App\Domain\Identity\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateUserRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique(User::class, 'email')->ignore($this->route('user')?->id)],
            'phone' => ['nullable', 'string', 'max:50'],
            'password' => ['sometimes', 'nullable', 'string', Password::defaults()],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'roles' => ['sometimes', 'array', 'min:1'],
            'roles.*' => ['string', Rule::exists(Role::class, 'code')],
        ];
    }
}
