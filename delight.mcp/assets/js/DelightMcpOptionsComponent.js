class DelightMcpOptionsComponent {
  constructor() {
    this.reindexButton = document.querySelector('.js-reindex-docs');
    this.reindexLiveApiButton = document.querySelector('.js-reindex-live_api');
    this.createTokenButton = document.querySelector('.js-create-token');
    this.testExternalApiButton = document.querySelector('.js-test-external-api');
    this.saveButton = document.querySelector('.js-btn-save');
    this.testResultsContainer = document.querySelector('.js-test-results-container');
    this.totalFilesToProcess = 0;
    this.currentProcessedFiles = 0;
    this.BATCH_LIMIT = 5; // Количество файлов для обработки за один AJAX-запрос
    this.progressBarContainer = null;
    this.progressBar = null;
    this.progressBarText = null;
  }

  init() {
    if (this.reindexButton) {
      this.reindexButton.addEventListener('click', this.handleReindexClick.bind(this));
    }
    if (this.reindexLiveApiButton) {
      this.reindexLiveApiButton.addEventListener('click', this.handleReindexLiveApiClick.bind(this));
    }
    if (this.createTokenButton) {
      this.createTokenButton.addEventListener('click', this.handleCreateTokenClick.bind(this));
    }
    if (this.testExternalApiButton) {
      this.testExternalApiButton.addEventListener('click', this.handleTestExternalApiClick.bind(this));
    }
    if (this.saveButton) {
      this.saveButton.addEventListener('click', this.handleSaveClick.bind(this));
    }
    this.initDatePicker();
  }

  handleSaveClick(event) {
    event.preventDefault();
    const form = document.getElementById('delight_mcp_settings');
    if (form) {
      form.submit();
    }
  }

  initDatePicker() {
    const input = document.querySelector(`input[name="token_exp"]`);
    if (!input) {
      return;
    }
    let picker = null;
    const getPicker = () => {
      if (picker === null) {
        const today = new Date();
        const tomorrow = new Date(today);
        tomorrow.setDate(today.getDate() + 1);
        tomorrow.setHours(0, 0, 0, 0);

        picker = new BX.UI.DatePicker.DatePicker({
          targetNode: input,
          inputField: input,
          enableTime: true,
          useInputEvents: false,
          events: {
            onBeforeSelect: function (event) {
              // Делаем выбор прошедших дат недоступным
              const date = event.getData().date;
              if (date < new Date()) {
                event.preventDefault();
              }
            }
          },
          dayColors: [
            {
              // Подкрашиваем недоступные к выбору даты
              matcher: function (date) {
                return date < new Date();
              },
              textColor: '#e3e3e3'
            }
          ]
        });
      }

      return picker;
    };

    BX.Event.bind(input, "click", () => getPicker().show());
  }

  isValidDateTime(value) {
    if (value.trim() === '') {
      return true; // Пустое поле допустимо
    }
    // Регулярное выражение для форматов ДД.ММ.ГГГГ или ДД.ММ.ГГГГ ЧЧ:ММ
    const regex = /^(0[1-9]|[12][0-9]|3[01])\.(0[1-9]|1[0-2])\.\d{4}(\s([01][0-9]|2[0-3]):([0-5][0-9]))?$/;
    return regex.test(value);
  }

  async handleCreateTokenClick(event) {
    event.preventDefault();

    const tokenIdentifierInput = document.querySelector('input[name="token_identifier"]');
    const tokenGrantsCheckboxes = document.querySelectorAll('input[name="token_grants[]"]:checked');
    const tokenExpInput = document.querySelector('input[name="token_exp"]');

    if (!tokenIdentifierInput || tokenIdentifierInput.value.trim() === '') {
      alert('Поле "Идентификатор токена" обязательно для заполнения.');
      return;
    }

    if (tokenGrantsCheckboxes.length === 0) {
      alert('Поле "Права токена" обязательно для заполнения.');
      return;
    }

    if (tokenExpInput && !this.isValidDateTime(tokenExpInput.value)) {
      alert('Поле "Срок действия токена" должно быть пустым или иметь формат ДД.ММ.ГГГГ или ДД.ММ.ГГГГ ЧЧ:ММ.');
      return;
    }

    this.createTokenButton.disabled = true;

    try {
      const response = await BX.ajax.runAction('delight:mcp.Internal.createToken', {
        data: {
          identifier: tokenIdentifierInput.value,
          grants: Array.from(tokenGrantsCheckboxes).map(cb => cb.value),
          exp: tokenExpInput ? tokenExpInput.value : ''
        }
      });

      if (response.status === 'success') {
        prompt(
            'Токен успешно создан. Скопируйте его в надёжное место, он больше не будет показан.',
            response.data
        );
        this.reloadPage('tokens');
      } else {
        const errorMessage = response.errors && response.errors.length > 0 ? response.errors.map(e => e.message).join('\n') : 'Произошла неизвестная ошибка.';
        alert(`Ошибка при создании токена: ${errorMessage}`);
        this.createTokenButton.disabled = false;
      }
    } catch (error) {
      console.error('Error creating token:', error);
      const errorMessage = error.errors && error.errors.length > 0 ? error.errors.map(e => e.message).join('\n') : 'Произошла неизвестная ошибка.';
      alert(`Произошла ошибка при создании токена: ${errorMessage}`);
      this.createTokenButton.disabled = false;
    }
  }

  async deleteTokens(tokenIds) {
    if (!confirm('Подтверждаете удаление?')) {
      return;
    }

    try {
      const response = await BX.ajax.runAction('delight:mcp.Internal.deleteTokens', {
        data: {
          ids: tokenIds
        }
      });

      if (response.status === 'success') {
        this.reloadPage('tokens');
      } else {
        const errorMessage = response.errors && response.errors.length > 0 ? response.errors.map(e => e.message).join('\n') : 'Произошла неизвестная ошибка.';
        alert(`Ошибка при удалении токена: ${errorMessage}`);
      }
    } catch (error) {
      console.error('Error deleting token:', error);
      const errorMessage = error.errors && error.errors.length > 0 ? error.errors.map(e => e.message).join('\n') : 'Произошла неизвестная ошибка.';
      alert(`Произошла ошибка при удалении токена: ${errorMessage}`);
    }
  }

  deleteSelectedTokens() {
    const grid = BX.Main.gridManager.getInstanceById('delight_mcp_tokens_grid');
    if (grid) {
      const selectedIds = grid.getRows().getSelectedIds();
      if (selectedIds.length > 0) {
        this.deleteTokens(selectedIds);
      } else {
        alert('Выберите токены для удаления.');
      }
    }
  }

  handleReindexClick(event) {
    event.preventDefault();

    const tabControlLayout = document.getElementById('tabControl_layout');
    if (!tabControlLayout) {
      console.error('Anchor element #tabControl_layout not found!');
      alert('Не удалось найти место для отображения прогресс-бара.');
      return;
    }

    this.removeProgressBar();
    this.createProgressBar(tabControlLayout, 'Выполняется переиндексация документации Bitrix Framework', 'файлов');

    this.reindexButton.disabled = true;
    this.currentProcessedFiles = 0;
    this.totalFilesToProcess = 0;

    this.sendDocsReindexRequest(0);
  }

  handleReindexLiveApiClick(event) {
    event.preventDefault();

    const tabControlLayout = document.getElementById('tabControl_layout');
    if (!tabControlLayout) {
      console.error('Anchor element #tabControl_layout not found!');
      alert('Не удалось найти место для отображения прогресс-бара.');
      return;
    }

    this.removeProgressBar();
    this.createProgressBar(tabControlLayout, 'Выполняется переиндексация модулей', 'модулей');

    this.reindexLiveApiButton.disabled = true;
    this.currentProcessedFiles = 0;
    this.totalFilesToProcess = 0;

    this.sendLiveApiReindexRequest(0);
  }

  createProgressBar(anchorElement, text, unit) {
    this.progressBarContainer = document.createElement('div');
    this.progressBarContainer.className = 'js-delight-progressbar delight-progressbar-container';
    this.progressBarContainer.innerHTML = `
            <div class="ui-progressbar">
                <div class="ui-progressbar-text-before">${text}</div>
                <div class="ui-progressbar-track">
                    <div class="ui-progressbar-bar" style="width:0%;"></div>
                </div>
                <div class="ui-progressbar-text-after">0 ${unit} из 0</div>
            </div>
        `;

    anchorElement.parentNode.insertBefore(this.progressBarContainer, anchorElement);

    this.progressBar = this.progressBarContainer.querySelector('.ui-progressbar-bar');
    this.progressBarText = this.progressBarContainer.querySelector('.ui-progressbar-text-after');
  }

  updateProgressBar(processed, total, unit) {
    if (this.progressBar && this.progressBarText) {
      const percentage = total > 0 ? Math.round((processed / total) * 100) : 0;
      this.progressBar.style.width = `${percentage}%`;
      this.progressBarText.textContent = `${processed} ${unit} из ${total}`;
    }
  }

  reloadPage(activeTab) {
    const url = new URL(window.location.href);
    url.searchParams.set('tabControl_active_tab', activeTab);
    url.hash = '';
    window.location.href = url.toString();
  }

  async sendDocsReindexRequest(startIndex) {
    try {
      const response = await BX.ajax.runAction('delight:mcp.Internal.reindexDocs', {
        data: {
          start: startIndex,
          limit: this.BATCH_LIMIT
        }
      });

      if (response.status === 'success') {
        const responseData = response.data;
        const processedInStep = responseData.processed || 0;
        const totalFiles = responseData.total || 0;

        this.currentProcessedFiles += processedInStep;
        this.totalFilesToProcess = totalFiles;

        this.updateProgressBar(this.currentProcessedFiles, this.totalFilesToProcess, 'файлов');

        if (this.currentProcessedFiles < this.totalFilesToProcess) {
          await this.sendDocsReindexRequest(this.currentProcessedFiles);
        } else {
          alert('Переиндексация документации Bitrix Framework успешно завершена!');
          this.removeProgressBar();
          this.reindexButton.disabled = false;
          this.reloadPage('settings');
        }
      } else {
        const errorMessage = response.errors && response.errors.length > 0 ? response.errors.map(e => e.message).join('\n') : 'Произошла неизвестная ошибка.';
        alert(`Ошибка при переиндексации: ${errorMessage}`);
        this.removeProgressBar();
        this.reindexButton.disabled = false;
      }
    } catch (error) {
      console.error('Error during reindexing:', error);
      const errorMessage = error.errors && error.errors.length > 0 ? error.errors.map(e => e.message).join('\n') : 'Произошла неизвестная ошибка.';
      alert(`Произошла ошибка при переиндексации: ${errorMessage}`);
      this.removeProgressBar();
      this.reindexButton.disabled = false;
    }
  }

  async sendLiveApiReindexRequest(startIndex) {
    try {
      const response = await BX.ajax.runAction('delight:mcp.Internal.reindexLiveApi', {
        data: {
          start: startIndex,
          limit: this.BATCH_LIMIT
        }
      });

      if (response.status === 'success') {
        const responseData = response.data;
        const processedInStep = responseData.processed || 0;
        const totalFiles = responseData.total || 0;

        this.currentProcessedFiles += processedInStep;
        this.totalFilesToProcess = totalFiles;

        this.updateProgressBar(this.currentProcessedFiles, this.totalFilesToProcess, 'модулей');

        if (this.currentProcessedFiles < this.totalFilesToProcess) {
          await this.sendLiveApiReindexRequest(this.currentProcessedFiles);
        } else {
          alert('Переиндексация модулей успешно завершена!');
          this.removeProgressBar();
          this.reindexLiveApiButton.disabled = false;
          this.reloadPage('settings');
        }
      } else {
        const errorMessage = response.errors && response.errors.length > 0 ? response.errors.map(e => e.message).join('\n') : 'Произошла неизвестная ошибка.';
        alert(`Ошибка при переиндексации: ${errorMessage}`);
        this.removeProgressBar();
        this.reindexLiveApiButton.disabled = false;
      }
    } catch (error) {
      console.error('Error during reindexing:', error);
      const errorMessage = error.errors && error.errors.length > 0 ? error.errors.map(e => e.message).join('\n') : 'Произошла неизвестная ошибка.';
      alert(`Произошла ошибка при переиндексации: ${errorMessage}`);
      this.removeProgressBar();
      this.reindexLiveApiButton.disabled = false;
    }
  }

  async handleTestExternalApiClick(event) {
    event.preventDefault();

    if (!this.testResultsContainer) {
        console.error('Test results container not found!');
        return;
    }

    const token = prompt('Введите токен для тестирования API');
    if (!token) {
      return;
    }

    this.testExternalApiButton.disabled = true;
    this.testResultsContainer.innerHTML = '<div class="ui-alert ui-alert-info"><span class="ui-alert-message">Запускаем тесты...</span></div>';


    try {
      const response = await BX.ajax.runAction('delight:mcp.Internal.testExternalApi', {
        data: {
          token: token
        }
      });

      if (response.status === 'success') {
        const { summary, details, error } = response.data;
        let resultHtml = '';

        if (error) {
          resultHtml = `<div class="ui-alert ui-alert-danger"><span class="ui-alert-message"><strong>Ошибка:</strong> ${error}</span></div>`;
        } else {
          resultHtml = `
            <div class="ui-alert ui-alert-default">
                <span class="ui-alert-message">
                    <strong>Результаты тестирования:</strong><br>
                    Всего тестов: ${summary.total}<br>
                    Прошло: <span style="color: green;">${summary.passed}</span><br>
                    Провалилось: <span style="color: red;">${summary.failed}</span>
                </span>
            </div>
          `;

          if (details.length > 0) {
            resultHtml += '<h4>Детали:</h4>';
            details.forEach(item => {
              const detailsList = item.details && item.details.length > 0
                ? `<ul><li>${item.details.join('</li><li>')}</li></ul>`
                : 'Нет подробностей.';

              const statusColor = item.status === 'PASS' ? 'green' : 'red';

              let debugInfo = '';
              if (item.status === 'FAIL') {
                  if (item.schema) {
                      debugInfo += `
                        <strong>Ожидаемая схема:</strong>
                        <pre><code>${JSON.stringify(item.schema, null, 2)}</code></pre>
                      `;
                  }
                  if (item.response) {
                      debugInfo += `
                        <strong>Фактический ответ:</strong>
                        <pre><code>${JSON.stringify(item.response, null, 2)}</code></pre>
                      `;
                  }
              }

              resultHtml += `
                <div class="ui-alert">
                    <span class="ui-alert-message">
                        <strong>Тест:</strong> ${item.name}<br>
                        <strong>Статус:</strong> <span style="color: ${statusColor};">${item.status}</span><br>
                        <strong>Сообщение:</strong> ${item.message}<br>
                        <strong>Подробности валидации:</strong>
                        ${detailsList}
                        ${debugInfo}
                    </span>
                </div>
              `;
            });
          }
        }
        this.testResultsContainer.innerHTML = resultHtml;
      } else {
        const errorMessage = response.errors && response.errors.length > 0 ? response.errors.map(e => e.message).join('\n') : 'Произошла неизвестная ошибка.';
        this.testResultsContainer.innerHTML = `<div class="ui-alert ui-alert-danger"><span class="ui-alert-message"><strong>Ошибка при запуске тестов:</strong> ${errorMessage}</span></div>`;
      }
    } catch (error) {
      console.error('Error running external API tests:', error);
      const errorMessage = error.errors && error.errors.length > 0 ? error.errors.map(e => e.message).join('\n') : 'Произошла неизвестная ошибка.';
      this.testResultsContainer.innerHTML = `<div class="ui-alert ui-alert-danger"><span class="ui-alert-message"><strong>Произошла ошибка при запуске тестов:</strong> ${errorMessage}</span></div>`;
    } finally {
      this.testExternalApiButton.disabled = false;
    }
  }

  removeProgressBar() {
    const progressBarContainer = document.querySelector('.js-delight-progressbar');
    if (progressBarContainer) {
      progressBarContainer.remove();
    }
  }
}

document.addEventListener('DOMContentLoaded', () => {
  window.delightMcpOptionsComponent = new DelightMcpOptionsComponent();
  window.delightMcpOptionsComponent.init();
});
