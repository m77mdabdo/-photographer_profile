<?php

declare(strict_types=1);

namespace Tests\Feature\Studio;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * ───────────────────────────────────────────────────────────────────────────
 * TASK 36b — THE THREE STORY-BUILDER CONTROLS THE HARNESS HAD NEVER
 * MEASURED.
 *
 * The browser harness measures every control on every panel screen against a
 * 44px minimum, and its story-builder row had passed since the row was
 * written. It was passing on an EMPTY builder: the target opens whichever
 * project the panel lists first, and that was a project with no blocks, so
 * there were no block cards, no drag handles and no outline entries to
 * measure. This task's seeding bumped updated_at on the demo records, a
 * project WITH eighteen blocks became first, and twelve controls appeared at
 * once:
 *
 *     1. Full-width image   21px   the JS-built outline entries
 *     ⠿                     40px   the drag handle
 *     ▾ Full-width          24px   the collapse toggle
 *
 * Attributed before being fixed: the identical failure appears with every
 * one of this task's code changes stashed.
 *
 * The harness is still the thing that measures them — only a browser can lay
 * out a JS-built list. This asserts the RULES exist, so a stylesheet rewrite
 * that drops them fails in the suite rather than waiting for the one harness
 * target whose fixture has to be right for it to be seen.
 * ───────────────────────────────────────────────────────────────────────────
 */
class StoryBuilderControlsTest extends TestCase
{
    private function stylesheet(): string
    {
        $path = resource_path('css/filament/studio/components.css');

        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /** @return array<string, array{0: string}> */
    public static function controls(): array
    {
        return [
            'the drag handle' => ['.project-row__handle'],
            'the outline entry' => ['.outline__item'],
            'the collapse toggle' => ['.block-card__toggle'],
        ];
    }

    #[DataProvider('controls')]
    public function test_each_control_declares_the_minimum_touch_target(string $selector): void
    {
        $css = $this->stylesheet();

        $position = strpos($css, $selector.' {');

        $this->assertNotFalse($position, "{$selector} has no rule of its own in the panel stylesheet");

        // The declaration block that follows the selector.
        $block = substr($css, $position, (int) strpos($css, '}', $position) - $position);

        $this->assertStringContainsString(
            'min-height: 44px',
            $block,
            "{$selector} does not declare a 44px minimum height:\n".$block
        );
    }

    /**
     * The outline is built in JavaScript, so the class the stylesheet styles
     * and the class the script sets have to be the same string. They were not
     * checked against each other before, and the outline had no rule at all.
     */
    public function test_the_outline_class_matches_the_one_the_script_sets(): void
    {
        $script = (string) file_get_contents(resource_path('js/studio/story-builder.js'));

        $this->assertStringContainsString(
            "className = 'outline__item'",
            $script,
            'the story outline no longer sets outline__item, so the stylesheet rule above styles nothing'
        );
    }

    /** The handle is dragged, so it needs width as well as height. */
    public function test_the_drag_handle_is_square_enough_to_hit(): void
    {
        $css = $this->stylesheet();

        $position = (int) strpos($css, '.project-row__handle {');
        $block = substr($css, $position, (int) strpos($css, '}', $position) - $position);

        $this->assertStringContainsString('min-width: 44px', $block);
        $this->assertStringContainsString('touch-action: none', $block);
    }
}
