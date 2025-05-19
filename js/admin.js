/**
 * Admin JavaScript для GitPush WP
 */
jQuery(document).ready(function($) { // Обертка для корректной работы $
    
    const ajax_url = gitpush_wp_obj.ajax_url; // Используем переданные через wp_localize_script
    const nonce = gitpush_wp_obj.nonce;

    // Определяем текущую страницу
    const currentPage = window.location.href.includes('page=gitpush-wp-settings') 
        ? 'settings' 
        : 'sync';
    
    const testConnectionBtn = $('#test-connection'); // Используем $
    const statusContainer = $('#sync-status');    // Используем $
    
    let syncThemeBtn, commitSelectedBtn, refreshFilesBtn,
        showAllFilesBtn, showChangedFilesBtn, gitpushContainer, filesPanel,
        diffPanel, filesListContainer, loadingIndicator, diffContent, commitHistoryContent;
    
    if (currentPage === 'sync') {
        syncThemeBtn = $('#sync-theme'); 
        commitSelectedBtn = $('#commit-selected');
        
        gitpushContainer = $('#gitpush-container');
        if (gitpushContainer.length) { // Проверяем, что элемент существует
            filesPanel = gitpushContainer.find('.gitpush-files-panel');
            diffPanel = gitpushContainer.find('.gitpush-diff-panel');
            filesListContainer = filesPanel.find('.files-list');
            loadingIndicator = filesPanel.find('.loading');
            diffContent = diffPanel.find('.diff-content');
            commitHistoryContent = diffPanel.find('.commit-history-content'); // Убедимся, что элемент существует в HTML
            
            refreshFilesBtn = $('#refresh-files');
            showAllFilesBtn = $('#show-all-files');
            showChangedFilesBtn = $('#show-changed-files');
        }
    }
    
    let currentViewFiles = []; // Переименовал для ясности
    let selectedFileForDiffPath = null; 
    let viewMode = 'changed'; 
    
    function escapeHtml(unsafe) {
        if (unsafe === null || typeof unsafe === 'undefined') return '';
        return $('<div>').text(unsafe).html(); // Простой способ экранирования с jQuery
    }

    function showStatus(type, message, details = null) {
        if (!statusContainer.length) return;
        
        statusContainer.empty(); 
        statusContainer.removeClass('notice-success notice-error notice-warning notice-info is-dismissible');
        statusContainer.addClass('notice notice-' + type + ' is-dismissible');
        
        statusContainer.append($('<p>').html('<strong>' + escapeHtml(message) + '</strong>'));

        if (details && Array.isArray(details) && details.length > 0) {
            const $ul = $('<ul>').addClass('sync-results');
            details.forEach(function(item) {
                $('<li>')
                    .addClass(item.type || type) // success, error, warning
                    .text(escapeHtml(item.path) + ': ' + escapeHtml(item.status_text))
                    .appendTo($ul);
            });
            statusContainer.append($ul);
        }
        
        statusContainer.append(
            $('<button>')
                .attr('type', 'button')
                .addClass('notice-dismiss')
                .html('<span class="screen-reader-text">Dismiss this notice.</span>')
                .on('click', function() { statusContainer.hide(); })
        );
        statusContainer.show();
    }

    function renderFileList(files) {
        if (!filesListContainer || !filesListContainer.length) return;
        
        filesListContainer.empty(); 
        
        if (!files || files.length === 0) {
            const message = (viewMode === 'changed') ? 'No changed files found.' : 'No files found in the theme.';
            filesListContainer.html('<li>' + message + '</li>');
            return;
        }
        
        files.sort((a, b) => (a.path || '').localeCompare(b.path || ''));
        
        files.forEach(function(file) {
            if (!file || typeof file.path === 'undefined') return;

            let fileStatusIcon = '';
            let fileStatusClass = '';
            let statusText = file.status || 'unknown';

            switch(file.status) {
                case 'new':
                    fileStatusIcon = '<span class="dashicons dashicons-plus-alt2 gitpush-status-new"></span>';
                    fileStatusClass = 'gitpush-file-new';
                    break;
                case 'modified':
                    fileStatusIcon = '<span class="dashicons dashicons-edit gitpush-status-modified"></span>';
                    fileStatusClass = 'gitpush-file-modified';
                    break;
                case 'deleted':
                    fileStatusIcon = '<span class="dashicons dashicons-minus gitpush-status-deleted"></span>';
                    fileStatusClass = 'gitpush-file-deleted';
                    break;
                default:
                    fileStatusIcon = '<span class="dashicons dashicons-info-outline"></span>';
                    fileStatusClass = 'gitpush-file-unknown';
            }

            const $listItem = $('<li>')
                .addClass('file ' + fileStatusClass)
                .data('filePath', file.path); // Сохраняем путь в data-атрибуте

            const checkboxId = 'file-' + file.path.replace(/[\/\.\s\W]/g, '-'); // Более надежный ID
            const $checkbox = $('<input>')
                .attr('type', 'checkbox')
                .attr('value', file.path)
                .attr('id', checkboxId)
                .prop('checked', ['new', 'modified', 'deleted'].includes(file.status));

            const $label = $('<label>')
                .attr('for', checkboxId)
                .html(fileStatusIcon + ' ' + escapeHtml(file.path) + ' <span class="file-status-text">(' + escapeHtml(statusText) + ')</span>');
            
            $listItem.append($checkbox).append($label);
            
            $listItem.on('click', function(e) {
                if (e.target !== $checkbox[0]) { 
                    filesListContainer.find('li').removeClass('active');
                    $listItem.addClass('active');
                    selectedFileForDiffPath = file.path;
                    fetchFileDiff(file.path);
                    fetchFileCommits(file.path); // Загрузка истории коммитов при выборе файла
                }
            });
            filesListContainer.append($listItem);
        });
    }
    
    async function fetchFiles(actionType, forceRefresh = false) {
        if (!loadingIndicator || !filesListContainer) {
             console.error("File list UI elements not found for fetchFiles.");
             return;
        }
        loadingIndicator.show();
        filesListContainer.empty();
        if (diffContent && diffContent.length) diffContent.html('<p class="diff-instructions">Select a file to view changes</p>');
        if (commitHistoryContent && commitHistoryContent.length) commitHistoryContent.empty();

        try {
            const response = await $.post(ajax_url, {
                action: actionType, // 'github_get_changed_files' or 'github_get_theme_files'
                nonce: nonce,
                force_refresh: forceRefresh 
            });

            if (response.success && response.data && response.data.changed_files) { // AJAX Handler возвращает 'changed_files'
                currentViewFiles = response.data.changed_files;
                if (actionType === 'github_get_changed_files' || viewMode === 'changed') {
                    // Для "Changed Files" фильтруем дополнительно, если сервер вернул больше чем надо
                     currentViewFiles = currentViewFiles.filter(file => 
                        file && typeof file.status !== 'undefined' && ['new', 'modified', 'deleted'].includes(file.status)
                    );
                }
                renderFileList(currentViewFiles);
                updateViewModeButtons();
            } else {
                const errorMsg = response.data?.message || response.data || 'Failed to load files.';
                showStatus('error', errorMsg);
                filesListContainer.html('<li>' + escapeHtml(errorMsg) + '</li>');
                console.error("Error in fetchFiles ("+actionType+"):", response);
            }
        } catch (error) {
            const errorMsg = 'Error during file loading: ' + (error.statusText || error.message);
            showStatus('error', errorMsg);
            filesListContainer.html('<li>' + escapeHtml(errorMsg) + '</li>');
            console.error("Exception in fetchFiles ("+actionType+"):", error);
        } finally {
            if (loadingIndicator) loadingIndicator.hide();
        }
    }

    function updateViewModeButtons() {
        if (!showAllFilesBtn || !showChangedFilesBtn) return;
        showAllFilesBtn.toggleClass('button-primary', viewMode === 'all').toggleClass('button-secondary', viewMode !== 'all');
        showChangedFilesBtn.toggleClass('button-primary', viewMode === 'changed').toggleClass('button-secondary', viewMode !== 'changed');
    }
    
    async function fetchFileDiff(filePath) {
        if (!diffContent || !diffContent.length) return;
        diffContent.html('<div class="loading">Loading file diff...</div>');
        
        try {
            const response = await $.post(ajax_url, {
                action: 'github_get_file_diff',
                nonce: nonce,
                file_path: filePath
            });

            if (response.success && response.data && response.data.diff) { // ajax_get_file_diff возвращает {success: true, data: {diff_data}}
                const diffData = response.data.diff; // Это объект с local_content, github_content, status, diff_output
                renderFileDiff(filePath, diffData);
            } else {
                const errorMsg = response.data?.message || response.data?.error || response.data || 'Error loading diff.';
                diffContent.html('<div class="error">' + escapeHtml(errorMsg) + '</div>');
                console.error("Error loading diff (AJAX):", response);
            }
        } catch (error) {
            diffContent.html('<div class="error">Request Error: ' + escapeHtml(error.statusText || error.message) + '</div>');
            console.error("Exception loading diff:", error);
        }
    }

    function renderFileDiff(filePath, diffData) {
        if (!diffContent || !diffPanel || !diffPanel.length) return;
    
        diffContent.empty();
    
        const $diffHeader = diffPanel.find('.diff-header');
        $diffHeader.find('h3').text(filePath);
        $diffHeader.find('p.diff-instructions').hide();
    
        let $statusDisplay = $diffHeader.find('.diff-status-display');
        if (!$statusDisplay.length) {
            $statusDisplay = $('<p>').addClass('diff-status-display').insertAfter($diffHeader.find('h3'));
        }
        $statusDisplay.text('Status: ' + (diffData.status || 'unknown'))
                      .removeClass (function (index, className) {
                          return (className.match (/(^|\s)status-\S+/g) || []).join(' ');
                      })
                      .addClass('status-' + (diffData.status || 'unknown'));
    
        const $pre = $('<pre>');
        const $code = $('<code>');
    
        const extension = filePath.split('.').pop().toLowerCase();
        if (extension) {
            $code.addClass('language-' + extension);
        }
    
        if (diffData.status === 'new') {
            $code.text(diffData.local_content || "// New file - no local content provided for diff view.");
        } else if (diffData.status === 'deleted') {
            $code.text(diffData.github_content || "// File deleted locally - no GitHub content provided for diff view.");
        } else if (diffData.status === 'unchanged' || diffData.status === 'identical') {
            $code.text(diffData.local_content || "// File unchanged - no content provided.");
        } else if (diffData.status === 'modified' && diffData.diff_output) {
             // WP_Text_Diff_Renderer_inline генерирует HTML, поэтому используем .html()
            $code.html(diffData.diff_output);
        } else if (diffData.status === 'error_github') {
            $code.text('// Error fetching content from GitHub: ' + (diffData.error_message || 'Unknown GitHub error.'));
        } else if (diffData.diff) { // Общий случай, если есть поле diff
            $code.text(diffData.diff);
        }
        else {
            $code.text('// Diff data is unavailable or status is unknown.');
        }
    
        $pre.append($code);
        diffContent.append($pre);
    
        if (window.hljs && $code.text().length > 0 && $code.html() === $code.text()) { // Подсвечиваем только если это не HTML-diff
             hljs.highlightElement($code[0]);
        } else if (window.hljs && diffData.status === 'modified' && diffData.diff_output) {
            // Для HTML diff от WP_Text_Diff_Renderer_inline, подсветка может не требоваться или быть выборочной
            // Например, подсветить только блоки кода внутри diff, если это возможно
            // diffContent.find('pre code').each(function(i, block) { hljs.highlightElement(block); });
        }
    }


    async function fetchFileCommits(filePath) {
        if (!commitHistoryContent || !commitHistoryContent.length) return;
        commitHistoryContent.html('<div class="loading">Loading commit history...</div>');

        try {
            const response = await $.post(ajax_url, {
                action: 'github_get_file_commits',
                nonce: nonce,
                file_path: filePath
            });
            if (response.success && response.data && response.data.commits) {
                renderCommitHistory(response.data.commits);
            } else {
                commitHistoryContent.html('<div class="error">' + escapeHtml(response.data?.message || 'No history found or error.') + '</div>');
            }
        } catch (error) {
            commitHistoryContent.html('<div class="error">Request Error: ' + escapeHtml(error.statusText || error.message) + '</div>');
        }
    }

    function renderCommitHistory(commits) {
        if (!commitHistoryContent || !commitHistoryContent.length) return;
        commitHistoryContent.empty();
        if (!commits || commits.length === 0) {
            commitHistoryContent.html('<p>No commit history available for this file.</p>');
            return;
        }
        const $ul = $('<ul>');
        commits.slice(0, 5).forEach(function(commit) { // Показываем последние 5
            $('<li>').addClass('commit-history-item').html(
                '<div class="commit-history-message">' + escapeHtml(commit.message) + '</div>' +
                '<div class="commit-history-meta">' +
                '<strong>' + escapeHtml(commit.author) + '</strong> on ' + new Date(commit.date).toLocaleDateString() +
                ' <em>(' + escapeHtml(commit.sha.substring(0,7)) + ')</em>' +
                '</div>'
            ).appendTo($ul);
        });
        commitHistoryContent.append($ul);
    }
    
    // Обработчики событий
    if (testConnectionBtn.length) {
        testConnectionBtn.on('click', async function() {
            const $button = $(this);
            $button.prop('disabled', true).text('Testing...');
            statusContainer.hide().empty();
            
            try {
                const response = await $.post(ajax_url, {
                    action: 'github_test_connection',
                    nonce: nonce
                });
                if (response.success) {
                    showStatus('success', response.data?.message || 'Connection successful!');
                    if (currentPage === 'sync' && gitpushContainer.length) {
                        fetchFiles('github_get_changed_files', true); // Загружаем измененные файлы после успешного теста
                    }
                } else {
                    showStatus('error', response.data?.message || "Connection test failed.");
                }
            } catch (error) {
                showStatus('error', 'Error testing connection: ' + (error.statusText || error.message));
            } finally {
                $button.prop('disabled', false).text('Test GitHub Connection');
            }
        });
    }
    
    if (currentPage === 'sync' && gitpushContainer.length) {
        if (showAllFilesBtn && showAllFilesBtn.length) {
            showAllFilesBtn.on('click', function() {
                viewMode = 'all';
                // Используем 'github_get_theme_files' для всех файлов, если это реализовано на сервере
                // или 'github_get_changed_files' и затем фильтруем/обрабатываем на клиенте
                fetchFiles('github_get_theme_files', true); 
            });
        }
        
        if (showChangedFilesBtn && showChangedFilesBtn.length) {
            showChangedFilesBtn.on('click', function() {
                viewMode = 'changed';
                fetchFiles('github_get_changed_files', true);
            });
        }
        
        if (refreshFilesBtn && refreshFilesBtn.length) {
            refreshFilesBtn.on('click', function() {
                statusContainer.hide().empty();
                fetchFiles(viewMode === 'all' ? 'github_get_theme_files' : 'github_get_changed_files', true);
            });
        }
        
        if (commitSelectedBtn && commitSelectedBtn.length) {
            commitSelectedBtn.on('click', async function() {
                const $button = $(this);
                const $commitMessageInput = $('#commit-message');
                
                const selectedFilePaths = filesListContainer.find('input[type="checkbox"]:checked').map(function() {
                    return $(this).val();
                }).get();
                
                if (selectedFilePaths.length === 0) {
                    showStatus('error', 'Please select at least one file to commit.');
                    return;
                }
                
                const commitMessage = $commitMessageInput.val().trim() || 'Update from WordPress Admin';
                
                $button.prop('disabled', true).text('Committing...');
                statusContainer.hide().empty();
                
                try {
                    const response = await $.post(ajax_url, {
                        action: 'github_sync_theme',
                        nonce: nonce,
                        files: JSON.stringify(selectedFilePaths), // Отправляем массив путей
                        commit_message: commitMessage
                    });
                    
                    if (response.success && response.data) {
                        showStatus('success', response.data.message || 'Sync process completed.', response.data.results);
                        $commitMessageInput.val("Update from WordPress Admin"); // Сброс сообщения
                        
                        // Обновляем список измененных файлов (которые по идее должны исчезнуть)
                        // response.data.changed_files должен содержать актуальный список после пуша
                        if (response.data.changed_files) {
                            currentViewFiles = response.data.changed_files;
                             if (viewMode === 'changed') {
                                currentViewFiles = currentViewFiles.filter(file => 
                                    file && typeof file.status !== 'undefined' && ['new', 'modified', 'deleted'].includes(file.status)
                                );
                            }
                            renderFileList(currentViewFiles);
                        } else { // Если сервер не вернул changed_files, просто принудительно обновляем
                             fetchFiles('github_get_changed_files', true);
                        }

                    } else {
                        showStatus('error', response.data?.message || 'Failed to commit files.', response.data?.results);
                        console.error("Commit error (AJAX response):", response);
                    }
                } catch (error) {
                    showStatus('error', 'Error committing files: ' + (error.statusText || error.message));
                    console.error("Commit exception:", error);
                } finally {
                    $button.prop('disabled', false).text('Commit Selected Files');
                }
            });
        }
        
        // Первоначальная загрузка измененных файлов
        if (gitpush_wp_obj.github_username && gitpush_wp_obj.github_repo) {
            fetchFiles('github_get_changed_files', true); // Принудительное обновление при первой загрузке
        } else {
            if (loadingIndicator && loadingIndicator.length) loadingIndicator.hide();
            if (filesListContainer && filesListContainer.length) filesListContainer.html('<li>Please configure GitHub settings first.</li>');
        }
    }
});