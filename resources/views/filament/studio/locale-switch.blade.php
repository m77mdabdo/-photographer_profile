{{--
    ───────────────────────────────────────────────────────────────────────────
    THE PANEL'S LANGUAGE, IN THE PANEL. Task 34b.

    The markup, the accessibility contract and the endpoint are the shared
    component's — see resources/views/components/locale-switch.blade.php. This
    file is what the panel adds to it: the Filament register.

      · fi-sr-only, because the panel's stylesheet is Filament's, not the
        public site's, and .sr-only is not in it.
      · x-filament::icon rather than <x-heroicon-…>, because the panel's
        middleware stack includes DisableBladeIconComponents.
      · keepQuery, because a dashboard deep link carries tableFilters and
        switching language on a filtered list must not quietly unfilter it.

    THIS IS THE INTERFACE LANGUAGE AND SAYS SO. The per-field tabs on a
    translatable form name the same two languages and mean something else: the
    language of the CONTENT. The globe here answers the document there, and
    the word "Interface" answers "Content language". See Translatable::tabs().
    ───────────────────────────────────────────────────────────────────────────
--}}
<x-locale-switch
    block="studio-locale"
    sr="fi-sr-only"
    :legend="__('studio.locale.interface_legend')"
    :label="__('studio.locale.interface')"
    :keep-query="true"
>
    <x-slot:icon>
        <x-filament::icon icon="heroicon-m-globe-alt" />
    </x-slot:icon>
</x-locale-switch>
