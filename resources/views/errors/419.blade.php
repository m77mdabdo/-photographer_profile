<x-layouts::public :seo="\App\Support\SeoData::make(
    title: 'The page expired',
    description: 'You were idle long enough that the form went stale. Go back and send it again.',
    robots: 'noindex, nofollow',
)">
    <section class="error-page">
        <p class="error-page__code">Error 419</p>

        <h1 class="error-page__title">The page expired</h1>

        <p class="error-page__body">You were idle long enough that the form went stale. Go back and send it again.</p>

        <a class="error-page__home" href="{{ route('home') }}">Return home</a>
    </section>
</x-layouts::public>
