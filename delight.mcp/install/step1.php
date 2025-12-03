<?php

/**
 * @global CMain $APPLICATION
 */

use Bitrix\Main\UI\Extension;

if (!check_bitrix_sessid()) {
    return;
}

Extension::load(['ui.progressbar', 'ui.alerts']);
CJSCore::Init();

CAdminMessage::ShowMessage([
    "TYPE" => "PROGRESS",
    "MESSAGE" => 'Индексация кодовой базы модулей',
    "DETAILS" => 'На этом шаге происходит индексация кода установленных в системе модулей. Это необходимо для работы функции "Live API".<br>Процесс может занять продолжительное время. Пожалуйста, не закрывайте и не обновляйте страницу до его завершения.',
    "HTML" => true,
]);
?>
<div id="delight_mcp_install_container">
    <!-- Progress bar will be rendered here by JavaScript -->
</div>

<br>
<input type="button" name="inst" value="Перейти к настройкам" disabled onclick="window.location.href='/bitrix/admin/settings.php?lang=<?= LANGUAGE_ID ?>&mid=delight.mcp'">

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const BATCH_LIMIT = 5;
        let currentProcessedFiles = 0;
        let totalFilesToProcess = 0;
        
        const container = document.getElementById('delight_mcp_install_container');
        const nextButton = document.querySelector('input[name="inst"]');

        let progressBarContainer = null;
        let progressBar = null;
        let progressBarText = null;

        function createProgressBar(anchorElement, text, unit) {
            progressBarContainer = document.createElement('div');
            progressBarContainer.className = 'delight-progressbar-container';
            progressBarContainer.innerHTML = `
                <div class="ui-progressbar">
                    <div class="ui-progressbar-text-before">${text}</div>
                    <div class="ui-progressbar-track">
                        <div class="ui-progressbar-bar" style="width:0%;"></div>
                    </div>
                    <div class="ui-progressbar-text-after">0 ${unit} из 0</div>
                </div>
            `;
            anchorElement.innerHTML = '';
            anchorElement.appendChild(progressBarContainer);

            progressBar = progressBarContainer.querySelector('.ui-progressbar-bar');
            progressBarText = progressBarContainer.querySelector('.ui-progressbar-text-after');
        }

        function updateProgressBar(processed, total, unit) {
            if (progressBar && progressBarText) {
                const percentage = total > 0 ? Math.round((processed / total) * 100) : 0;
                progressBar.style.width = `${percentage}%`;
                progressBarText.textContent = `${processed} ${unit} из ${total}`;
            }
        }

        function showFinalMessage(message, isSuccess) {
            const alertClass = isSuccess ? 'ui-alert-success' : 'ui-alert-danger';
            if (container) {
                container.innerHTML = `<div class="ui-alert ${alertClass}"><span class="ui-alert-message">${message}</span></div>`;
            }
            if (nextButton) {
                nextButton.disabled = false;
            }
        }

        async function sendReindexRequest(startIndex) {
            try {
                const response = await BX.ajax.runAction('delight:mcp.Internal.reindexLiveApi', {
                    data: {
                        start: startIndex,
                        limit: BATCH_LIMIT
                    }
                });

                if (response.status === 'success') {
                    const responseData = response.data;
                    const processedInStep = responseData.processed || 0;
                    const totalFiles = responseData.total || 0;

                    currentProcessedFiles += processedInStep;
                    totalFilesToProcess = totalFiles;

                    updateProgressBar(currentProcessedFiles, totalFilesToProcess, 'модулей');

                    if (currentProcessedFiles < totalFilesToProcess && processedInStep > 0) {
                        await sendReindexRequest(currentProcessedFiles);
                    } else {
                        if (currentProcessedFiles >= totalFilesToProcess && totalFilesToProcess > 0) {
                            showFinalMessage('Индексация модулей успешно завершена!', true);
                        } else {
                             showFinalMessage('Не удалось проиндексировать модули. Возможно, они уже проиндексированы или произошла ошибка. Вы можете попробовать продолжить установку.', false);
                        }
                    }
                } else {
                    const errorMessage = response.errors && response.errors.length > 0 ? response.errors.map(e => e.message).join('<br>') : 'Произошла неизвестная ошибка.';
                    showFinalMessage('Ошибка при индексации: ' + errorMessage, false);
                }
            } catch (error) {
                console.error('Error during reindexing:', error);
                const errorMessage = error.errors && error.errors.length > 0 ? error.errors.map(e => e.message).join('<br>') : 'Произошла неизвестная ошибка.';
                showFinalMessage('Произошла критическая ошибка при индексации: ' + errorMessage, false);
            }
        }

        createProgressBar(container, 'Выполняется индексация модулей', 'модулей');
        sendReindexRequest(0);
    });
</script>
