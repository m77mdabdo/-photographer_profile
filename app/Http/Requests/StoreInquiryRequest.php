<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\SpamGuard;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Everything a stranger sends, checked before it becomes a record.
 *
 * All of it is server-side. There is no JavaScript on this page, so the
 * browser's own `required` and `type="email"` are a convenience for people
 * who have them and nothing more — this class is the only thing standing
 * between a POST and the database.
 */
class StoreInquiryRequest extends FormRequest
{
    /** The public may submit this form. That is the entire point of it. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            /*
             * rfc, NOT rfc,dns.
             *
             * The dns rule requires an MX record on the domain. It makes
             * validation depend on a DNS lookup completing inside the
             * request, and it refuses real addresses at domains that receive
             * mail without an MX record. The cost of accepting a mistyped
             * address is one bounced reply; the cost of refusing a good one
             * is a lost commission and a stranger who thinks the site is
             * broken.
             */
            'email' => ['required', 'string', 'email:rfc', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40'],
            'company' => ['nullable', 'string', 'max:120'],

            'project_type' => ['nullable', 'string', 'max:60'],
            // after_or_equal:today rather than after: a shoot being arranged
            // for this afternoon is a real thing.
            'shoot_date' => ['nullable', 'date', 'after_or_equal:today'],
            'location' => ['nullable', 'string', 'max:120'],
            'budget_range' => ['nullable', 'string', 'max:40'],
            'heard_from' => ['nullable', 'string', 'max:60'],

            /*
             * Twenty characters minimum. Not to be difficult: "hi" is not an
             * enquiry anyone can answer, and the message box is where the
             * page's own copy asks for the thing that matters.
             */
            'message' => ['required', 'string', 'min:20', 'max:5000'],

            // Unticked by default and must be ticked. `accepted` rejects a
            // missing field, an empty string and a literal "false".
            'consent' => ['accepted'],
        ];
    }

    /**
     * Messages a person wrote, in the second person, saying what to do.
     *
     * Laravel's defaults are grammatical and useless: "The name field is
     * required" tells somebody who has just filled in a long message that a
     * FIELD has a problem, not that they need to give their name.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Please give a name to reply to.',
            'name.max' => 'That name is longer than the form can store — 120 characters is the limit.',

            'email.required' => 'An email address is needed, or there is no way to answer you.',
            'email.email' => 'That email address does not look complete — check for a typo.',
            'email.max' => 'That email address is longer than 190 characters, which is longer than any real one.',

            'shoot_date.after_or_equal' => 'That date has already passed. Leave it blank if you are not sure yet.',
            'shoot_date.date' => 'That date could not be read. Leave it blank if you are not sure yet.',

            'message.required' => 'Please say something about the work — this is the part that gets read first.',
            'message.min' => 'A little more detail would help: what the pictures are for, and roughly when.',
            'message.max' => 'That message is over 5,000 characters. Send the essentials and the rest can follow by email.',

            'consent.accepted' => 'Please confirm you are happy for your details to be kept, so the enquiry can be answered.',
        ];
    }

    /**
     * Human names for the fields, used by any message not overridden above.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'name',
            'email' => 'email address',
            'phone' => 'phone number',
            'company' => 'company',
            'project_type' => 'kind of work',
            'shoot_date' => 'date',
            'location' => 'location',
            'budget_range' => 'budget',
            'heard_from' => 'how you heard',
            'message' => 'message',
            'consent' => 'consent',
        ];
    }

    /**
     * The spam checks run after the field rules, and produce one message.
     *
     * ONE message, with no field attached, identical whichever check
     * refused. A bot that can tell the honeypot from the timer from the rate
     * limit can defeat them one at a time; a bot that only ever sees "this
     * could not be sent" has to solve all three at once or none.
     *
     * It runs `after` rather than as a rule so a genuine visitor who has ALSO
     * made a typo sees the typo — the field errors are already collected by
     * this point, and a real person's problem is reported before an
     * imaginary robot's.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if (! SpamGuard::passes($this)) {
                $validator->errors()->add(
                    'form',
                    'This could not be sent. Please reload the page and try again, or email the studio directly.'
                );
            }
        });
    }

    /**
     * Only the fields that belong on the record.
     *
     * Never $request->all(): status, consented_at and ip_hash are decided by
     * the controller, and a submitted field must not be able to reach them.
     *
     * @return array<string, mixed>
     */
    public function inquiryAttributes(): array
    {
        return [
            'name' => trim((string) $this->string('name')),
            'email' => mb_strtolower(trim((string) $this->string('email'))),
            'phone' => $this->filledOrNull('phone'),
            'company' => $this->filledOrNull('company'),
            'project_type' => $this->filledOrNull('project_type'),
            'shoot_date' => $this->filledOrNull('shoot_date'),
            'location' => $this->filledOrNull('location'),
            'budget_range' => $this->filledOrNull('budget_range'),
            'heard_from' => $this->filledOrNull('heard_from'),
            'message' => trim((string) $this->string('message')),
            'source' => 'contact_form',
        ];
    }

    private function filledOrNull(string $key): ?string
    {
        $value = trim((string) $this->input($key, ''));

        return $value === '' ? null : $value;
    }
}
