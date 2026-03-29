<?php

namespace Delight\Mcp\Controller;

use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Error;
use Bitrix\Main\Response;
use Bitrix\Main\Web\Json;
use Delight\Mcp\Exceptions\OAuthException;
use Delight\Mcp\Services\OAuthService;
use Delight\Mcp\Services\TokenService;
use Delight\Mcp\Helpers\Utils;

/**
 * OAuth 2.1 контроллер.
 *
 * Endpoints:
 *   GET  ?action=delight:mcp.OAuth.authorize   — Authorization Code Grant: страница авторизации
 *   POST ?action=delight:mcp.OAuth.getToken    — Token endpoint (Client Credentials + Authorization Code)
 *   GET  ?action=delight:mcp.OAuth.getMetadata — Authorization Server Metadata (RFC 8414)
 *   GET  ?action=delight:mcp.OAuth.getResource — Protected Resource Metadata (RFC 9728)
 */
class OAuth extends Controller
{
    private OAuthService $oauthService;

    protected function init(): void
    {
        parent::init();
        $this->oauthService = new OAuthService(new TokenService());
    }

    public function configureActions(): array
    {
        // OAuth-эндпоинты работают без сессии и CSRF
        $noFilters = ['prefilters' => [], 'postfilters' => []];

        return [
            'authorize'   => $noFilters,
            'getToken'    => $noFilters,
            'getMetadata' => $noFilters,
            'getResource' => $noFilters,
        ];
    }

    // -------------------------------------------------------------------------

    /**
     * GET ?action=delight:mcp.OAuth.authorize
     *
     * Authorization endpoint для Authorization Code flow.
     * ChatGPT редиректит сюда пользователя с параметрами:
     *   client_id, redirect_uri, scope, state, response_type=code
     *
     * Генерируем code и редиректим обратно на redirect_uri
     */
    public function authorizeAction(): void
    {
        $request = $this->getRequest();

        $clientId    = (string)$request->get('client_id');
        $redirectUri = (string)$request->get('redirect_uri');
        $scope       = (string)$request->get('scope');
        $state       = (string)$request->get('state');
        $responseType= (string)$request->get('response_type');

        // Валидация
        if ($responseType !== 'code') {
            $this->showError('Unsupported response_type: ' . $responseType);
            return;
        }

        if (empty($clientId) || empty($redirectUri)) {
            $this->showError('Missing required parameters');
            return;
        }

        // Проверяем что токен с таким client_id существует
        $tokenService = new TokenService();
        $tokenInfo = $tokenService->getTokenInfo($clientId);

        if (!$tokenInfo) {
            $this->showError('Unknown client_id: ' . $clientId);
            return;
        }

        // Генерируем code
        $code = $this->oauthService->generateAuthorizationCode($clientId, $redirectUri, $scope);

        // Логируем для отладки
        error_log("OAuth authorize: generated code={$code} for client_id={$clientId}");

        // Редиректим обратно
        $separator = strpos($redirectUri, '?') !== false ? '&' : '?';
        $location = $redirectUri . $separator . http_build_query([
                'code' => $code,
                'state' => $state,
            ]);

        header('Location: ' . $location, true, 302);
        die();
    }

    /**
     * POST ?action=delight:mcp.OAuth.getToken
     *
     * Параметры (form-data или JSON):
     *   grant_type    = "client_credentials" | "authorization_code"
     *   client_id     = "<IDENTIFIER>"
     *   client_secret = "<JWT>"
     *
     *   Для client_credentials:
     *     scope = "GRANT1 GRANT2"  (опционально)
     *
     *   Для authorization_code:
     *     code         = "<authorization code>"
     *     redirect_uri = "<redirect_uri>"
     */
    public function getTokenAction(): ?array
    {
        $request = $this->getRequest();

        $grantType    = (string)($request->getPost('grant_type')    ?? $this->getJsonParam('grant_type'));
        $clientId     = (string)($request->getPost('client_id')     ?? $this->getJsonParam('client_id'));
        $clientSecret = (string)($request->getPost('client_secret') ?? $this->getJsonParam('client_secret'));

        try {
            if ($grantType === 'authorization_code') {
                $code        = (string)($request->getPost('code')         ?? $this->getJsonParam('code'));
                $redirectUri = (string)($request->getPost('redirect_uri') ?? $this->getJsonParam('redirect_uri'));

                $dto = $this->oauthService->exchangeCodeForToken($code, $clientId, $clientSecret, $redirectUri);
            } else {
                $scope = $request->getPost('scope') ?? $this->getJsonParam('scope');
                $dto = $this->oauthService->issueToken($grantType, $clientId, $clientSecret, $scope);
            }

            return $dto->toArray();
        } catch (OAuthException $e) {
            return $this->oauthError($e);
        }
    }

    /**
     * GET ?action=delight:mcp.OAuth.getMetadata
     * Authorization Server Metadata (RFC 8414)
     * Обычно проксируется с /.well-known/oauth-authorization-server
     */
    public function getMetadataAction(): array
    {
        return $this->oauthService->getAuthorizationServerMetadata(Utils::getBaseUrl());
    }

    /**
     * GET ?action=delight:mcp.OAuth.getResource
     * Protected Resource Metadata (RFC 9728)
     * Обычно проксируется с /.well-known/oauth-protected-resource
     */
    public function getResourceAction(): array
    {
        return $this->oauthService->getProtectedResourceMetadata(Utils::getBaseUrl());
    }

    // -------------------------------------------------------------------------

    private function oauthError(OAuthException $e): array
    {
        $statusTexts = [400 => 'Bad Request', 401 => 'Unauthorized'];
        $status      = $e->getHttpStatus();

        header('HTTP/1.1 ' . $status . ' ' . ($statusTexts[$status] ?? ''), true, $status);

        if ($status === 401) {
            $resourceUrl = Utils::getBaseUrl() . '/bitrix/services/main/ajax.php?action=delight:mcp.OAuth.getResource';
            header('WWW-Authenticate: Bearer realm="delight.mcp", resource_metadata="' . $resourceUrl . '"');
        }

        $this->addError(new Error($e->getMessage(), $e->getOauthError()));

        return [
            'error'             => $e->getOauthError(),
            'error_description' => $e->getMessage(),
        ];
    }

    /**
     * Показывает ошибку пользователю (для authorize endpoint)
     */
    private function showError(string $message): void
    {
        header('HTTP/1.1 400 Bad Request', true, 400);
        echo '<h1>OAuth Authorization Error</h1><p>' . htmlspecialchars($message) . '</p>';
        die();
    }

    /**
     * Читает параметр из JSON-тела запроса (для клиентов, шлющих application/json).
     */
    private function getJsonParam(string $key): ?string
    {
        static $json = null;

        if ($json === null) {
            $raw  = file_get_contents('php://input');
            $json = $raw ? (json_decode($raw, true) ?? []) : [];
        }

        return isset($json[$key]) ? (string)$json[$key] : null;
    }

    /**
     * Отдаём чистый JSON без битриксовой обёртки {"status":"success","data":{...}}.
     * RFC 8414 и RFC 9728 требуют именно сырой JSON.
     */
    public function finalizeResponse(Response $response): void
    {
        parent::finalizeResponse($response);

        $content = $response->getContent();
        if (!$content) {
            return;
        }

        $decoded = json_decode($content, true);

        // Если Битрикс обернул ответ — достаём data, иначе отдаём как есть
        if (isset($decoded['status']) && isset($decoded['data'])) {
            $payload = $decoded['data'];
        } else {
            $payload = $decoded;
        }

        $response->addHeader('Content-Type', 'application/json; charset=utf-8');
        $response->setContent(Json::encode($payload));
    }
}
