<?php

namespace romanavr\contentops\helpers;

/**
 * Encodes serialized field/attribute values for storage and comparison.
 *
 * Encoding is canonical (associative keys sorted recursively), so two values are equal
 * exactly when their encodings are identical strings.
 *
 * @author Romanavr
 * @since 1.0.0
 */
abstract class Values
{
    // Public Methods
    // =========================================================================

    /**
     * Encodes a serialized value to a canonical JSON string.
     *
     * @param mixed $value
     * @return string
     * @throws \JsonException if the value can't be encoded
     */
    public static function encode(mixed $value): string
    {
        return json_encode(
            self::_canonicalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * Decodes a string produced by {@see encode()}.
     *
     * @param string|null $encoded
     * @return mixed
     * @throws \JsonException if the string isn't valid JSON
     */
    public static function decode(?string $encoded): mixed
    {
        if ($encoded === null) {
            return null;
        }

        return json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Returns whether two serialized values are equal.
     *
     * @param mixed $a
     * @param mixed $b
     * @return bool
     * @throws \JsonException
     */
    public static function equal(mixed $a, mixed $b): bool
    {
        return self::encode($a) === self::encode($b);
    }

    // Private Methods
    // =========================================================================

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function _canonicalize(mixed $value): mixed
    {
        if ($value instanceof \JsonSerializable) {
            $value = $value->jsonSerialize();
        }

        if (!is_array($value)) {
            return $value;
        }

        $value = array_map(fn($item) => self::_canonicalize($item), $value);

        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }
}
