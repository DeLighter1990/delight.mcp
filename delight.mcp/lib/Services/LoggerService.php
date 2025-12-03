<?php

namespace Delight\Mcp\Services;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Web\Json;
use Psr\Log\AbstractLogger;
use Bitrix\Main\IO\Directory;
use Bitrix\Main\IO\File;
use Bitrix\Main\Config\Option;

class LoggerService extends AbstractLogger
{
    /**
     * @var string Директория для хранения лог-файлов.
     */
    private string $logDir;

    /**
     * @var bool Флаг, указывающий, включено ли логирование в настройках модуля.
     */
    private bool $isLoggingEnabled;

    /**
     * @var string|null Идентификатор токена, если он был передан.
     */
    private ?string $tokenIdentifier;

    public function __construct(?string $tokenIdentifier = null)
    {
        $this->isLoggingEnabled = Option::get('delight.mcp', 'log_enabled', 'N') === 'Y';
        $this->tokenIdentifier = $tokenIdentifier;

        if ($this->isLoggingEnabled) {
            $this->logDir = dirname(__DIR__, 2) . '/data/logs/';
        }
    }

    /**
     * Записывает лог с произвольным уровнем.
     *
     * @param mixed $level Уровень логирования (например, 'info', 'error').
     * @param mixed $message Сообщение для лога. Может быть строкой, объектом с __toString, массивом или другим объектом.
     * @param array $context Контекстные данные для интерполяции в сообщение.
     *
     * @return void
     * @throws ArgumentException
     */
    public function log($level, $message, array $context = []): void
    {
        if (!$this->isLoggingEnabled) {
            return;
        }

        if (!Directory::isDirectoryExists($this->logDir)) {
            Directory::createDirectory($this->logDir);
        }

        if (is_array($message) || (is_object($message) && !method_exists($message, '__toString'))) {
            $message = Json::encode($message, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }

        $logFile = $this->logDir . date('Y-m-d') . '.log';
        $interpolatedMessage = $this->interpolate((string)$message, $context);

        $tokenPart = $this->tokenIdentifier ? '[' . $this->tokenIdentifier . '] ' : '';

        $formattedMessage = sprintf(
            '[%s] [%s] %s%s' . PHP_EOL,
            date('Y-m-d H:i:s'),
            strtoupper($level),
            $tokenPart,
            $interpolatedMessage
        );

        File::putFileContents($logFile, $formattedMessage, File::APPEND);
    }

    /**
     * Интерполирует контекстные значения в плейсхолдеры сообщения.
     *
     * @param string $message Исходное сообщение с плейсхолдерами.
     * @param array $context Массив с данными для замены.
     * @return string Сообщение с подставленными значениями.
     * @throws ArgumentException
     */
    private function interpolate(string $message, array $context = []): string
    {
        if (strpos($message, '{') === false) {
            return $message;
        }

        $replace = [];
        foreach ($context as $key => $val) {
            $placeholder = '{' . $key . '}';
            if (strpos($message, $placeholder) === false) {
                continue;
            }

            if (is_string($val) || is_numeric($val)) {
                $replace[$placeholder] = $val;
            } elseif (is_bool($val)) {
                $replace[$placeholder] = $val ? 'true' : 'false';
            } elseif (is_null($val)) {
                $replace[$placeholder] = 'null';
            } elseif (is_object($val) && method_exists($val, '__toString')) {
                $replace[$placeholder] = (string)$val;
            } elseif (is_array($val) || is_object($val)) {
                $replace[$placeholder] = Json::encode($val, JSON_UNESCAPED_UNICODE);
            }
        }

        return strtr($message, $replace);
    }
}
