<x-layouts::public :seo="\App\Support\SeoData::make(
    title: 'Not for you',
    description: 'This page is private, or the link you followed has since been locked.',
    robots: 'noindex, nofollow',
)">
    <section class="error-page">
        <p class="error-page__code">Error 403</p>

        <h1 class="error-page__title">Not for you</h1>

        <p class="error-page__body">This page is private, or the link you followed has since been locked.</p>

        <a class="error-page__home" href="{{ route('home') }}">Return home</a>
    </section>
</x-layouts::public>
