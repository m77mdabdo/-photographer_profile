<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The settings, grouped by WHERE THEY APPEAR ON THE SITE.
 *
 * ───────────────────────────────────────────────────────────────────────────
 * NOT BY DATABASE KEY, WHICH IS WHAT IT WAS.
 *
 * A key like `rail.client` or `closing.cta` tells you which template reads it.
 * That is useful to whoever wrote the template and useless to the person whose
 * website it is: an owner who wants to reword the line above the contact form
 * does not know that it lives under `contact` rather than `closing`, and the
 * old grouping put those two in the same section by accident of prefix.
 *
 * So the order below is the order a visitor meets the site — identity, then
 * the navigation, then the home page top to bottom, then each section's own
 * page, then the footer, then the things shared across pages, then the
 * machinery. An owner looking for a string can find it by remembering where
 * they saw it.
 *
 * WHY THIS IS NOT A CONSTANT IN A LIVEWIRE COMPONENT ANY MORE.
 *
 * It was: SettingsManager::GROUP_TITLES, which the Filament page imported from
 * the old admin. That is a dependency pointing the wrong way — the component
 * is deleted in Part 6 and would have taken the panel's section headings with
 * it — and it listed five of the nine groups that existed, so four sections
 * were titled by str()->headline() on the raw key.
 *
 * Both admins read this instead, which is the project's standing rule: where
 * both exist, one calls the other.
 * ───────────────────────────────────────────────────────────────────────────
 */
final class SettingGroups
{
    /**
     * Group => the lang key for its title, in the order they are shown.
     *
     * A group with no entry here still renders — Settings sorts unknown groups
     * to the end rather than hiding them, because a setting nobody can find is
     * worse than one in the wrong section.
     *
     * @var array<string, string>
     */
    private const GROUPS = [
        'identity' => 'identity',
        'navigation' => 'navigation',
        'hero' => 'hero',
        'home' => 'home',
        'work' => 'work',
        'project' => 'project',
        'archive' => 'archive',
        'journal' => 'journal',
        'services' => 'services',
        'about' => 'about',
        'contact' => 'contact',
        'contact_form' => 'contact_form',
        'footer' => 'footer',
        'shared' => 'shared',
        'errors' => 'errors',
        'attribution' => 'attribution',
        'seo' => 'seo',
    ];

    /** @return list<string> the group names, in display order */
    public static function order(): array
    {
        return array_keys(self::GROUPS);
    }

    /** The section heading an owner reads. */
    public static function title(string $group): string
    {
        $key = self::GROUPS[$group] ?? null;

        if ($key === null) {
            return str($group)->headline()->toString();
        }

        return __("studio.settings.group.{$key}");
    }

    /**
     * One sentence saying where on the site this group appears.
     *
     * Optional by design: a group whose title already says it ("Error pages")
     * does not need a sentence, and a description that only restates the
     * heading trains people to stop reading descriptions.
     */
    public static function description(string $group): ?string
    {
        $key = self::GROUPS[$group] ?? null;

        if ($key === null) {
            return null;
        }

        $lang = "studio.settings.group_hint.{$key}";
        $text = __($lang);

        return $text === $lang ? null : $text;
    }

    /**
     * Sort a collection of groups into display order, unknown ones last.
     *
     * @param  iterable<string>  $groups
     * @return list<string>
     */
    public static function sorted(iterable $groups): array
    {
        $order = self::order();
        $seen = [];

        foreach ($groups as $group) {
            $seen[$group] = true;
        }

        $sorted = [];

        foreach ($order as $group) {
            if (isset($seen[$group])) {
                $sorted[] = $group;
                unset($seen[$group]);
            }
        }

        // Anything this class has not been told about, alphabetically, last.
        $rest = array_keys($seen);
        sort($rest);

        return [...$sorted, ...$rest];
    }

    /** Which group should be open when the page loads. */
    public static function default(): string
    {
        return 'identity';
    }
}
