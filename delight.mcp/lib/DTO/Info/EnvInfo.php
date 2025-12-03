<?php

namespace Delight\Mcp\DTO\Info;

class EnvInfo
{
    /**
     * @param SystemInfo $system Информация об операционной системе
     * @param BitrixInfo $bitrix Информация о Битриксе
     * @param PhpInfo $php Информация о PHP
     * @param DatabaseInfo $database Информация о базе данных
     */
    public function __construct(
        readonly public SystemInfo   $system,
        readonly public BitrixInfo   $bitrix,
        readonly public PhpInfo      $php,
        readonly public DatabaseInfo $database
    )
    {
    }
}
