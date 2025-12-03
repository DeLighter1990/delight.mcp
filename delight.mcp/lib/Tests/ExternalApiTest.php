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
 * Класс для запуска тестов инструментов Delight MCP RPC.
 * Предназначен для вызова из контроллера для проверки работоспособности API.
 */
class ExternalApiTest
{
    private string $token;
    private string $rpcUrl;

    /**
     * @param string $token Токен для доступа к API.
     */
    public function __construct(string $token)
    {
        $this->token = $token;
    }

    /**
     * Запускает все тесты.
     *
     * @return TestResultDto Результаты тестирования.
     * @throws ArgumentException
     * @throws \JsonException
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

        $this->rpcUrl = $protocol . '://' . $host . '/bitrix/services/main/ajax.php?action=delight:mcp.Rpc.handler';

        $response = $this->sendRpcRequest('tools/list');

        if (!isset($response['result']['tools'])) {
            $errorMsg = $response['error']['message'] ?? 'Не удалось получить список инструментов';
            return new TestResultDto(
                new TestSummaryDto(0, 0, 0),
                [],
                "Ошибка при получении списка инструментов: $errorMsg"
            );
        }

        $allTools = $response['result']['tools'];

        $passed = 0;
        $failed = 0;
        $details = [];
        $testContext = [];

        foreach ($allTools as $tool) {
            $this->testTool($tool, $testContext, $passed, $failed, $details);
        }

        $summary = new TestSummaryDto($passed, $failed, count($allTools));
        return new TestResultDto($summary, $details);
    }

    /**
     * Тестирует один инструмент.
     *
     * @param array $tool
     * @param array $testContext
     * @param int $passed
     * @param int $failed
     * @param TestDetailsItemDto[] $details
     * @throws ArgumentException
     * @throws \JsonException
     */
    private function testTool(array $tool, array &$testContext, int &$passed, int &$failed, array &$details): void
    {
        $arguments = $this->getSampleArguments($tool);

        // Обработка зависимого теста
        if ($tool['name'] === 'liveApiGetModuleFunctionCode' && isset($testContext['liveApiFindFunction'])) {
            $foundFunc = $testContext['liveApiFindFunction'];
            $arguments['functionName'] = $foundFunc['name'];
            $arguments['moduleId'] = $foundFunc['moduleId'];
        }

        $response = $this->sendRpcRequest('tools/call', ['name' => $tool['name'], 'arguments' => $arguments]);

        if (!$response) {
            $this->addResult($tool['name'], false, 'Нет ответа от сервера.', [], $passed, $failed, $details);
            return;
        }

        if (isset($response['error'])) {
            $errorMsg = $response['error']['message'] ?? 'Неизвестная ошибка';
            $this->addResult($tool['name'], false, "Ошибка RPC: $errorMsg", [], $passed, $failed, $details, null, $response);
            return;
        }

        if (!empty($tool['outputSchema'])) {
            $resultData = $response['result']['structuredContent'] ?? null;
            if (is_null($resultData)) {
                $this->addResult(
                    $tool['name'],
                    false,
                    "В ответе отсутствует 'structuredContent'.",
                    [],
                    $passed,
                    $failed,
                    $details,
                    $tool['outputSchema'],
                    $response
                );
                return;
            }

            $resultDataAsObject = json_decode(Json::encode($resultData), false, 512, JSON_THROW_ON_ERROR);
            $schemaAsObject = json_decode(Json::encode($tool['outputSchema']), false, 512, JSON_THROW_ON_ERROR);

            $validationErrors = $this->validateSchema($resultDataAsObject, $schemaAsObject);
            if (empty($validationErrors)) {
                $this->addResult(
                    $tool['name'],
                    true,
                    'Схема ответа успешно валидирована.',
                    [],
                    $passed,
                    $failed,
                    $details
                );
                if ($tool['name'] === 'liveApiFindFunction' && !empty($resultDataAsObject->items)) {
                    $testContext['liveApiFindFunction'] = (array)($resultDataAsObject->items[0]);
                }
            } else {
                $this->addResult($tool['name'], false, 'Ошибка валидации схемы.', $validationErrors, $passed, $failed, $details, $tool['outputSchema'], $resultData);
            }
        } else {
            if (isset($response['result']['content'][0]['text'])) {
                $this->addResult($tool['name'], true, 'Получен текстовый ответ.', [], $passed, $failed, $details);
            } else {
                $this->addResult(
                    $tool['name'],
                    false,
                    "В ответе отсутствует 'content[0].text'.",
                    [],
                    $passed,
                    $failed,
                    $details,
                    null,
                    $response
                );
            }
        }
    }

    /**
     * @param string $toolName
     * @param bool $isSuccess
     * @param string $message
     * @param array $validationDetails
     * @param int $passed
     * @param int $failed
     * @param array<TestDetailsItemDto> $resultDetails
     * @param array|null $schema
     * @param mixed|null $response
     */
    private function addResult(
        string $toolName,
        bool   $isSuccess,
        string $message,
        array  $validationDetails,
        int    &$passed,
        int    &$failed,
        array  &$resultDetails,
        ?array $schema = null,
        mixed  $response = null
    ): void
    {
        if ($isSuccess) {
            $passed++;
        } else {
            $failed++;
        }

        $resultDetails[] = new TestDetailsItemDto(
            $toolName,
            $isSuccess ? 'PASS' : 'FAIL',
            $message,
            $validationDetails,
            $schema,
            $response
        );
    }

    private function sendRpcRequest(string $method, array $params = []): ?array
    {
        $httpClient = new HttpClient();
        $httpClient->setHeader('Authorization', 'Bearer ' . $this->token);
        $httpClient->setHeader('Content-Type', 'application/json');

        try {
            $requestBody = Json::encode([
                'jsonrpc' => '2.0',
                'method' => $method,
                'params' => $params,
                'id' => uniqid('', true),
            ]);
            $responseBody = $httpClient->post($this->rpcUrl, $requestBody);
            if ($httpClient->getStatus() !== 200) {
                return null;
            }
            return Json::decode($responseBody);
        } catch (Exception) {
            return null;
        }
    }

    private function getSampleArguments(array $tool): array
    {
        return match ($tool['name']) {
            'eval' => ['code' => 'echo "test";'],
            'exec' => ['command' => 'echo "test"'],
            'sql' => ['query' => 'SELECT 1 as TEST'],
            'searchBxDocs' => ['query' => 'Какая должна быть структура у компонента?'],
            'liveApiGetModuleFunctions' => ['moduleId' => 'main'],
            'liveApiGetModuleEvents' => ['moduleId' => 'main'],
            'liveApiFindFunction' => ['functionName' => 'GetList', 'moduleId' => 'iblock'],
            'liveApiGetModuleFunctionCode' => ['functionName' => 'CIBlockElement::GetList', 'moduleId' => 'iblock'],
            'liveApiFindFunctionByReflection' => ['functionName' => 'Bitrix\Main\Application::getConnection'],
            default => [],
        };
    }

    /**
     * Валидация схемы данных инструмента
     *
     * @param mixed $data Ответ инструмента
     * @param object $schema Схема данных, которой должен соответствовать ответ инструмента
     * @param string $path
     * @return array
     */
    private function validateSchema(mixed $data, object $schema, string $path = 'root'): array
    {
        $errors = [];
        $dataType = gettype($data);

        if (!isset($schema->type)) {
            return [];
        }

        $expectedSchemaType = $schema->type;

        $isValidType = match ($expectedSchemaType) {
            'string' => is_string($data),
            'integer' => is_int($data),
            'number' => is_numeric($data),
            'boolean' => is_bool($data),
            'array' => is_array($data),
            'object', 'resource' => is_object($data) || is_array($data),
            default => false,
        };

        if (!$isValidType) {
            $errors[] = "Поле '$path': несоответствие типа. Ожидался '{$expectedSchemaType}', получен '$dataType'.";
            return $errors;
        }

        if ($expectedSchemaType === 'object' || $expectedSchemaType === 'resource') {
            $dataArr = (array)$data;
            foreach (($schema->required ?? []) as $requiredField) {
                if (!array_key_exists($requiredField, $dataArr)) {
                    $errors[] = "Поле '$path.$requiredField': отсутствует обязательное поле.";
                }
            }
            foreach (($schema->properties ?? []) as $propName => $propSchema) {
                if (array_key_exists($propName, $dataArr)) {
                    $errors = array_merge($errors, $this->validateSchema($dataArr[$propName], $propSchema, "$path.$propName"));
                }
            }
        }

        if ($expectedSchemaType === 'array' && isset($schema->items) && is_array($data)) {
            foreach ($data as $index => $item) {
                $errors = array_merge($errors, $this->validateSchema($item, $schema->items, "$path[$index]"));
            }
        }

        return $errors;
    }
}
