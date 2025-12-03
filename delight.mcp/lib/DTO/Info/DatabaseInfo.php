<?php

namespace Delight\Mcp\DTO\Info;

class DatabaseInfo
{
    /**
     * @param string $type Тип базы данных
     * @param string $version Версия сервера баз данных
     * @param string $host Хост подключения
     * @param string $name Имя базы данных
     */
    public function __construct(
        readonly public string $type,
        readonly public string $version,
        readonly public string $host,
        readonly public string $name
    )
    {
    }
}
