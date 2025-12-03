<?php

namespace Delight\Mcp\DTO\Tool;

/**
 * Входная схема для MCP инструмента
 */
class InputSchema implements \JsonSerializable
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

    public function jsonSerialize(): object
    {
        // Пустой $properties должен быть преобразован в объект
        $data = (object)get_object_vars($this);
        $data->properties = (object)$data->properties;
        return $data;
    }
}
