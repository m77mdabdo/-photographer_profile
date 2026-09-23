<x-layouts::public :seo="\App\Support\SeoData::make(
    title: 'Something broke',
    description: 'An error on our side stopped this page from loading. It has been logged.',
    robots: 'noindex, nofollow',
)">
    <section class="error-page">
        <p class="error-page__code">Error 500</p>

        <h1 class="error-page__title">Something broke</h1>

        <p class="error-page__body">An error on our side stopped this page from loading. It has been logged.</p>

        <a class="error-page__home" href="{{ route('home') }}">Return home</a>
    </section>
</x-layouts::public>
