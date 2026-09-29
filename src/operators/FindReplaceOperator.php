<?php

namespace romanavr\contentops\operators;

use craft\base\ElementInterface;
use craft\ckeditor\Field as CkeditorField;
use craft\fields\PlainText;
use romanavr\contentops\ContentOps;
use romanavr\contentops\helpers\Matcher;
use romanavr\contentops\models\MatchSpec;
use romanavr\contentops\models\Operation;
use romanavr\contentops\models\Target;
use yii\base\InvalidArgumentException;

/**
 * Find & Replace operator: applies a {@see MatchSpec} to text and HTML values.
 * Options are the spec's attributes, plus `skip` (match indexes to leave alone, set by per-match exclusion).
 *
 * @author Romanavr
 * @since 1.0.0
 */
class FindReplaceOperator extends BaseOperator
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function handle(): string
    {
        return 'findReplace';
    }

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return 'Find & Replace';
    }

    /**
     * Returns whether a target holds HTML (so tags must be protected).
     *
     * @param Target $target
     * @return bool
     */
    public static function isHtml(Target $target): bool
    {
        return $target->field instanceof CkeditorField;
    }

    /**
     * Builds the match spec from operation options.
     *
     * @param array<string, mixed> $options
     * @return MatchSpec
     */
    public static function spec(array $options): MatchSpec
    {
        return new MatchSpec(array_intersect_key($options, array_flip(['find', 'replace', 'regex', 'caseSensitive', 'wholeWord', 'html'])));
    }

    /**
     * @inheritdoc
     */
    public function getOperations(): array
    {
        return ['replace' => 'Find and replace'];
    }

    /**
     * @inheritdoc
     */
    public function getInputs(string $operation, Target $target): array
    {
        return [];
    }

    /**
     * @inheritdoc
     */
    public function supports(Target $target): bool
    {
        return $target->attribute === 'title' || $target->field instanceof PlainText || $target->field instanceof CkeditorField;
    }

    /**
     * @inheritdoc
     */
    public function validateOperation(Operation $operation): void
    {
        parent::validateOperation($operation);

        $spec = self::spec($operation->options);

        if (!$spec->validate()) {
            throw new InvalidArgumentException(implode(' ', $spec->getFirstErrors()));
        }

        Matcher::validate($spec);
    }

    /**
     * @inheritdoc
     */
    public function apply(mixed $value, Operation $operation, ?ElementInterface $element = null): mixed
    {
        if (!is_string($value) || $value === '') {
            return $value;
        }

        $target = $element ? ContentOps::getInstance()->getTargets()->resolve($element, $operation->target) : null;
        [$new, $count] = Matcher::replace($value, self::spec($operation->options), $target && self::isHtml($target), (array)($operation->options['skip'] ?? []));

        return $count === 0 ? $value : $new;
    }
}
