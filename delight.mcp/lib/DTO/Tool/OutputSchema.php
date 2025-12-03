<?php

namespace Delight\Mcp\DTO\Tool;

class OutputSchema
{
    /**
     * @param string $type Тип схемы (пока что всегда "object")
     * @param array<string, PropertySchema> $properties Свойства схемы
     * @param string[] $required Обязательные поля
     */
    public function __construct(
        readonly public string $type,
        readonly public array  $properties,
        readonly public array  $required
    )
    {
    }
}
