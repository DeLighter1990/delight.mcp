<?php

namespace Delight\Mcp\Tests;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Context;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Json;
use Delight\Mcp\DTO\Tests\TestDetailsItemDto;
use Delight\Mcp\DTO\Tests\TestResultDto;
use Delight\Mcp\DTO\Tests\TestSummaryDto;
use Exception;

/**
 * Класс для тестирования OAuth 2.1 endpoints модуля Delight MCP.
 * Проверяет корректность работы Authorization Server и Protected Resource Metadata.
 */
class OAuthTest
{
    private string $baseUrl;
    private string $clientId;
    private string $clientSecret;
    private array $testContext = [];

    /**
     * @param string $clientId IDENTIFIER токена для тестирования
     * @param string $clientSecret JWT-токен для тестирования
     */
    public function __construct(string $clientId, string $clientSecret)
    {
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
    }

    /**
     * Запускает все OAuth тесты.
     *
     * @return TestResultDto Результаты тестирования
     * @throws ArgumentException
     */
    public function run(): TestResultDto
    {
        $request = Context::getCurrent()->getRequest();
        $protocol = $request->isHttps() ? 'https' : 'http';
        $host = $request->getHttpHost();

        if (!$host) {
            return new TestResultDto(
                new TestSummaryDto(0, 0, 0),
                [],
                'Не удалось определить хост из текущего запроса.'
            );
        }

        $this->baseUrl = $protocol . '://' . $host;

        $passed = 0;
        $failed = 0;
        $details = [];

        // Последовательность тестов следует OAuth flow
        $this->testProtectedResourceMetadata($passed, $failed, $details);
        $this->testAuthorizationServerMetadata($passed, $failed, $details);
        $this->testWellKnownEndpoints($passed, $failed, $details);
        $this->testUnauthorizedMcpRequest($passed, $failed, $details);
        $this->testClientCredentialsGrant($passed, $failed, $details);
        $this->testAuthorizationCodeFlow($passed, $failed, $details);
        $this->testInvalidCredentials($passed, $failed, $details);
        $this->testInvalidCode($passed, $failed, $details);

        $totalTests = 8;
        $summary = new TestSummaryDto($passed, $failed, $totalTests);
        return new TestResultDto($summary, $details);
    }

    /**
     * Тест 1: Protected Resource Metadata (RFC 9728)
     */
    private function testProtectedResourceMetadata(int &$passed, int &$failed, array &$details): void
    {
        $url = $this->baseUrl . '/bitrix/services/main/ajax.php?action=delight:mcp.OAuth.getResource';
        $response = $this->sendGetRequest($url);

        if (!$response) {
            $this->addResult('getResource', false, 'Нет ответа от сервера', [], $passed, $failed, $details);
            return;
        }

        $requiredFields = ['resource', 'authorization_servers', 'bearer_methods_supported', 'scopes_supported'];
        $errors = [];

        foreach ($requiredFields as $field) {
            if (!isset($response[$field])) {
                $errors[] = "Отсутствует обязательное поле: $field";
            }
        }

        if (empty($errors)) {
            $this->addResult('getResource', true, 'Метаданные защищённого ресурса корректны', [], $passed, $failed, $details);
            $this->testContext['resource_metadata'] = $response;
        } else {
            $this->addResult('getResource', false, 'Некорректная структура ответа', $errors, $passed, $failed, $details, null, $response);
        }
    }

    /**
     * Тест 2: Authorization Server Metadata (RFC 8414)
     */
    private function testAuthorizationServerMetadata(int &$passed, int &$failed, array &$details): void
    {
        $url = $this->baseUrl . '/bitrix/services/main/ajax.php?action=delight:mcp.OAuth.getMetadata';
        $response = $this->sendGetRequest($url);

        if (!$response) {
            $this->addResult('getMetadata', false, 'Нет ответа от сервера', [], $passed, $failed, $details);
            return;
        }

        $requiredFields = [
            'issuer',
            'authorization_endpoint',
            'token_endpoint',
            'grant_types_supported',
            'token_endpoint_auth_methods_supported',
            'scopes_supported'
        ];
        $errors = [];

        foreach ($requiredFields as $field) {
            if (!isset($response[$field])) {
                $errors[] = "Отсутствует обязательное поле: $field";
            }
        }

        // Проверяем поддержку authorization_code
        if (isset($response['grant_types_supported']) &&
            !in_array('authorization_code', $response['grant_types_supported'], true)) {
            $errors[] = "Не поддерживается grant_type=authorization_code";
        }

        if (empty($errors)) {
            $this->addResult('getMetadata', true, 'Метаданные AS корректны', [], $passed, $failed, $details);
            $this->testContext['as_metadata'] = $response;
        } else {
            $this->addResult('getMetadata', false, 'Некорректная структура ответа', $errors, $passed, $failed, $details, null, $response);
        }
    }

    /**
     * Тест 3: .well-known endpoints
     */
    private function testWellKnownEndpoints(int &$passed, int &$failed, array &$details): void
    {
        $endpoints = [
            '/bitrix/services/main/ajax.php/.well-known/oauth-authorization-server',
            '/bitrix/services/main/ajax.php/.well-known/openid-configuration',
        ];

        $allPassed = true;
        $errors = [];

        foreach ($endpoints as $endpoint) {
            $url = $this->baseUrl . $endpoint;
            $response = $this->sendGetRequest($url);

            if (!$response || !isset($response['token_endpoint'])) {
                $allPassed = false;
                $errors[] = "Endpoint $endpoint недоступен или вернул некорректный ответ";
            }
        }

        if ($allPassed) {
            $this->addResult('wellKnownEndpoints', true, 'Все .well-known endpoints доступны', [], $passed, $failed, $details);
        } else {
            $this->addResult('wellKnownEndpoints', false, 'Проблемы с .well-known endpoints', $errors, $passed, $failed, $details);
        }
    }

    /**
     * Тест 4: Запрос к MCP без токена должен возвращать 401 с WWW-Authenticate
     */
    private function testUnauthorizedMcpRequest(int &$passed, int &$failed, array &$details): void
    {
        $url = $this->baseUrl . '/bitrix/services/main/ajax.php?action=delight:mcp.Rpc.handler';

        $httpClient = new HttpClient();
        $httpClient->setHeader('Content-Type', 'application/json');

        try {
            $requestBody = Json::encode([
                'jsonrpc' => '2.0',
                'method' => 'initialize',
                'id' => 1,
            ]);

            $httpClient->post($url, $requestBody);
            $status = $httpClient->getStatus();
            $headers = $httpClient->getHeaders();

            if ($status === 401) {
                $wwwAuth = $headers->get('WWW-Authenticate') ?? '';

                if (strpos($wwwAuth, 'resource_metadata') !== false) {
                    $this->addResult('unauthorizedMcpRequest', true, 'MCP корректно возвращает 401 с WWW-Authenticate', [], $passed, $failed, $details);
                } else {
                    $this->addResult('unauthorizedMcpRequest', false, 'WWW-Authenticate не содержит resource_metadata', ["WWW-Authenticate: $wwwAuth"], $passed, $failed, $details);
                }
            } else {
                $this->addResult('unauthorizedMcpRequest', false, "Неверный HTTP статус: ожидался 401, получен $status", [], $passed, $failed, $details);
            }
        } catch (Exception $e) {
            $this->addResult('unauthorizedMcpRequest', false, 'Ошибка запроса: ' . $e->getMessage(), [], $passed, $failed, $details);
        }
    }

    /**
     * Тест 5: Client Credentials Grant
     */
    private function testClientCredentialsGrant(int &$passed, int &$failed, array &$details): void
    {
        $url = $this->baseUrl . '/bitrix/services/main/ajax.php?action=delight:mcp.OAuth.getToken';

        $params = [
            'grant_type' => 'client_credentials',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
        ];

        $response = $this->sendPostRequest($url, $params);

        if (!$response) {
            $this->addResult('clientCredentialsGrant', false, 'Нет ответа от сервера', [], $passed, $failed, $details);
            return;
        }

        if (isset($response['error'])) {
            $error = $response['error'] . ': ' . ($response['error_description'] ?? '');
            $this->addResult('clientCredentialsGrant', false, "Ошибка OAuth: $error", [], $passed, $failed, $details, null, $response);
            return;
        }

        $errors = [];
        if (!isset($response['access_token'])) {
            $errors[] = 'Отсутствует access_token';
        }
        if (!isset($response['token_type']) || $response['token_type'] !== 'bearer') {
            $errors[] = 'token_type должен быть "bearer"';
        }
        if (!isset($response['scope'])) {
            $errors[] = 'Отсутствует scope';
        }

        if (empty($errors)) {
            $this->addResult('clientCredentialsGrant', true, 'Client Credentials Grant работает корректно', [], $passed, $failed, $details);
            $this->testContext['access_token'] = $response['access_token'];
        } else {
            $this->addResult('clientCredentialsGrant', false, 'Некорректный токен-ответ', $errors, $passed, $failed, $details, null, $response);
        }
    }

    /**
     * Тест 6: Authorization Code Flow (симуляция)
     */
    private function testAuthorizationCodeFlow(int &$passed, int &$failed, array &$details): void
    {
        // Шаг 1: Симулируем запрос authorize endpoint
        $authorizeUrl = $this->baseUrl . '/bitrix/services/main/ajax.php?' . http_build_query([
                'action' => 'delight:mcp.OAuth.authorize',
                'response_type' => 'code',
                'client_id' => $this->clientId,
                'redirect_uri' => 'http://localhost/callback',
                'scope' => 'ENV_INFO EXEC',
                'state' => 'test_state_123',
            ]);

        $httpClient = new HttpClient([
            'redirect' => false, // Не следуем редиректам автоматически
        ]);

        try {
            $httpClient->get($authorizeUrl);
            $status = $httpClient->getStatus();
            $headers = $httpClient->getHeaders();

            if ($status === 302) {
                $location = $headers->get('Location') ?? '';

                // Извлекаем code из редиректа
                if (preg_match('/code=([^&]+)/', $location, $matches)) {
                    $code = $matches[1];

                    // Шаг 2: Обмениваем code на токен
                    $tokenUrl = $this->baseUrl . '/bitrix/services/main/ajax.php?action=delight:mcp.OAuth.getToken';
                    $tokenParams = [
                        'grant_type' => 'authorization_code',
                        'code' => $code,
                        'client_id' => $this->clientId,
                        'client_secret' => $this->clientSecret,
                        'redirect_uri' => 'http://localhost/callback',
                    ];

                    $tokenResponse = $this->sendPostRequest($tokenUrl, $tokenParams);

                    if (isset($tokenResponse['access_token'])) {
                        $this->addResult('authorizationCodeFlow', true, 'Authorization Code Flow работает корректно', [], $passed, $failed, $details);
                    } else {
                        $error = $tokenResponse['error'] ?? 'Unknown error';
                        $this->addResult('authorizationCodeFlow', false, "Не удалось обменять code на токен: $error", [], $passed, $failed, $details, null, $tokenResponse);
                    }
                } else {
                    $this->addResult('authorizationCodeFlow', false, 'В Location нет параметра code', ["Location: $location"], $passed, $failed, $details);
                }
            } else {
                $this->addResult('authorizationCodeFlow', false, "Неверный статус: ожидался 302, получен $status", [], $passed, $failed, $details);
            }
        } catch (Exception $e) {
            $this->addResult('authorizationCodeFlow', false, 'Ошибка запроса: ' . $e->getMessage(), [], $passed, $failed, $details);
        }
    }

    /**
     * Тест 7: Неверные credentials должны возвращать 401
     */
    private function testInvalidCredentials(int &$passed, int &$failed, array &$details): void
    {
        $url = $this->baseUrl . '/bitrix/services/main/ajax.php?action=delight:mcp.OAuth.getToken';

        $params = [
            'grant_type' => 'client_credentials',
            'client_id' => 'invalid_client',
            'client_secret' => 'invalid_secret_token',
        ];

        $response = $this->sendPostRequest($url, $params);

        if (isset($response['error']) && $response['error'] === 'invalid_client') {
            $this->addResult('invalidCredentials', true, 'Неверные credentials корректно отклонены', [], $passed, $failed, $details);
        } else {
            $this->addResult('invalidCredentials', false, 'Должна быть ошибка invalid_client', [], $passed, $failed, $details, null, $response);
        }
    }

    /**
     * Тест 8: Невалидный authorization code должен возвращать ошибку
     */
    private function testInvalidCode(int &$passed, int &$failed, array &$details): void
    {
        $url = $this->baseUrl . '/bitrix/services/main/ajax.php?action=delight:mcp.OAuth.getToken';

        $params = [
            'grant_type' => 'authorization_code',
            'code' => 'invalid_code_12345',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri' => 'http://localhost/callback',
        ];

        $response = $this->sendPostRequest($url, $params);

        if (isset($response['error']) && $response['error'] === 'invalid_request') {
            $this->addResult('invalidCode', true, 'Невалидный code корректно отклонён', [], $passed, $failed, $details);
        } else {
            $this->addResult('invalidCode', false, 'Должна быть ошибка invalid_request', [], $passed, $failed, $details, null, $response);
        }
    }

    // -------------------------------------------------------------------------
    // Вспомогательные методы
    // -------------------------------------------------------------------------

    private function sendGetRequest(string $url): ?array
    {
        $httpClient = new HttpClient();

        try {
            $responseBody = $httpClient->get($url);

            if ($httpClient->getStatus() !== 200) {
                return null;
            }

            return Json::decode($responseBody);
        } catch (Exception) {
            return null;
        }
    }

    private function sendPostRequest(string $url, array $params): ?array
    {
        $httpClient = new HttpClient();
        $httpClient->setHeader('Content-Type', 'application/x-www-form-urlencoded');

        try {
            $responseBody = $httpClient->post($url, http_build_query($params));
            return Json::decode($responseBody);
        } catch (Exception) {
            return null;
        }
    }

    private function addResult(
        string $testName,
        bool   $isSuccess,
        string $message,
        array  $validationDetails,
        int    &$passed,
        int    &$failed,
        array  &$resultDetails,
        ?array $schema = null,
        mixed  $response = null
    ): void {
        if ($isSuccess) {
            $passed++;
        } else {
            $failed++;
        }

        $resultDetails[] = new TestDetailsItemDto(
            $testName,
            $isSuccess ? 'PASS' : 'FAIL',
            $message,
            $validationDetails,
            $schema,
            $response
        );
    }
}
