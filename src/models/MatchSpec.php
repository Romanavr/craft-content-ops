<?php

namespace romanavr\contentops\models;

use craft\base\Model;

/**
 * What Find & Replace looks for and how it replaces it.
 *
 * @author Romanavr
 * @since 1.0.0
 */
class MatchSpec extends Model
{
    // Const Properties
    // =========================================================================

    /** Only text between HTML tags is searched (tags and attributes are left alone). */
    public const HTML_TEXT = 'text';

    /** Text between tags, plus the values of `href` and `src` attributes (for domain moves). */
    public const HTML_TEXT_AND_LINKS = 'textAndLinks';

    // Public Properties
    // =========================================================================

    /**
     * @var string Text (or a regular expression without delimiters, if {@see $regex}) to find.
     */
    public string $find = '';

    /**
     * @var string Replacement. With {@see $regex}, `$1`… refer to capture groups.
     */
    public string $replace = '';

    /**
     * @var bool
     */
    public bool $regex = false;

    /**
     * @var bool
     */
    public bool $caseSensitive = true;

    /**
     * @var bool Only match whole words.
     */
    public bool $wholeWord = false;

    /**
     * @var string How HTML values (CKEditor) are searched: {@see HTML_TEXT} or {@see HTML_TEXT_AND_LINKS}.
     */
    public string $html = self::HTML_TEXT;

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['find'], 'required'];
        $rules[] = [['html'], 'in', 'range' => [self::HTML_TEXT, self::HTML_TEXT_AND_LINKS]];

        return $rules;
    }
}
