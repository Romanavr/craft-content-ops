<?php

namespace romanavr\contentops\helpers;

use romanavr\contentops\models\MatchSpec;
use yii\base\InvalidArgumentException;

/**
 * Finds and replaces text in plain and HTML values.
 *
 * Matches are numbered in document order, so a preview can list them and the user can exclude individual ones:
 * {@see replace()} skips the given match indexes. In HTML mode, tags are never touched except, optionally,
 * the values of `href` and `src` attributes.
 *
 * @author Romanavr
 * @since 1.0.0
 */
abstract class Matcher
{
    // Const Properties
    // =========================================================================

    /**
     * @var int Backtracking limit for user regexes, so a catastrophic pattern fails fast instead of hanging a request.
     */
    public const BACKTRACK_LIMIT = 100000;

    /**
     * @var int Characters of context on each side of a match in {@see findAll()}.
     */
    public const CONTEXT = 40;

    // Public Methods
    // =========================================================================

    /**
     * Validates a spec, throwing a friendly error for invalid or unsafe patterns.
     *
     * @param MatchSpec $spec
     * @throws InvalidArgumentException
     */
    public static function validate(MatchSpec $spec): void
    {
        if ($spec->find === '') {
            throw new InvalidArgumentException('Enter something to find.');
        }

        self::_run(fn() => preg_match(self::pattern($spec), ''));
    }

    /**
     * Returns the PCRE pattern for a spec.
     *
     * @param MatchSpec $spec
     * @return string
     */
    public static function pattern(MatchSpec $spec): string
    {
        $body = $spec->regex ? str_replace('~', '\~', $spec->find) : preg_quote($spec->find, '~');

        if ($spec->wholeWord) {
            $body = '(?<![\p{L}\p{N}_])(?:' . $body . ')(?![\p{L}\p{N}_])';
        }

        return '~' . $body . '~u' . ($spec->caseSensitive ? '' : 'i');
    }

    /**
     * Lists matches with context: `[['index', 'match', 'replacement', 'before', 'after'], …]`.
     *
     * @param string $text
     * @param MatchSpec $spec
     * @param bool $isHtml
     * @return array<int, array<string, mixed>>
     * @throws InvalidArgumentException if the pattern fails
     */
    public static function findAll(string $text, MatchSpec $spec, bool $isHtml = false): array
    {
        $matches = [];
        $index = 0;

        self::_eachSegment($text, $spec, $isHtml, function(string $segment, int $segmentOffset) use ($text, $spec, $isHtml, &$matches, &$index) {
            $m = [];
            $found = self::_run(function() use ($spec, $segment, &$m) {
                return preg_match_all(self::pattern($spec), $segment, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            });

            if (!$found) {
                return $segment;
            }

            foreach ($m as $match) {
                [$matched, $offset] = $match[0];
                $absolute = $segmentOffset + $offset;
                $matches[] = [
                    'index' => $index++,
                    'match' => $matched,
                    'replacement' => self::_replacementFor($match, $spec),
                    'before' => self::_context(substr($text, 0, $absolute), $isHtml, true),
                    'after' => self::_context(substr($text, $absolute + strlen($matched)), $isHtml, false),
                ];
            }

            return $segment;
        });

        return $matches;
    }

    /**
     * Replaces all matches except the skipped match indexes. Returns `[newText, replacedCount]`.
     *
     * @param string $text
     * @param MatchSpec $spec
     * @param bool $isHtml
     * @param int[] $skip Match indexes (from {@see findAll()}) to leave as they are
     * @return array{0: string, 1: int}
     * @throws InvalidArgumentException if the pattern fails
     */
    public static function replace(string $text, MatchSpec $spec, bool $isHtml = false, array $skip = []): array
    {
        $index = 0;
        $replaced = 0;
        $skip = array_flip($skip);

        $result = self::_eachSegment($text, $spec, $isHtml, function(string $segment) use ($spec, &$index, &$replaced, $skip) {
            // Regular closures (not arrow functions) so the counters are shared by reference.
            return self::_run(function() use ($spec, $segment, &$index, &$replaced, $skip) {
                return preg_replace_callback(self::pattern($spec), function(array $match) use ($spec, &$index, &$replaced, $skip) {
                    if (isset($skip[$index++])) {
                        return $match[0];
                    }

                    $replaced++;
                    return self::_replacementFor(array_map(fn($group) => [$group, 0], $match), $spec);
                }, $segment);
            });
        });

        return [$result, $replaced];
    }

    // Private Methods
    // =========================================================================

    /**
     * Calls `$callback($segment, $offset)` for each searchable part of the text and reassembles the result.
     *
     * @param string $text
     * @param MatchSpec $spec
     * @param bool $isHtml
     * @param callable $callback Returns the (possibly replaced) segment
     * @return string
     */
    private static function _eachSegment(string $text, MatchSpec $spec, bool $isHtml, callable $callback): string
    {
        if (!$isHtml) {
            return $callback($text, 0);
        }

        $parts = preg_split('/(<[^>]*>)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_OFFSET_CAPTURE) ?: [];
        $result = '';

        foreach ($parts as [$part, $offset]) {
            if ($part === '') {
                continue;
            }

            if ($part[0] !== '<') {
                $result .= $callback($part, $offset);
                continue;
            }

            if ($spec->html !== MatchSpec::HTML_TEXT_AND_LINKS) {
                $result .= $part;
                continue;
            }

            // Search only inside href="…" / src="…" values of the tag.
            $result .= preg_replace_callback(
                '/(\s(?:href|src)\s*=\s*)(["\'])(.*?)\2/i',
                function(array $attr) use ($callback, $offset) {
                    [$full, $prefix, $quote, $value] = [$attr[0][0], $attr[1][0], $attr[2][0], $attr[3][0]];
                    $valueOffset = $offset + $attr[3][1];

                    return $prefix . $quote . $callback($value, $valueOffset) . $quote;
                },
                $part,
                flags: PREG_OFFSET_CAPTURE,
            );
        }

        return $result;
    }

    /**
     * @param array<int, array{0: string, 1: int}> $match Match groups with offsets
     * @param MatchSpec $spec
     * @return string
     */
    private static function _replacementFor(array $match, MatchSpec $spec): string
    {
        if (!$spec->regex) {
            return $spec->replace;
        }

        // Support $1 / ${1} / \1 references to capture groups.
        return preg_replace_callback('/\$\{(\d+)\}|\$(\d+)|\\\\(\d+)/', function(array $ref) use ($match) {
            $group = (int)($ref[1] !== '' ? $ref[1] : ($ref[2] !== '' ? $ref[2] : $ref[3]));
            return $match[$group][0] ?? '';
        }, $spec->replace);
    }

    /**
     * @param string $text
     * @param bool $isHtml
     * @param bool $leading Take the end of the text (context before a match) or its start (after)
     * @return string
     */
    private static function _context(string $text, bool $isHtml, bool $leading): string
    {
        if ($isHtml) {
            $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        if (mb_strlen($text) <= self::CONTEXT) {
            return $text;
        }

        return $leading ? '…' . mb_substr($text, -self::CONTEXT) : mb_substr($text, 0, self::CONTEXT) . '…';
    }

    /**
     * Runs a PCRE call with a backtracking limit and turns PCRE failures into friendly errors.
     *
     * @param callable $callback
     * @return mixed
     * @throws InvalidArgumentException
     */
    private static function _run(callable $callback): mixed
    {
        $limit = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', (string)self::BACKTRACK_LIMIT);
        set_error_handler(fn() => true);

        try {
            $result = $callback();
        } finally {
            restore_error_handler();
            ini_set('pcre.backtrack_limit', (string)$limit);
        }

        if ($result === null || $result === false || preg_last_error() !== PREG_NO_ERROR) {
            $error = preg_last_error();
            throw new InvalidArgumentException(match ($error) {
                PREG_BACKTRACK_LIMIT_ERROR, PREG_RECURSION_LIMIT_ERROR, PREG_JIT_STACKLIMIT_ERROR => 'The pattern is too complex to run safely. Try a simpler or more specific pattern.',
                PREG_BAD_UTF8_ERROR, PREG_BAD_UTF8_OFFSET_ERROR => 'The text contains invalid UTF-8.',
                default => 'The regular expression is invalid.',
            });
        }

        return $result;
    }
}
