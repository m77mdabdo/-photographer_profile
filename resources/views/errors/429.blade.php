<x-layouts::public :seo="\App\Support\SeoData::make(
    title: 'Too many requests',
    description: 'Something asked for a great deal at once. Wait a moment and try again.',
    robots: 'noindex, nofollow',
)">
    <section class="error-page">
        <p class="error-page__code">Error 429</p>

        <h1 class="error-page__title">Too many requests</h1>

        <p class="error-page__body">Something asked for a great deal at once. Wait a moment and try again.</p>

        <a class="error-page__home" href="{{ route('home') }}">Return home</a>
    </section>
</x-layouts::public>
