<?php

namespace romanavr\contentops\operators;

use Craft;
use craft\base\ElementInterface;
use craft\fields\PlainText;
use craft\helpers\ElementHelper;
use romanavr\contentops\models\Operation;
use romanavr\contentops\models\Target;
use yii\base\InvalidArgumentException;

/**
 * Text operator: Plain Text and CKEditor fields, plus the `title` attribute.
 *
 * Note: `replace` on CKEditor values matches the raw HTML. HTML-aware matching belongs to Find & Replace (M4).
 *
 * @author Romanavr
 * @since 1.0.0
 */
class TextOperator extends BaseOperator
{
    // Const Properties
    // =========================================================================

    /**
     * @var string[] Native attributes this operator can edit.
     */
    public const ATTRIBUTES = ['title', 'slug'];

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function handle(): string
    {
        return 'text';
    }

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return 'Text';
    }

    /**
     * @inheritdoc
     */
    public function getOperations(): array
    {
        return [
            'set' => 'Set to',
            'clear' => 'Clear',
            'prepend' => 'Prepend',
            'append' => 'Append',
            'replace' => 'Find and replace',
            'pattern' => 'Set from pattern',
        ];
    }

    /**
     * @inheritdoc
     */
    public function getInputs(string $operation, Target $target): array
    {
        $valueType = $target->field instanceof \craft\ckeditor\Field ? 'textarea' : 'text';

        return match ($operation) {
            'set', 'prepend', 'append' => [['name' => 'value', 'type' => $valueType, 'label' => 'Value']],
            'replace' => [
                ['name' => 'find', 'type' => 'text', 'label' => 'Find'],
                ['name' => 'replace', 'type' => 'text', 'label' => 'Replace with'],
                ['name' => 'caseSensitive', 'type' => 'lightswitch', 'label' => 'Case-sensitive', 'default' => true],
            ],
            'pattern' => [['name' => 'pattern', 'type' => 'text', 'label' => 'Pattern', 'placeholder' => $target->attribute === 'slug' ? '{title}' : '{title} – {section.name}']],
            default => [],
        };
    }

    /**
     * @inheritdoc
     */
    public function supports(Target $target): bool
    {
        if ($target->attribute !== null) {
            return in_array($target->attribute, self::ATTRIBUTES, true);
        }

        return $target->field instanceof PlainText || $target->field instanceof \craft\ckeditor\Field;
    }

    /**
     * @inheritdoc
     */
    public function validateOperation(Operation $operation): void
    {
        parent::validateOperation($operation);

        match ($operation->operation) {
            'set', 'prepend', 'append' => $this->_requireString($operation, 'value'),
            'replace' => $this->_validateReplace($operation),
            'pattern' => $this->_requireString($operation, 'pattern'),
            default => null,
        };
    }

    /**
     * @inheritdoc
     */
    public function apply(mixed $value, Operation $operation, ?ElementInterface $element = null): mixed
    {
        $current = $value === null ? '' : (string)$value;
        $options = $operation->options;

        $new = match ($operation->operation) {
            'set' => (string)$options['value'],
            'clear' => '',
            'prepend' => $options['value'] . $current,
            'append' => $current . $options['value'],
            'replace' => ($options['caseSensitive'] ?? true)
                ? str_replace((string)$options['find'], (string)$options['replace'], $current)
                : str_ireplace((string)$options['find'], (string)$options['replace'], $current),
            'pattern' => $this->_renderPattern((string)$options['pattern'], $operation, $element),
            default => throw new InvalidArgumentException("Unknown operation \"$operation->operation\"."),
        };

        // Return the original value untouched when nothing changed, so `null` vs `''` never shows up as a change.
        if ($new === $current) {
            return $value;
        }

        return $new === '' ? null : $new;
    }

    // Private Methods
    // =========================================================================

    /**
     * Renders an object template like `{title}-{postDate|date('Y')}` for the element.
     *
     * @param string $pattern
     * @param Operation $operation
     * @param ElementInterface|null $element
     * @return string
     * @throws InvalidArgumentException if there's no element to render against
     */
    private function _renderPattern(string $pattern, Operation $operation, ?ElementInterface $element): string
    {
        if ($element === null) {
            throw new InvalidArgumentException('The "pattern" operation needs an element.');
        }

        $value = Craft::$app->getView()->renderObjectTemplate($pattern, $element);

        return $operation->target === 'slug' ? ElementHelper::normalizeSlug($value) : trim($value);
    }

    /**
     * @param Operation $operation
     * @param string $name
     * @throws InvalidArgumentException
     */
    private function _requireString(Operation $operation, string $name): void
    {
        $value = $this->requireOption($operation, $name);

        if (!is_scalar($value)) {
            throw new InvalidArgumentException(sprintf('The "%s" option must be a string.', $name));
        }
    }

    /**
     * @param Operation $operation
     * @throws InvalidArgumentException
     */
    private function _validateReplace(Operation $operation): void
    {
        $this->_requireString($operation, 'find');
        $this->_requireString($operation, 'replace');

        if ((string)$operation->options['find'] === '') {
            throw new InvalidArgumentException('The "find" option can’t be empty.');
        }
    }
}
