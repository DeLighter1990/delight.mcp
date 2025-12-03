<?php

namespace Delight\Mcp\ActionFilter\Prefilters;

use Bitrix\Main\Context;
use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;

/**
 * Action для проверки прав пользователя на доступ к модулю
 */
class CheckRights extends Base
{
    /**
     * Проверка перед исполнением действия в контроллере
     *
     * @param Event $event
     * @return EventResult
     */
    public function onBeforeAction(Event $event): EventResult
    {
        global $APPLICATION;

        $moduleRights = $APPLICATION->GetGroupRight('delight.mcp');
        if ($moduleRights !== 'W') {
            Context::getCurrent()->getResponse()->setStatus(401);
            $this->addError(new Error(
                    'Недостаточно прав для работы с модулем', 'invalid_module_rights')
            );
            return new EventResult(EventResult::ERROR, null, null, $this);
        }

        return new EventResult(EventResult::SUCCESS);
    }
}
