<?php

namespace Delight\Mcp\DTO\Tests;

/**
 * Корневой DTO для хранения результатов тестирования.
 */
class TestResultDto
{
    /**
     * @param TestSummaryDto $summary Сводная информация о результатах.
     * @param array<TestDetailsItemDto> $details Детальные результаты по каждому тесту.
     * @param string|null $error Сообщение об ошибке, если она произошла до запуска тестов.
     */
    public function __construct(
        readonly public TestSummaryDto $summary,
        readonly public array $details,
        readonly public ?string $error = null
    ) {
    }
}
