<?php

use Bitrix\Main\Application;
use Bitrix\Main\Grid\Panel\Actions;
use Bitrix\Main\Grid\Panel\Snippet\Onchange;
use Bitrix\Main\Loader;
use Bitrix\Main\UI\Extension;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Web\Json;
use Delight\Mcp\Enum\TokenGrants;
use Delight\Mcp\Generators\BxDocsEmbeddingsGenerator;
use Delight\Mcp\Helpers\Options;
use Delight\Mcp\Services\EmbeddingService;

global $APPLICATION, $USER;

$moduleId = 'delight.mcp';
Loader::includeModule($moduleId);
$module_id = $moduleId;

$moduleRights = $APPLICATION->GetGroupRight($moduleId);
if ($moduleRights >= 'R') {
    $request = Application::getInstance()->getContext()->getRequest();
    $godMode = $request->get('GOD_MODE') === 'Y';
    $isEmbeddingsAvailable = (new EmbeddingService())->isAvailable();

    $liveApiIndexedModulesStat = Json::decode(Option::get($moduleId, 'live_api_indexed_modules_stat', '[]'));

    CJSCore::Init('ajax');
    Extension::load(['ui', 'ui.buttons', 'ui.buttons.icons', 'ui.forms', 'ui.layout-form', 'ui.hint', 'ui.select', 'ui.notification', 'ui.treeconditions', 'ui.alerts', 'ui.progressbar', 'ui.date-picker']);

    if ($isEmbeddingsAvailable) {
        $bxDocsEmbeddingsGenerator = new BxDocsEmbeddingsGenerator();
        $reindexDocsStat = (file_exists($bxDocsEmbeddingsGenerator->embeddingsOutputDir . '/embeddings_stats.json')) ? Json::decode(file_get_contents($bxDocsEmbeddingsGenerator->embeddingsOutputDir . '/embeddings_stats.json')) : [];

        if (!empty($reindexDocsStat)) {
            $reindexDocsStatClass = 'ui-alert-default ui-alert-icon-info';
            $reindexDocsStatStr = '<strong>Статистика индексирования документации Bitrix Framework:</strong>';
            $reindexDocsStatStr .= '<p>Дата и время последней индексации: ' . $reindexDocsStat['datetime'] . '<br/>';
            $reindexDocsStatStr .= 'Обработано файлов: ' . $reindexDocsStat['total_processed_files'] . '<br/>';
            $reindexDocsStatStr .= 'Количество чанков: ' . $reindexDocsStat['total_chunks'] . '</p>';
        }
    }

    $needToReindexModules = Option::get($moduleId, 'need_to_reindex_modules', 'N') === 'Y';

    $fields = [
        'nextTab',
        [
            'name' => 'enabled',
            'title' => 'Активность',
            'type' => 'checkbox',
            'hint' => ''
        ],
        [
            'name' => 'log_enabled',
            'title' => 'Включить логирование запросов/ответов',
            'type' => 'checkbox',
            'hint' => 'Логи хранятся в ' . __DIR__ . '/data/logs/'
        ],
        $isEmbeddingsAvailable ? [
            'type' => 'note',
            'value' => '<span class="ui-alert-message">' . $reindexDocsStatStr . '</span>',
            'class' => $reindexDocsStatClass
        ] : '',
        $needToReindexModules ? [
            'type' => 'note',
            'value' => '<span class="ui-alert-message">Вы выполнили обновление системы, рекомендуется переиндексировать кодовую базу модулей.</span>',
            'class' => 'ui-alert-warning ui-alert-icon-danger'
        ] : '',
        (!empty($liveApiIndexedModulesStat)) ? [
            'type' => 'note',
            'value' => '<span class="ui-alert-message">
                            <strong>Статистика индексирования кодовой базы модулей:</strong>
                            <p>Дата и время последней индексации: ' . $liveApiIndexedModulesStat['DATETIME'] . '<br/>
                            Проиндексировано модулей: ' . $liveApiIndexedModulesStat['MODULES_CNT'] . '</p>
                        </span>',
            'class' => 'ui-alert-default ui-alert-icon-info'
        ] : '',
        'nextTab',
        'nextTab',
        /*
         * Редактирование секретного ключа
         * [
            'name' => 'jwt_secret_key',
            'title' => 'Секретный ключ',
            'type' => 'password',
            'hint' => 'Используется для генерации JWT-токена. Не может быть пустым. При изменении секретного ключа существующие токены перестанут работать.
        ],*/
    ];

    // Сохранение настроек
    if ($moduleRights === 'W' && $request->isPost() && check_bitrix_sessid()) {
        foreach ($fields as $field) {
            if ((is_array($field)) && (isset($request[$field['name']]))) {
                if(($field['type'] === 'password') && (empty($request[$field['name']]))){
                    continue;
                }
                Option::set($moduleId, $field['name'], $request[$field['name']]);
            } elseif (is_array($field) && $field['type'] === 'checkbox' && empty($request[$field['name']])) {
                Option::set($moduleId, $field['name'], '');
            }
        }
        // Сохранение прав на доступ к модулю
        $Update = 'Сохранить';
        ob_start();
        require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/admin/group_rights.php");
        ob_end_clean();
        CAdminMessage::ShowNote('Настройки сохранены');
    }

    $aTabs = [
        ['DIV' => 'settings', 'TAB' => 'Настройки', 'TITLE' => 'Настройки'],
        ['DIV' => 'tokens', 'TAB' => 'Токены', 'TITLE' => 'Токены'],
        ['DIV' => 'rights', 'TAB' => 'Доступ', 'TITLE' => 'Доступ'],
    ];

    $tabControl = new CAdminTabControl('tabControl', $aTabs);

    $tabControl->Begin();
    ?>
    <style>
        <?php include __DIR__ . '/assets/css/options.css'; ?>
    </style>
    <div>
        <?php
        foreach ($fields

        as $field) {
            if(empty($field)) {
                continue;
            } elseif ($field === 'nextTab') {
        if ($aTabs[$tabControl->tabIndex]['DIV'] === 'tokens') {
        ?></form><?php
        }
        if ($tabControl->tabIndex > 1){
        ?></td></tr><?php
        }
        $tabControl->BeginNextTab();
        ?>
        <tr>
            <td><?php
                if ($aTabs[$tabControl->tabIndex - 1]['DIV'] === 'rights') {
                    require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/admin/group_rights.php");
                }
                if ($aTabs[$tabControl->tabIndex - 1]['DIV'] === 'tokens') {
                    $columns = [
                        ['id' => 'ID', 'name' => 'ID', 'default' => false],
                        ['id' => 'IDENTIFIER', 'name' => 'Идентификатор', 'default' => true],
                        ['id' => 'GRANTS', 'name' => 'Права', 'default' => true],
                        ['id' => 'EXPIRATION', 'name' => 'Срок действия', 'default' => true],
                        ['id' => 'DATE_CREATE', 'name' => 'Дата создания', 'default' => true],
                        ['id' => 'LAST_USED', 'name' => 'Последнее использование', 'default' => true],
                    ];
                    $list = Options::buildTokensTableRows();

                    CJSCore::Init(['grid', 'ui.fonts.opensans']);
                    ?>
                    <div class="delight-mcp-tokens-table-wrapper js-delight-mcp-tokens-table-wrapper"><?php

                    // Кнопка удалить
                    $onchange = new Onchange();
                    $onchange->addAction(
                        [
                            'ACTION' => Actions::CALLBACK,
                            'CONFIRM' => false,
                            'DATA' => [
                                ['JS' => 'window.delightMcpOptionsComponent.deleteSelectedTokens()']
                            ]
                        ]
                    );
                    $APPLICATION->IncludeComponent(
                        'bitrix:main.ui.grid',
                        '',
                        [
                            'GRID_ID' => 'delight_mcp_tokens_grid',
                            'COLUMNS' => $columns,
                            'ROWS' => $list,
                            'AJAX_MODE' => 'N',
                            'SHOW_ROW_CHECKBOXES' => true,
                            'SHOW_CHECK_ALL_CHECKBOXES' => true,
                            'SHOW_ROW_ACTIONS_MENU' => true,
                            'SHOW_GRID_SETTINGS_MENU' => false,
                            'SHOW_NAVIGATION_PANEL' => true,
                            'SHOW_PAGINATION' => false,
                            'SHOW_SELECTED_COUNTER' => true,
                            'SHOW_TOTAL_COUNTER' => true,
                            'SHOW_PAGESIZE' => false,
                            'SHOW_ACTION_PANEL' => true,
                            'ALLOW_COLUMNS_SORT' => true,
                            'ALLOW_COLUMNS_RESIZE' => true,
                            'ALLOW_HORIZONTAL_SCROLL' => true,
                            'ALLOW_SORT' => true,
                            'ALLOW_PIN_HEADER' => true,
                            'TOTAL_ROWS_COUNT_HTML' => '<span class="main-grid-panel-content-title">Всего:</span> <span class="main-grid-panel-content-text">' . count($list) . '</span>',
                            'ACTION_PANEL' => [
                                'GROUPS' => [
                                    'TYPE' => [
                                        'ITEMS' => [
                                            [
                                                'ID' => 'delete',
                                                'TYPE' => 'BUTTON',
                                                'TEXT' => 'Удалить',
                                                'CLASS' => 'icon remove',
                                                'ONCHANGE' => $onchange->toArray()
                                            ],
                                        ],
                                    ]
                                ],
                            ],
                        ]);
                    ?></div><?php
                }
                if ($aTabs[$tabControl->tabIndex - 1]['DIV'] === 'settings') {
                ?>
                <form method="post" name="delight_mcp_settings" id="delight_mcp_settings"
                      action="<?= $APPLICATION->GetCurPage() ?>?mid=<?= urlencode($moduleId) ?>&amp;lang=<?= LANGUAGE_ID ?>">
                    <?= bitrix_sessid_post(); ?>
                    <?php } ?>
                    <div class="ui-form ui-form-section ui-form-line no-border"><?php
                        if (($aTabs[$tabControl->tabIndex - 1]['DIV'] === 'tokens') && ($moduleRights === 'W')) { ?>
                            <div class="ui-form ui-form-section">
                                <div class="ui-form-row">
                                    <div class="ui-form-label">
                                        <div class="ui-ctl-label-text">
                                            <strong>Создание токена</strong>
                                        </div>
                                    </div>
                                    <div class="ui-form-content">
                                        <div class="ui-form-row">
                                            <div class="ui-form-label">
                                                <div class="ui-ctl-label-text">
                                                    Идентификатор токена <span class="required-star">*</span>
                                                    <span
                                                        data-hint="Можно указать, например, имя разработчика, который будет работать с данным токеном. Должно быть уникальным."
                                                        class="ui-hint">
                                            <span class="ui-hint-icon"></span>
                                        </span>
                                                </div>
                                            </div>
                                            <div class="ui-ctl ui-ctl-textbox">
                                                <input type="text" name="token_identifier" value=""
                                                       class="ui-ctl-element"
                                                       maxlength="255">
                                            </div>
                                        </div>
                                        <div class="ui-form-row">
                                            <div class="ui-form-label">
                                                <div class="ui-ctl-label-text">
                                                    Срок действия токена
                                                    <span
                                                        data-hint="Можно оставить пустым, если нужен вечный токен."
                                                        class="ui-hint">
                                            <span class="ui-hint-icon"></span>
                                        </span>
                                                </div>
                                            </div>
                                            <div class="ui-ctl ui-ctl-after-icon ui-ctl-datetime">
                                                <div class="ui-ctl-after ui-ctl-icon-calendar"></div>
                                                <input class="ui-ctl-element" type="text" name="token_exp" size="18"
                                                       value="">
                                            </div>
                                        </div>
                                        <div class="ui-form-row">
                                            <div class="ui-form-label">
                                                <div class="ui-ctl-label-text">
                                                    Права токена <span class="required-star">*</span>
                                                </div>
                                            </div>
                                            <div class="ui-form-content">
                                                <?php
                                                foreach (TokenGrants::cases() as $grant) {
                                                    if($grant === TokenGrants::SEARCH_BX_DOCS && !$isEmbeddingsAvailable){
                                                        // Если сервис генерации эмбеддингов недоступен, то и не показываем право на поиск по документации
                                                        continue;
                                                    } ?>
                                                    <div class="ui-form-row">
                                                        <label class="ui-ctl ui-ctl-checkbox">
                                                            <input type="checkbox" name="token_grants[]"
                                                                   value="<?= $grant->name ?>" checked
                                                                   class="ui-ctl-element">
                                                            <div class="ui-ctl-label-text"><?= $grant->value ?></div>
                                                        </label>
                                                    </div>
                                                    <?php
                                                }
                                                ?>
                                            </div>
                                        </div>
                                        <div class="ui-form-row">
                                            <div class="ui-form-content">
                                                <div class="ui-alert ui-alert-warning ui-alert-icon-warning">
                                                    <span class="ui-alert-message"><strong>Внимание!</strong> Токены с правами на исполнение PHP/Shell/SQL кода рекомендуется использовать только на тестовых площадках! Помните, что AI-агенты ошибаются и могут удалить или испортить ваши данные.</span>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="ui-form-row">
                                            <div class="ui-form-content">
                                                <button
                                                    class="ui-btn ui-btn-icon-tariff ui-btn-light-border js-create-token">
                                                    Выпустить токен
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php }
                        } elseif ($field['type'] === 'text') { ?>
                            <div class="ui-form-row">
                                <div class="ui-form-label">
                                    <div class="ui-ctl-label-text">
                                        <?= $field['title'] ?>
                                        <?php
                                        if (!empty($field['hint'])) { ?>
                                            <span data-hint="<?= $field['hint'] ?>" class="ui-hint"><span
                                                    class="ui-hint-icon"></span></span>
                                            <?php
                                        } ?>
                                    </div>
                                </div>
                                <div class="ui-form-content">
                                    <div class="ui-form-row">
                                        <label class="ui-ctl ui-ctl-text">
                                            <input type="text" name="<?= $field['name'] ?>"
                                                   value="<?= Option::get($moduleId, $field['name'], '') ?>"
                                                   class="ui-ctl-element">
                                        </label>
                                    </div>
                                </div>
                            </div>
                            <?php
                        } elseif ($field['type'] === 'checkbox') {
                            ?>
                            <div class="ui-form-row">
                            <div class="ui-form-label">
                                <div class="ui-ctl-label-text">
                                    <?= $field['title'] ?>
                                    <?php
                                    if (!empty($field['hint'])) { ?>
                                        <span data-hint="<?= $field['hint'] ?>" class="ui-hint"><span
                                                class="ui-hint-icon"></span></span>
                                        <?php
                                    } ?>
                                </div>
                            </div>
                            <div class="ui-form-content">
                                <div class="ui-form-row">
                                    <label class="ui-ctl ui-ctl-checkbox">
                                        <input type="checkbox" name="<?= $field['name'] ?>"
                                               value="Y" <?= Option::get($moduleId, $field['name'], '') === 'Y' ? 'checked' : '' ?>
                                               class="ui-ctl-element">
                                    </label>
                                </div>
                            </div>
                            </div><?php
                        } elseif ($field['type'] === 'select') {
                            $selectedValue = Option::get($moduleId, $field['name'], ''); ?>
                            <div class="ui-form-row">
                                <div class="ui-form-label">
                                    <div class="ui-ctl-label-text">
                                        <?= $field['title'] ?>
                                        <?php
                                        if (!empty($field['hint'])) { ?>
                                            <span data-hint="<?= $field['hint'] ?>" class="ui-hint"><span
                                                    class="ui-hint-icon"></span></span>
                                            <?php
                                        } ?>
                                    </div>
                                </div>
                                <div class="ui-form-content">
                                    <div class="ui-form-row">
                                        <div class="ui-ctl ui-ctl-after-icon ui-ctl-dropdown">
                                            <div class="ui-ctl-after ui-ctl-icon-angle"></div>
                                            <select name="<?= $field['name'] ?>" class="ui-ctl-element">
                                                <?php
                                                foreach ($field['values'] as $value => $title) { ?>
                                                    <option <?= ($selectedValue === $value) ? 'selected="selected"' : '' ?>
                                                        value="<?= $value ?>"><?= $title ?></option>
                                                    <?php
                                                }
                                                ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php
                        } elseif ($field['type'] === 'note') { ?>
                            <div class="ui-alert <?= $field['class'] ?>">
                                <?= $field['value'] ?>
                            </div>
                        <?php } elseif ($field['type'] === 'password') { ?>
                            <div class="ui-form-row">
                                <div class="ui-form-label">
                                    <div class="ui-ctl-label-text">
                                        <?= $field['title'] ?>
                                        <?php
                                        if (!empty($field['hint'])) { ?>
                                            <span data-hint="<?= $field['hint'] ?>" class="ui-hint"><span
                                                    class="ui-hint-icon"></span></span>
                                            <?php
                                        } ?>
                                    </div>
                                </div>
                                <div class="ui-form-content">
                                    <div class="ui-form-row">
                                        <label class="ui-ctl ui-ctl-text">
                                            <input type="password" name="<?= $field['name'] ?>"
                                                   value=""
                                                   placeholder="••••••••••••" class="ui-ctl-element">
                                        </label>
                                    </div>
                                </div>
                            </div>
                        <?php }
                        }
                        ?>
                    </div>
            </td>
        </tr><?php
        if ($moduleRights === 'W') {
            $tabControl->Buttons(); ?>
            <button class="ui-btn ui-btn-success js-btn-save">Сохранить</button>
            <?=$isEmbeddingsAvailable ? '<button class="ui-btn ui-btn-light-border js-reindex-docs">Переиндексировать документацию</button>' : '' ?>
            <button class="ui-btn ui-btn-light-border js-reindex-live_api">Переиндексировать кодовую базу</button>
            <?php
            if ($godMode) { ?>
                <button class="ui-btn ui-btn-light-border js-test-external-api">Тест внешнего API</button>
            <?php }
        }
        $tabControl->End(); ?>
        <?php if ($godMode): ?>
            <div class="js-test-results-container" style="margin-top: 20px;"></div>
        <?php endif; ?>
    </div>
    <script>
        // Для корректного отображения чекбоксов в таблице с токенами
        const tokensTableWrapper = document.querySelector('.js-delight-mcp-tokens-table-wrapper');
        if (tokensTableWrapper) {
            const checkboxes = tokensTableWrapper.querySelectorAll('.adm-designed-checkbox');
            checkboxes.forEach(function (checkbox) {
                checkbox.classList.remove('adm-designed-checkbox');
            });
            const checkboxLabels = tokensTableWrapper.querySelectorAll('.adm-designed-checkbox-label');
            checkboxLabels.forEach(function (checkbox) {
                checkbox.classList.remove('adm-designed-checkbox-label');
            });
        }
        // Для связи полей с правами на доступ к модулю с основной формой
        const rightsTableWrapper = document.getElementById('rights');
        if (rightsTableWrapper) {
            const inputs = rightsTableWrapper.querySelectorAll('input');
            inputs.forEach(function (input) {
                input.setAttribute('form', 'delight_mcp_settings');
            });
            const selects = rightsTableWrapper.querySelectorAll('select');
            selects.forEach(function (select) {
                select.setAttribute('form', 'delight_mcp_settings');
            });
        }
        <?php include __DIR__ . '/assets/js/DelightMcpOptionsComponent.js'; ?>
    </script>
<?php } ?>
