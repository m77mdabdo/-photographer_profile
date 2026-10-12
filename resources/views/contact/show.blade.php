@php
    use App\Support\Settings;
    use App\Support\SpamGuard;

    /*
     * THE MESSAGE COMES FIRST.
     *
     * It used to be last, under nine short boxes. The page's own copy says
     * "tell me what you are making" and then made you fill in a record before
     * you could. The layout now agrees with the copy: the brief is the first
     * thing on the form and the largest thing on the page, and the logistics
     * sit underneath it where somebody can add them if they know them.
     *
     * The short fields are grouped into two fieldsets, which is both the
     * editorial division — who you are, what the job is — and a real
     * accessibility improvement: a screen reader announces the legend when
     * focus enters the group, so "Email" is heard as "Email, about you".
     *
     * `heard_from` is gone. It was a marketing question asked of somebody who
     * has not been replied to yet. The column and the admin field remain, so
     * the studio can record it themselves after a conversation.
     */
    /*
     * EVERY LABEL AND HINT COMES FROM THE PANEL.
     *
     * This array held ten labels, four hints and two legends as literals —
     * the most-read copy on the site after the hero, on the form a client
     * fills in to hire the studio, and the owner could not change a word.
     *
     * The STRUCTURE stays here: which field, what input type, whether it is
     * required, which autocomplete token. Those are not copy, and a `type`
     * an owner could edit is a text box that stops accepting email
     * addresses. See the report's "kept uneditable" list.
     */
    $copy = static fn (string $key): string => \App\Support\SiteCopy::get($key);

    $groups = [
        'about-you' => [
            'legend' => $copy('contact.legend_about'),
            'fields' => [
                'name' => ['label' => $copy('contact.field_name'), 'type' => 'text', 'required' => true, 'autocomplete' => 'name'],
                'email' => ['label' => $copy('contact.field_email'), 'type' => 'email', 'required' => true, 'autocomplete' => 'email'],
                'phone' => ['label' => $copy('contact.field_phone'), 'type' => 'tel', 'required' => false, 'autocomplete' => 'tel', 'hint' => $copy('contact.field_phone_hint')],
                'company' => ['label' => $copy('contact.field_company'), 'type' => 'text', 'required' => false, 'autocomplete' => 'organization'],
            ],
        ],
        'the-job' => [
            'legend' => $copy('contact.legend_job'),
            'fields' => [
                'project_type' => ['label' => $copy('contact.field_project_type'), 'type' => 'text', 'required' => false, 'hint' => $copy('contact.field_project_type_hint')],
                'shoot_date_text' => ['label' => $copy('contact.field_date'), 'type' => 'text', 'required' => false, 'hint' => $copy('contact.field_date_hint')],
                'location' => ['label' => $copy('contact.field_location'), 'type' => 'text', 'required' => false],
                'budget_range' => ['label' => $copy('contact.field_budget'), 'type' => 'text', 'required' => false, 'hint' => $copy('contact.field_budget_hint')],
            ],
        ],
    ];
@endphp
<x-layouts::public :seo="$seo">
    <section class="enquiry" aria-labelledby="enquiry-title">
        <header class="enquiry__header">
            <p class="enquiry__eyebrow">{{ Settings::get('nav.contact') }}</p>

            <h1 class="enquiry__title" id="enquiry-title">
                {{ Settings::get('contact.heading') }}
            </h1>

            @if ($standfirst = Settings::get('contact.standfirst'))
                <p class="enquiry__standfirst">{{ $standfirst }}</p>
            @endif

            @if ($note = Settings::get('contact.response_note'))
                <p class="enquiry__note">{{ $note }}</p>
            @endif
        </header>

        {{--
            THE ERROR SUMMARY.

            role="alert" so it is announced the moment the page loads after a
            failed submission, tabindex="-1" so the controller's redirect can
            be followed by focus landing here, and every entry is a real link
            to the field it describes. A summary that only restates the errors
            is decoration; one that takes you to them is a tool.
        --}}
        @if ($errors->any())
            <div class="enquiry__errors" role="alert" tabindex="-1" id="enquiry-errors" autofocus>
                <h2 class="enquiry__errors-title">
                    {{ $errors->count() === 1 ? 'One thing needs fixing' : $errors->count().' things need fixing' }}
                </h2>

                <ul class="enquiry__errors-list" role="list">
                    @foreach ($errors->keys() as $key)
                        <li>
                            @if ($key === 'form')
                                {{ $errors->first($key) }}
                            @else
                                <a href="#field-{{ $key }}">{{ $errors->first($key) }}</a>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form class="enquiry__form" method="POST" action="{{ route('contact.store') }}" novalidate>
            @csrf

            {{--
                THE HONEYPOT.

                Hidden three ways, because one is not enough:
                  - .visually-gone takes it out of the visual flow without
                    display:none, which some bots check for;
                  - aria-hidden removes it from the accessibility tree, so a
                    screen reader never announces it;
                  - tabindex="-1" takes it out of the keyboard path, so nobody
                    tabs into a field they cannot see.

                autocomplete="off" stops a browser helpfully filling it in and
                turning a real person into a false positive.
            --}}
            <div class="visually-gone" aria-hidden="true">
                <label for="field-{{ SpamGuard::HONEYPOT }}">{{ \App\Support\SiteCopy::get('contact.honeypot_label') }}</label>
                <input type="text"
                       id="field-{{ SpamGuard::HONEYPOT }}"
                       name="{{ SpamGuard::HONEYPOT }}"
                       tabindex="-1"
                       autocomplete="off"
                       value="">
            </div>

            {{-- Encrypted render time. See App\Support\SpamGuard. --}}
            <input type="hidden" name="{{ SpamGuard::TIMER }}" value="{{ $timer }}">

            {{-- ---------- 1. THE BRIEF ---------------------------------- --}}
            <div @class(['field', 'field--brief', 'field--invalid' => $errors->has('message')])>
                <label class="field__label field__label--lead" for="field-message">
                    {{ Settings::get('contact.message_label') }}
                    <span class="field__required">{{ \App\Support\SiteCopy::get('contact.required_marker') }}</span>
                </label>

                <p class="field__hint" id="hint-message">
                    {{ Settings::get('contact.message_hint') }}
                </p>

                <textarea id="field-message"
                          name="message"
                          rows="10"
                          required
                          autofocus
                          aria-describedby="hint-message{{ $errors->has('message') ? ' error-message' : '' }}"
                          @if ($errors->has('message')) aria-invalid="true" @endif
                >{{ old('message') }}</textarea>

                @if ($errors->has('message'))
                    <p class="field__error" id="error-message">{{ $errors->first('message') }}</p>
                @endif
            </div>

            {{-- ---------- 2. THE LOGISTICS, UNDERNEATH ------------------ --}}
            @foreach ($groups as $key => $group)
                <fieldset class="enquiry__group">
                    <legend class="enquiry__legend">{{ $group['legend'] }}</legend>

                    <div class="enquiry__grid">
                        @foreach ($group['fields'] as $name => $field)
                            @php
                                $hasError = $errors->has($name);
                                $described = array_filter([
                                    isset($field['hint']) ? "hint-{$name}" : null,
                                    $hasError ? "error-{$name}" : null,
                                ]);
                            @endphp
                            <div @class(['field', 'field--invalid' => $hasError])>
                                <label class="field__label" for="field-{{ $name }}">
                                    {{ $field['label'] }}
                                    @if ($field['required'])
                                        <span class="field__required">{{ \App\Support\SiteCopy::get('contact.required_marker') }}</span>
                                    @else
                                        <span class="field__optional">{{ \App\Support\SiteCopy::get('contact.optional_marker') }}</span>
                                    @endif
                                </label>

                                @if (isset($field['hint']))
                                    <p class="field__hint" id="hint-{{ $name }}">{{ $field['hint'] }}</p>
                                @endif

                                <input type="{{ $field['type'] }}"
                                       id="field-{{ $name }}"
                                       name="{{ $name }}"
                                       value="{{ old($name) }}"
                                       @if ($field['required']) required @endif
                                       @if (isset($field['autocomplete'])) autocomplete="{{ $field['autocomplete'] }}" @endif
                                       @if ($described !== []) aria-describedby="{{ implode(' ', $described) }}" @endif
                                       @if ($hasError) aria-invalid="true" @endif>

                                {{-- The error is bound to the input by aria-describedby, so a
                                     screen reader reads the problem when focus arrives. --}}
                                @if ($hasError)
                                    <p class="field__error" id="error-{{ $name }}">{{ $errors->first($name) }}</p>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </fieldset>
            @endforeach

            {{--
                CONSENT. Unticked, always — `old('consent')` is the only thing
                that can check it, and that only happens when the visitor
                already ticked it and something else failed.
            --}}
            <div @class(['consent', 'field--invalid' => $errors->has('consent')])>
                <input type="checkbox"
                       id="field-consent"
                       name="consent"
                       value="1"
                       @checked(old('consent'))
                       aria-describedby="consent-detail{{ $errors->has('consent') ? ' error-consent' : '' }}"
                       @if ($errors->has('consent')) aria-invalid="true" @endif>

                <label class="consent__label" for="field-consent">{{ Settings::get('contact.consent') }}</label>

                <p class="consent__detail" id="consent-detail">{{ Settings::get('contact.consent_detail') }}</p>

                @if ($errors->has('consent'))
                    <p class="field__error" id="error-consent">{{ $errors->first('consent') }}</p>
                @endif
            </div>

            {{--
                "Send this", not "Send this enquiry".

                An enquiry is what a CRM calls it. The person pressing the
                button has just written a few sentences about a job they care
                about, and "send this" is what you would say handing somebody a
                note — it refers to the thing they wrote rather than
                reclassifying it as a form submission on the way out.
            --}}
            <button type="submit" class="enquiry__submit">
                {{ Settings::get('contact.submit') }}
                <span class="enquiry__submit-rule" aria-hidden="true"></span>
            </button>
        </form>
    </section>
</x-layouts::public>
