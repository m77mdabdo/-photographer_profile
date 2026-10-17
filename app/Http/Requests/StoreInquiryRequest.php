<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\LooseDate;
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
            /*
             * FREE TEXT, AND DELIBERATELY NOT A DATE RULE.
             *
             * This was `date|after_or_equal:today` behind a native date
             * picker. The picker rendered as a stock mm/dd/yyyy control — the
             * one thing on a typeset page that looked like a browser default —
             * and the rule refused every honest answer that is not a calendar
             * date: "late April", "the week of the 14th", "flexible".
             *
             * The only constraint now is length, because the only thing that
             * could go wrong is somebody pasting an essay into it. What can be
             * parsed is parsed into shoot_date; what cannot is kept verbatim.
             * Nobody's enquiry is refused over the least important field on
             * the form.
             */
            'shoot_date_text' => ['nullable', 'string', 'max:120'],
            'location' => ['nullable', 'string', 'max:120'],
            'budget_range' => ['nullable', 'string', 'max:40'],

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
        /*
         * ═══════════════════════════════════════════════════════════════════
         * TASK 35 H4 — AND THEY ARE TRANSLATED, WHICH THEY WERE NOT.
         *
         * Eleven refusals, written as English literals, on the ONE form a
         * member of the public fills in. An Arabic visitor who left the name
         * blank was answered "Please give a name to reply to." — on an
         * Arabic page, in an Arabic flow, after an Arabic heading.
         *
         * Nothing caught it because a validation message only exists when a
         * submission fails, and no scan has ever submitted anything.
         * ═══════════════════════════════════════════════════════════════════
         */
        return [
            'name.required' => __('studio.contact_errors.name_required'),
            'name.max' => __('studio.contact_errors.name_max'),

            'email.required' => __('studio.contact_errors.email_required'),
            'email.email' => __('studio.contact_errors.email_invalid'),
            'email.max' => __('studio.contact_errors.email_max'),

            'shoot_date_text.max' => __('studio.contact_errors.date_max'),

            'message.required' => __('studio.contact_errors.message_required'),
            'message.min' => __('studio.contact_errors.message_min'),
            'message.max' => __('studio.contact_errors.message_max'),

            'consent.accepted' => __('studio.contact_errors.consent'),
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
            'shoot_date_text' => 'date',
            'location' => 'location',
            'budget_range' => 'budget',
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

            /*
             * Both, always. The text is what they wrote; the date is what we
             * could make of it, and is null whenever that would mean guessing.
             * heard_from is absent on purpose — it is no longer asked on the
             * public form, and the column is now the studio's to fill in
             * after a conversation.
             */
            'shoot_date_text' => $this->filledOrNull('shoot_date_text'),
            'shoot_date' => LooseDate::parse($this->input('shoot_date_text'))?->toDateString(),

            'location' => $this->filledOrNull('location'),
            'budget_range' => $this->filledOrNull('budget_range'),
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
