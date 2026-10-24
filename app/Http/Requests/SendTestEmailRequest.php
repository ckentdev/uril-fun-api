<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SendTestEmailRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // One recipient only: no arrays, lists or comma-separated values.
            'to' => ['required', 'string', 'email:rfc', 'max:254'],
            // No line breaks: blocks header injection.
            'subject' => ['required', 'string', 'max:150', 'regex:/^[^\r\n]+$/'],
            'message' => ['required', 'string', 'max:2000'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $allowed = array_filter(array_map('trim', explode(',', (string) config('hub.email.allowed_domains'))));
            if ($allowed && ! $validator->errors()->has('to')) {
                $domain = strtolower(substr(strrchr((string) $this->input('to'), '@'), 1));
                if (! in_array($domain, array_map('strtolower', $allowed), true)) {
                    $validator->errors()->add('to', 'Recipients on this domain are not allowed in this demo.');
                }
            }
        }];
    }
}
