<?php

namespace benf\neo\validators;

use benf\neo\elements\Block;
use Craft;
use yii\validators\Validator;

/**
 * Class FieldValidator
 *
 * @package benf\neo\validators
 * @author Spicy Web <plugins@spicyweb.com.au>
 * @since 2.3.0
 */
class FieldValidator extends Validator
{
    /**
     * @var int|null The minimum top-level blocks the field can have.  If not set, there is no top-level block minimum.
     */
    public ?int $minTopBlocks = null;

    /**
     * @var int|null The maximum top-level blocks the field can have.  If not set, there is no top-level block maximum.
     */
    public ?int $maxTopBlocks = null;

    /**
     * @var int|null The minimum level blocks can be nested in the field.  If not set, there is no limit.
     * @since 3.3.0
     */
    public ?int $minLevels = null;

    /**
     * @var int|null The maximum level blocks can be nested in the field.  If not set, there is no limit.
     * @since 2.9.0
     */
    public ?int $maxLevels = null;

    /**
     * @var string|null A user-defined error message to be used if the field's `minTopBlocks` is exceeded.
     * @since 3.3.0
     */
    public ?string $tooFewTopBlocks = null;

    /**
     * @var string|null A user-defined error message to be used if the field's `maxTopBlocks` is exceeded.
     */
    public ?string $tooManyTopBlocks = null;

    /**
     * @var string|null A user-defined error message to be used if the field's `minLevels` is exceeded.
     * @since 3.3.0
     */
    public ?string $exceedsMinLevels = null;

    /**
     * @var string|null A user-defined error message to be used if the field's `maxLevels` is exceeded.
     * @since 2.9.0
     */
    public ?string $exceedsMaxLevels = null;

    /**
     * @var string|null A user-defined error message to be used if a block type's `minBlocks` setting is violated.
     * @since 3.3.0
     */
    public ?string $tooFewBlocksOfType = null;

    /**
     * @var string|null A user-defined error message to be used if a block type's `maxBlocks` is exceeded.
     * @since 3.0.0
     */
    public ?string $tooManyBlocksOfType = null;

    /**
     * @var string|null A user-defined error message to be used if a block type's `minSiblingBlocks` setting is violated.
     * @since 3.3.0
     */
    public ?string $tooFewSiblingBlocks = null;

    /**
     * @var Block[]
     */
    private array $_blocks = [];

    /**
     * @var Block[]
     */
    private array $_enabledBlocks = [];

    /**
     * @inheritdoc
     */
    public function validateAttribute($model, $attribute): void
    {
        $this->_setDefaultErrorMessages();

        $field = $model->getFieldLayout()->getFieldByHandle($attribute);
        $value = $model->$attribute;
        $this->_blocks = $value->status(null)->all();
        $this->_enabledBlocks = array_filter($this->_blocks, fn($block) => $block->enabled);

        $this->_checkTopLevelBlocks($model, $attribute);
        $this->_checkMinLevels($model, $attribute);
        $this->_checkMaxLevels($model, $attribute);

        // Check for block type block constraints
        // TODO: validate max sibling blocks by type in Neo 4, arguably a breaking change before then
        $blockTypesCount = [];
        $blockTypesById = [];
        $blockTypesByHandle = [];
        $childBlockTypes = [];
        $topLevelBlockTypeHandles = [];
        $blockSiblingCount = [];
        $blockAncestors = [null];
        $lastBlock = null;
        $blockTypes = $field->getBlockTypes();
        $newCounter = 0;

        foreach ($blockTypes as $blockType) {
            $blockTypesCount[$blockType->id] = 0;
            $blockTypesById[$blockType->id] = $blockType;
            $blockTypesByHandle[$blockType->handle] = $blockType;
            $childBlockTypes[$blockType->id] = $blockType->childBlocks ?? [];

            if ($blockType->topLevel) {
                $topLevelBlockTypeHandles[] = $blockType->handle;
            }
        }

        // Now that it's safe to use array_keys($blockTypesByHandle)
        foreach ($blockTypes as $blockType) {
            if ($childBlockTypes[$blockType->id] === '*') {
                $childBlockTypes[$blockType->id] = array_keys($blockTypesByHandle);
            }
        }

        foreach ($this->_blocks as $block) {
            if ($block->enabled) {
                $blockTypesCount[$block->typeId] += 1;
            }

            // Make sure we have the correct ancestors for the current block
            if ($lastBlock !== null) {
                if ($lastBlock->level < $block->level) {
                    $blockAncestors[] = $lastBlock;
                } elseif ($lastBlock->level > $block->level) {
                    array_splice($blockAncestors, $block->level - $lastBlock->level);
                }
            }

            $parent = end($blockAncestors);
            $parentId = $parent !== null
                ? ($parent->id ?? 'new' . $newCounter++) // Maybe an unsaved block
                : 0; // Maybe a top level block
            $atTopLevel = $parent === null;

            // Create the sibling count for this parent block if this block is the first of its children
            if (!isset($blockSiblingCount[$parentId])) {
                $blockTypesToMap = $atTopLevel ? $topLevelBlockTypeHandles : $childBlockTypes[$parent->typeId];
                $blockSiblingCount[$parentId] = array_fill_keys(
                    array_filter(array_map(
                        fn($handle) => isset($blockTypesByHandle[$handle]) ? $blockTypesByHandle[$handle]->id : null,
                        $blockTypesToMap ?? []
                    )),
                    0
                );
            }

            $lastBlock = $block;

            if (!isset($blockSiblingCount[$parentId][$block->typeId])) {
                // This block is in a place in the field where it isn't supposed to exist any more, so ignore it for the
                // purposes of min sibling blocks validation
                continue;
            }

            if ($block->enabled) {
                $blockSiblingCount[$parentId][$block->typeId] += 1;
            }
        }

        foreach ($blockTypesCount as $blockTypeId => $blockTypeCount) {
            $blockType = $blockTypesById[$blockTypeId];

            if ($blockType->minBlocks > 0 && $blockTypeCount < $blockType->minBlocks) {
                $this->addError(
                    $model,
                    $attribute,
                    $this->tooFewBlocksOfType,
                    [
                        'minBlockTypeBlocks' => $blockType->minBlocks,
                        'blockType' => $blockType->name,
                    ]
                );
            }

            if ($blockType->maxBlocks > 0 && $blockTypeCount > $blockType->maxBlocks) {
                $this->addError(
                    $model,
                    $attribute,
                    $this->tooManyBlocksOfType,
                    [
                        'maxBlockTypeBlocks' => $blockType->maxBlocks,
                        'blockType' => $blockType->name,
                    ]
                );
            }
        }

        // Check the block sibling counts for any violations of minSiblingBlocks
        foreach ($blockSiblingCount as $childBlockTypeCount) {
            $childBlockTypes = array_map(fn($id) => $blockTypesById[$id], array_keys($childBlockTypeCount));

            foreach ($childBlockTypes as $childBlockType) {
                if ($childBlockType->minSiblingBlocks > $childBlockTypeCount[$childBlockType->id]) {
                    $this->addError(
                        $model,
                        $attribute,
                        $this->tooFewSiblingBlocks,
                        [
                            'minSiblingBlocks' => $childBlockType->minSiblingBlocks,
                            'blockType' => $childBlockType->name,
                        ]
                    );
                }
            }
        }
    }

    /**
     * Adds an error if the field violates its min or max top-level blocks settings.
     */
    private function _checkTopLevelBlocks($model, $attribute): void
    {
        if ($this->minTopBlocks !== null || $this->maxTopBlocks !== null) {
            $topBlocks = array_filter($this->_enabledBlocks, fn($block) => (int)$block->level === 1);

            if ($this->minTopBlocks !== null && count($topBlocks) < $this->minTopBlocks) {
                $this->addError($model, $attribute, $this->tooFewTopBlocks, ['minTopBlocks' => $this->minTopBlocks]);
            }

            if ($this->maxTopBlocks !== null && count($topBlocks) > $this->maxTopBlocks) {
                $this->addError($model, $attribute, $this->tooManyTopBlocks, ['maxTopBlocks' => $this->maxTopBlocks]);
            }
        }
    }

    /**
     * Adds an error if the field exceeds its min levels.
     */
    private function _checkMinLevels($model, $attribute): void
    {
        $minLevels = $this->minLevels;

        if ($minLevels !== null) {
            $blocksAtMinLevels = array_filter($this->_enabledBlocks, fn($block) => ((int)$block->level) >= $minLevels);

            if (empty($blocksAtMinLevels)) {
                $this->addError($model, $attribute, $this->exceedsMinLevels, ['minLevels' => $this->minLevels]);
            }
        }
    }

    /**
     * Adds an error if the field exceeds its max levels.
     */
    private function _checkMaxLevels($model, $attribute): void
    {
        $maxLevels = $this->maxLevels;

        if ($maxLevels !== null) {
            $tooHighBlocks = array_filter($this->_enabledBlocks, fn($block) => ((int)$block->level) > $maxLevels);

            if (!empty($tooHighBlocks)) {
                $this->addError($model, $attribute, $this->exceedsMaxLevels, ['maxLevels' => $this->maxLevels]);
            }
        }
    }

    /**
     * Sets default error messages for any error messages that have not already been set.
     */
    private function _setDefaultErrorMessages(): void
    {
        if ($this->tooFewTopBlocks === null) {
            $this->tooFewTopBlocks = Craft::t('neo', '{attribute} should contain at least {minTopBlocks, number} top-level {minTopBlocks, plural, one{block} other{blocks}}.');
        }

        if ($this->tooManyTopBlocks === null) {
            $this->tooManyTopBlocks = Craft::t('neo', '{attribute} should contain at most {maxTopBlocks, number} top-level {maxTopBlocks, plural, one{block} other{blocks}}.');
        }

        if ($this->exceedsMinLevels === null) {
            $this->exceedsMinLevels = Craft::t('neo', '{attribute} must have at least one block nested at level {minLevels, number}.');
        }

        if ($this->exceedsMaxLevels === null) {
            $this->exceedsMaxLevels = Craft::t('neo', '{attribute} blocks must not be nested deeper than level {maxLevels, number}.');
        }

        if ($this->tooFewBlocksOfType === null) {
            $this->tooFewBlocksOfType = Craft::t('neo', '{attribute} should contain at least {minBlockTypeBlocks, number} {minBlockTypeBlocks, plural, one{block} other{blocks}} of type {blockType}.');
        }

        if ($this->tooManyBlocksOfType === null) {
            $this->tooManyBlocksOfType = Craft::t('neo', '{attribute} should contain at most {maxBlockTypeBlocks, number} {maxBlockTypeBlocks, plural, one{block} other{blocks}} of type {blockType}.');
        }

        if ($this->tooFewSiblingBlocks === null) {
            $this->tooFewSiblingBlocks = Craft::t('neo', '{attribute} should not contain any instances of fewer than {minSiblingBlocks, number} sibling {minSiblingBlocks, plural, one{block} other{blocks}} of type {blockType}.');
        }
    }
}
