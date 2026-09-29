<?php

namespace romanavr\contentops\operators;

use craft\base\ElementInterface;
use craft\fields\Lightswitch;
use romanavr\contentops\models\Operation;
use romanavr\contentops\models\Target;
use yii\base\InvalidArgumentException;

/**
 * Lightswitch operator: Lightswitch fields and the `enabled`/`enabledForSite` attributes.
 *
 * @author Romanavr
 * @since 1.0.0
 */
class LightswitchOperator extends BaseOperator
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function handle(): string
    {
        return 'lightswitch';
    }

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return 'Lightswitch';
    }

    /**
     * @inheritdoc
     */
    public function getOperations(): array
    {
        return [
            'on' => 'Turn on',
            'off' => 'Turn off',
            'toggle' => 'Toggle',
        ];
    }

    /**
     * @inheritdoc
     */
    public function supports(Target $target): bool
    {
        return $target->field instanceof Lightswitch || in_array($target->attribute, ['enabled', 'enabledForSite'], true);
    }

    /**
     * @inheritdoc
     */
    public function apply(mixed $value, Operation $operation, ?ElementInterface $element = null): mixed
    {
        $current = (bool)$value;

        $new = match ($operation->operation) {
            'on' => true,
            'off' => false,
            'toggle' => !$current,
            default => throw new InvalidArgumentException("Unknown operation \"$operation->operation\"."),
        };

        return $new === $current ? $value : $new;
    }
}
