<x-layouts::public :seo="\App\Support\SeoData::make(
    title: 'Back shortly',
    description: 'The site is down for brief maintenance. Nothing is lost; try again in a few minutes.',
    robots: 'noindex, nofollow',
)">
    <section class="error-page">
        <p class="error-page__code">Error 503</p>

        <h1 class="error-page__title">Back shortly</h1>

        <p class="error-page__body">The site is down for brief maintenance. Nothing is lost; try again in a few minutes.</p>

        <a class="error-page__home" href="{{ route('home') }}">Return home</a>
    </section>
</x-layouts::public>
