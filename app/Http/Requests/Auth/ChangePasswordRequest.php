<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ChangePasswordRequest extends FormRequest
{
    public function rules(): array
    {
        $rules = [
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ];

        // Google-only accounts have no current password to confirm.
        if ($this->user()->password !== null) {
            $rules['current_password'] = ['required', 'current_password:web'];
        }

        return $rules;
    }
}
