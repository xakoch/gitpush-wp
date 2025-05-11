/**
 * Admin JavaScript для GitPush WP
 */
document.addEventListener('DOMContentLoaded', function() {
    // Элементы управления
    const testConnectionBtn = document.getElementById('test-connection');
    const syncThemeBtn = document.getElementById('sync-theme');
    const statusContainer = document.getElementById('sync-status');
    const fileList = document.getElementById('file-list');
    const filesListContainer = fileList.querySelector('.files-list');
    const loadingIndicator = fileList.querySelector('.loading');
    
    // Получить список файлов темы
    async function getThemeFiles() {
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
                renderFileList(data.data.files);
            } else {
                showStatus('error', data.data);
            }
        } catch (error) {
            showStatus('error', 'Error loading theme files: ' + error.message);
        } finally {
            loadingIndicator.style.display = 'none';
        }
    }
    
    // Отобразить список файлов
    function renderFileList(files) {
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
            
            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.value = file.path;
            checkbox.id = 'file-' + file.path.replace(/[\/\.]/g, '-');
            
            if (file.type !== 'dir') {
                checkbox.dataset.fileType = file.extension;
            }
            
            const label = document.createElement('label');
            label.htmlFor = checkbox.id;
            label.textContent = file.path;
            
            listItem.appendChild(checkbox);
            listItem.appendChild(label);
            
            if (file.type === 'dir') {
                // Добавляем класс для папок и возможность их раскрывать
                listItem.classList.add('has-children');
                
                const toggleBtn = document.createElement('span');
                toggleBtn.className = 'toggle-dir';
                toggleBtn.innerHTML = '&#9660;';
                
                toggleBtn.addEventListener('click', function() {
                    listItem.classList.toggle('expanded');
                    toggleBtn.innerHTML = listItem.classList.contains('expanded') ? '&#9650;' : '&#9660;';
                });
                
                listItem.insertBefore(toggleBtn, listItem.firstChild);
            }
            
            filesListContainer.appendChild(listItem);
        });
        
        // Добавляем кнопки выбора/снятия выбора
        const actionButtons = document.createElement('div');
        actionButtons.className = 'file-list-actions';
        
        const selectAllBtn = document.createElement('button');
        selectAllBtn.type = 'button';
        selectAllBtn.className = 'button';
        selectAllBtn.textContent = 'Select All';
        selectAllBtn.addEventListener('click', function() {
            document.querySelectorAll('#file-list input[type="checkbox"]').forEach(checkbox => {
                checkbox.checked = true;
            });
        });
        
        const deselectAllBtn = document.createElement('button');
        deselectAllBtn.type = 'button';
        deselectAllBtn.className = 'button';
        deselectAllBtn.textContent = 'Deselect All';
        deselectAllBtn.addEventListener('click', function() {
            document.querySelectorAll('#file-list input[type="checkbox"]').forEach(checkbox => {
                checkbox.checked = false;
            });
        });
        
        const selectModifiedBtn = document.createElement('button');
        selectModifiedBtn.type = 'button';
        selectModifiedBtn.className = 'button';
        selectModifiedBtn.textContent = 'Select PHP/JS/CSS Files';
        selectModifiedBtn.addEventListener('click', function() {
            document.querySelectorAll('#file-list input[data-file-type="php"], #file-list input[data-file-type="js"], #file-list input[data-file-type="css"]').forEach(checkbox => {
                checkbox.checked = true;
            });
        });
        
        actionButtons.appendChild(selectAllBtn);
        actionButtons.appendChild(deselectAllBtn);
        actionButtons.appendChild(selectModifiedBtn);
        
        fileList.insertBefore(actionButtons, filesListContainer);
        
        // Добавляем поле для commit message
        const commitMessageContainer = document.createElement('div');
        commitMessageContainer.className = 'commit-message-container';
        
        const commitMessageLabel = document.createElement('label');
        commitMessageLabel.htmlFor = 'commit-message';
        commitMessageLabel.textContent = 'Commit Message:';
        
        const commitMessageInput = document.createElement('input');
        commitMessageInput.type = 'text';
        commitMessageInput.id = 'commit-message';
        commitMessageInput.className = 'regular-text';
        commitMessageInput.value = 'Update from WordPress Admin';
        
        commitMessageContainer.appendChild(commitMessageLabel);
        commitMessageContainer.appendChild(commitMessageInput);
        
        fileList.insertBefore(commitMessageContainer, filesListContainer);
    }
    
    // Показать статус операции
    function showStatus(type, message) {
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
                    // Если соединение успешно, загружаем файлы
                    getThemeFiles();
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
    
    // Синхронизация темы с GitHub
    if (syncThemeBtn) {
        syncThemeBtn.addEventListener('click', async function() {
            try {
                // Получаем выбранные файлы
                const selectedFiles = Array.from(
                    document.querySelectorAll('#file-list input[type="checkbox"]:checked')
                ).map(checkbox => checkbox.value);
                
                if (selectedFiles.length === 0) {
                    showStatus('error', 'Please select at least one file to sync');
                    return;
                }
                
                const commitMessage = document.getElementById('commit-message').value || 'Update from WordPress Admin';
                
                syncThemeBtn.disabled = true;
                syncThemeBtn.textContent = 'Syncing...';
                
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
                        listItem.className = status;
                        listItem.textContent = file + ': ' + (status === 'success' ? 'Synchronized' : 'Failed');
                        resultsList.appendChild(listItem);
                    });
                    
                    resultsContainer.appendChild(resultsList);
                    statusContainer.appendChild(resultsContainer);
                } else {
                    showStatus('error', data.data);
                }
            } catch (error) {
                showStatus('error', 'Error syncing theme: ' + error.message);
            } finally {
                syncThemeBtn.disabled = false;
                syncThemeBtn.textContent = 'Sync Theme to GitHub';
            }
        });
    }
});