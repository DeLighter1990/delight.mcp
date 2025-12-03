<?php

namespace Delight\Mcp\DTO\Tool;

use Delight\Mcp\Traits\JsonSerializable;

/**
 * Схема элементов массива у свойства
 */
class PropertyItemsSchema implements \JsonSerializable
{
    use JsonSerializable;

    /**
     * @param string|null $type Тип элемента
     * @param PropertyItemsSchema|null $items Вложенные элементы
     * @param string|null $description Описание
     * @param PropertySchema[]|null $properties Свойства (если $type = object)
     */
    public function __construct(
        readonly public ?string $type = null,
        readonly public ?self   $items = null,
        readonly public ?string $description = null,
        readonly public ?array  $properties = null
    )
    {
    }
}
