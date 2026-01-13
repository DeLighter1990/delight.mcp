<?php

namespace Delight\Mcp\EventHandlers;

use Bitrix\Main\ArgumentOutOfRangeException;
use Bitrix\Main\Config\Option;

/**
 * Обработчики событий главного модуля
 */
class Main
{
    /**
     * Выводит уведомление после установки обновлений Битрикса
     *
     * @param mixed $moduleId Идентификатор обновленного модуля. Может приходить как строка, так и массив
     * @return void
     * @throws ArgumentOutOfRangeException
     */
    public static function onModuleUpdateHandler(mixed $moduleId): void
    {
        if ($moduleId === 'main') {
            \CAdminNotify::Add(array(
                    'MESSAGE' => 'delight.mcp: После установки обновлений рекомендуется <a href="/bitrix/admin/settings.php?lang=ru&mid=delight.mcp">переиндексировать кодовую базу модулей</a> для MCP-сервера.',
                    'TAG' => 'delight_mcp_updates',
                    'MODULE_ID' => 'delight.mcp',
                    'ENABLE_CLOSE' => 'Y'
                )
            );

            Option::set('delight.mcp', 'need_to_reindex_modules', 'Y');
        }
    }
}
