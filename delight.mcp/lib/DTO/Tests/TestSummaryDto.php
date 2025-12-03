<?php

namespace Delight\Mcp\DTO\Tests;

/**
 * DTO для сводной информации о результатах тестирования.
 */
class TestSummaryDto
{
    /**
     * @param int $passed Количество успешных тестов.
     * @param int $failed Количество проваленных тестов.
     * @param int $total Общее количество тестов.
     */
    public function __construct(
        readonly public int $passed,
        readonly public int $failed,
        readonly public int $total
    ) {
    }
}
