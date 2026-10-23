<?php

namespace App\Http\Requests;

use App\Enums\SubmissionResult;
use App\Models\Integration;
use App\Services\Leads\LeadIntake;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * A post to POST /intake/{key} (public, no session). The integration is found by its key (404 if
 * unknown or turned off). Server-to-server posts must carry the secret; browser posts are allowed
 * only when the integration accepts them and the Origin is one of its sites. The form's own field
 * names are mapped to lead fields first (LeadIntake::map), then those are validated.
 */
class IntakeRequest extends FormRequest
{
    public const MAX_BYTES = 65536;

    private ?Integration $integration = null;

    private ?array $mapped = null;

    public function integration(): Integration
    {
        if ($this->integration === null) {
            abort_if(strlen($this->getContent()) > self::MAX_BYTES, 413);
            $this->integration = Integration::query()->active()->where('public_key', (string) $this->route('key'))->first()
                ?? abort(404);
        }

        return $this->integration;
    }

    /** Signed or Bearer post (from a server, Zapier, Make…) rather than a visitor's browser. */
    public function isServerPost(): bool
    {
        return $this->bearerToken() !== null || $this->hasHeader('X-CRM-Signature');
    }

    public function origin(): ?string
    {
        return $this->header('Origin') ?: $this->header('Referer');
    }

    public function authorize(): bool
    {
        $integration = $this->integration();

        return $this->isServerPost()
            ? $integration->acceptsSecret($this->bearerToken(), $this->header('X-CRM-Timestamp'), $this->header('X-CRM-Signature'), $this->getContent())
            : $integration->acceptsOrigin($this->origin());
    }

    /** @return array{fields: array<string, string>, extras: array<string, string>, tracking: array<string, string>, names: list<string>, spam: bool} */
    public function mapped(): array
    {
        return $this->mapped ??= app(LeadIntake::class)->map($this->all());
    }

    public function validationData(): array
    {
        return $this->mapped()['fields'];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [];
        foreach (LeadIntake::FIELDS as $field => $max) {
            $rules[$field] = ['nullable', 'string', "max:{$max}"];
        }
        $rules['email'] = ['nullable', 'required_without:phone', 'email', 'max:191'];
        $rules['phone'] = ['nullable', 'required_without:email', 'string', 'max:50', 'regex:/^(\D*\d){6,}/'];

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required_without' => 'An email address or phone number is needed. No field we could read as one was sent.',
            'phone.required_without' => 'An email address or phone number is needed. No field we could read as one was sent.',
            'email.email' => 'The email address isn’t valid.',
            'phone.regex' => 'The phone number needs at least 6 digits.',
        ];
    }

    protected function failedAuthorization(): void
    {
        throw new HttpResponseException(response()->json([
            'ok' => false,
            'error' => $this->isServerPost()
                ? 'The secret key or signature is wrong, or the timestamp is more than 5 minutes old.'
                : 'This website isn’t allowed to send leads to this form.',
        ], 401));
    }

    protected function failedValidation(Validator $validator): void
    {
        $errors = array_values(array_unique($validator->errors()->all()));
        app(LeadIntake::class)->log($this->integration(), SubmissionResult::Rejected, null, $this->mapped()['names'], $errors, $this->ip());

        throw new HttpResponseException(response()->json(['ok' => false, 'errors' => $errors], 422)
            ->withHeaders($this->corsHeaders()));
    }

    /** CORS for browser posts from an allowed site (fetch/XHR); nothing for anyone else. */
    public function corsHeaders(): array
    {
        if ($this->isServerPost() || ! $this->integration()->acceptsOrigin($this->header('Origin'))) {
            return [];
        }

        return [
            'Access-Control-Allow-Origin' => Integration::normalizeOrigin($this->header('Origin')),
            'Vary' => 'Origin',
        ];
    }
}
