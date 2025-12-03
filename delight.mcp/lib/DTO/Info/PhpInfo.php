<?php

namespace Delight\Mcp\DTO\Info;

class PhpInfo
{
    /**
     * @param string $version Версия PHP
     * @param string[] $extensions Подключенные расширения
     * @param string $memoryLimit Лимит оперативной памяти
     * @param string $maxExecutionTime Максимальное время исполнения PHP-скриптов
     */
    public function __construct(
        readonly public string $version,
        readonly public array  $extensions,
        readonly public string $memoryLimit,
        readonly public string $maxExecutionTime,
    )
    {
    }
}
