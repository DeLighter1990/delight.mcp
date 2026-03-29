<?php

namespace Delight\Mcp\Enum;

/**
 * Права токена на работу с инструментами.
 * Имя функции-инструмента должно начинаться с имени права, но в snakeCase.
 */
enum TokenGrants: string
{
    case ENV_INFO = 'Получение информации об окружении';
    case SEARCH_BX_DOCS = 'Поиск по документации Bitrix Framework';
    case LIVE_API = 'Поиск по кодовой базе (LiveAPI)';
    case EXEC = 'Исполнение Shell-команд';
    case EVAL = 'Исполнение PHP-кода';
    case SQL = 'Исполнение SQL-запросов';

    /**
     * Возвращает массив со всеми именами списочных значений
     *
     * @return string[]
     */
    public static function names(): array
    {
        return array_map(
            static fn (self $case) => $case->name,
            self::cases()
        );
    }
}
