<?php

namespace Delight\Mcp\Services;

use Bitrix\Main\Type\DateTime;
use Delight\Mcp\DTO\OAuth\OAuthTokenResponseDTO;
use Delight\Mcp\Enum\TokenGrants;
use Delight\Mcp\Exceptions\OAuthException;

/**
 * Сервис OAuth 2.1 Authorization Server.
 *
 * Реализует Client Credentials Grant и Authorization Code Grant
 * согласно спецификации MCP Authorization (2025-03-26).
 *
 * Маппинг существующей модели на OAuth-термины:
 *   client_id     → IDENTIFIER (уникальное имя токена в БД)
 *   client_secret → JWT-токен, выданный в настройках модуля
 *   scope         → GRANTS токена (хранятся в БД, не в JWT)
 */
class OAuthService
{
    public const GRANT_CLIENT_CREDENTIALS = 'client_credentials';
    public const GRANT_AUTHORIZATION_CODE = 'authorization_code';
    public const TOKEN_TYPE = 'bearer';
    /** @var int Время жизни проверочного кода для авторизации */
    private const CODE_TTL_SECONDS = 60 * 5;

    public function __construct(
        private readonly TokenService $tokenService
    ) {}

    /**
     * Обрабатывает Client Credentials Grant.
     *
     * @param string $grantType   Должен быть "client_credentials"
     * @param string $clientId    IDENTIFIER токена
     * @param string $clientSecret JWT-токен из настроек модуля
     * @param string|null $scope  Запрошенные права через пробел (опционально)
     * @return OAuthTokenResponseDTO
     * @throws OAuthException
     */
    public function issueToken(
        string $grantType,
        string $clientId,
        string $clientSecret,
        ?string $scope = null
    ): OAuthTokenResponseDTO {
        $this->validateGrantType($grantType);
        $this->validateRequiredParams($clientId, $clientSecret);

        $grants = $this->authenticateClient($clientId, $clientSecret);
        $grantedScopes = $this->resolveScopes($scope, $grants);
        $expiresIn = $this->resolveExpiresIn($clientSecret);

        return new OAuthTokenResponseDTO(
            accessToken: $clientSecret,
            tokenType:   self::TOKEN_TYPE,
            scope:       implode(' ', $grantedScopes),
            expiresIn:   $expiresIn,
        );
    }

    /**
     * Формирует Authorization Server Metadata (RFC 8414).
     *
     * @param string $baseUrl Базовый URL сайта (scheme + host)
     * @return array
     */
    public function getAuthorizationServerMetadata(string $baseUrl): array
    {
        return [
            'issuer'                                          => $baseUrl,
            'authorization_endpoint'                          => $baseUrl . '/bitrix/services/main/ajax.php?action=delight:mcp.OAuth.authorize',
            'token_endpoint'                                  => $baseUrl . '/bitrix/services/main/ajax.php?action=delight:mcp.OAuth.getToken',
            'grant_types_supported'                           => [self::GRANT_AUTHORIZATION_CODE, self::GRANT_CLIENT_CREDENTIALS],
            'token_endpoint_auth_methods_supported'           => ['client_secret_post'],
            'token_endpoint_auth_signing_alg_values_supported'=> ['HS256'],
            'scopes_supported'                                => TokenGrants::names(),
            'code_challenge_methods_supported'                => [],
        ];
    }

    /**
     * Формирует Protected Resource Metadata (RFC 9728).
     *
     * @param string $baseUrl Базовый URL сайта (scheme + host)
     * @return array
     */
    public function getProtectedResourceMetadata(string $baseUrl): array
    {
        return [
            'resource'               => $baseUrl,
            'authorization_servers'  => [$baseUrl],
            'bearer_methods_supported'=> ['header'],
            // TODO: здесь отдавать список прав текущего токена (если это возможно)
            'scopes_supported'       => [],
        ];
    }

    // -------------------------------------------------------------------------
    // Authorization Code flow
    // -------------------------------------------------------------------------

    /**
     * Генерирует authorization code и сохраняет его в кеше.
     *
     * @param string $clientId IDENTIFIER токена
     * @param string $redirectUri Куда вернуть пользователя
     * @param string $scope Запрошенные права
     * @return string Сгенерированный code
     */
    public function generateAuthorizationCode(string $clientId, string $redirectUri, string $scope): string
    {
        $code = bin2hex(random_bytes(32)); // 64 символа

        $cacheData = [
            'client_id'    => $clientId,
            'redirect_uri' => $redirectUri,
            'scope'        => $scope,
            'created_at'   => time(),
        ];

        // Сохраняем в кеш на 5 минут
        $cache = \Bitrix\Main\Data\Cache::createInstance();
        $cacheId = 'oauth_code_' . $code;
        $cache->initCache(self::CODE_TTL_SECONDS, $cacheId, '/');
        $cache->startDataCache();
        $cache->endDataCache($cacheData);

        return $code;
    }

    /**
     * Обменивает authorization code на access token.
     *
     * @param string $code Authorization code
     * @param string $clientId
     * @param string $clientSecret JWT-токен
     * @param string $redirectUri Должен совпадать с тем, что был при генерации code
     * @return OAuthTokenResponseDTO
     * @throws OAuthException
     */
    public function exchangeCodeForToken(
        string $code,
        string $clientId,
        string $clientSecret,
        string $redirectUri
    ): OAuthTokenResponseDTO {
        // Читаем code из кеша
        $cache = \Bitrix\Main\Data\Cache::createInstance();
        $cacheId = 'oauth_code_' . $code;

        // Если кеш НЕ существует (initCache вернул false) — code недействителен
        if (!$cache->initCache(self::CODE_TTL_SECONDS, $cacheId, '/')) {
            throw OAuthException::invalidRequest('Authorization code недействителен или истёк');
        }

        $codeData = $cache->getVars();

        // Удаляем code сразу (одноразовый)
        $cache->clean($cacheId, '/');

        // Валидация
        if (!isset($codeData['client_id']) || $codeData['client_id'] !== $clientId) {
            throw OAuthException::invalidClient();
        }

        if (!isset($codeData['redirect_uri']) || $codeData['redirect_uri'] !== $redirectUri) {
            throw OAuthException::invalidRequest('redirect_uri не совпадает');
        }

        // Аутентифицируем клиента через JWT
        $grants = $this->authenticateClient($clientId, $clientSecret);

        // Проверяем что запрошенные scope есть у токена
        $grantedScopes = $this->resolveScopes($codeData['scope'] ?? '', $grants);
        $expiresIn = $this->resolveExpiresIn($clientSecret);

        return new OAuthTokenResponseDTO(
            accessToken: $clientSecret,
            tokenType:   self::TOKEN_TYPE,
            scope:       implode(' ', $grantedScopes),
            expiresIn:   $expiresIn,
        );
    }

    // -------------------------------------------------------------------------
    // Приватные методы
    // -------------------------------------------------------------------------

    /**
     * @throws OAuthException
     */
    private function validateGrantType(string $grantType): void
    {
        $supported = [self::GRANT_CLIENT_CREDENTIALS, self::GRANT_AUTHORIZATION_CODE];

        if (!in_array($grantType, $supported, true)) {
            throw OAuthException::unsupportedGrantType($grantType);
        }
    }

    /**
     * @throws OAuthException
     */
    private function validateRequiredParams(string $clientId, string $clientSecret): void
    {
        if (empty($clientId)) {
            throw OAuthException::invalidRequest('Параметр client_id обязателен');
        }

        if (empty($clientSecret)) {
            throw OAuthException::invalidRequest('Параметр client_secret обязателен');
        }
    }

    /**
     * Аутентифицирует клиента через существующий TokenService:
     * валидирует JWT и проверяет соответствие client_id идентификатору в токене.
     *
     * @return string[] Массив grants из БД
     * @throws OAuthException
     */
    private function authenticateClient(string $clientId, string $clientSecret): array
    {
        try {
            $grants = $this->tokenService->validateToken($clientSecret);
            $identifier = $this->tokenService->getTokenIdentifier($clientSecret);
        } catch (\Exception) {
            throw OAuthException::invalidClient();
        }

        if ($identifier !== $clientId) {
            throw OAuthException::invalidClient();
        }

        return $grants;
    }

    /**
     * Определяет итоговый список scope.
     * Если scope не запрошен — возвращаем все grants токена.
     * Если запрошен — проверяем, что все они есть у токена.
     *
     * @param string|null $scopeParam
     * @param string[]    $availableGrants
     * @return string[]
     * @throws OAuthException
     */
    private function resolveScopes(?string $scopeParam, array $availableGrants): array
    {
        if (empty($scopeParam)) {
            return $availableGrants;
        }

        $requested = array_filter(array_map('trim', explode(' ', $scopeParam)));
        $unknown   = array_diff($requested, $availableGrants);

        if (!empty($unknown)) {
            throw OAuthException::invalidScope($unknown);
        }

        return $requested;
    }

    /**
     * Вычисляет оставшееся время жизни токена в секундах.
     * Возвращает null если токен бессрочный.
     */
    private function resolveExpiresIn(string $jwt): ?int
    {
        try {
            $identifier = $this->tokenService->getTokenIdentifier($jwt);
            $tokenInfo  = $this->tokenService->getTokenInfo($identifier);

            $expiration = $tokenInfo['expiration'] ?? null;

            if ($expiration instanceof DateTime) {
                return max(0, $expiration->getTimestamp() - time());
            }
        } catch (\Exception) {
            // не критично
        }

        return null;
    }
}
