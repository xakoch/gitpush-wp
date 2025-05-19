/**
 * Admin JavaScript для GitPush WP
 */
jQuery(document).ready(function($) {
    
    const ajax_url = gitpush_wp_obj.ajax_url;
    const nonce = gitpush_wp_obj.nonce;

    const currentPage = window.location.href.includes('page=gitpush-wp-settings') ? 'settings' : 'sync';
    
    const $statusContainer = $('#sync-status'); // jQuery объект
    
    // jQuery объекты для элементов на странице синхронизации
    let $commitSelectedBtn, $refreshFilesBtn, $showAllFilesBtn, $showChangedFilesBtn, 
        $gitpushContainer, $filesPanel, $diffPanel, 
        $filesListContainer, $loadingIndicator, $diffContent, $commitHistoryContent;
    
    let currentViewFiles = []; 
    let selectedFileForDiffPath = null; 
    let viewMode = 'changed'; 
    
    if (currentPage === 'sync') {
        // Кнопка #sync-theme пока не используется активно, поэтому ее можно убрать, если не планируется
        // const $syncThemeBtn = $('#sync-theme'); 
        $commitSelectedBtn = $('#commit-selected');
        
        $gitpushContainer = $('#gitpush-container'); // Инициализируем как jQuery объект

        if ($gitpushContainer.length) { // Проверяем, что элемент существует
            $filesPanel = $gitpushContainer.find('.gitpush-files-panel');
            $diffPanel = $gitpushContainer.find('.gitpush-diff-panel');
            
            $filesListContainer = $filesPanel.find('.files-list');
            $loadingIndicator = $filesPanel.find('.loading'); // Используем find для поиска внутри $filesPanel
            $diffContent = $diffPanel.find('.diff-content');
            $commitHistoryContent = $diffPanel.find('.commit-history-content');
            
            $refreshFilesBtn = $('#refresh-files');
            $showAllFilesBtn = $('#show-all-files');
            $showChangedFilesBtn = $('#show-changed-files');
        } else {
            // Если основной контейнер не найден, возможно, настройки не сконфигурированы
            // или есть проблема с HTML-разметкой страницы.
            console.warn("GitPush WP: Main container #gitpush-container not found on sync page.");
        }
    }
    
    function escapeHtml(unsafe) {
        if (unsafe === null || typeof unsafe === 'undefined') return '';
        return $('<div>').text(unsafe).html();
    }

    function showStatus(type, message, details = null) {
        if (!$statusContainer.length) return;
        
        $statusContainer.empty()
            .removeClass('notice-success notice-error notice-warning notice-info is-dismissible')
            .addClass('notice notice-' + type + ' is-dismissible')
            .append($('<p>').html('<strong>' + escapeHtml(message) + '</strong>'));

        if (details && Array.isArray(details) && details.length > 0) {
            const $ul = $('<ul>').addClass('sync-results');
            details.forEach(function(item) {
                $('<li>')
                    .addClass(item.type || type)
                    .text(escapeHtml(item.path || 'File') + ': ' + escapeHtml(item.status_text || item.status || 'N/A'))
                    .appendTo($ul);
            });
            $statusContainer.append($ul);
        }
        
        $statusContainer.append(
            $('<button>')
                .attr('type', 'button')
                .addClass('notice-dismiss')
                .html('<span class="screen-reader-text">Dismiss this notice.</span>')
                .on('click', function() { $statusContainer.hide(); })
        ).show();
    }

    function renderFileList(files) {
        // Проверяем, что все jQuery объекты существуют перед использованием
        if (!$filesListContainer || !$filesListContainer.length) {
             console.error("renderFileList: filesListContainer is not defined or empty.");
             return;
        }
        
        $filesListContainer.empty(); 
        
        if (!files || files.length === 0) {
            const message = (viewMode === 'changed') ? 'No changed files found.' : 'No files found in the theme.';
            $filesListContainer.html('<li>' + message + '</li>');
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
                case 'unknown': 
                default:
                    fileStatusIcon = '<span class="dashicons dashicons-media-default gitpush-status-unknown"></span>';
                    fileStatusClass = 'gitpush-file-unknown';
                    statusText = (file.status === 'unknown') ? 'Unchanged/Local' : statusText;
                    break;
            }

            const $listItem = $('<li>')
                .addClass('file ' + fileStatusClass)
                .data('filePath', file.path);

            const checkboxId = 'file-' + file.path.replace(/[\/\.\s\W]/g, '-');
            const $checkbox = $('<input>')
                .attr('type', 'checkbox')
                .attr('value', file.path)
                .attr('id', checkboxId)
                .prop('checked', ['new', 'modified', 'deleted'].includes(file.status)); 

            const $label = $('<label>')
                .attr('for', checkboxId)
                .html(fileStatusIcon + ' ' + escapeHtml(file.path) + 
                      ( (file.status !== 'unknown') ? ' <span class="file-status-text">(' + escapeHtml(statusText) + ')</span>' : '') );
            
            $listItem.append($checkbox).append($label);
            
            $listItem.on('click', function(e) {
                if (e.target !== $checkbox[0]) { 
                    $filesListContainer.find('li').removeClass('active');
                    $listItem.addClass('active');
                    selectedFileForDiffPath = file.path;
                    fetchFileDiff(file.path);
                    if (typeof fetchFileCommits === "function" && $commitHistoryContent && $commitHistoryContent.length) {
                        fetchFileCommits(file.path);
                    }
                }
            });
            $filesListContainer.append($listItem);
        });
    }
    
    async function fetchFiles(actionType, forceRefresh = false) {
        if (!$loadingIndicator || !$loadingIndicator.length || !$filesListContainer || !$filesListContainer.length) {
             console.error("UI elements for fetchFiles are missing.");
             showStatus('error', 'UI elements for file list are missing. Please contact support.');
             return;
        }
        $loadingIndicator.show();
        $filesListContainer.empty();
        if ($diffContent && $diffContent.length) $diffContent.html('<p class="diff-instructions">Select a file to view changes</p>');
        if ($commitHistoryContent && $commitHistoryContent.length) $commitHistoryContent.empty();

        try {
            const response = await $.post(ajax_url, {
                action: actionType, nonce: nonce, force_refresh: forceRefresh 
            });

            if (response.success && response.data && typeof response.data.changed_files !== 'undefined') {
                currentViewFiles = response.data.changed_files;
                if (viewMode === 'changed' && actionType === 'github_get_changed_files') { // Уточняем условие для фильтрации
                     currentViewFiles = currentViewFiles.filter(file => 
                        file && typeof file.status !== 'undefined' && ['new', 'modified', 'deleted'].includes(file.status)
                    );
                }
                renderFileList(currentViewFiles);
            } else {
                let errorMsg = 'Failed to load files. Server returned an error.';
                if (response.data) {
                    if (typeof response.data.message === 'string' && response.data.message.trim() !== '') { errorMsg = response.data.message;}
                    else if (typeof response.data.error === 'string' && response.data.error.trim() !== '') { errorMsg = response.data.error;}
                    else if (typeof response.data === 'string' && response.data.trim() !== '') { errorMsg = response.data; } 
                    else { console.warn("Received complex error data structure in fetchFiles:", response.data); errorMsg = 'Received complex error data from server (check console).';}
                } else if (response.message && typeof response.message === 'string') { errorMsg = response.message;}
                else if (!response.success && typeof response.data === 'undefined') {errorMsg = 'Failed to load files. Server did not provide details.'}

                showStatus('error', errorMsg);
                $filesListContainer.html('<li>' + escapeHtml(errorMsg) + '</li>');
                console.error("Error in fetchFiles (" + actionType + "): Full response:", response);
            }
        } catch (error) {
            const errorMsg = 'AJAX request failed: ' + (error.statusText || error.message || 'Unknown error');
            showStatus('error', errorMsg);
            $filesListContainer.html('<li>' + escapeHtml(errorMsg) + '</li>');
            console.error("Exception in fetchFiles (" + actionType + "):", error);
        } finally {
            if ($loadingIndicator) $loadingIndicator.hide();
            updateViewModeButtons();
        }
    }

    function updateViewModeButtons() {
        if (!$showAllFilesBtn || !$showAllFilesBtn.length || !$showChangedFilesBtn || !$showChangedFilesBtn.length) return;
        $showAllFilesBtn.toggleClass('button-primary', viewMode === 'all').toggleClass('button-secondary', viewMode !== 'all');
        $showChangedFilesBtn.toggleClass('button-primary', viewMode === 'changed').toggleClass('button-secondary', viewMode !== 'changed');
    }
    
    async function fetchFileDiff(filePath) {
        if (!$diffContent || !$diffContent.length) return;
        $diffContent.html('<div class="loading">Loading file diff...</div>');
        
        try {
            const response = await $.post(ajax_url, {
                action: 'github_get_file_diff', nonce: nonce, file_path: filePath
            });

            if (response.success && response.data && response.data.diff) {
                renderFileDiff(filePath, response.data.diff);
            } else {
                let errorMsg = 'Error loading diff.';
                if(response.data?.message) errorMsg = response.data.message;
                else if(response.data?.error) errorMsg = response.data.error;
                else if(response.data?.diff?.error_message) errorMsg = response.data.diff.error_message;
                $diffContent.html('<div class="error">' + escapeHtml(errorMsg) + '</div>');
                console.error("Error loading diff (AJAX):", response);
            }
        } catch (error) {
            $diffContent.html('<div class="error">Request Error: ' + escapeHtml(error.statusText || error.message) + '</div>');
            console.error("Exception loading diff:", error);
        }
    }

    function renderFileDiff(filePath, diffData) {
        if (!$diffContent || !$diffPanel || !$diffPanel.length) return;
        $diffContent.empty();
        const $diffHeader = $diffPanel.find('.diff-header');
        $diffHeader.find('h3').text(filePath);
        $diffHeader.find('p.diff-instructions').hide();
    
        let $statusDisplay = $diffHeader.find('.diff-status-display');
        if (!$statusDisplay.length) {
            $statusDisplay = $('<p>').addClass('diff-status-display').insertAfter($diffHeader.find('h3'));
        }
        $statusDisplay.text('Status: ' + (diffData.status || 'unknown'))
            .removeClass (function (index, className) { return (className.match (/(^|\s)status-\S+/g) || []).join(' ');})
            .addClass('status-' + (diffData.status || 'unknown'));
    
        const $pre = $('<pre>'); const $code = $('<code>');
        const extension = filePath.split('.').pop().toLowerCase();
        if (extension) $code.addClass('language-' + extension);
    
        if (diffData.diff_output) { 
            $code.html(diffData.diff_output); // WP Diff Renderer генерирует HTML
        } else if (diffData.status === 'new') {
            $code.text(diffData.local_content || "// New file");
        } else if (diffData.status === 'deleted') {
            $code.text(diffData.github_content || "// File deleted locally");
        } else if (diffData.status === 'unchanged' || diffData.status === 'identical') {
            $code.text(diffData.local_content || "// File unchanged");
        } else if (diffData.error_message) {
            $code.text('// Error: ' + diffData.error_message);
        } else {
            $code.text('// Diff data is unavailable.');
        }
        $pre.append($code); $diffContent.append($pre);
        
        // Подсветка нужна, только если это не HTML-diff от WP
        if (window.hljs && $code.text().length > 0 && $code.html().indexOf('<span class="diff-') === -1 ) { 
             hljs.highlightElement($code[0]);
        }
    }

    async function fetchFileCommits(filePath) {
        if (!$commitHistoryContent || !$commitHistoryContent.length) return;
        $commitHistoryContent.html('<div class="loading">Loading commit history...</div>');
        try {
            const response = await $.post(ajax_url, { action: 'github_get_file_commits', nonce: nonce, file_path: filePath });
            if (response.success && response.data && response.data.commits) {
                renderCommitHistory(response.data.commits);
            } else {
                $commitHistoryContent.html('<div class="error">' + escapeHtml(response.data?.message || 'No history or error.') + '</div>');
            }
        } catch (error) {
            $commitHistoryContent.html('<div class="error">Request Error: ' + escapeHtml(error.statusText || error.message) + '</div>');
        }
    }

    function renderCommitHistory(commits) {
        if (!$commitHistoryContent || !$commitHistoryContent.length) return;
        $commitHistoryContent.empty();
        if (!commits || commits.length === 0) {
            $commitHistoryContent.html('<p>No commit history for this file.</p>'); return;
        }
        const $ul = $('<ul>');
        commits.slice(0, 5).forEach(function(commit) {
            $('<li>').addClass('commit-history-item').html(
                '<div class="commit-history-message">' + escapeHtml(commit.message) + '</div>' +
                '<div class="commit-history-meta">' +
                '<strong>' + escapeHtml(commit.author) + '</strong> on ' + new Date(commit.date).toLocaleDateString() +
                ' <em>(' + escapeHtml((commit.sha || '').substring(0,7)) + ')</em>' +
                '</div>'
            ).appendTo($ul);
        });
        $commitHistoryContent.append($ul);
    }
    
    // Event Handlers
    if ($('#test-connection').length) {
        $('#test-connection').on('click', async function() { // Используем #id напрямую, так как testConnectionBtn может быть не в этой области видимости
            const $button = $(this);
            $button.prop('disabled', true).text('Testing...');
            $statusContainer.hide().empty();
            try {
                const response = await $.post(ajax_url, { action: 'github_test_connection', nonce: nonce });
                if (response.success) {
                    showStatus('success', response.data?.message || 'Connection successful!');
                    if (currentPage === 'sync' && $gitpushContainer && $gitpushContainer.length) {
                        fetchFiles('github_get_changed_files', true);
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
    
    if (currentPage === 'sync' && $gitpushContainer && $gitpushContainer.length) {
        if ($showAllFilesBtn && $showAllFilesBtn.length) {
            $showAllFilesBtn.on('click', function() {
                viewMode = 'all';
                fetchFiles('github_get_theme_files', true); 
            });
        }
        if ($showChangedFilesBtn && $showChangedFilesBtn.length) {
            $showChangedFilesBtn.on('click', function() {
                viewMode = 'changed';
                fetchFiles('github_get_changed_files', true);
            });
        }
        if ($refreshFilesBtn && $refreshFilesBtn.length) {
            $refreshFilesBtn.on('click', function() {
                $statusContainer.hide().empty();
                fetchFiles(viewMode === 'all' ? 'github_get_theme_files' : 'github_get_changed_files', true);
            });
        }
        
        if ($commitSelectedBtn && $commitSelectedBtn.length) {
            $commitSelectedBtn.on('click', async function() {
                const $button = $(this);
                const $commitMessageInput = $('#commit-message');
                
                const selectedFilePaths = $filesListContainer.find('input[type="checkbox"]:checked').map(function() {
                    return $(this).val();
                }).get();
                
                if (selectedFilePaths.length === 0) {
                    showStatus('error', 'Please select at least one file to commit.'); return;
                }
                const commitMessage = $commitMessageInput.val().trim() || 'Update from WordPress Admin';
                
                $button.prop('disabled', true).text('Committing...');
                $statusContainer.hide().empty();
                
                try {
                    const response = await $.post(ajax_url, {
                        action: 'github_sync_theme', nonce: nonce,
                        files: JSON.stringify(selectedFilePaths), 
                        commit_message: commitMessage
                    });
                    
                    // Ожидаем, что response.data.results будет массивом для showStatus
                    const resultsForDisplay = response.data?.results || [];

                    if (response.success && response.data) {
                        showStatus('success', response.data.message || 'Sync process completed.', resultsForDisplay);
                        $commitMessageInput.val("Update from WordPress Admin"); 
                        
                        if (response.data.changed_files) { // Сервер должен вернуть актуальный список измененных файлов
                            currentViewFiles = response.data.changed_files;
                             if (viewMode === 'changed') { // Фильтруем, если мы на вкладке "Changed"
                                currentViewFiles = currentViewFiles.filter(file => 
                                    file && typeof file.status !== 'undefined' && ['new', 'modified', 'deleted'].includes(file.status)
                                );
                            }
                            renderFileList(currentViewFiles);
                        } else { 
                             fetchFiles('github_get_changed_files', true); // Принудительно обновляем, если сервер не вернул
                        }
                    } else {
                        showStatus('error', response.data?.message || 'Failed to commit files.', resultsForDisplay);
                    }
                } catch (error) {
                    showStatus('error', 'Error committing files: ' + (error.statusText || error.message));
                } finally {
                    $button.prop('disabled', false).text('Commit Selected Files');
                }
            });
        }
        
        // Первоначальная загрузка измененных файлов
        if (gitpush_wp_obj.github_username && gitpush_wp_obj.github_repo) {
            fetchFiles('github_get_changed_files', true); 
        } else {
            if ($loadingIndicator && $loadingIndicator.length) $loadingIndicator.hide();
            if ($filesListContainer && $filesListContainer.length) $filesListContainer.html('<li>Please configure GitHub settings first.</li>');
        }
    }
});