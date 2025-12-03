<?php

namespace Delight\Mcp\ActionFilter\Prefilters;

use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\Error;
use Delight\Mcp\Enum\TokenGrants;
use Delight\Mcp\Services\TokenService;

/**
 * Проверка токена. Доступен ли запрашиваемый эндпоинт текущему токену?
 */
class CheckToken extends Base
{
    /** @var TokenGrants|null */
    private ?TokenGrants $requiredGrant;

    /**
     * @param TokenGrants|null $requiredGrant Если права токена не переданы, значит нужно проверить только актуальность токена
     */
    public function __construct(?TokenGrants $requiredGrant = null)
    {
        $this->requiredGrant = $requiredGrant;
        parent::__construct();
    }

    /**
     * @param Event $event
     * @return EventResult|null
     */
    public function onBeforeAction(Event $event): ?EventResult
    {
        $token = TokenService::extractTokenFromHeader();

        if (!$token) {
            $this->addError(new Error('В запросе отсутствует токен', 'NO_TOKEN'));
            return new EventResult(EventResult::ERROR, null, null, $this);
        }

        try {
            $tokenService = new TokenService();
            // Здесь может выскочить ошибка, если токен истёк
            $grants = $tokenService->validateToken($token);

            if (!is_null($this->requiredGrant)) {
                // Проверяем наличие необходимого права в токене
                if (!in_array($this->requiredGrant->name, $grants, true)) {
                    $this->addError(new Error(
                        'У вас недостаточно прав для использования этого метода',
                        'WRONG_PERMISSIONS'
                    ));
                    return new EventResult(EventResult::ERROR, null, null, $this);
                }
            }
        } catch (\Exception $e) {
            $this->addError(new Error($e->getMessage(), 'INVALID_TOKEN'));
            return new EventResult(EventResult::ERROR, null, null, $this);
        }

        return null;
    }
}
