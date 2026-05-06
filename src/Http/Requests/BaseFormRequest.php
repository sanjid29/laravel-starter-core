<?php

namespace Sanjid29\StarterCore\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

abstract class BaseFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function isUpdating(): bool
    {
        return $this->recordId() !== null;
    }

    protected function recordId(): int|string|null
    {
        return $this->route('record');
    }
}
