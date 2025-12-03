<?php

namespace Delight\Mcp\Services;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Delight\Mcp\DTO\Tool\InputSchema;
use Delight\Mcp\DTO\Tool\OutputSchema;
use Delight\Mcp\DTO\Tool\PropertyItemsSchema;
use Delight\Mcp\DTO\Tool\PropertySchema;
use Delight\Mcp\DTO\Tool\ToolListItem;
use Delight\Mcp\Helpers\Utils;

/**
 * Вспомогательный сервис для работы с методами tools/
 */
class ToolsService
{
    /** @var ToolListItem[] Список инструментов */
    private array $tools;

    public function __construct()
    {
        $this->tools = [
            new ToolListItem(
                name: 'envInfo',
                description: 'Возвращает информацию об окружении',
                inputSchema: new InputSchema(
                    type: 'object',
                    properties: [],
                    required: [],
                ),
                outputSchema: new OutputSchema(
                    type: 'object',
                    properties: [
                        'system' => new PropertySchema(
                            type: 'object',
                            description: 'Информация о системе',
                            properties: [
                                'os' => new PropertySchema(
                                    type: 'string',
                                    description: 'Операционная система'
                                ),
                                'hostname' => new PropertySchema(
                                    type: 'string',
                                    description: 'Имя хоста'
                                ),
                                'user' => new PropertySchema(
                                    type: 'string',
                                    description: 'Имя текущего пользователя'
                                ),
                                'totalDiskSpace' => new PropertySchema(
                                    type: 'integer',
                                    description: 'Объем диска в байтах'
                                ),
                                'freeDiskSpace' => new PropertySchema(
                                    type: 'integer',
                                    description: 'Объем свободного места на диске в байтах'
                                )
                            ]
                        ),
                        'bitrix' => new PropertySchema(
                            type: 'object',
                            description: 'Информация о Битриксе',
                            properties: [
                                'edition' => new PropertySchema(
                                    type: 'string',
                                    description: 'Редакция'
                                ),
                                'version' => new PropertySchema(
                                    type: 'string',
                                    description: 'Версия главного модуля'
                                ),
                                'modules' => new PropertySchema(
                                    type: 'array',
                                    description: 'Установленные модули',
                                    items: new PropertyItemsSchema(
                                        type: 'object',
                                        properties: [
                                            'code' => new PropertySchema(
                                                type: 'string',
                                                description: 'Код модуля'
                                            ),
                                            'name' => new PropertySchema(
                                                type: 'string',
                                                description: 'Название модуля'
                                            ),
                                            'version' => new PropertySchema(
                                                type: 'string',
                                                description: 'Версия модуля'
                                            )
                                        ]
                                    )
                                )
                            ]
                        ),
                        'php' => new PropertySchema(
                            type: 'object',
                            description: 'Информация о PHP',
                            properties: [
                                'version' => new PropertySchema(
                                    type: 'string',
                                    description: 'Версия PHP'
                                ),
                                'extensions' => new PropertySchema(
                                    type: 'array',
                                    description: 'Подключенные расширения',
                                    items: new PropertyItemsSchema(
                                        type: 'string'
                                    )
                                ),
                                'memoryLimit' => new PropertySchema(
                                    type: 'string',
                                    description: 'Ограничение по объему доступной оперативной памяти'
                                ),
                                'maxExecutionTime' => new PropertySchema(
                                    type: 'string',
                                    description: 'Максимальное время исполнения PHP-скриптов'
                                )
                            ]
                        ),
                        'database' => new PropertySchema(
                            type: 'object',
                            description: 'Информация о базе данных',
                            properties: [
                                'type' => new PropertySchema(
                                    type: 'string',
                                    description: 'Тип базы данных'
                                ),
                                'version' => new PropertySchema(
                                    type: 'string',
                                    description: 'Версия сервера баз данных'
                                ),
                                'host' => new PropertySchema(
                                    type: 'string',
                                    description: 'Хост подключения'
                                ),
                                'name' => new PropertySchema(
                                    type: 'string',
                                    description: 'Имя базы данных'
                                )
                            ]
                        )
                    ],
                    required: ['system', 'bitrix', 'php', 'database']
                )
            ),
            new ToolListItem(
                name: 'exec',
                description: 'Исполняет Shell-команды и возвращает консольный вывод',
                inputSchema: new InputSchema(
                    type: 'object',
                    properties: [
                        'command' => new PropertySchema(
                            type: 'string',
                            description: 'Команды'
                        )
                    ],
                    required: ['command'],
                ),
            ),
            new ToolListItem(
                name: 'eval',
                description: 'Исполняет PHP-код на сервере и возвращает экранный вывод',
                inputSchema: new InputSchema(
                    type: 'object',
                    properties: [
                        'code' => new PropertySchema(
                            type: 'string',
                            description: 'PHP-код'
                        )
                    ],
                    required: ['code'],
                ),
            ),
            new ToolListItem(
                name: 'sql',
                description: 'Исполняет SQL-запрос на сервере и возвращает результат',
                inputSchema: new InputSchema(
                    type: 'object',
                    properties: [
                        'query' => new PropertySchema(
                            type: 'string',
                            description: 'SQL-запрос'
                        )
                    ],
                    required: ['query'],
                ),
                outputSchema: new OutputSchema(
                    type: 'object',
                    properties: [
                        'columns' => new PropertySchema(
                            type: 'array',
                            description: 'Названия столбцов',
                            items: new PropertyItemsSchema(
                                type: 'string'
                            )
                        ),
                        'rows' => new PropertySchema(
                            type: 'array',
                            description: 'Строки',
                            items: new PropertyItemsSchema(
                                type: 'array',
                                items: new PropertyItemsSchema(
                                    type: 'string'
                                )
                            )
                        ),
                        'rowCount' => new PropertySchema(
                            type: 'integer',
                            description: 'Количество отданных строк'
                        ),
                        'query' => new PropertySchema(
                            type: 'string',
                            description: 'Исходный запрос'
                        )
                    ],
                    required: ['columns', 'rows', 'rowCount', 'query'],
                ),
            ),
            new ToolListItem(
                name: 'searchBxDocs',
                description: 'Поиск по документации Bitrix Framework',
                inputSchema: new InputSchema(
                    type: 'object',
                    properties: [
                        'query' => new PropertySchema(
                            type: 'string',
                            description: 'Поисковый запрос'
                        )
                    ],
                    required: ['query'],
                ),
                outputSchema: new OutputSchema(
                    type: 'object',
                    properties: [
                        'results' => new PropertySchema(
                            type: 'array',
                            description: 'Результаты поиска - найденные релевантные части документации',
                            items: new PropertyItemsSchema(
                                type: 'resource'
                            )
                        )
                    ],
                    required: ['results'],
                ),
            ),
            new ToolListItem(
                name: 'liveApiGetModuleFunctions',
                description: 'Возвращает информацию о функциях установленного модуля',
                inputSchema: new InputSchema(
                    type: 'object',
                    properties: [
                        'moduleId' => new PropertySchema(
                            type: 'string',
                            description: 'Идентификатор (код) модуля'
                        ),
                        'cursor' => new PropertySchema(
                            type: 'string',
                            description: 'Cursor-based пагинация. Передавать только значения, пришедшие из nextCursor.'
                        )
                    ],
                    required: ['moduleId'],
                ),
                outputSchema: new OutputSchema(
                    type: 'object',
                    properties: [
                        'id' => new PropertySchema(
                            type: 'string',
                            description: 'Идентификатор (код) модуля'
                        ),
                        'version' => new PropertySchema(
                            type: 'string',
                            description: 'Версия модуля'
                        ),
                        'functions' => new PropertySchema(
                            type: 'array',
                            description: 'Информация о функциях модуля',
                            items: new PropertyItemsSchema(
                                type: 'object',
                                description: 'Информация о функции',
                                properties: [
                                    'name' => new PropertySchema(
                                        type: 'string',
                                        description: 'Имя функции'
                                    ),
                                    'source' => new PropertySchema(
                                        type: 'object',
                                        description: 'Информация о том, где объявлена функция',
                                        properties: [
                                            'file' => new PropertySchema(
                                                type: 'string',
                                                description: 'Путь к файлу, где объявлена функция'
                                            ),
                                            'line' => new PropertySchema(
                                                type: 'integer',
                                                description: 'Номер строки, где объявлена функция'
                                            )
                                        ]
                                    ),
                                    'args' => new PropertySchema(
                                        type: 'array',
                                        description: 'Аргументы функции',
                                        items: new PropertyItemsSchema(
                                            type: 'object',
                                            properties: [
                                                'type' => new PropertySchema(
                                                    type: 'string',
                                                    description: 'Тип аргумента'
                                                ),
                                                'name' => new PropertySchema(
                                                    type: 'string',
                                                    description: 'Имя аргумента'
                                                ),
                                                'defaultValue' => new PropertySchema(
                                                    type: 'string',
                                                    description: 'Значение по умолчанию'
                                                )
                                            ]
                                        )
                                    )
                                ]
                            )
                        ),
                        'nextCursor' => new PropertySchema(
                            type: 'string',
                            description: 'Пагинация'
                        )
                    ],
                    required: ['id', 'version', 'functions']
                ),
            ),
            new ToolListItem(
                name: 'liveApiGetModuleEvents',
                description: 'Возвращает информацию о событиях установленного модуля',
                inputSchema: new InputSchema(
                    type: 'object',
                    properties: [
                        'moduleId' => new PropertySchema(
                            type: 'string',
                            description: 'Идентификатор (код) модуля'
                        )
                    ],
                    required: ['moduleId'],
                ),
                outputSchema: new OutputSchema(
                    type: 'object',
                    properties: [
                        'id' => new PropertySchema(
                            type: 'string',
                            description: 'Идентификатор (код) модуля'
                        ),
                        'version' => new PropertySchema(
                            type: 'string',
                            description: 'Версия модуля'
                        ),
                        'events' => new PropertySchema(
                            type: 'array',
                            description: 'События модуля',
                            items: new PropertyItemsSchema(
                                type: 'object',
                                properties: [
                                    'name' => new PropertySchema(
                                        type: 'string',
                                        description: 'Имя события'
                                    ),
                                    'source' => new PropertySchema(
                                        type: 'object',
                                        description: 'Информация о том, где объявлена функция',
                                        properties: [
                                            'file' => new PropertySchema(
                                                type: 'string',
                                                description: 'Путь к файлу, где объявлена функция'
                                            ),
                                            'line' => new PropertySchema(
                                                type: 'integer',
                                                description: 'Номер строки, где объявлена функция'
                                            )
                                        ]
                                    ),
                                    'firedInFunc' => new PropertySchema(
                                        type: 'string',
                                        description: 'Функция, в которой вызывается событие'
                                    )
                                ]
                            )
                        )
                    ],
                    required: ['id', 'version', 'events'],
                ),
            ),
            new ToolListItem(
                name: 'liveApiFindFunction',
                description: 'Ищет PHP-функцию в базе данных среди проиндексированных функций модулей',
                inputSchema: new InputSchema(
                    type: 'object',
                    properties: [
                        'functionName' => new PropertySchema(
                            type: 'string',
                            description: 'Имя PHP-функции'
                        ),
                        'moduleId' => new PropertySchema(
                            type: 'string',
                            description: 'Идентификатор (код) модуля, в котором нужно найти функцию'
                        ),
                        'strictSearch' => new PropertySchema(
                            type: 'boolean',
                            description: 'Строгий поиск'
                        )
                    ],
                    required: ['functionName'],
                ),
            ),
            new ToolListItem(
                name: 'liveApiGetModuleFunctionCode',
                description: 'Возвращает код PHP-функции с описанием PHPDoc (если есть). Выборка происходит только по проиндексированным функциям модулей.',
                inputSchema: new InputSchema(
                    type: 'object',
                    properties: [
                        'functionName' => new PropertySchema(
                            type: 'string',
                            description: 'Имя PHP-функции. Должно совпадать с именем функции из выдачи инструмента liveApiGetModuleFunctions или liveApiFindFunction.'
                        ),
                        'moduleId' => new PropertySchema(
                            type: 'string',
                            description: 'Идентификатор (код) модуля, в котором описана функция'
                        )
                    ],
                    required: ['functionName', 'moduleId'],
                ),
            ),
            new ToolListItem(
                name: 'liveApiFindFunctionByReflection',
                description: 'Ищет PHP-функцию в проекте с помощью Reflection',
                inputSchema: new InputSchema(
                    type: 'object',
                    properties: [
                        'functionName' => new PropertySchema(
                            type: 'string',
                            description: 'Имя функции или метода для поиска (например, myFunction или MyClass::myMethod).'
                        ),
                        'returnCode' => new PropertySchema(
                            type: 'boolean',
                            description: 'Возвращать код функции? Для сценариев, когда нужно просто убедиться в наличии функции, её код может быть избыточен и будет тратить лишний контекст. (по умолчанию true)'
                        )
                    ],
                    required: ['functionName'],
                ),
                outputSchema: new OutputSchema(
                    type: 'object',
                    properties: [
                        'items' => new PropertySchema(
                            type: 'array',
                            description: 'Найденные функции',
                            items: new PropertyItemsSchema(
                                type: 'object',
                                properties: [
                                    'name' => new PropertySchema(
                                        type: 'string',
                                        description: 'Полное имя функции'
                                    ),
                                    'code' => new PropertySchema(
                                        type: 'string',
                                        description: 'Код функции с описанием PHPDoc',
                                    )
                                ]
                            )
                        ),
                        'searchPhrase' => new PropertySchema(
                            type: 'string',
                            description: 'Исходный поисковый запрос'
                        )
                    ],
                    required: ['items', 'searchPhrase']
                )
            ),
        ];
    }

    /**
     * Возвращает список доступных инструментов в необходимом формате.
     *
     * @param string $token Токен
     * @return ToolListItem[] Список доступных инструментов для указанного токена
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    public function getAvailableTools(string $token): array
    {
        $availableTools = $this->tools;
        $grants = (new TokenService())->validateToken($token);

        foreach ($availableTools as $key => &$tool) {
            $grantName = Utils::toScreamingSnakeCase($tool->name);
            // $grantName должен начинаться с имени элемента в TokenGrants
            if (!array_filter($grants, fn($g) => str_starts_with($grantName, $g))) {
                unset($availableTools[$key]);
            }
        }
        unset($tool);

        // Для searchBxDocs нужно дополнительно проверить сервис генерации эмбеддингов
        $availableTools = array_values(array_filter($availableTools, function ($tool) {
            if (isset($tool->name) && $tool->name === 'searchBxDocs') {
                $embeddingService = new EmbeddingService();
                if (!$embeddingService->isAvailable()) {
                    return false;
                }
            }
            return true;
        }));

        return $availableTools;
    }
}
