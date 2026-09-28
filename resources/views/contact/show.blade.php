@php
    use App\Support\Settings;
    use App\Support\SpamGuard;

    /*
     * Every field, in one place, so the markup below stays readable and the
     * error summary and the inputs cannot drift apart.
     */
    $fields = [
        'name' => ['label' => 'Your name', 'type' => 'text', 'required' => true, 'autocomplete' => 'name'],
        'email' => ['label' => 'Email', 'type' => 'email', 'required' => true, 'autocomplete' => 'email'],
        'phone' => ['label' => 'Phone', 'type' => 'tel', 'required' => false, 'autocomplete' => 'tel', 'hint' => 'Only if you would rather talk than type.'],
        'company' => ['label' => 'Company or publication', 'type' => 'text', 'required' => false, 'autocomplete' => 'organization'],
        'project_type' => ['label' => 'Kind of work', 'type' => 'text', 'required' => false, 'hint' => 'Portrait, editorial, archive, something else.'],
        'shoot_date' => ['label' => 'Date, if you have one', 'type' => 'date', 'required' => false],
        'location' => ['label' => 'Where', 'type' => 'text', 'required' => false],
        'budget_range' => ['label' => 'Budget', 'type' => 'text', 'required' => false, 'hint' => 'A range is fine. It decides what is possible, not whether you get a reply.'],
        'heard_from' => ['label' => 'How you heard', 'type' => 'text', 'required' => false],
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
                <label for="field-{{ SpamGuard::HONEYPOT }}">Do not fill this in</label>
                <input type="text"
                       id="field-{{ SpamGuard::HONEYPOT }}"
                       name="{{ SpamGuard::HONEYPOT }}"
                       tabindex="-1"
                       autocomplete="off"
                       value="">
            </div>

            {{-- Encrypted render time. See App\Support\SpamGuard. --}}
            <input type="hidden" name="{{ SpamGuard::TIMER }}" value="{{ $timer }}">

            <div class="enquiry__grid">
                @foreach ($fields as $name => $field)
                    @php
                        $hasError = $errors->has($name);
                        $described = array_filter([
                            isset($field['hint']) ? "hint-{$name}" : null,
                            $hasError ? "error-{$name}" : null,
                        ]);
                    @endphp
                    <div @class(['field', 'field--wide' => in_array($name, ['name', 'email'], true), 'field--invalid' => $hasError])>
                        <label class="field__label" for="field-{{ $name }}">
                            {{ $field['label'] }}
                            @if ($field['required'])
                                <span class="field__required">required</span>
                            @else
                                <span class="field__optional">optional</span>
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

                <div @class(['field', 'field--full', 'field--invalid' => $errors->has('message')])>
                    <label class="field__label" for="field-message">
                        What are you making?
                        <span class="field__required">required</span>
                    </label>

                    <p class="field__hint" id="hint-message">
                        The part that gets read first. What the pictures are for, who will see them, and roughly when.
                    </p>

                    <textarea id="field-message"
                              name="message"
                              rows="9"
                              required
                              aria-describedby="hint-message{{ $errors->has('message') ? ' error-message' : '' }}"
                              @if ($errors->has('message')) aria-invalid="true" @endif
                    >{{ old('message') }}</textarea>

                    @if ($errors->has('message'))
                        <p class="field__error" id="error-message">{{ $errors->first('message') }}</p>
                    @endif
                </div>
            </div>

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

            <button type="submit" class="enquiry__submit">
                Send this enquiry
                <span class="enquiry__submit-rule" aria-hidden="true"></span>
            </button>
        </form>
    </section>
</x-layouts::public>
