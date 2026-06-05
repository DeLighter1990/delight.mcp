<?php

namespace Delight\Mcp\Controller;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\ArgumentNullException;
use Bitrix\Main\ArgumentTypeException;
use Bitrix\Main\Context;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Error;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\Response;
use Bitrix\Main\SystemException;
use Bitrix\Main\Web\Json;
use Delight\Mcp\ActionFilter\Prefilters\CheckModuleIsActive;
use Delight\Mcp\ActionFilter\Prefilters\CheckToken;
use Delight\Mcp\Services\ResourceService;
use Delight\Mcp\Services\TokenService;
use Delight\Mcp\Services\ToolsService;
use Delight\Mcp\Services\LoggerService;

/*
 * Прокси-контроллер, обрабатывает запрос в формате JSON-RPC и передаёт в следующий контроллер
 */

class Rpc extends Controller
{
    /** @var int Время жизни SSE-подключения */
    private const SSE_MAX_LIFETIME_SECONDS = 60 * 5;

    public function __construct()
    {
        parent::__construct();
    }

    public function configureActions(): array
    {
        return [
            'handler' => [
                'prefilters' => [
                    new CheckModuleIsActive(),
                    new CheckToken()
                ]
            ],
        ];
    }

    /**
     * Основной обработчик запросов к MCP-серверу от AI-агента
     *
     * @throws ObjectPropertyException
     * @throws SystemException
     * @throws ArgumentException
     */
    public function handlerAction(): ?array
    {
        if ($this->getRequest()->getRequestMethod() === 'GET') {
            $this->handleSse();
        }

        $data = $this->getJsonRequestData();

        $token = TokenService::extractTokenFromHeader();
        $tokenIdentifier = (new TokenService())->getTokenIdentifier($token);
        (new LoggerService($tokenIdentifier))->log('In', $data);

        if ($data === null) {
            $this->addError(new Error('Parse error. Invalid JSON.', -32700));
            return null;
        }

        include(__DIR__ . '/../../install/version.php');
        /** @var array $arModuleVersion */

        $method = $data['method'] ?? '';
        $params = $data['params'] ?? [];

        switch ($method) {
            case 'initialize':
                // Mcp-Session-Id требуется для соединения с Codex
                Context::getCurrent()->getResponse()->addHeader(
                    'Mcp-Session-Id',
                    $this->generateSessionId($token)
                );
                return [
                    'serverInfo' => [
                        'name' => 'Delight Bitrix MCP',
                        'version' => $arModuleVersion['VERSION'],
                    ],
                    'protocolVersion' => '2025-06-18',
                    'capabilities' => [
                        'tools' => new \stdClass(),
                        'prompts' => new \stdClass(),
                        'resources' => new \stdClass(),
                    ]
                ];
            case 'notifications/initialized':
                /**
                 * @see https://github.com/DeLighter1990/delight.mcp/issues/1
                 *
                 * На этот запрос сервер не должен отвечать.
                 * Он служит для уведомления сервера клиентом о готовности работы.
                 * На других MCP он может быть использован для инициализации внутренних ресурсов/сервисов,
                 * в нашем случае он не используется, но должен быть обработан без ошибок и с кодом 202.
                 */
                return null;
            case 'tools/list':
                $toolsService = new ToolsService();
                return [
                    'tools' => $toolsService->getAvailableTools($token)
                ];
            case 'prompts/list':
                return [
                    'prompts' => []
                ];

            case 'resources/list':
                return [
                    'resources' => (new ResourceService())->getResourceList()
                ];

            case 'resources/templates/list':
                return [
                    'resourceTemplates' => [],
                ];

            case 'resources/read':
                return [
                    'contents' => [(new ResourceService())->getResource($params['uri'])]
                ];

            case 'tools/call':
                $toolResult = $this->forward(Tools::class, $params['name'], $params['arguments'] ?? []);
                if (is_string($toolResult)) {
                    return [
                        'content' => [[
                            'type' => 'text',
                            'text' => $toolResult
                        ]]
                    ];
                }
                if (is_object($toolResult)) {
                    return [
                        'content' => [[
                            'type' => 'text',
                            'text' => Json::encode($toolResult)
                        ]],
                        'structuredContent' => $toolResult
                    ];
                }

                $this->addError(new Error('Инструмент ' . $params['name'] . ' вернул ответ в несоответствующем формате: ' . gettype($toolResult), -32601));
                return null;
            default:
                $this->addError(new Error("Method not found: '$method'", -32601));
                return null;
        }
    }

    /**
     * Возвращает массив с данными запроса в формате JSON-RPC
     *
     * @return array|null
     */
    private function getJsonRequestData(): ?array
    {
        $rawInput = $this->getRequest()->getInput();
        if (!$rawInput) {
            return null;
        }

        try {
            return Json::decode($rawInput);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Финализация запроса. Приводит ответ к формату JSON-RPC
     *
     * @param Response $response Ответ сервера
     *
     * @return void
     * @throws ArgumentException
     * @throws ArgumentNullException
     * @throws ArgumentTypeException
     * @throws \JsonException
     * @throws SystemException
     */
    public function finalizeResponse(Response $response): void
    {
        $requestData = $this->getJsonRequestData();

        /**
         * По JSON-RPC любое сообщение без id является notification.
         * Сервер не должен возвращать на него JSON-RPC-ответ, включая уведомления, которые появятся в будущем.
         */
        $isNotification = is_array($requestData)
            && isset($requestData['method'])
            && !array_key_exists('id', $requestData);

        if ($isNotification) {
            $response->setStatus('202 Accepted');
            $response->setContent('');

            return;
        }

        parent::finalizeResponse($response);

        $id = $requestData['id'] ?? null;

        $errors = $this->getErrors();

        $content = $response->getContent();
        $jsonResult = null;
        if ($content) {
            /**
             * Ответ действия контроллера Bitrix ([status => ... , data => ...]).
             * Нельзя декодировать в массив, т.к. некоторые объекты потом могут не вернуть свой тип.
             */
            $jsonResult = json_decode($content, false, 512, JSON_THROW_ON_ERROR);
        }

        $response->addHeader('Content-Type', 'application/json; charset=utf-8');
        if (empty($errors) && is_object($jsonResult) && $jsonResult->status === 'success') {
            $finalResponse = [
                'jsonrpc' => '2.0',
                'result' => $jsonResult->data,
                'id' => $id,
            ];
            $response->setContent(Json::encode($finalResponse));
        } else {
            $errorMessages = array_map(fn($error) => $error->getMessage(), $errors);
            $finalResponse = [
                'jsonrpc' => '2.0',
                'error' => ['code' => -32603, 'message' => implode(', ', $errorMessages)],
                'id' => $id,
            ];
            $response->setContent(Json::encode($finalResponse));
        }

        $token = TokenService::extractTokenFromHeader();
        if ($token !== null) {
            $tokenIdentifier = (new TokenService())->getTokenIdentifier($token);
            (new LoggerService($tokenIdentifier))->log('Out', $finalResponse);
        }
    }

    /**
     * Обработчик SSE GET-соединения для Streamable HTTP transport.
     *
     * Codex открывает SSE-соединение сразу после initialize.
     * Мы не используем server-push, но соединение должно существовать —
     * иначе handshake не завершится и транспорт закроется.
     *
     * @return never
     * @throws ArgumentNullException
     */
    private function handleSse(): never
    {
        $token = TokenService::extractTokenFromHeader();
        $sessionId = $this->getRequest()->getHeader('Mcp-Session-Id');

        if (!$token || !$sessionId || !hash_equals($this->generateSessionId($token), $sessionId)) {
            $response = Context::getCurrent()->getResponse();
            $response->setStatus('401 Unauthorized');
            $response->flush('');
            exit;
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $response = Context::getCurrent()->getResponse();
        $response->setStatus('200 OK');
        $response->addHeader('Content-Type', 'text/event-stream');
        $response->addHeader('Cache-Control', 'no-cache');
        $response->addHeader('Connection', 'keep-alive');
        $response->addHeader('X-Accel-Buffering', 'no');
        $response->flush('');

        $request = Context::getCurrent()->getRequest();
        $protocol = $request->isHttps() ? 'https' : 'http';
        $host = $request->getHttpHost();
        $postUrl = $protocol . '://' . $host . $request->getRequestUri();
        echo "event: endpoint\n";
        echo "data: {$postUrl}\n\n";
        flush();

        set_time_limit(self::SSE_MAX_LIFETIME_SECONDS);

        while (!connection_aborted()) {
            echo ": ping\n\n";
            flush();
            sleep(15);
        }

        exit;
    }

    /**
     * Генерирует Mcp-Session-Id на основе токена.
     * Сессия не хранится — она детерминирована: один токен = один session id.
     * Используется только для корректной установки соединения.
     *
     * @param string $token
     * @return string
     */
    private function generateSessionId(string $token): string
    {
        return hash('sha256', $token);
    }
}
