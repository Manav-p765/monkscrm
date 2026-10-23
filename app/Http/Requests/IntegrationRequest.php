<?php

namespace App\Http\Requests;

use App\Models\Integration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Add or change a website form integration (Settings → Integrations, admin only). "Allowed
 * websites" is typed one per line and stored as normalized origins ("https://example.com").
 */
class IntegrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-settings');
    }

    protected function prepareForValidation(): void
    {
        $lines = preg_split('/[\s,]+/', (string) $this->input('allowed_origins'), -1, PREG_SPLIT_NO_EMPTY);

        $this->merge([
            'name' => trim((string) $this->input('name')),
            'allow_browser' => $this->boolean('allow_browser'),
            'is_active' => $this->has('is_active') ? $this->boolean('is_active') : true,
            'origins' => $lines,
            'redirect_url' => trim((string) $this->input('redirect_url')) ?: null,
        ]);
        $this->errorBag = $this->route('integration') ? 'integration'.$this->route('integration')->id : 'integration';
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'lead_source_id' => ['nullable', 'integer', Rule::exists('lead_sources', 'id')->where('is_active', true)],
            'assign_to' => ['nullable', 'integer', Rule::exists('users', 'id')->where('is_active', true)],
            'allow_browser' => ['boolean'],
            'origins' => ['array', 'max:20'],
            'origins.*' => ['string', 'max:255'],
            'redirect_url' => ['nullable', 'url:https', 'max:255'],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Enter a name, e.g. “Contact form – hybridmonks.com”.',
            'name.max' => 'Keep the name under 100 characters.',
            'lead_source_id.exists' => 'Choose an active source from the list.',
            'assign_to.exists' => 'Choose an active user from the list.',
            'origins.max' => 'Add at most 20 websites.',
            'redirect_url.url' => 'Enter a full web address starting with https://.',
        ];
    }

    /**
     * @return list<callable>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            foreach ($this->input('origins', []) as $line) {
                if (Integration::normalizeOrigin($line) === null) {
                    $validator->errors()->add('allowed_origins', "“{$line}” isn’t a website address. Use one per line, like https://example.com.");

                    return;
                }
            }
            if ($this->boolean('allow_browser') && $this->input('origins', []) === []) {
                $validator->errors()->add('allowed_origins', 'Add the website(s) the form is on, so posts from other sites are refused.');
            }
        }];
    }

    /** Validated values ready for the model. @return array<string, mixed> */
    public function values(): array
    {
        $origins = array_values(array_unique(array_map(Integration::normalizeOrigin(...), $this->validated('origins', []))));

        return [
            ...$this->safe()->only(['name', 'lead_source_id', 'assign_to', 'allow_browser', 'redirect_url', 'is_active']),
            'allowed_origins' => $origins ?: null,
        ];
    }
}
