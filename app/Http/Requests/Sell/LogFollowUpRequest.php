<?php

namespace App\Http\Requests\Sell;

use App\Models\OrderFollowUp;
use Illuminate\Foundation\Http\FormRequest;

class LogFollowUpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'outcome' => 'required|string|in:' . implode(',', OrderFollowUp::OUTCOMES),
            'channel' => 'sometimes|nullable|string|in:' . implode(',', OrderFollowUp::CHANNELS),
            'note' => 'nullable|string|max:1000|required_if:outcome,' . implode(',', OrderFollowUp::NOTE_REQUIRED),
            'nextFollowUpAt' => 'nullable|date|after:now|required_if:outcome,call_later',
        ];
    }

    public function messages(): array
    {
        return [
            'outcome.in' => 'Pick what happened on the call.',
            'note.required_if' => 'Add a note for this outcome.',
            'nextFollowUpAt.required_if' => 'Pick when to call back.',
            'nextFollowUpAt.after' => 'The next follow-up must be in the future.',
        ];
    }
}
