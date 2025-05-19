/**
 * Admin JavaScript для GitPush WP
 */
jQuery(document).ready(function($) {
    // Определяем текущую страницу
    const currentPage = window.location.href.includes('page=gitpush-wp-settings') 
        ? 'settings' 
        : 'sync';
    
    // Элементы общие для всех страниц
    const testConnectionBtn = document.getElementById('test-connection');
    const statusContainer = document.getElementById('sync-status');
    
    // Элементы только для страницы синхронизации
    let /* pullFromGithubBtn, */ syncThemeBtn, commitSelectedBtn, refreshFilesBtn, // pullFromGithubBtn закомментирован
        showAllFilesBtn, showChangedFilesBtn, gitpushContainer, filesPanel,
        diffPanel, filesListContainer, loadingIndicator, diffContent, commitHistoryContent;
    
    if (currentPage === 'sync') {
        // pullFromGithubBtn = document.getElementById('pull-from-github'); // Закомментировано
        syncThemeBtn = document.getElementById('sync-theme'); // Эта кнопка пока не используется явно, используется commitSelectedBtn
        commitSelectedBtn = document.getElementById('commit-selected');
        
        gitpushContainer = document.getElementById('gitpush-container');
        if (gitpushContainer) {
            filesPanel = gitpushContainer.querySelector('.gitpush-files-panel');
            diffPanel = gitpushContainer.querySelector('.gitpush-diff-panel');
            filesListContainer = filesPanel.querySelector('.files-list');
            loadingIndicator = filesPanel.querySelector('.loading');
            diffContent = diffPanel.querySelector('.diff-content');
            commitHistoryContent = diffPanel.querySelector('.commit-history-content');
            
            refreshFilesBtn = document.getElementById('refresh-files');
            showAllFilesBtn = document.getElementById('show-all-files');
            showChangedFilesBtn = document.getElementById('show-changed-files');
        }
    }
    
    // Состояние приложения
    let currentFiles = [];
    let selectedFileForDiff = null; // Переименовано для ясности
    let viewMode = 'changed'; // 'all' или 'changed'
    
    // Получить список всех файлов темы
    async function getAllThemeFiles() {
        if (!loadingIndicator || !filesListContainer) return;
        try {
            loadingIndicator.style.display = 'block';
            filesListContainer.innerHTML = '';
            diffContent.innerHTML = '<p class="diff-instructions">Select a file to view changes</p>';
            if (commitHistoryContent) commitHistoryContent.innerHTML = '';


            const response = await fetch(gitpush_wp_obj.ajax_url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'github_get_changed_files',
                    nonce: gitpush_wp_obj.nonce
                })
            });
            
            const data = await response.json();
            
            if (data.success && data.data && data.data.files) {
                currentFiles = data.data.files;
                renderFileList(currentFiles.filter(file => file.type === 'file')); // Показываем только файлы, не директории для выбора
                viewMode = 'all';
                updateViewModeButtons();
            } else {
                showStatus('error', data.data?.message || data.data || 'Failed to load theme files.');
                console.error("Error in getAllThemeFiles:", data);
            }
        } catch (error) {
            showStatus('error', 'Error loading theme files: ' + error.message);
            console.error("Exception in getAllThemeFiles:", error);
        } finally {
            if (loadingIndicator) loadingIndicator.style.display = 'none';
        }
    }
    
    // Получить только измененные файлы
    async function getChangedFiles() {
        if (!loadingIndicator || !filesListContainer) return;
        try {
            loadingIndicator.style.display = 'block';
            filesListContainer.innerHTML = '';
            diffContent.innerHTML = '<p class="diff-instructions">Select a file to view changes</p>';
            if (commitHistoryContent) commitHistoryContent.innerHTML = '';

            const response = await fetch(gitpush_wp_obj.ajax_url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'github_get_changed_files',
                    nonce: gitpush_wp_obj.nonce
                })
            });
            
            const data = await response.json();
            
            if (data.success && data.data && data.data.files) {
                currentFiles = data.data.files.filter(file => 
                    file.type === 'file' && ['new', 'modified', 'deleted'].includes(file.status)
                );
                renderFileList(currentFiles);
                viewMode = 'changed';
                updateViewModeButtons();
                 if (currentFiles.length === 0) {
                    filesListContainer.innerHTML = '<li>No changed files found.</li>';
                }
            } else {
                showStatus('error', data.data?.message || data.data || 'Failed to load changed files.');
                console.error("Error in getChangedFiles:", data);
                 filesListContainer.innerHTML = '<li>Error loading changed files.</li>';
            }
        } catch (error) {
            showStatus('error', 'Error loading changed files: ' + error.message);
            console.error("Exception in getChangedFiles:", error);
            filesListContainer.innerHTML = '<li>Error loading changed files.</li>';
        } finally {
            if (loadingIndicator) loadingIndicator.style.display = 'none';
        }
    }
    
    // Обновить кнопки режима просмотра
    function updateViewModeButtons() {
        if (!showAllFilesBtn || !showChangedFilesBtn) return;
        
        showAllFilesBtn.classList.toggle('button-primary', viewMode === 'all');
        showAllFilesBtn.classList.toggle('button-secondary', viewMode !== 'all');
        
        showChangedFilesBtn.classList.toggle('button-primary', viewMode === 'changed');
        showChangedFilesBtn.classList.toggle('button-secondary', viewMode !== 'changed');
    }
    
    // Отобразить список файлов
    function renderFileList(files) {
        if (!filesListContainer) return;
        
        filesListContainer.innerHTML = ''; // Очищаем перед рендером
        
        if (!files || files.length === 0) {
            if (viewMode === 'changed') {
                 filesListContainer.innerHTML = '<li>No changed files found.</li>';
            } else {
                 filesListContainer.innerHTML = '<li>No files found in the theme.</li>';
            }
            return;
        }
        
        // Сортировка файлов по имени
        files.sort((a, b) => a.path.localeCompare(b.path));
        
        files.forEach(file => {
            const listItem = document.createElement('li');
            listItem.className = 'file'; // Всегда файл, т.к. директории отфильтрованы
            
            if (file.status) {
                listItem.classList.add('status-' + file.status);
            }
            
            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.value = file.path;
            checkbox.id = 'file-' + file.path.replace(/[\/\.\s]/g, '-'); // Убрал точку из замены, чтобы не ломать id для файлов с несколькими точками
            checkbox.dataset.filePath = file.path; // Сохраняем путь для легкого доступа
            checkbox.checked = ['new', 'modified', 'deleted'].includes(file.status); // Автовыбор измененных


            const statusIconSpan = document.createElement('span');
            statusIconSpan.className = 'file-status'; // Для dashicon
            // Иконки будут добавлены через CSS :before на основе класса status-*

            const label = document.createElement('label');
            label.htmlFor = checkbox.id;
            label.textContent = file.path;
            
            listItem.appendChild(checkbox);
            listItem.appendChild(statusIconSpan); // Добавляем span для иконки статуса
            listItem.appendChild(label);
            
            listItem.addEventListener('click', function(e) {
                if (e.target !== checkbox) { // Клик не на чекбокс
                    document.querySelectorAll('.files-list li').forEach(li => li.classList.remove('active'));
                    listItem.classList.add('active');
                    selectFileForDiff(file.path);
                    fetchFileCommits(file.path);
                }
            });
            filesListContainer.appendChild(listItem);
        });
    }
    
    // Выбрать файл для просмотра изменений
    async function selectFileForDiff(filePath) {
        if (!diffContent) return;
        
        selectedFileForDiff = filePath;
        
        try {
            diffContent.innerHTML = '<div class="loading">Loading file diff...</div>';
            
            const response = await fetch(gitpush_wp_obj.ajax_url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'github_get_file_diff',
                    nonce: gitpush_wp_obj.nonce,
                    file_path: filePath
                })
            });
            
            const data = await response.json();
            
            if (data.success && data.data) {
                renderFileDiff(filePath, data.data);
            } else {
                diffContent.innerHTML = `<div class="error">Error loading diff: ${data.data?.message || data.data || 'Unknown error'}</div>`;
                console.error("Error in selectFileForDiff (AJAX):", data);
            }
        } catch (error) {
            diffContent.innerHTML = `<div class="error">Error: ${error.message}</div>`;
            console.error("Exception in selectFileForDiff:", error);
        }
    }

    // Загрузка истории коммитов для файла
    async function fetchFileCommits(filePath) {
        if (!commitHistoryContent) return;
        commitHistoryContent.innerHTML = '<div class="loading">Loading commit history...</div>';

        try {
            const response = await fetch(gitpush_wp_obj.ajax_url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'github_get_file_commits',
                    nonce: gitpush_wp_obj.nonce,
                    file_path: filePath
                })
            });
            const data = await response.json();
            if (data.success && data.data && data.data.commits) {
                renderCommitHistory(data.data.commits);
            } else {
                commitHistoryContent.innerHTML = `<div class="error">Error loading commit history: ${data.data?.message || data.data || 'No history found.'}</div>`;
            }
        } catch (error) {
            commitHistoryContent.innerHTML = `<div class="error">Error: ${error.message}</div>`;
        }
    }

    function renderCommitHistory(commits) {
        if (!commitHistoryContent) return;
        commitHistoryContent.innerHTML = '';
        if (!commits || commits.length === 0) {
            commitHistoryContent.innerHTML = '<p>No commit history available for this file.</p>';
            return;
        }
        const ul = document.createElement('ul');
        commits.slice(0, 5).forEach(commit => { // Показываем последние 5
            const li = document.createElement('li');
            li.className = 'commit-history-item';
            li.innerHTML = `
                <div class="commit-history-message">${escapeHtml(commit.message)}</div>
                <div class="commit-history-meta">
                    <strong>${escapeHtml(commit.author)}</strong> on ${new Date(commit.date).toLocaleDateString()}
                    <em>(${commit.sha.substring(0,7)})</em>
                </div>`;
            ul.appendChild(li);
        });
        commitHistoryContent.appendChild(ul);
    }

    function escapeHtml(unsafe) {
        if (unsafe === null || typeof unsafe === 'undefined') return '';
        return unsafe
             .toString()
             .replace(/&/g, "&amp;")
             .replace(/</g, "&lt;")
             .replace(/>/g, "&gt;")
             .replace(/"/g, "&quot;")
             .replace(/'/g, "&#039;");
    }
    
    // Отобразить различия в файле
    function renderFileDiff(filePath, diffData) {
        if (!diffContent || !diffPanel) return;
        
        const { local_content, github_content, status } = diffData;
        
        diffContent.innerHTML = ''; // Очищаем предыдущий дифф
        
        const diffHeaderP = diffPanel.querySelector('.diff-header p.diff-instructions');
        if(diffHeaderP) diffHeaderP.style.display = 'none'; // Скрываем инструкцию

        const diffHeaderH3 = diffPanel.querySelector('.diff-header h3');
        if(diffHeaderH3) diffHeaderH3.textContent = filePath;
        
        // Добавляем статус файла под заголовком
        let statusDisplay = diffPanel.querySelector('.diff-status-display');
        if (!statusDisplay) {
            statusDisplay = document.createElement('p');
            statusDisplay.className = 'diff-status-display';
            diffHeaderH3.insertAdjacentElement('afterend', statusDisplay);
        }
        statusDisplay.textContent = `Status: ${status}`;
        statusDisplay.className = `diff-status-display status-${status}`;


        const pre = document.createElement('pre');
        const code = document.createElement('code'); // Для highlight.js
        
        // Получаем расширение файла для подсветки синтаксиса
        const extension = filePath.split('.').pop().toLowerCase();
        if (extension) {
            code.className = 'language-' + extension;
        }

        if (status === 'deleted' || status === 'deleted_locally') {
            code.textContent = github_content; // Показываем содержимое с GitHub, т.к. локально его нет или оно было удалено
            if (!github_content) code.textContent = "// File deleted and no content on GitHub branch to display.";
        } else if (status === 'new') {
            code.textContent = local_content; // Новый файл, показываем локальное содержимое
        } else if (status === 'unchanged') {
            code.textContent = local_content; // Не изменен, показываем локальное (или с GitHub, они идентичны)
        } else if (status === 'modified') {
            // Для измененных файлов генерируем простой diff view
            const localLines = local_content.split('\n');
            const githubLines = github_content.split('\n');
            const maxLines = Math.max(localLines.length, githubLines.length);
            let diffHtml = '';

            for (let i = 0; i < maxLines; i++) {
                const localLine = localLines[i];
                const githubLine = githubLines[i];

                if (typeof localLine !== 'undefined' && typeof githubLine !== 'undefined') {
                    if (localLine !== githubLine) {
                        diffHtml += `<span class="gitpush-diff-line removed"><span class="gitpush-diff-line-number">-</span><span class="gitpush-diff-line-content">${escapeHtml(githubLine)}</span></span>\n`;
                        diffHtml += `<span class="gitpush-diff-line added"><span class="gitpush-diff-line-number">+</span><span class="gitpush-diff-line-content">${escapeHtml(localLine)}</span></span>\n`;
                    } else {
                        diffHtml += `<span class="gitpush-diff-line"><span class="gitpush-diff-line-number">${i + 1}</span><span class="gitpush-diff-line-content">${escapeHtml(localLine)}</span></span>\n`;
                    }
                } else if (typeof localLine !== 'undefined') { // Строка добавлена
                    diffHtml += `<span class="gitpush-diff-line added"><span class="gitpush-diff-line-number">+</span><span class="gitpush-diff-line-content">${escapeHtml(localLine)}</span></span>\n`;
                } else if (typeof githubLine !== 'undefined') { // Строка удалена
                    diffHtml += `<span class="gitpush-diff-line removed"><span class="gitpush-diff-line-number">-</span><span class="gitpush-diff-line-content">${escapeHtml(githubLine)}</span></span>\n`;
                }
            }
            code.innerHTML = diffHtml; // Используем innerHTML, так как добавили span'ы
        } else {
             code.textContent = "// Could not determine file status or generate diff.";
        }
        
        pre.appendChild(code);
        diffContent.appendChild(pre);
        
        if (window.hljs) {
            hljs.highlightElement(code);
        }
    }
    
    // Показать статус операции
    function showStatus(type, message, detailsList = null) {
        if (!statusContainer) return;
        
        statusContainer.innerHTML = ''; // Очищаем предыдущие сообщения
        statusContainer.className = 'notice notice-' + (type === 'success' ? 'success is-dismissible' : 'error is-dismissible');
        
        const paragraph = document.createElement('p');
        paragraph.innerHTML = `<strong>${escapeHtml(message)}</strong>`; // Используем innerHTML для bold
        
        statusContainer.appendChild(paragraph);

        if (detailsList && Array.isArray(detailsList) && detailsList.length > 0) {
            const ul = document.createElement('ul');
            ul.className = 'sync-results';
            detailsList.forEach(item => {
                const li = document.createElement('li');
                li.className = item.type || type; // success, error, deleted, updated, created
                li.textContent = `${escapeHtml(item.file)}: ${escapeHtml(item.status_text)}`;
                ul.appendChild(li);
            });
            statusContainer.appendChild(ul);
        }
        
        statusContainer.style.display = 'block';
        
        // Добавляем кнопку закрытия для "is-dismissible"
        const dismissButton = document.createElement('button');
        dismissButton.type = 'button';
        dismissButton.className = 'notice-dismiss';
        dismissButton.innerHTML = '<span class="screen-reader-text">Dismiss this notice.</span>';
        dismissButton.onclick = () => { statusContainer.style.display = 'none'; };
        statusContainer.appendChild(dismissButton);

        // Не скрываем автоматически, так как есть кнопка закрытия
    }
    
    // Обработчики событий для кнопок
    if (testConnectionBtn) {
        testConnectionBtn.addEventListener('click', async function() {
            try {
                testConnectionBtn.disabled = true;
                testConnectionBtn.textContent = 'Testing...';
                statusContainer.style.display = 'none'; // Скрываем старый статус
                
                const response = await fetch(gitpush_wp_obj.ajax_url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        action: 'github_test_connection',
                        nonce: gitpush_wp_obj.nonce
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    showStatus('success', data.data);
                    if (currentPage === 'sync' && gitpushContainer) { // Если на странице синхронизации и контейнер существует
                        getChangedFiles(); // Загружаем измененные файлы после успешного теста
                    }
                } else {
                    showStatus('error', data.data?.message || data.data || "Connection test failed.");
                    console.error("Test connection error:", data);
                }
            } catch (error) {
                showStatus('error', 'Error testing connection: ' + error.message);
                console.error("Test connection exception:", error);
            } finally {
                if (testConnectionBtn) {
                    testConnectionBtn.disabled = false;
                    testConnectionBtn.textContent = 'Test GitHub Connection';
                }
            }
        });
    }
    
    if (currentPage === 'sync' && gitpushContainer) { // Добавляем проверку на gitpushContainer
        /* // Логика для Pull удалена
        if (pullFromGithubBtn) {
            pullFromGithubBtn.addEventListener('click', async function() {
                // ...
            });
        }
        */
        
        if (showAllFilesBtn) {
            showAllFilesBtn.addEventListener('click', function() {
                if (viewMode !== 'all') {
                    getAllThemeFiles();
                }
            });
        }
        
        if (showChangedFilesBtn) {
            showChangedFilesBtn.addEventListener('click', function() {
                if (viewMode !== 'changed') {
                    getChangedFiles();
                }
            });
        }
        
        if (refreshFilesBtn) {
            refreshFilesBtn.addEventListener('click', function() {
                statusContainer.style.display = 'none';
                if (viewMode === 'all') {
                    getAllThemeFiles();
                } else {
                    getChangedFiles();
                }
            });
        }
        
        if (commitSelectedBtn) {
            commitSelectedBtn.addEventListener('click', async function() {
                try {
                    const selectedCheckboxes = document.querySelectorAll('.files-list input[type="checkbox"]:checked');
                    const selectedFiles = Array.from(selectedCheckboxes).map(checkbox => checkbox.value);
                    
                    if (selectedFiles.length === 0) {
                        showStatus('error', 'Please select at least one file to commit.');
                        return;
                    }
                    
                    const commitMessageInput = document.getElementById('commit-message');
                    const commitMessage = commitMessageInput.value.trim() || 'Update from WordPress Admin';
                    
                    commitSelectedBtn.disabled = true;
                    commitSelectedBtn.textContent = 'Committing...';
                    statusContainer.style.display = 'none';
                    
                    const response = await fetch(gitpush_wp_obj.ajax_url, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({
                            action: 'github_sync_theme',
                            nonce: gitpush_wp_obj.nonce,
                            files: JSON.stringify(selectedFiles),
                            commit_message: commitMessage
                        })
                    });
                    
                    const data = await response.json();
                    
                    if (data.success && data.data) {
                        const resultsForDisplay = [];
                        if (data.data.results && typeof data.data.results === 'object') {
                            Object.entries(data.data.results).forEach(([file, status]) => {
                                let status_text = 'Unknown';
                                let type = 'info';
                                switch (status) {
                                    case 'updated': status_text = 'Updated'; type = 'success'; break;
                                    case 'created': status_text = 'Created'; type = 'success'; break;
                                    case 'deleted': status_text = 'Deleted'; type = 'success'; break; // или 'deleted' class
                                    case 'skipped_not_on_github': status_text = 'Skipped (not on GitHub or SHA missing)'; type = 'warning'; break;
                                    case 'error_deleting': status_text = 'Error Deleting'; type = 'error'; break;
                                    case 'error_pushing': status_text = 'Error Pushing'; type = 'error'; break;
                                    default: status_text = status; type = 'error';
                                }
                                resultsForDisplay.push({ file, status_text, type });
                            });
                        }
                        
                        const mainMessage = data.data.message || 'Sync process completed.';
                        showStatus('success', `${mainMessage} (Synced at: ${data.data.sync_time || 'N/A'})`, resultsForDisplay);
                        
                        // Обновляем список измененных файлов, чтобы отразить изменения
                        setTimeout(() => { // Небольшая задержка, чтобы GitHub успел обработать
                            getChangedFiles();
                             if(commitMessageInput) commitMessageInput.value = "Update from WordPress Admin"; // Сброс сообщения
                        }, 1500);

                    } else {
                        showStatus('error', data.data?.message || data.data || 'Failed to commit files.');
                        console.error("Commit error:", data);
                    }
                } catch (error) {
                    showStatus('error', 'Error committing files: ' + error.message);
                    console.error("Commit exception:", error);
                } finally {
                    if (commitSelectedBtn) {
                        commitSelectedBtn.disabled = false;
                        commitSelectedBtn.textContent = 'Commit Selected Files';
                    }
                }
            });
        }
        
        // Первоначальная загрузка измененных файлов, если мы на странице синхронизации и GitHub настроен
        if (gitpush_wp_obj.github_username && gitpush_wp_obj.github_repo) {
            getChangedFiles();
        } else {
            if (loadingIndicator) loadingIndicator.style.display = 'none';
            if (filesListContainer) filesListContainer.innerHTML = '<li>Please configure GitHub settings first.</li>';
        }
    } else if (currentPage === 'sync' && !gitpushContainer) {
        // Если мы на странице синхронизации, но основной контейнер не найден (например, настройки не сконфигурированы)
        // Можно добавить сообщение или оставить как есть, т.к. PHP должен выводить предупреждение
         console.warn("GitPush WP: Sync page loaded, but main container not found. GitHub might not be configured.");
    }

    // Фрагмент для js/admin.js

    function display_changed_files(files) {
        var listHtml = '';
        var fileList = $('#gitpush-changed-files-list');
        var noChangesMessage = $('#gitpush-no-changes');

        if (files && files.length > 0) {
            listHtml = '<ul>';
            files.forEach(function(file) {
                if (typeof file.path === 'undefined' || file.path === null) {
                    console.warn('File with undefined path skipped:', file);
                    return; // Пропустить этот файл
                }

                var fileStatusIcon = '';
                var fileStatusClass = '';
                var statusText = ''; // Текстовое описание статуса

                switch(file.status) {
                    case 'new':
                        fileStatusIcon = '<span class="dashicons dashicons-plus-alt2 gitpush-status-new"></span>';
                        fileStatusClass = 'gitpush-file-new';
                        statusText = 'New';
                        break;
                    case 'modified':
                        fileStatusIcon = '<span class="dashicons dashicons-edit gitpush-status-modified"></span>';
                        fileStatusClass = 'gitpush-file-modified';
                        statusText = 'Modified';
                        break;
                    case 'deleted':
                        fileStatusIcon = '<span class="dashicons dashicons-minus gitpush-status-deleted"></span>';
                        fileStatusClass = 'gitpush-file-deleted';
                        statusText = 'Deleted';
                        break;
                    default:
                        fileStatusIcon = '<span class="dashicons dashicons-info-outline"></span>'; // Для неизвестных или не отслеживаемых
                        fileStatusClass = 'gitpush-file-unknown';
                        statusText = 'Unknown';
                }
                // Используем escape для file.path, если он может содержать спецсимволы, но для простого отображения обычно не критично
                listHtml += '<li class="' + fileStatusClass + '">' +
                            '<input type="checkbox" name="selected_files[]" value="' + file.path.replace(/"/g, '&quot;') + '" checked data-status="' + file.status + '"> ' +
                            fileStatusIcon + ' ' + file.path +
                            ' <span class="file-status-text">(' + statusText + ')</span>' +
                            '</li>';
            });
            listHtml += '</ul>';
            fileList.html(listHtml);
            noChangesMessage.hide();
            fileList.show();
        } else {
            fileList.empty().hide();
            noChangesMessage.show();
        }
    }

    // Убедитесь, что остальная часть admin.js (обработчики кликов, AJAX-запросы)
    // корректно вызывает display_changed_files с данными от сервера.
    // Особенно важно, чтобы после успешного AJAX-запроса на push,
    // response.data.changed_files содержал АКТУАЛЬНЫЙ список измененных файлов
    // (который должен быть пустым, если все выбранные файлы успешно отправлены).

    // Пример обработчика для кнопки "Refresh Files":
    $('#gitpush-refresh-button').on('click', function() {
        var $this = $(this);
        var originalText = $this.text();
        $this.text('Refreshing...');
        $('#gitpush-changed-files-list').html('<li>Loading...</li>');
        $('#gitpush-no-changes').hide();

        $.post(ajaxurl, { action: 'gitpush_get_changed_files', nonce: gitpush_vars.nonce, force_refresh: true }, function(response) {
            if (response.success) {
                display_changed_files(response.data.changed_files);
            } else {
                $('#gitpush-changed-files-list').html('<li>Error loading files: ' + response.data.message + '</li>');
            }
            $this.text(originalText);
        }).fail(function() {
            $('#gitpush-changed-files-list').html('<li>Request failed. Please try again.</li>');
            $this.text(originalText);
        });
    });


    // Пример части обработчика для кнопки "Push to GitHub"
    //$('#gitpush-push-button').on('click', function() {
    // ... ваш существующий AJAX-запрос ...
    // Внутри .done() или success callback:
    // if (response.success) {
    //     ...
    //     display_changed_files(response.data.changed_files); // ОБЯЗАТЕЛЬНО обновить список файлов
    //     ...
    // }
    //});
});