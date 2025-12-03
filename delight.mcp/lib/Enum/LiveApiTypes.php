<?php

namespace Delight\Mcp\Enum;

/**
 * Типы данных LiveApi
 */
enum LiveApiTypes: string
{
    /** Функция */
    case FUNCTION = 'function';
    /** Событие */
    case EVENT = 'event';
}
