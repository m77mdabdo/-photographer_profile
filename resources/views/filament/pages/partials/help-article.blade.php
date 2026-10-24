{{--
    One Help article (Help Center directive, Parts 3, 11, 12).

    The steps are an ordinary numbered list — complete, printable, readable
    with no script at all. "Guide me step by step" (Alpine) shows them one at
    a time with Previous / Next / Start again / Show all steps; it changes
    only what is visible, never anything in the dashboard. The status line
    is a polite live region and takes focus, so a screen reader hears
    "Step 3 of 7" after each move.

    No double quote may appear inside an x-data or x-* attribute here:
    one in a comment once ended the attribute and broke the component.
--}}
@php
    $isGuide = $article['kind'] === 'reference';
    $t = $article[$locale] ?? $article['en'];
    $category = $categories[$article['category']] ?? null;
    $steps = $isGuide ? [] : $t['steps'];
@endphp

<article class="studio-help__article" aria-labelledby="help-article-title">
    <nav class="studio-help__crumbs" aria-label="{{ __('studio.help.nav') }}">
        <a href="{{ $url() }}">{{ __('studio.help.nav') }}</a>
        @if ($category !== null)
            <span aria-hidden="true">/</span>
            <a href="{{ $url(['topic' => $article['category']]) }}">{{ $category['title'] }}</a>
        @elseif ($isGuide)
            <span aria-hidden="true">/</span>
            <a href="{{ $url(['guide' => 1]) }}">{{ __('studio.help.full_guide') }}</a>
        @endif
    </nav>

    <header class="studio-help__article-header">
        <h2 id="help-article-title" class="studio-help__article-title">{{ \App\Support\Help\HelpText::plain($t['title']) }}</h2>

        @unless ($isGuide)
            <p class="studio-help__article-summary">{{ \App\Support\Help\HelpText::plain($t['summary']) }}</p>

            <p class="studio-help__meta">
                <span @class(['studio-help__kind', 'studio-help__kind--task' => $article['kind'] === 'task', 'studio-help__kind--trouble' => $article['kind'] === 'trouble'])>{{ __('studio.help.kind.'.$article['kind']) }}</span>
                <span>{{ __('studio.help.minutes', ['n' => $article['minutes']]) }}</span>
                <span>{{ __('studio.help.level.'.$article['level']) }}</span>
                <span>{{ __('studio.help.roles.'.$article['roles']) }}</span>
            </p>

            <p class="studio-help__actions">
                <a href="{{ url($article['open']) }}" class="studio-help__open" target="_blank" rel="noopener">
                    {{ __('studio.help.open') }}
                    <span class="sr-only">({{ __('studio.help.opens_new_tab') }})</span>
                    <x-filament::icon icon="heroicon-m-arrow-top-right-on-square" class="studio-help__open-icon" />
                </a>
            </p>
        @endunless
    </header>

    @if ($isGuide)
        <div class="studio-help__body">{!! $t['html'] !!}</div>
    @else
        <div class="studio-help__article-grid">
            <div class="studio-help__article-main">
                <section class="studio-help__panel" aria-labelledby="help-outcome">
                    <h3 id="help-outcome">{{ __('studio.help.outcome') }}</h3>
                    <div class="studio-help__prose">{!! $this->html($t['outcome']) !!}</div>
                </section>

                @if ($t['before'] !== [])
                    <section class="studio-help__panel" aria-labelledby="help-before">
                        <h3 id="help-before">{{ __('studio.help.before') }}</h3>
                        <ul class="studio-help__bullets">
                            @foreach ($t['before'] as $line)
                                <li>{!! $this->inline($line) !!}</li>
                            @endforeach
                        </ul>
                    </section>
                @endif

                @if ($t['warnings'] !== [])
                    <aside class="studio-help__warning" aria-labelledby="help-warnings">
                        <h3 id="help-warnings">
                            <x-filament::icon icon="heroicon-m-exclamation-triangle" class="studio-help__warning-icon" />
                            {{ __('studio.help.warnings') }}
                        </h3>
                        <ul class="studio-help__bullets">
                            @foreach ($t['warnings'] as $line)
                                <li>{!! $this->inline($line) !!}</li>
                            @endforeach
                        </ul>
                    </aside>
                @endif

                <section class="studio-help__steps-block" aria-labelledby="help-steps"
                         x-data="{ guided: false, i: 0, total: {{ count($steps) }},
                                   go(n) { this.i = Math.min(Math.max(n, 0), this.total - 1); this.$nextTick(() => this.$refs.status.focus()) } }">
                    <div class="studio-help__steps-head">
                        <h3 id="help-steps">{{ __('studio.help.steps') }}</h3>
                        <p class="studio-help__where"><span>{{ __('studio.help.where') }}:</span> {!! $this->inline($t['path']) !!}</p>
                        <button type="button" class="studio-help__button" x-show="! guided" x-on:click="guided = true; go(0)">
                            {{ __('studio.help.step_mode') }}
                        </button>
                    </div>

                    <div class="studio-help__stepper" x-show="guided" x-cloak>
                        <p class="studio-help__step-status" x-ref="status" tabindex="-1" role="status" aria-live="polite"
                           data-template="{{ __('studio.help.step_of', ['n' => '__N__', 'total' => count($steps)]) }}"
                           x-text="$el.dataset.template.replace('__N__', i + 1)"></p>
                        <div class="studio-help__progress" aria-hidden="true">
                            <span x-bind:style="'inline-size:' + ((i + 1) / total * 100) + '%'"></span>
                        </div>
                    </div>

                    <ol class="studio-help__steps">
                        @foreach ($steps as $n => $step)
                            <li class="studio-help__step" x-show="! guided || i === {{ $n }}" wire:key="step-{{ $n }}">
                                <span class="studio-help__step-number" aria-hidden="true">{{ $n + 1 }}</span>
                                <div class="studio-help__step-text">
                                    <h4>{!! $this->inline($step['title']) !!}</h4>
                                    <div class="studio-help__prose">{!! $this->html($step['body']) !!}</div>
                                </div>
                            </li>
                        @endforeach
                    </ol>

                    <div class="studio-help__stepper-controls" x-show="guided" x-cloak>
                        <button type="button" class="studio-help__button" x-on:click="go(i - 1)" x-bind:disabled="i === 0">{{ __('studio.help.previous') }}</button>
                        <button type="button" class="studio-help__button studio-help__button--primary" x-on:click="go(i + 1)" x-show="i < total - 1">{{ __('studio.help.next') }}</button>
                        <p class="studio-help__finished" x-show="i === total - 1">{{ __('studio.help.finished') }}</p>
                        <button type="button" class="studio-help__link" x-on:click="go(0)">{{ __('studio.help.restart') }}</button>
                        <button type="button" class="studio-help__link" x-on:click="guided = false">{{ __('studio.help.show_all') }}</button>
                    </div>
                </section>

                @if ($t['fields'] !== [])
                    <section class="studio-help__panel" aria-labelledby="help-fields">
                        <h3 id="help-fields">{{ __('studio.help.fields') }}</h3>
                        <dl class="studio-help__fields">
                            @foreach ($t['fields'] as $field)
                                <div>
                                    <dt>{!! $this->inline($field['name']) !!}</dt>
                                    <dd>{!! $this->inline($field['about']) !!}</dd>
                                </div>
                            @endforeach
                        </dl>
                    </section>
                @endif

                @if ($t['verify'] !== [])
                    <section class="studio-help__panel" aria-labelledby="help-verify">
                        <h3 id="help-verify">{{ __('studio.help.verify') }}</h3>
                        <ul class="studio-help__checks" role="list">
                            @foreach ($t['verify'] as $n => $line)
                                <li>
                                    <label>
                                        <input type="checkbox">
                                        <span>{!! $this->inline($line) !!}</span>
                                    </label>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif

                @if ($t['trouble'] !== [])
                    <section class="studio-help__panel" aria-labelledby="help-trouble">
                        <h3 id="help-trouble">{{ __('studio.help.trouble') }}</h3>
                        <dl class="studio-help__fields studio-help__fields--trouble">
                            @foreach ($t['trouble'] as $problem)
                                <div>
                                    <dt>{!! $this->inline($problem['if']) !!}</dt>
                                    <dd>{!! $this->inline($problem['then']) !!}</dd>
                                </div>
                            @endforeach
                        </dl>
                    </section>
                @endif
            </div>

            <aside class="studio-help__article-side">
                @if ($article['related'] !== [])
                    <section class="studio-help__panel studio-help__panel--quiet" aria-labelledby="help-related">
                        <h3 id="help-related">{{ __('studio.help.related') }}</h3>
                        <ul class="studio-help__list" role="list">
                            @foreach ($this->cards($article['related']) as $card)
                                <li><a href="{{ $url(['article' => $card['id']]) }}">{{ $card['title'] }}</a></li>
                            @endforeach
                        </ul>
                    </section>
                @endif

                <p class="studio-help__verified">
                    <x-filament::icon icon="heroicon-m-check-badge" class="studio-help__verified-icon" />
                    {{ __('studio.help.verified.'.$article['verified']['how']) }}
                </p>
            </aside>
        </div>
    @endif
</article>
