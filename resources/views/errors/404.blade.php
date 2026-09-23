<x-layouts::public :seo="\App\Support\SeoData::make(
    title: 'Nothing here',
    description: 'The page you asked for has moved, been renamed, or never existed.',
    robots: 'noindex, nofollow',
)">
    <section class="error-page">
        <p class="error-page__code">Error 404</p>

        <h1 class="error-page__title">Nothing here</h1>

        <p class="error-page__body">The page you asked for has moved, been renamed, or never existed.</p>

        <a class="error-page__home" href="{{ route('home') }}">Return home</a>
    </section>
</x-layouts::public>
