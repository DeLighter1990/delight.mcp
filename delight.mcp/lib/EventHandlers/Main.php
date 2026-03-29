<?php

namespace Delight\Mcp\EventHandlers;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\ArgumentOutOfRangeException;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Context;
use Bitrix\Main\Web\Json;
use Delight\Mcp\DTO\OAuth\OAuthAuthorizationServerMetadataDTO;
use Delight\Mcp\Enum\TokenGrants;
use Delight\Mcp\Helpers\Utils;

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

    /**
     * Перехватывает запросы к /bitrix/services/main/ajax.php/.well-known/oauth-authorization-server
     * и /bitrix/services/main/ajax.php/.well-known/openid-configuration.
     * Используется для подключения к ChatGPT в режиме разработчика.
     *
     * @return void
     * @throws ArgumentException
     */
    public static function onPageStartHandler(): void
    {
        $request = Context::getCurrent()?->getRequest();
        $uri = $request->getRequestUri();

        if (
            (
                $uri === '/bitrix/services/main/ajax.php/.well-known/oauth-authorization-server'
                || $uri === '/bitrix/services/main/ajax.php/.well-known/openid-configuration'
            )
            && Option::get('delight.mcp', 'well_known_interception_enabled') === 'Y'
        ) {
            header('Content-Type: application/json; charset=utf-8');

            echo Json::encode(
                (new OAuthAuthorizationServerMetadataDTO(
                    issuer: Utils::getBaseUrl() . '/bitrix/services/main/ajax.php',
                    authorizationEndpoint: Utils::getBaseUrl() . '/bitrix/services/main/ajax.php?action=delight:mcp.OAuth.authorize',
                    tokenEndpoint: Utils::getBaseUrl() . '/bitrix/services/main/ajax.php?action=delight:mcp.OAuth.getToken',
                    grantTypesSupported: ['authorization_code'],
                    tokenEndpointAuthMethodsSupported: ['client_secret_post'],
                    tokenEndpointAuthSigningAlgValuesSupported: ['HS256'],
                    scopesSupported: [],
                    codeChallengeMethodsSupported: []
                ))->toArray()
            );

            exit;
        }
    }
}
