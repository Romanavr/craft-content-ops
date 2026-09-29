<?php

namespace romanavr\contentops\helpers;

use craft\helpers\Html;

/**
 * Renders compact before/after snippets for previews: the changed middle part is highlighted,
 * with a little unchanged context on either side.
 *
 * @author Romanavr
 * @since 1.0.0
 */
abstract class Diff
{
    // Const Properties
    // =========================================================================

    /**
     * @var int Characters of unchanged context kept around a change.
     */
    public const CONTEXT = 40;

    // Public Methods
    // =========================================================================

    /**
     * Returns `[beforeHtml, afterHtml]`.
     *
     * @param mixed $old
     * @param mixed $new
     * @return array{0: string, 1: string}
     */
    public static function inline(mixed $old, mixed $new): array
    {
        $oldChars = mb_str_split(self::toText($old));
        $newChars = mb_str_split(self::toText($new));
        $max = min(count($oldChars), count($newChars));

        $prefix = 0;
        while ($prefix < $max && $oldChars[$prefix] === $newChars[$prefix]) {
            $prefix++;
        }

        $suffix = 0;
        while (
            $suffix < $max - $prefix &&
            $oldChars[count($oldChars) - 1 - $suffix] === $newChars[count($newChars) - 1 - $suffix]
        ) {
            $suffix++;
        }

        return [
            self::_render($oldChars, $prefix, $suffix, 'del'),
            self::_render($newChars, $prefix, $suffix, 'ins'),
        ];
    }

    /**
     * Converts a serialized value to plain text for display.
     *
     * @param mixed $value
     * @return string
     */
    public static function toText(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'On' : 'Off',
            is_scalar($value) => (string)$value,
            default => (string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        };
    }

    // Private Methods
    // =========================================================================

    /**
     * @param string[] $chars
     * @param int $prefix
     * @param int $suffix
     * @param string $tag
     * @return string
     */
    private static function _render(array $chars, int $prefix, int $suffix, string $tag): string
    {
        $length = count($chars);
        $changed = implode('', array_slice($chars, $prefix, max(0, $length - $prefix - $suffix)));
        $before = implode('', array_slice($chars, 0, $prefix));
        $after = implode('', array_slice($chars, $length - $suffix));

        if (mb_strlen($before) > self::CONTEXT) {
            $before = '…' . mb_substr($before, -self::CONTEXT);
        }

        if (mb_strlen($after) > self::CONTEXT) {
            $after = mb_substr($after, 0, self::CONTEXT) . '…';
        }

        if (mb_strlen($changed) > 300) {
            $changed = mb_substr($changed, 0, 150) . ' … ' . mb_substr($changed, -150);
        }

        $middle = $changed === '' ? '' : Html::tag($tag, Html::encode($changed));

        return Html::encode($before) . $middle . Html::encode($after);
    }
}
