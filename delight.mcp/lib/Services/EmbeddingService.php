<?php

namespace Delight\Mcp\Services;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Json;

/**
 * Класс для работы с микросервисом генерации embeddings
 */
class EmbeddingService
{
    /** @var string URL генерации эмбеддинга. Можно изменить по событию OnBeforeGenerateEmbedding. */
    private const GENERATE_EMBEDDINGS_ENDPOINT = 'http://127.0.0.1:8000/generate_embedding';

    /** @var int Таймаут запроса в секундах */
    private int $timeout;

    public function __construct(int $timeout = 30)
    {
        $this->timeout = $timeout;
    }

    /**
     * Генерирует embedding для текста
     *
     * @param string $text Текст для генерации embedding
     * @return array float[]
     * @throws \Exception
     */
    public function generateEmbedding(string $text): array
    {
        if (empty(trim($text))) {
            throw new ArgumentException('Текст не может быть пустым');
        }

        $requestData = [
            'url' => self::GENERATE_EMBEDDINGS_ENDPOINT,
            'data' => Json::encode(['text' => $text]),
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'timeout' => $this->timeout,
            'waitResponse' => true
        ];

        $event = new Event('delight.mcp', 'OnBeforeGenerateEmbedding', $requestData);
        $event->send();

        if ($event->getResults()) {
            foreach ($event->getResults() as $eventResult) {
                if ($eventResult->getType() === EventResult::SUCCESS) {
                    $requestData = $eventResult->getParameters();
                }
            }
        }

        $httpClient = new HttpClient($requestData);

        $response = $httpClient->post(
            $requestData['url'],
            $requestData['data']
        );

        $eventParameters = ['response' => $response];
        $event = new Event('delight.mcp', 'OnAfterGenerateEmbedding', $eventParameters);
        $event->send();

        // Проверяем статус ответа
        $status = $httpClient->getStatus();

        if ($status !== 200) {
            $error = $response ?: 'Неизвестная ошибка';
            throw new \Exception("HTTP ошибка {$status}: {$error}");
        }

        if ($event->getResults()) {
            foreach ($event->getResults() as $eventResult) {
                if ($eventResult->getType() === EventResult::SUCCESS) {
                    $eventParameters = $eventResult->getParameters();
                }
            }
        }

        $response = $eventParameters['response'];
        if ($response === false) {
            $errors = $httpClient->getError();
            throw new \Exception("Ошибка запроса: " . implode(', ', $errors));
        }

        $result = Json::decode($response);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception("Ошибка декодирования JSON: " . json_last_error_msg());
        }

        return $result;
    }

    /**
     * Проверяет доступность сервиса
     *
     * @return bool
     */
    public function isAvailable(): bool
    {
        try {
            $this->generateEmbedding('test');
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}
