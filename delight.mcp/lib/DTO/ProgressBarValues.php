<?php

namespace Delight\Mcp\DTO;

/**
 * ValueObject для прогресс-бара на странице настроек модуля
 */
class ProgressBarValues
{
    /**
     * @param int $total Общее количество элементов
     * @param int $processed Количество обработанных элементов
     */
    public function __construct(
        readonly public int $total,
        readonly public int $processed
    )
    {
    }
}
