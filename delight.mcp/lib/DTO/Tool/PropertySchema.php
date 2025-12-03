<?php

namespace Delight\Mcp\DTO\Tool;

use Delight\Mcp\Traits\JsonSerializable;

/**
 * Схема входного параметра инструмента
 */
class PropertySchema implements \JsonSerializable
{
    use JsonSerializable;

    /**
     * @param string $type Тип свойства (пока что всегда "string")
     * @param string $description Описание свойства
     * @param PropertyItemsSchema|null $items Элементы массива (если $type = array)
     * @param PropertySchema[]|null $properties Вложенные свойства (если $type = object)
     */
    public function __construct(
        readonly public string               $type,
        readonly public string               $description,
        readonly public ?PropertyItemsSchema $items = null,
        readonly public ?array               $properties = null,
    )
    {
    }
}
