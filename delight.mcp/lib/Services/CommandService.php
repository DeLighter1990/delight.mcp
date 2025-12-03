<?php

namespace Delight\Mcp\Services;

use Bitrix\Main\Application;
use Bitrix\Main\Db\SqlQueryException;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Delight\Mcp\DTO\SqlResult;

/**
 * Сервис исполнения команд на сервере (Shell/PHP/SQL)
 */
class CommandService
{
    /**
     * Исполняет Shell-команды
     *
     * @param string $command Команды
     * @return string Консольный вывод
     */
    public function exec(string $command): string
    {
        $execData = ['command' => $command];
        $event = new Event('delight.mcp', 'OnBeforeExec', $execData);
        $event->send();

        if ($event->getResults()) {
            foreach ($event->getResults() as $eventResult) {
                if ($eventResult->getType() === EventResult::SUCCESS) {
                    $execData = $eventResult->getParameters();
                }
            }
        }

        $output = [];
        exec($execData['command'], $output);

        $eventParameters = ['output' => $output];
        $event = new Event('delight.mcp', 'OnAfterExec', $eventParameters);
        $event->send();

        if ($event->getResults()) {
            foreach ($event->getResults() as $eventResult) {
                if ($eventResult->getType() === EventResult::SUCCESS) {
                    $eventParameters = $eventResult->getParameters();
                }
            }
        }

        return implode('\n', $eventParameters['output']);
    }

    /**
     * Исполняет PHP-код
     *
     * @param string $code Исполняемый PHP-код
     * @return string Экранный вывод
     */
    public function eval(string $code): string
    {
        $evalData = ['code' => $code];
        $event = new Event('delight.mcp', 'OnBeforeEval', $evalData);
        $event->send();

        if ($event->getResults()) {
            foreach ($event->getResults() as $eventResult) {
                if ($eventResult->getType() === EventResult::SUCCESS) {
                    $evalData = $eventResult->getParameters();
                }
            }
        }

        ob_start();
        eval($evalData['code']);
        $output = ob_get_clean();

        $eventParameters = ['output' => $output];
        $event = new Event('delight.mcp', 'OnAfterEval', $eventParameters);
        $event->send();

        if ($event->getResults()) {
            foreach ($event->getResults() as $eventResult) {
                if ($eventResult->getType() === EventResult::SUCCESS) {
                    $eventParameters = $eventResult->getParameters();
                }
            }
        }

        return $eventParameters['output'];
    }

    /**
     * Исполняет SQL-код
     *
     * @param string $query SQL-запрос
     * @return SqlResult Результат исполнения SQL-запроса
     * @throws SqlQueryException
     */
    public function sql(string $query): SqlResult
    {
        $sqlData = ['query' => $query];
        $event = new Event('delight.mcp', 'OnBeforeSql', $sqlData);
        $event->send();

        if ($event->getResults()) {
            foreach ($event->getResults() as $eventResult) {
                if ($eventResult->getType() === EventResult::SUCCESS) {
                    $sqlData = $eventResult->getParameters();
                }
            }
        }

        $connection = Application::getConnection();
        $queryResult = $connection->query($sqlData['query']);

        // --- Формирование результата ---
        $rows = [];
        $columns = [];

        while ($row = $queryResult->fetch()) {
            if (empty($columns)) {
                $columns = array_keys($row);
            }
            $rows[] = array_values($row);
        }

        $result = [
            'columns' => $columns,
            'rows' => $rows,
            'rowCount' => count($rows),
            'query' => $sqlData['query']
        ];

        $event = new Event('delight.mcp', 'OnAfterSql', $result);
        $event->send();

        if ($event->getResults()) {
            foreach ($event->getResults() as $eventResult) {
                if ($eventResult->getType() === EventResult::SUCCESS) {
                    $result = $eventResult->getParameters();
                }
            }
        }

        return new SqlResult(
            columns: $result['columns'],
            rows: $result['rows'],
            rowCount: $result['rowCount'],
            query: $result['query']
        );
    }
}
