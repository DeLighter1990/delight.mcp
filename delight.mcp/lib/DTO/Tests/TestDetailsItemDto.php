<?php

namespace Delight\Mcp\DTO\Tests;

/**
 * DTO для детальной информации о результате одного теста.
 */
class TestDetailsItemDto
{
    /**
     * @param string $name Название теста (имя инструмента).
     * @param string $status Статус выполнения теста (PASS/FAIL).
     * @param string $message Сообщение о результате.
     * @param array $details Дополнительные детали (например, ошибки валидации).
     * @param array|null $schema Схема для валидации.
     * @param mixed|null $response Полученный ответ.
     */
    public function __construct(
        readonly public string $name,
        readonly public string $status,
        readonly public string $message,
        readonly public array $details = [],
        readonly public ?array $schema = null,
        readonly public mixed $response = null,
    ) {
    }
}
