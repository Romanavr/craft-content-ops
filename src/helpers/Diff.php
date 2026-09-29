<?php

namespace romanavr\contentops\helpers;

use Craft;
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
     * Returns `[beforeHtml, afterHtml]` for lists of nested entries (Matrix values): one line per block with its
     * type and a short excerpt; removed blocks are struck through, added ones highlighted.
     *
     * @param array<int, array<string, mixed>> $old
     * @param array<int, array<string, mixed>> $new
     * @return array{0: string, 1: string}
     */
    public static function blocks(array $old, array $new): array
    {
        $oldIds = array_column($old, 'id');
        $newIds = array_column($new, 'id');

        $render = function(array $blocks, array $otherIds, string $tag): string {
            $lines = array_map(function(array $block) use ($otherIds, $tag) {
                $line = Html::encode(self::blockLabel($block));
                $changed = ($block['id'] ?? null) === null || !in_array($block['id'], $otherIds, true);

                return $changed ? Html::tag($tag, $line) : Html::tag('span', $line, ['class' => 'light']);
            }, $blocks);

            return $lines ? implode('<br>', $lines) : Html::tag('span', '—', ['class' => 'light']);
        };

        return [$render($old, $newIds, 'del'), $render($new, $oldIds, 'ins')];
    }

    /**
     * Returns whether a value is a list of nested entry blocks.
     *
     * @param mixed $value
     * @return bool
     */
    public static function isBlockList(mixed $value): bool
    {
        return is_array($value) && array_is_list($value) && ($value === [] || (is_array($value[0]) && array_key_exists('type', $value[0]) && array_key_exists('fields', $value[0])));
    }

    /**
     * Returns “Type: excerpt” for a nested entry block.
     *
     * @param array<string, mixed> $block
     * @return string
     */
    public static function blockLabel(array $block): string
    {
        $type = Craft::$app->getEntries()->getEntryTypeByHandle((string)($block['type'] ?? ''))->name ?? (string)($block['type'] ?? '?');
        $text = trim((string)($block['title'] ?? ''));

        if ($text === '') {
            array_walk_recursive($block['fields'], function($value) use (&$text) {
                if ($text === '' && is_string($value) && trim(strip_tags($value)) !== '') {
                    $text = trim(strip_tags($value));
                }
            });
        }

        $text = preg_replace('/\s+/', ' ', $text);

        return $text === '' ? $type : sprintf('%s: %s', $type, mb_strlen($text) > 50 ? mb_substr($text, 0, 49) . '…' : $text);
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
