/**
 * Admin JavaScript для GitPush WP
 */
document.addEventListener('DOMContentLoaded', function() {
    // Определяем текущую страницу
    const currentPage = window.location.href.includes('page=gitpush-wp-settings') 
        ? 'settings' 
        : 'sync';
    
    // Элементы общие для всех страниц
    const testConnectionBtn = document.getElementById('test-connection');
    const statusContainer = document.getElementById('sync-status');
    
    // Элементы только для страницы синхронизации
    let pullFromGithubBtn, syncThemeBtn, commitSelectedBtn, refreshFilesBtn,
        showAllFilesBtn, showChangedFilesBtn, gitpushContainer, filesPanel,
        diffPanel, filesListContainer, loadingIndicator, diffContent;
    
    if (currentPage === 'sync') {
        pullFromGithubBtn = document.getElementById('pull-from-github');
        syncThemeBtn = document.getElementById('sync-theme');
        commitSelectedBtn = document.getElementById('commit-selected');
        
        gitpushContainer = document.getElementById('gitpush-container');
        if (gitpushContainer) {
            filesPanel = gitpushContainer.querySelector('.gitpush-files-panel');
            diffPanel = gitpushContainer.querySelector('.gitpush-diff-panel');
            filesListContainer = filesPanel.querySelector('.files-list');
            loadingIndicator = filesPanel.querySelector('.loading');
            diffContent = diffPanel.querySelector('.diff-content');
            
            refreshFilesBtn = document.getElementById('refresh-files');
            showAllFilesBtn = document.getElementById('show-all-files');
            showChangedFilesBtn = document.getElementById('show-changed-files');
        }
    }
    
    // Состояние приложения
    let currentFiles = [];
    let selectedFile = null;
    let viewMode = 'changed'; // 'all' или 'changed'
    
    // Получить список всех файлов темы
    async function getAllThemeFiles() {
        try {
            loadingIndicator.style.display = 'block';
            filesListContainer.innerHTML = '';
            
            const response = await fetch(gitpush_wp_obj.ajax_url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'github_get_theme_files',
                    nonce: gitpush_wp_obj.nonce
                })
            });
            
            const data = await response.json();
            
            if (data.success) {
                currentFiles = data.data.files;
                renderFileList(currentFiles);
                viewMode = 'all';
                updateViewModeButtons();
            } else {
                showStatus('error', data.data);
            }
        } catch (error) {
            showStatus('error', 'Error loading theme files: ' + error.message);
        } finally {
            loadingIndicator.style.display = 'none';
        }
    }
    
    // Получить только измененные файлы
    async function getChangedFiles() {
        try {
            loadingIndicator.style.display = 'block';
            filesListContainer.innerHTML = '';
            
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
            
            if (data.success) {
                // Фильтруем файлы, чтобы показать только измененные
                currentFiles = data.data.files.filter(file => 
                    file.type === 'file' && ['new', 'modified', 'deleted'].includes(file.status)
                );
                renderFileList(currentFiles);
                viewMode = 'changed';
                updateViewModeButtons();
            } else {
                showStatus('error', data.data);
            }
        } catch (error) {
            showStatus('error', 'Error loading changed files: ' + error.message);
        } finally {
            loadingIndicator.style.display = 'none';
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
        
        if (!files || files.length === 0) {
            filesListContainer.innerHTML = '<li>No files found</li>';
            return;
        }
        
        // Сортировка файлов по типу и имени
        files.sort((a, b) => {
            // Папки сверху
            const aIsDir = a.type === 'dir';
            const bIsDir = b.type === 'dir';
            
            if (aIsDir && !bIsDir) return -1;
            if (!aIsDir && bIsDir) return 1;
            
            // Затем по имени
            return a.path.localeCompare(b.path);
        });
        
        filesListContainer.innerHTML = '';
        
        files.forEach(file => {
            const listItem = document.createElement('li');
            listItem.className = file.type === 'dir' ? 'directory' : 'file';
            
            // Добавляем класс статуса файла, если это файл и имеет статус
            if (file.type === 'file' && file.status) {
                listItem.classList.add('status-' + file.status);
            }
            
            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.value = file.path;
            checkbox.id = 'file-' + file.path.replace(/[\/\.]/g, '-');
            
            if (file.type !== 'dir') {
                checkbox.dataset.fileType = file.extension;
                checkbox.dataset.fileStatus = file.status || 'unchanged';
            }
            
            // Отображаем значок статуса файла
            const statusSpan = document.createElement('span');
            statusSpan.className = 'file-status';
            
            if (file.type === 'file') {
                switch (file.status) {
                    case 'modified':
                        statusSpan.innerHTML = '&#128397;'; // Символ редактирования
                        break;
                    case 'new':
                        statusSpan.innerHTML = '&#10010;'; // Символ плюса
                        break;
                    case 'deleted':
                        statusSpan.innerHTML = '&#10006;'; // Символ крестика
                        break;
                    default:
                        statusSpan.innerHTML = '&#8226;'; // Просто точка
                }
            }
            
            const label = document.createElement('label');
            label.htmlFor = checkbox.id;
            label.textContent = file.path;
            
            listItem.appendChild(checkbox);
            listItem.appendChild(statusSpan);
            listItem.appendChild(label);
            
            // Добавляем обработчик клика для просмотра изменений
            if (file.type === 'file') {
                listItem.addEventListener('click', function(e) {
                    // Избегаем срабатывания при клике на чекбокс
                    if (e.target !== checkbox) {
                        selectFile(file.path);
                        
                        // Добавляем класс активности
                        document.querySelectorAll('.files-list li').forEach(li => {
                            li.classList.remove('active');
                        });
                        listItem.classList.add('active');
                    }
                });
            }
            
            filesListContainer.appendChild(listItem);
        });
    }
    
    // Выбрать файл для просмотра изменений
    async function selectFile(filePath) {
        if (!diffContent) return;
        
        selectedFile = filePath;
        
        try {
            diffContent.innerHTML = '<div class="loading">Loading file diff...</div>';
            
            const response = await fetch(gitpush_wp_obj.ajax_url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'github_get_file_diff',
                    nonce: gitpush_wp_obj.nonce,
                    file_path: filePath
                })
            });
            
            const data = await response.json();
            
            if (data.success) {
                renderFileDiff(filePath, data.data);
            } else {
                diffContent.innerHTML = '<div class="error">Error loading diff: ' + data.data + '</div>';
            }
        } catch (error) {
            diffContent.innerHTML = '<div class="error">Error: ' + error.message + '</div>';
        }
    }
    
    // Отобразить различия в файле
    function renderFileDiff(filePath, diffData) {
        if (!diffContent || !diffPanel) return;
        
        const { local_content, github_content, status } = diffData;
        
        diffContent.innerHTML = '';
        
        // Обновляем заголовок диф-панели
        const diffHeader = diffPanel.querySelector('.diff-header');
        diffHeader.innerHTML = `
            <h3>${filePath}</h3>
            <p class="diff-status ${status}">Status: ${status}</p>
        `;
        
        // Получаем расширение файла для подсветки синтаксиса
        const extension = filePath.split('.').pop().toLowerCase();
        
        // Создаем контейнер для дифа
        const diffContainer = document.createElement('div');
        diffContainer.className = 'diff-container';
        
        if (status === 'deleted_locally') {
            // Файл удален локально, показываем только GitHub версию
            diffContainer.innerHTML = '<div class="diff-message">File deleted locally</div>';
            
            const githubContent = document.createElement('pre');
            githubContent.className = 'language-' + extension;
            githubContent.textContent = github_content;
            
            diffContainer.appendChild(githubContent);
        } else if (status === 'new') {
            // Новый файл, показываем только локальную версию
            diffContainer.innerHTML = '<div class="diff-message">New file</div>';
            
            const localContent = document.createElement('pre');
            localContent.className = 'language-' + extension;
            localContent.textContent = local_content;
            
            diffContainer.appendChild(localContent);
        } else if (status === 'unchanged') {
            // Файл не изменен, показываем содержимое
            diffContainer.innerHTML = '<div class="diff-message">No changes</div>';
            
            const content = document.createElement('pre');
            content.className = 'language-' + extension;
            content.textContent = local_content;
            
            diffContainer.appendChild(content);
        } else {
            // Файл изменен, показываем различия
            const lines1 = local_content.split('\n');
            const lines2 = github_content.split('\n');
            
            // Простой алгоритм определения различий строк
            for (let i = 0; i < Math.max(lines1.length, lines2.length); i++) {
                const line1 = i < lines1.length ? lines1[i] : '';
                const line2 = i < lines2.length ? lines2[i] : '';
                
                if (line1 !== line2) {
                    // Строки отличаются
                    if (line2 !== '') {
                        // Показываем удаленную строку
                        const removedLine = document.createElement('div');
                        removedLine.className = 'gitpush-diff-line removed';
                        
                        const lineNumber = document.createElement('div');
                        lineNumber.className = 'gitpush-diff-line-number';
                        lineNumber.textContent = '-';
                        
                        const lineContent = document.createElement('div');
                        lineContent.className = 'gitpush-diff-line-content';
                        lineContent.textContent = line2;
                        
                        removedLine.appendChild(lineNumber);
                        removedLine.appendChild(lineContent);
                        diffContainer.appendChild(removedLine);
                    }
                    
                    if (line1 !== '') {
                        // Показываем добавленную строку
                        const addedLine = document.createElement('div');
                        addedLine.className = 'gitpush-diff-line added';
                        
                        const lineNumber = document.createElement('div');
                        lineNumber.className = 'gitpush-diff-line-number';
                        lineNumber.textContent = '+';
                        
                        const lineContent = document.createElement('div');
                        lineContent.className = 'gitpush-diff-line-content';
                        lineContent.textContent = line1;
                        
                        addedLine.appendChild(lineNumber);
                        addedLine.appendChild(lineContent);
                        diffContainer.appendChild(addedLine);
                    }
                } else {
                    // Строки одинаковые, просто показываем их
                    const unchangedLine = document.createElement('div');
                    unchangedLine.className = 'gitpush-diff-line';
                    
                    const lineNumber = document.createElement('div');
                    lineNumber.className = 'gitpush-diff-line-number';
                    lineNumber.textContent = (i + 1).toString();
                    
                    const lineContent = document.createElement('div');
                    lineContent.className = 'gitpush-diff-line-content';
                    lineContent.textContent = line1;
                    
                    unchangedLine.appendChild(lineNumber);
                    unchangedLine.appendChild(lineContent);
                    diffContainer.appendChild(unchangedLine);
                }
            }
        }
        
        diffContent.appendChild(diffContainer);
        
        // Применяем подсветку синтаксиса, если доступна библиотека highlight.js
        if (window.hljs) {
            diffContent.querySelectorAll('pre').forEach(block => {
                hljs.highlightElement(block);
            });
        }
    }
    
    // Показать статус операции
    function showStatus(type, message) {
        if (!statusContainer) return;
        
        statusContainer.innerHTML = '';
        statusContainer.className = 'notice notice-' + (type === 'success' ? 'success' : 'error');
        
        const paragraph = document.createElement('p');
        paragraph.textContent = message;
        
        statusContainer.appendChild(paragraph);
        statusContainer.style.display = 'block';
        
        // Автоскрытие через 5 секунд для успешных сообщений
        if (type === 'success') {
            setTimeout(() => {
                statusContainer.style.display = 'none';
            }, 5000);
        }
    }
    
    // Обработчики событий для кнопок
    
    // Тестирование соединения с GitHub
    if (testConnectionBtn) {
        testConnectionBtn.addEventListener('click', async function() {
            try {
                testConnectionBtn.disabled = true;
                testConnectionBtn.textContent = 'Testing...';
                
                const response = await fetch(gitpush_wp_obj.ajax_url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        action: 'github_test_connection',
                        nonce: gitpush_wp_obj.nonce
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    showStatus('success', data.data);
                    // Если соединение успешно и мы на странице синхронизации, загружаем измененные файлы
                    if (currentPage === 'sync') {
                        getChangedFiles();
                    }
                } else {
                    showStatus('error', data.data);
                }
            } catch (error) {
                showStatus('error', 'Error testing connection: ' + error.message);
            } finally {
                testConnectionBtn.disabled = false;
                testConnectionBtn.textContent = 'Test GitHub Connection';
            }
        });
    }
    
    // Если мы на странице синхронизации, настраиваем обработчики событий для кнопок синхронизации
    if (currentPage === 'sync') {
        // Pull изменений с GitHub
        if (pullFromGithubBtn) {
            pullFromGithubBtn.addEventListener('click', async function() {
                try {
                    pullFromGithubBtn.disabled = true;
                    pullFromGithubBtn.textContent = 'Pulling...';
                    
                    const response = await fetch(gitpush_wp_obj.ajax_url, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: new URLSearchParams({
                            action: 'github_pull_from_github',
                            nonce: gitpush_wp_obj.nonce
                        })
                    });
                    
                    const data = await response.json();
                    
                    if (data.success) {
                        showStatus('success', 'Pull completed at ' + data.data.pull_time);
                        
                        // Отображаем результаты
                        const resultsContainer = document.createElement('div');
                        resultsContainer.className = 'sync-results';
                        
                        const resultsList = document.createElement('ul');
                        
                        data.data.updated_files.slice(0, 10).forEach(file => {
                            const listItem = document.createElement('li');
                            listItem.className = 'success';
                            listItem.textContent = file + ': Updated';
                            resultsList.appendChild(listItem);
                        });
                        
                        if (data.data.updated_files.length > 10) {
                            const moreItem = document.createElement('li');
                            moreItem.textContent = `... and ${data.data.updated_files.length - 10} more files`;
                            resultsList.appendChild(moreItem);
                        }
                        
                        data.data.errors.forEach(error => {
                            const listItem = document.createElement('li');
                            listItem.className = 'error';
                            listItem.textContent = error;
                            resultsList.appendChild(listItem);
                        });
                        
                        resultsContainer.appendChild(resultsList);
                        statusContainer.appendChild(resultsContainer);
                        
                        // Обновляем список файлов
                        getChangedFiles();
                    } else {
                        showStatus('error', data.data);
                    }
                } catch (error) {
                    showStatus('error', 'Error pulling from GitHub: ' + error.message);
                } finally {
                    pullFromGithubBtn.disabled = false;
                    pullFromGithubBtn.textContent = 'Pull from GitHub';
                }
            });
        }
        
        // Обработчики кнопок переключения режима
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
                if (viewMode === 'all') {
                    getAllThemeFiles();
                } else {
                    getChangedFiles();
                }
            });
        }
        
        // Коммит выбранных файлов
        if (commitSelectedBtn) {
            commitSelectedBtn.addEventListener('click', async function() {
                try {
                    // Получаем выбранные файлы
                    const selectedFiles = Array.from(
                        document.querySelectorAll('.files-list input[type="checkbox"]:checked')
                    ).map(checkbox => checkbox.value);
                    
                    if (selectedFiles.length === 0) {
                        showStatus('error', 'Please select at least one file to commit');
                        return;
                    }
                    
                    const commitMessage = document.getElementById('commit-message').value || 'Update from WordPress Admin';
                    
                    commitSelectedBtn.disabled = true;
                    commitSelectedBtn.textContent = 'Committing...';
                    
                    const response = await fetch(gitpush_wp_obj.ajax_url, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: new URLSearchParams({
                            action: 'github_sync_theme',
                            nonce: gitpush_wp_obj.nonce,
                            files: JSON.stringify(selectedFiles),
                            commit_message: commitMessage
                        })
                    });
                    
                    const data = await response.json();
                    
                    if (data.success) {
                        showStatus('success', data.data.message + ' at ' + data.data.sync_time);
                        
                        // Отображаем результаты синхронизации
                        const resultsContainer = document.createElement('div');
                        resultsContainer.className = 'sync-results';
                        
                        const resultsList = document.createElement('ul');
                        
                        Object.entries(data.data.results).forEach(([file, status]) => {
                            const listItem = document.createElement('li');
                            listItem.className = status === 'error' ? 'error' : 'success';
                            
                            let statusText = 'Unknown';
                            switch (status) {
                                case 'success':
                                case 'updated':
                                    statusText = 'Updated';
                                    break;
                                case 'created':
                                    statusText = 'Created';
                                    break;
                                case 'deleted':
                                    statusText = 'Deleted';
                                    break;
                                case 'error':
                                case 'error_deleting':
                                    statusText = 'Failed';
                                    break;
                            }
                            
                            listItem.textContent = file + ': ' + statusText;
                            resultsList.appendChild(listItem);
                        });
                        
                        resultsContainer.appendChild(resultsList);
                        statusContainer.appendChild(resultsContainer);
                        
                        // Обновляем список файлов через небольшую задержку
                        setTimeout(() => {
                            getChangedFiles();
                        }, 1000);
                    } else {
                        showStatus('error', data.data);
                    }
                } catch (error) {
                    showStatus('error', 'Error committing files: ' + error.message);
                } finally {
                    commitSelectedBtn.disabled = false;
                    commitSelectedBtn.textContent = 'Commit Selected Files';
                }
            });
        }
        
        // Обновляем информацию о репозитории
        const repoInfoBanner = document.getElementById('repo-info-banner');
        if (repoInfoBanner) {
            if (gitpush_wp_obj.github_username && gitpush_wp_obj.github_repo) {
                const repoUrl = `https://github.com/${gitpush_wp_obj.github_username}/${gitpush_wp_obj.github_repo}`;
                const repoLink = document.createElement('a');
                repoLink.href = repoUrl;
                repoLink.target = '_blank';
                repoLink.className = 'repo-link';
                repoLink.textContent = 'Open Repository';
                
                repoInfoBanner.appendChild(repoLink);
            }
        }
        
        // При первой загрузке получаем измененные файлы
        getChangedFiles();
    }
});