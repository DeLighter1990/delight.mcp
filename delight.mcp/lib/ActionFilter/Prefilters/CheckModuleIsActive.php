<?php

namespace Delight\Mcp\ActionFilter\Prefilters;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;

/**
 * Производит проверку активности модуля
 */
class CheckModuleIsActive extends Base
{
    /**
     * Проверка перед исполнением действия в контроллере
     *
     * @param Event $event
     * @return EventResult
     */
    public function onBeforeAction(Event $event): EventResult
    {
        $moduleEnabled = Option::get('delight.mcp', 'enabled');

        if ($moduleEnabled === 'Y') {
            return new EventResult(EventResult::SUCCESS);
        }

        $this->addError(new Error('Модуль delight.mcp отключен', 'module is disabled'));

        return new EventResult(EventResult::ERROR, null, 'delight.mcp', $this);
    }
}
