<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * THE ONE WRITER OF THE ACTIVITY LOG.
 *
 * Everything that records anything goes through here, so there is one place
 * that decides what an entry looks like and one place to look when an entry
 * is wrong.
 *
 * IT NEVER THROWS. A failure to write the audit trail must not fail the thing
 * being audited: an editor publishing a project should not see an error
 * because the log table is full. The entry is lost and the publish succeeds,
 * which is the right way round — the alternative is a studio unable to work
 * because of its own bookkeeping.
 * ═══════════════════════════════════════════════════════════════════════════
 */
final class Activity
{
    /** Writing is suspended while this is true — see withoutLogging(). */
    private static bool $suspended = false;

    /**
     * @param  array<string, mixed>  $properties
     */
    public static function record(
        string $event,
        ?Model $subject = null,
        array $properties = [],
        ?string $label = null,
    ): void {
        if (self::$suspended) {
            return;
        }

        try {
            $user = auth()->user();

            ActivityLog::query()->create([
                'user_id' => $user?->getAuthIdentifier(),
                'actor_name' => $user?->name,
                'event' => $event,
                'subject_type' => $subject ? $subject::class : null,
                'subject_id' => $subject?->getKey(),
                'subject_label' => $label ?? self::labelFor($subject),
                'properties' => $properties === [] ? null : $properties,
                'created_at' => now(),
            ]);
        } catch (\Throwable) {
            /*
             * Deliberately swallowed. See the class comment: the audit trail
             * is not allowed to break the work it is auditing. A log table
             * that cannot be written to is an operational problem, and the
             * place that surfaces it is monitoring, not an editor's screen.
             */
        }
    }

    /**
     * Run something without recording it.
     *
     * For seeders and migrations, which move thousands of rows and would
     * otherwise bury the handful of entries a human reading this screen is
     * looking for.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withoutLogging(callable $callback): mixed
    {
        self::$suspended = true;

        try {
            return $callback();
        } finally {
            self::$suspended = false;
        }
    }

    /**
     * What the thing was called, in the DEFAULT locale.
     *
     * Not the active one: an entry written while an editor happened to be
     * working in Arabic would otherwise read in Arabic for ever, including
     * for the English reader looking at it next year. The log is a record,
     * not a page.
     */
    private static function labelFor(?Model $subject): ?string
    {
        if ($subject === null) {
            return null;
        }

        foreach (['title', 'name', 'label', 'original_filename', 'from_path', 'email', 'key'] as $attribute) {
            if (! isset($subject->{$attribute})) {
                continue;
            }

            $value = method_exists($subject, 'getTranslation')
                && method_exists($subject, 'isTranslatableAttribute')
                && $subject->isTranslatableAttribute($attribute)
                    ? $subject->getTranslation($attribute, Locale::DEFAULT, fallback: true)
                    : $subject->{$attribute};

            if (is_string($value) && $value !== '') {
                return mb_substr($value, 0, 200);
            }
        }

        return null;
    }
}
