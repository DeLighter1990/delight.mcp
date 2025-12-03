<?php

namespace Delight\Mcp\Services;

use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Config\Option;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\IO\Directory;
use Bitrix\Main\IO\File;
use Bitrix\Main\IO\FileNotFoundException;
use Bitrix\Main\Loader;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Main\Web\Json;
use Delight\Mcp\DTO\LiveApi\EventInfo;
use Delight\Mcp\DTO\LiveApi\FunctionArgumentInfo;
use Delight\Mcp\DTO\LiveApi\FunctionDetailInfo;
use Delight\Mcp\DTO\LiveApi\FunctionListItemInfo;
use Delight\Mcp\DTO\LiveApi\ModuleEventsDTO;
use Delight\Mcp\DTO\LiveApi\ModuleFunctionsDTO;
use Delight\Mcp\DTO\LiveApi\SourceInfo;
use Delight\Mcp\DTO\ProgressBarValues;
use Delight\Mcp\Entity\DelightMcpLiveApiEventsTable;
use Delight\Mcp\Entity\DelightMcpLiveApiFunctionsTable;
use Delight\Mcp\Enum\LiveApiTypes;
use Delight\Mcp\Helpers\Pagination;
use ReflectionClass;
use ReflectionFunction;
use ReflectionMethod;

class LiveApiService
{
    /** @var string[] Пути к разделам с модулями */
    private const MODULE_PATHS = [
        '/bitrix/modules',
        '/local/modules',
    ];

    /**
     * Переиндексирует установленные модули из /bitrix/modules и /local/modules.
     *
     * @param int $start Индекс начального модуля для обработки.
     * @param int $limit Максимальное количество модулей для обработки за один шаг.
     * @return ProgressBarValues
     * @throws \Exception
     */
    public function reindex(int $start = 0, int $limit = 10): ProgressBarValues
    {
        if ($start === 0) {
            $this->clearTables();
        }

        $modules = $this->getModuleList();
        $totalModules = count($modules);
        $modulesToProcess = ($limit > 0) ? array_slice($modules, $start, $limit) : $modules;

        foreach ($modulesToProcess as $module) {
            $moduleId = $module['id'];
            $modulePath = $module['path'];
            $version = $module['version'];

            $ar = $this->scanModuleDirectory($modulePath);
            foreach ($ar as $type => $arList) {
                foreach ($arList as $item => $location) {
                    $data = [
                        'MODULE_ID' => $moduleId,
                        'MODULE_VERSION' => $version,
                        'NAME' => $item,
                        'DATA' => Json::encode($location),
                    ];

                    switch ($type) {
                        case LiveApiTypes::FUNCTION->value:
                            DelightMcpLiveApiFunctionsTable::add($data);
                            break;
                        case LiveApiTypes::EVENT->value:
                            DelightMcpLiveApiEventsTable::add($data);
                            break;
                    }
                }
            }
        }

        if (($start + $limit) >= $totalModules) {
            Option::set('delight.mcp', 'live_api_indexed_modules_stat', Json::encode(['MODULES_CNT' => $totalModules, 'DATETIME' => date('d.m.Y H:i:s'), 'TO' => count($modulesToProcess)]));
            Option::set('delight.mcp', 'need_to_reindex_modules', 'N');
        }

        return new ProgressBarValues(
            total: $totalModules,
            processed: count($modulesToProcess)
        );
    }

    /**
     * Получает список функций модуля
     *
     * @param string $moduleId Идентификатор (код) модуля
     * @param int $offset Пагинация - смещение
     * @param int $limit Пагинация - максимальное количество элементов в выдаче
     * @return ModuleFunctionsDTO Объект с информацией о модуле
     * @throws ObjectNotFoundException
     * @throws ArgumentException
     * @throws SystemException
     */
    public function getModuleFunctionsInfo(string $moduleId, int $offset = 0, int $limit = 200): ModuleFunctionsDTO
    {
        $version = null;
        $functions = [];

        $functionRes = DelightMcpLiveApiFunctionsTable::getList([
            'filter' => ['=MODULE_ID' => $moduleId],
            'offset' => $offset,
            // Запрашиваем на 1 запись больше, чтобы определить наличие следующей страницы
            'limit' => $limit + 1,
            'cache' => ['ttl' => 60 * 60 * 24 * 365]
        ]);

        $count = 0;
        $hasMore = false;
        while ($func = $functionRes->fetch()) {
            // Если достигли лимита, останавливаемся (последняя запись для проверки)
            if ($count >= $limit) {
                $hasMore = true;
                break;
            }

            if (!$version) {
                $version = $func['MODULE_VERSION'];
            }
            $data = Json::decode($func['DATA']);
            $args = [];
            if (isset($data['args']) && is_array($data['args'])) {
                foreach ($data['args'] as $arg) {
                    $args[] = new FunctionArgumentInfo(
                        type: $arg['type'] ?? '',
                        name: $arg['name'] ?? '',
                        defaultValue: $arg['defaultValue'] ?? ''
                    );
                }
            }
            $functions[] = new FunctionListItemInfo(
                name: $func['NAME'],
                source: new SourceInfo(
                    file: $data['source']['file'],
                    line: $data['source']['line']
                ),
                args: $args
            );
            $count++;
        }

        if ($version === null) {
            throw new ObjectNotFoundException('Модуль с ID ' . $moduleId . ' не найден');
        }

        // Формируем nextCursor: если получили больше записей, чем limit, есть следующая страница
        $nextCursor = null;
        if ($count === $limit && $hasMore) {
            $nextOffset = $offset + $limit;
            $nextCursor = Pagination::makeNextCursor($nextOffset, $limit);
        }

        return new ModuleFunctionsDTO(
            id: $moduleId,
            version: $version,
            functions: $functions,
            nextCursor: $nextCursor
        );
    }

    /**
     * Получает список событий модуля
     *
     * @param string $moduleId Идентификатор (код) модуля
     * @return ModuleEventsDTO Объект с событиями модуля
     * @throws ArgumentException
     * @throws ObjectNotFoundException
     * @throws SystemException
     * @throws ObjectPropertyException
     */
    public function getModuleEventsInfo(string $moduleId): ModuleEventsDTO
    {
        $version = null;
        $events = [];

        $eventRes = DelightMcpLiveApiEventsTable::getList([
            'filter' => ['=MODULE_ID' => $moduleId],
            'cache' => ['ttl' => 60 * 60 * 24 * 365],
        ]);

        while ($event = $eventRes->fetch()) {
            if (!$version) {
                $version = $event['MODULE_VERSION'];
            }
            $data = Json::decode($event['DATA']);
            $sourceData = $data['source'] ?? $data;
            $events[] = new EventInfo(
                name: $event['NAME'],
                source: new SourceInfo(
                    file: $sourceData['file'],
                    line: $sourceData['line']
                ),
                firedInFunc: $data['firedInFunc'] ?? ''
            );
        }

        if ($version === null) {
            throw new ObjectNotFoundException('Модуль с ID ' . $moduleId . ' не найден');
        }

        return new ModuleEventsDTO(
            id: $moduleId,
            version: $version,
            events: $events
        );
    }

    /**
     * Очищает таблицы Live API.
     *
     * @return void
     */
    private function clearTables(): void
    {
        /** @var Connection $connection */
        $connection = Application::getConnection();
        $connection->truncateTable(DelightMcpLiveApiFunctionsTable::getTableName());
        $connection->truncateTable(DelightMcpLiveApiEventsTable::getTableName());
        DelightMcpLiveApiFunctionsTable::cleanCache();
        DelightMcpLiveApiEventsTable::cleanCache();
    }

    /**
     * Собирает список установленных модулей.
     *
     * @return array
     * @throws FileNotFoundException
     */
    private function getModuleList(): array
    {
        $modules = [];
        foreach (self::MODULE_PATHS as $path) {
            $fullPath = Application::getDocumentRoot() . $path;
            if (!Directory::isDirectoryExists($fullPath)) {
                continue;
            }

            $dir = new Directory($fullPath);
            foreach ($dir->getChildren() as $moduleDir) {
                if (!$moduleDir->isDirectory() || str_starts_with($moduleDir->getName(), '.')) {
                    continue;
                }

                $modulePath = $moduleDir->getPath();
                $moduleId = $moduleDir->getName();
                $modules[] = [
                    'id' => $moduleId,
                    'path' => $modulePath,
                    'version' => $this->getModuleVersion($modulePath),
                ];
            }
        }
        return $modules;
    }

    /**
     * Сканирует директорию модуля и извлекает информацию о коде.
     *
     * @param string $path Путь к директории модуля.
     * @return array
     */
    private function scanModuleDirectory(string $path): array
    {
        $results = [
            LiveApiTypes::FUNCTION->value => [],
            LiveApiTypes::EVENT->value => [],
        ];

        if (!Directory::isDirectoryExists($path)) {
            return $results;
        }

        $directory = new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS);
        $iterator = new \RecursiveIteratorIterator($directory, \RecursiveIteratorIterator::LEAVES_ONLY);
        $phpFiles = new \RegexIterator($iterator, '/\.php$/i');

        foreach ($phpFiles as $fileInfo) {
            $filePath = $fileInfo->getPathname();

            $normalizedFilePath = str_replace('\\', '/', $filePath);
            if (str_contains($normalizedFilePath, '/install/')) {
                continue;
            }

            if (!File::isFileExists($filePath)) {
                continue;
            }

            $content = File::getFileContents($filePath);
            $tokens = @token_get_all($content);
            $functionsInFile = [];

            $currentNamespace = '';
            $currentClass = null;
            $currentFunction = null;
            $classBraceLevel = 0;
            $functionBraceLevel = 0;
            $isInsideClass = false;
            $isInsideFunction = false;

            foreach ($tokens as $i => $iValue) {
                $token = $iValue;

                if (is_string($token)) {
                    if ($token === '{') {
                        if ($isInsideClass) {
                            $classBraceLevel++;
                        }
                        if ($isInsideFunction) {
                            $functionBraceLevel++;
                        }
                    } elseif ($token === '}') {
                        if ($isInsideClass) {
                            $classBraceLevel--;
                            if ($classBraceLevel === 0) {
                                $isInsideClass = false;
                                $currentClass = null;
                            }
                        }
                        if ($isInsideFunction) {
                            $functionBraceLevel--;
                            if ($functionBraceLevel === 0) {
                                $isInsideFunction = false;
                                $currentFunction = null;
                            }
                        }
                    }
                    continue;
                }

                $nextToken = $tokens[$i + 1] ?? null;
                $nextNextToken = $tokens[$i + 2] ?? null;

                switch ($token[0]) {
                    case T_NAMESPACE:
                        $currentNamespace = '';
                        $j = $i + 2;
                        while (isset($tokens[$j]) && is_array($tokens[$j]) && in_array($tokens[$j][0], [T_STRING, T_NS_SEPARATOR, T_NAME_QUALIFIED])) {
                            $currentNamespace .= $tokens[$j][1];
                            $j++;
                        }
                        $i = $j - 1;
                        break;

                    case T_CLASS:
                        if (!$isInsideClass && $nextNextToken && is_array($nextNextToken) && $nextNextToken[0] === T_STRING) {
                            $currentClass = ($currentNamespace ? $currentNamespace . '\\' : '') . $nextNextToken[1];
                            $isInsideClass = true;
                            $classBraceLevel = 0;
                        }
                        break;

                    case T_FUNCTION:
                        if ($nextNextToken && is_array($nextNextToken) && $nextNextToken[0] === T_STRING) {
                            $functionName = $nextNextToken[1];
                            $currentFunction = $currentClass ? "{$currentClass}::{$functionName}" : $functionName;
                            $isInsideFunction = true;
                            $functionBraceLevel = 0;

                            $functionsInFile[] = ['name' => $currentFunction, 'line' => $token[2]];

                            $location = new SourceInfo(
                                file: str_replace(Application::getDocumentRoot(), '', $filePath),
                                line: $token[2],
                            );
                            $results[LiveApiTypes::FUNCTION->value][$currentFunction] = [
                                'source' => $location,
                                'args' => $this->getFunctionArgs($tokens, $i + 2),
                            ];
                        }
                        break;
                }
            }

            // Event parsing
            $pattern = '#(?:new\s+(?:\\\\Bitrix\\\\Main\\\\)?Event|GetModuleEvents)\s*\([^,]+,\s*["\']([a-zA-Z0-9_.:-]+)["\']|(?:ExecuteModuleEvent|ExecuteEvents)\s*\(\s*["\']([a-zA-Z0-9_.:-]+)["\']#i';
            if (preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
                $relativePath = str_replace(Application::getDocumentRoot(), '', $filePath);
                foreach ($matches as $match) {
                    $eventNameMatch = null;
                    if (isset($match[2]) && $match[2][1] > -1) {
                        $eventNameMatch = $match[2];
                    } elseif (isset($match[1]) && $match[1][1] > -1) {
                        $eventNameMatch = $match[1];
                    }

                    if ($eventNameMatch) {
                        $eventName = $eventNameMatch[0];
                        $offset = $eventNameMatch[1];
                        $line = substr_count(substr($content, 0, $offset), "\n") + 1;

                        $firedInFunc = null;
                        $bestMatchLine = -1;
                        foreach ($functionsInFile as $func) {
                            if ($func['line'] <= $line && $func['line'] > $bestMatchLine) {
                                $bestMatchLine = $func['line'];
                                $firedInFunc = $func['name'];
                            }
                        }

                        $results[LiveApiTypes::EVENT->value][$eventName] = [
                            'source' => new SourceInfo(
                                file: $relativePath,
                                line: $line
                            ),
                            'firedInFunc' => $firedInFunc,
                        ];
                    }
                }
            }
        }

        return $results;
    }

    /**
     * Извлекает информацию об аргументах функции.
     *
     * @param array $tokens Массив токенов.
     * @param int $startIndex Индекс начала поиска.
     * @return FunctionArgumentInfo[]
     */
    private function getFunctionArgs(array $tokens, int $startIndex): array
    {
        $args = [];
        $parenCount = 0;
        $i = $startIndex;

        while (isset($tokens[$i])) {
            if ($tokens[$i] === '(') {
                $parenCount = 1;
                $i++;
                break;
            }
            $i++;
        }

        if ($parenCount === 0) {
            return [];
        }

        $argTokens = [];
        while (isset($tokens[$i])) {
            $token = $tokens[$i];
            if ($token === '(') {
                $parenCount++;
            } elseif ($token === ')') {
                $parenCount--;
                if ($parenCount === 0) {
                    break;
                }
            }

            if ($token === ',' && $parenCount === 1) {
                if (!empty($argTokens)) {
                    $args[] = $this->parseArgumentTokens($argTokens);
                    $argTokens = [];
                }
            } else {
                $argTokens[] = $token;
            }
            $i++;
        }

        if (!empty($argTokens)) {
            $args[] = $this->parseArgumentTokens($argTokens);
        }

        return $args;
    }

    /**
     * @param array $argTokens
     * @return FunctionArgumentInfo
     */
    private function parseArgumentTokens(array $argTokens): FunctionArgumentInfo
    {
        $type = '';
        $name = '';
        $defaultValue = '';
        $foundName = false;
        $isDefault = false;

        foreach ($argTokens as $token) {
            $tokenValue = is_array($token) ? $token[1] : $token;

            if ($token === '=') {
                $isDefault = true;
                continue;
            }

            if ($isDefault) {
                $defaultValue .= $tokenValue;
            } elseif (is_array($token) && $token[0] === T_VARIABLE) {
                $name = $tokenValue;
                $foundName = true;
            } elseif (!$foundName) {
                $type .= $tokenValue;
            }
        }

        return new FunctionArgumentInfo(
            type: trim($type),
            name: trim($name),
            defaultValue: trim($defaultValue)
        );
    }


    /**
     * Получает версию модуля.
     *
     * @param string $modulePath Путь к модулю.
     * @return string|null
     */
    private function getModuleVersion(string $modulePath): ?string
    {
        $versionFile = $modulePath . '/install/version.php';
        if (File::isFileExists($versionFile)) {
            $arModuleVersion = [];
            include $versionFile;
            return $arModuleVersion['VERSION'] ?? null;
        }

        $versionFile = $modulePath . '/classes/general/version.php';
        if (File::isFileExists($versionFile)) {
            $content = File::getFileContents($versionFile);
            if (preg_match('#SM_VERSION[" ,]+([^"]+)#m', $content, $regs)) {
                return $regs[1];
            }
        }

        return null;
    }

    /**
     * Ищет функцию в индексе, сохраненном в базе данных.
     *
     * @param string $function Имя функции
     * @param string $moduleId Идентификатор (код) модуля
     * @return string Код функции с описанием PHPDoc
     * @throws ObjectNotFoundException
     * @throws FileNotFoundException
     * @throws SystemException
     */
    public function getFunctionFromIndex(string $function, string $moduleId): string
    {
        $filter = [
            '=NAME' => $function,
            '=MODULE_ID' => $moduleId,
        ];

        $funcData = DelightMcpLiveApiFunctionsTable::getRow([
            'filter' => $filter,
            'cache' => ['ttl' => 60 * 60 * 24 * 365]
        ]);

        if (!$funcData) {
            throw new ObjectNotFoundException("Функция '$function' не найдена в базе данных.");
        }

        $data = Json::decode($funcData['DATA']);
        $fullPath = Application::getDocumentRoot() . $data['source']['file'];
        $startLine = (int)$data['source']['line'];

        if (!File::isFileExists($fullPath)) {
            throw new FileNotFoundException("Файл '{$data['source']['file']}' не найден.");
        }

        $content = File::getFileContents($fullPath);
        $tokens = token_get_all($content);

        $functionTokenIndex = -1;
        foreach ($tokens as $index => $token) {
            if (is_array($token) && $token[0] === T_FUNCTION && $token[2] === $startLine) {
                $functionTokenIndex = $index;
                break;
            }
        }

        if ($functionTokenIndex === -1) {
            throw new ObjectNotFoundException("Не удалось найти токен для функции '$function' в файле.");
        }

        $docCommentIndex = -1;
        for ($i = $functionTokenIndex - 1; $i >= 0; $i--) {
            $token = $tokens[$i];
            if (is_array($token) && $token[0] === T_DOC_COMMENT) {
                $docCommentIndex = $i;
                break;
            }
            if (is_array($token) && !in_array($token[0], [T_WHITESPACE, T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_ABSTRACT, T_FINAL], true)) {
                break;
            }
        }

        $startIndex = ($docCommentIndex !== -1) ? $docCommentIndex : $functionTokenIndex;

        $braceLevel = 0;
        $endIndex = -1;
        $bodyStarted = false;
        $tokensCount = count($tokens);
        for ($i = $functionTokenIndex; $i < $tokensCount; $i++) {
            $token = $tokens[$i];
            if ($token === '{') {
                $braceLevel++;
                $bodyStarted = true;
            } elseif ($token === '}') {
                $braceLevel--;
            } elseif (!$bodyStarted && $token === ';') {
                $endIndex = $i;
                break;
            }
            if ($bodyStarted && $braceLevel === 0) {
                $endIndex = $i;
                break;
            }
        }

        if ($endIndex === -1) {
            throw new SystemException("Не удалось найти конец функции '$function'.");
        }

        $result = '';
        for ($i = $startIndex; $i <= $endIndex; $i++) {
            $result .= is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
        }

        return $result;
    }

    /**
     * Проверяет, существует ли функция или метод класса.
     *
     * @param string $functionName Имя функции или метода ('Class::method').
     * @return bool
     */
    private function isFunctionExists(string $functionName): bool
    {
        if (str_contains($functionName, '::')) {
            [$class, $method] = explode('::', $functionName, 2);
            return class_exists($class) && method_exists($class, $method);
        }
        return function_exists($functionName);
    }

    /**
     * Ищет функцию или метод с помощью Reflection API, загружая модули при необходимости.
     *
     * @param string $functionName Имя функции или метода для поиска (например, 'myFunction' или 'MyClass::myMethod').
     * @param bool $returnCode Возвращать код функции? Можно не возвращать для экономии контекста.
     * @return FunctionDetailInfo[] Список найденных функций
     * @throws FileNotFoundException
     * @throws ObjectNotFoundException Если ничего не найдено.
     */
    public function findFunctionByReflection(string $functionName, bool $returnCode = true): array
    {
        $results = [];
        $this->loadModulesUntilFunctionExists($functionName);

        // Сценарий 1: Ищем конкретный метод 'Class::method'
        if (str_contains($functionName, '::')) {
            if ($this->isFunctionExists($functionName)) {
                try {
                    $reflection = new ReflectionMethod($functionName);
                    $source = $this->getSourceFromReflection($reflection);
                    if ($source !== null) {
                        $results[] = new FunctionDetailInfo(
                            name: $functionName,
                            code: $returnCode ? $source : null
                        );
                    }
                } catch (\ReflectionException $e) {
                    // Игнорируем, если не удалось получить рефлексию, возможно класс не тот
                }
            }
        } else { // Сценарий 2: Ищем глобальную функцию и методы с таким именем
            // Поиск глобальной функции
            if (function_exists($functionName)) {
                try {
                    $reflection = new ReflectionFunction($functionName);
                    $source = $this->getSourceFromReflection($reflection);
                    if ($source !== null) {
                        $results[] = new FunctionDetailInfo(
                            name: $functionName,
                            code: $returnCode ? $source : null
                        );
                    }
                } catch (\ReflectionException $e) {
                    // Игнорируем
                }
            }

            // Поиск методов в классах
            $declaredClasses = get_declared_classes();
            foreach ($declaredClasses as $className) {
                try {
                    $reflectionClass = new ReflectionClass($className);
                    if ($reflectionClass->isInternal() || !$reflectionClass->hasMethod($functionName)) {
                        continue;
                    }
                    $method = $reflectionClass->getMethod($functionName);
                    // Убедимся, что метод определён именно в этом классе (а не унаследован)
                    if ($method->getDeclaringClass()->getName() === $className) {
                        $source = $this->getSourceFromReflection($method);
                        if ($source) {
                            $results[] = new FunctionDetailInfo(
                                name: $className . '::' . $functionName,
                                code: $returnCode ? $source : null
                            );
                        }
                    }
                } catch (\ReflectionException) {
                    // Игнорируем ошибки, если класс не может быть проанализирован
                }
            }
        }

        if (empty($results)) {
            throw new ObjectNotFoundException("Функция или метод '$functionName' не найдены.");
        }

        return $results;
    }


    /**
     * Загружает модули, пока не будет найдена функция или пока не закончатся модули.
     * @param string|null $functionName
     * @throws FileNotFoundException
     */
    private function loadModulesUntilFunctionExists(?string $functionName): void
    {
        $modules = $this->getModuleList();
        foreach ($modules as $module) {
            // Если ищем конкретную функцию и она уже существует, не нужно ничего грузить
            if ($functionName && $this->isFunctionExists($functionName) && str_contains($functionName, '::')) {
                return;
            }
            try {
                Loader::includeModule($module['id']);
            } catch (\Exception) {
                // Модуль может не загрузиться, это нормально
            }
        }
    }

    /**
     * @param ReflectionMethod|ReflectionFunction $reflection
     * @return string|null
     */
    private function getSourceFromReflection(ReflectionMethod|ReflectionFunction $reflection): ?string
    {
        $fileName = $reflection->getFileName();
        $startLine = $reflection->getStartLine();
        $endLine = $reflection->getEndLine();
        $docComment = $reflection->getDocComment();

        if (!$fileName || !$startLine || !$endLine) {
            return null;
        }

        $lines = file($fileName);
        if ($lines === false) {
            return null;
        }

        $source = implode('', array_slice($lines, $startLine - 1, $endLine - $startLine + 1));

        return ($docComment ? $docComment . "\n" : '') . $source;
    }

    /**
     * Ищет функции в таблице Live API
     *
     * @param string $functionName Имя функции
     * @param string|null $moduleId Идентификатор (код) модуля
     * @param bool $strictSearch Строгий поиск?
     * @return FunctionListItemInfo[] Список найденных функций
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    public function findFunctionsFromLiveApi(string $functionName, ?string $moduleId = null, bool $strictSearch = false): array
    {
        $filter = [];

        if ($strictSearch) {
            $filter['=NAME'] = $functionName;
        } else {
            $filter['%=NAME'] = $functionName;
        }

        if ($moduleId !== null) {
            $filter['=MODULE_ID'] = $moduleId;
        }

        $result = [];
        $functionRes = DelightMcpLiveApiFunctionsTable::getList([
            'filter' => $filter,
            'cache' => ['ttl' => 60 * 60 * 24 * 365]
        ]);

        while ($func = $functionRes->fetch()) {
            $data = Json::decode($func['DATA']);
            $args = [];
            if (isset($data['args']) && is_array($data['args'])) {
                foreach ($data['args'] as $arg) {
                    $args[] = new FunctionArgumentInfo(
                        type: $arg['type'] ?? '',
                        name: $arg['name'] ?? '',
                        defaultValue: $arg['defaultValue'] ?? ''
                    );
                }
            }

            $result[] = new FunctionListItemInfo(
                name: $func['NAME'],
                source: new SourceInfo(
                    file: $data['source']['file'],
                    line: $data['source']['line'],
                    moduleId: $func['MODULE_ID']
                ),
                args: $args,
            );
        }

        return $result;
    }
}
