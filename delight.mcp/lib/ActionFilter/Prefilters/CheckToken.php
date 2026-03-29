<?php

namespace Delight\Mcp\ActionFilter\Prefilters;

use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\Error;
use Delight\Mcp\Enum\TokenGrants;
use Delight\Mcp\Helpers\Utils;
use Delight\Mcp\Services\TokenService;

/**
 * Проверка токена. Доступен ли запрашиваемый эндпоинт текущему токену?
 */
class CheckToken extends Base
{
    private const WWW_AUTHENTICATE       = 'WWW-Authenticate';
    private const BEARER_REALM           = 'Bearer realm="delight.mcp"';
    private const RESOURCE_METADATA_PATH = '/bitrix/services/main/ajax.php?action=delight:mcp.OAuth.getResource';

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
            // RFC 6750 §3.1: токен отсутствует — 401 без error (просто вызов к аутентификации)
            header('HTTP/1.1 401 Unauthorized', true, 401);
            header(self::WWW_AUTHENTICATE . ': ' . self::BEARER_REALM . ', resource_metadata="' . $this->getResourceMetadataUrl() . '"');

            $this->addError(new Error('В запросе отсутствует токен', 'NO_TOKEN'));
            return new EventResult(EventResult::ERROR, null, null, $this);
        }

        try {
            $tokenService = new TokenService();
            $grants       = $tokenService->validateToken($token);

            if (!is_null($this->requiredGrant)) {
                if (!in_array($this->requiredGrant->name, $grants, true)) {
                    // RFC 6750 §3.1: токен валиден, но прав недостаточно — 403, заголовок не нужен
                    header('HTTP/1.1 403 Forbidden', true, 403);

                    $this->addError(new Error(
                        'У вас недостаточно прав для использования этого метода',
                        'WRONG_PERMISSIONS'
                    ));
                    return new EventResult(EventResult::ERROR, null, null, $this);
                }
            }
        } catch (\Exception $e) {
            // RFC 6750 §3.1: токен есть, но невалиден — 401 с error="invalid_token"
            header('HTTP/1.1 401 Unauthorized', true, 401);
            header(self::WWW_AUTHENTICATE . ': ' . self::BEARER_REALM . ', error="invalid_token", resource_metadata="' . $this->getResourceMetadataUrl() . '"');

            $this->addError(new Error($e->getMessage(), 'INVALID_TOKEN'));
            return new EventResult(EventResult::ERROR, null, null, $this);
        }

        return null;
    }

    /**
     * Строит абсолютный URL endpoint'а Protected Resource Metadata.
     * Домен определяется динамически из текущего запроса — модуль универсален.
     */
    private function getResourceMetadataUrl(): string
    {
        return Utils::getBaseUrl() . self::RESOURCE_METADATA_PATH;
    }
}
