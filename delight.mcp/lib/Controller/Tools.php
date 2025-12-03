<?php

namespace Delight\Mcp\Controller;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Db\SqlQueryException;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\IO\FileNotFoundException;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Main\Web\Json;
use Delight\Mcp\ActionFilter\Prefilters\CheckModuleIsActive;
use Delight\Mcp\ActionFilter\Prefilters\CheckToken;
use Delight\Mcp\DTO\BxDocsSearchResults;
use Delight\Mcp\DTO\Info\EnvInfo;
use Delight\Mcp\DTO\LiveApi\SearchItemsDTO;
use Delight\Mcp\DTO\LiveApi\ModuleEventsDTO;
use Delight\Mcp\DTO\LiveApi\ModuleFunctionsDTO;
use Delight\Mcp\DTO\SqlResult;
use Delight\Mcp\Enum\TokenGrants;
use Delight\Mcp\Helpers\Pagination;
use Delight\Mcp\Services\CommandService;
use Delight\Mcp\Services\BxDocsService;
use Delight\Mcp\Services\EnvInfoService;
use Delight\Mcp\Services\LiveApiService;

/**
 * Основной обработчик запросов к инструментам от AI-агентов,
 * куда приходят запросы после их разбора в прокси-контроллере Rpc.
 */
class Tools extends Controller
{
    public function configureActions(): array
    {
        return [
            'envInfo' => [
                'prefilters' => [
                    new CheckModuleIsActive(),
                    new CheckToken(TokenGrants::ENV_INFO)
                ]
            ],
            'searchBxDocs' => [
                'prefilters' => [
                    new CheckModuleIsActive(),
                    new CheckToken(TokenGrants::SEARCH_BX_DOCS)
                ]
            ],
            'eval' => [
                'prefilters' => [
                    new CheckModuleIsActive(),
                    new CheckToken(TokenGrants::EVAL)
                ]
            ],
            'exec' => [
                'prefilters' => [
                    new CheckModuleIsActive(),
                    new CheckToken(TokenGrants::EXEC)
                ]
            ],
            'sql' => [
                'prefilters' => [
                    new CheckModuleIsActive(),
                    new CheckToken(TokenGrants::SQL)
                ]
            ],
            'liveApiGetModuleFunctions' => [
                'prefilters' => [
                    new CheckModuleIsActive(),
                    new CheckToken(TokenGrants::LIVE_API)
                ]
            ],
            'liveApiGetModuleEvents' => [
                'prefilters' => [
                    new CheckModuleIsActive(),
                    new CheckToken(TokenGrants::LIVE_API)
                ]
            ],
            'liveApiFindFunction' => [
                'prefilters' => [
                    new CheckModuleIsActive(),
                    new CheckToken(TokenGrants::LIVE_API)
                ]
            ],
            'liveApiGetModuleFunctionCode' => [
                'prefilters' => [
                    new CheckModuleIsActive(),
                    new CheckToken(TokenGrants::LIVE_API)
                ]
            ],
            'liveApiFindFunctionByReflection' => [
                'prefilters' => [
                    new CheckModuleIsActive(),
                    new CheckToken(TokenGrants::LIVE_API)
                ]
            ]
        ];
    }

    /**
     * Возвращает информацию об окружении
     *
     * @return EnvInfo
     */
    public function envInfoAction(): EnvInfo
    {
        return (new EnvInfoService())->getInfo();
    }

    /**
     * Исполняет PHP-код на сервере и возвращает экранный вывод
     *
     * @param string $code PHP-код
     * @return string Экранный вывод
     */
    public function evalAction(string $code): string
    {
        return (new CommandService())->eval($code);
    }

    /**
     * Исполняет системную команду на сервере и возвращает вывод консоли
     *
     * @param string $command Команда для выполнения в shell
     * @return string Вывод консоли
     */
    public function execAction(string $command): string
    {
        return (new CommandService())->exec($command);
    }

    /**
     * Исполняет SQL-запрос на сервере и возвращает результат
     *
     * @param string $query SQL-запрос
     * @return SqlResult Результат исполнения запроса
     * @throws SqlQueryException
     */
    public function sqlAction(string $query): SqlResult
    {
        return (new CommandService())->sql($query);
    }

    /**
     * Поиск по документации Bitrix Framework
     *
     * @param string $query Поисковый запрос
     * @return BxDocsSearchResults Результаты поиска
     * @throws \Exception
     */
    public function searchBxDocsAction(string $query): BxDocsSearchResults
    {
        return (new BxDocsService())->search($query);
    }

    /**
     * Возвращает информацию о функциях модуля
     *
     * @param string $moduleId Идентификатор (код) модуля
     * @param string $cursor Пагинация cursor-based
     * @return ModuleFunctionsDTO
     * @throws ArgumentException
     * @throws ObjectNotFoundException
     * @throws SystemException
     */
    public function liveApiGetModuleFunctionsAction(string $moduleId, string $cursor = ''): ModuleFunctionsDTO
    {
        if (!empty($cursor)) {
            $pagination = Pagination::getFromCursor($cursor);
            return (new LiveApiService())->getModuleFunctionsInfo($moduleId, $pagination['offset'], $pagination['limit']);
        }

        return (new LiveApiService())->getModuleFunctionsInfo($moduleId);
    }

    /**
     * Возвращает информацию о событиях модуля
     *
     * @param string $moduleId Идентификатор (код) модуля
     * @return ModuleEventsDTO
     * @throws ArgumentException
     * @throws ObjectNotFoundException
     * @throws SystemException
     */
    public function liveApiGetModuleEventsAction(string $moduleId): ModuleEventsDTO
    {
        return (new LiveApiService())->getModuleEventsInfo($moduleId);
    }

    /**
     * Возвращает код функции вместе с её описанием PHPDoc (если есть).
     * Если не находит - выбрасывает исключение.
     *
     * @param string $functionName Имя функции. Можно с классом в формате ClassName::FunctionName
     * @param string $moduleId ID модуля, в котором нужно найти функцию
     * @return string Код функции
     * @throws ObjectNotFoundException
     * @throws SystemException
     * @throws FileNotFoundException
     */
    public function liveApiGetModuleFunctionCodeAction(string $functionName, string $moduleId): string
    {
        return (new LiveApiService())->getFunctionFromIndex($functionName, $moduleId);
    }

    /**
     * Ищет функции в таблице Live API
     *
     * @param string $functionName Имя функции
     * @param string|null $moduleId Идентификатор (код) модуля
     * @param bool $strictSearch Строгий поиск?
     * @return SearchItemsDTO Список найденных функций
     * @throws ArgumentException
     * @throws SystemException
     * @throws ObjectPropertyException
     */
    public function liveApiFindFunctionAction(string $functionName, ?string $moduleId = null, bool $strictSearch = false): SearchItemsDTO
    {
        return new SearchItemsDTO(
            items: (new LiveApiService())->findFunctionsFromLiveApi($functionName, $moduleId, $strictSearch),
            searchPhrase: Json::encode(['functionName' => $functionName, 'moduleId' => $moduleId, 'strictSearch' => $strictSearch], JSON_THROW_ON_ERROR)
        );
    }

    /**
     * Ищет функцию с помощью Reflection
     *
     * @param string $functionName Имя функции или метода для поиска (например, 'myFunction' или 'MyClass::myMethod').
     * @param bool $returnCode Возвращать код функции? Можно не возвращать для экономии контекста.
     * @return SearchItemsDTO Список найденных функций с их кодом
     * @throws FileNotFoundException
     * @throws ObjectNotFoundException
     */
    public function liveApiFindFunctionByReflectionAction(string $functionName, bool $returnCode = true): SearchItemsDTO
    {
        return new SearchItemsDTO(
            items: (new LiveApiService())->findFunctionByReflection($functionName, $returnCode),
            searchPhrase: $functionName
        );
    }
}
