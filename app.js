const state = {
    mode: 'login',
    csrf: '',
    user: null,
    notes: [],
    selectedNoteId: null,
    sharedUsers: [],
    noteScope: 'all',
    notesOffset: 0,
    notesLimit: 20,
    hasMoreNotes: true,
    isLoadingNotes: false,
};

const el = {
    authSection: document.getElementById('authSection'),
    notesSection: document.getElementById('notesSection'),
    userPanel: document.getElementById('userPanel'),
    welcomeText: document.getElementById('welcomeText'),
    logoutBtn: document.getElementById('logoutBtn'),
    loginTab: document.getElementById('loginTab'),
    registerTab: document.getElementById('registerTab'),
    authForm: document.getElementById('authForm'),
    authUsername: document.getElementById('authUsername'),
    authPassword: document.getElementById('authPassword'),
    authSubmit: document.getElementById('authSubmit'),
    searchInput: document.getElementById('searchInput'),
    noteScopeFilter: document.getElementById('noteScopeFilter'),
    newNoteBtn: document.getElementById('newNoteBtn'),
    notesList: document.getElementById('notesList'),
    noteForm: document.getElementById('noteForm'),
    noteId: document.getElementById('noteId'),
    noteTitle: document.getElementById('noteTitle'),
    noteContent: document.getElementById('noteContent'),
    deleteBtn: document.getElementById('deleteBtn'),
    shareBlock: document.getElementById('shareBlock'),
    shareUsername: document.getElementById('shareUsername'),
    shareUserSuggestions: document.getElementById('shareUserSuggestions'),
    shareBtn: document.getElementById('shareBtn'),
    sharedUsersList: document.getElementById('sharedUsersList'),
    editorTitle: document.getElementById('editorTitle'),
    message: document.getElementById('message'),
};

let messageTimer = null;

function hideMessage() {
    if (messageTimer) {
        clearTimeout(messageTimer);
        messageTimer = null;
    }

    el.message.classList.add('hidden');
}

function showMessage(text, type = 'ok') {
    if (messageTimer) {
        clearTimeout(messageTimer);
    }

    el.message.innerHTML = `
        <span>${text}</span>
        <button type="button" class="message-close" aria-label="Закрыть уведомление">×</button>
    `;
    el.message.className = `message ${type}`;

    const closeBtn = el.message.querySelector('.message-close');
    closeBtn?.addEventListener('click', hideMessage);

    messageTimer = setTimeout(hideMessage, 10000);
}

async function api(action, options = {}) {
    const method = options.method || 'GET';
    const headers = { 'Content-Type': 'application/json', ...(options.headers || {}) };

    if (method !== 'GET' && state.csrf) {
        headers['X-CSRF-Token'] = state.csrf;
    }

    const query = options.query ? `&${new URLSearchParams(options.query).toString()}` : '';

    const res = await fetch(`api.php?action=${encodeURIComponent(action)}${query}`, {
        method,
        headers,
        body: options.body ? JSON.stringify(options.body) : undefined,
    });

    const rawText = await res.text();
    let data;

    try {
        data = rawText ? JSON.parse(rawText) : null;
    } catch {
        throw new Error('Сервер вернул некорректный ответ. Проверьте API и настройки БД.');
    }

    if (!data || typeof data !== 'object') {
        throw new Error('Сервер вернул пустой ответ. Проверьте логи PHP и БД.');
    }

    if (!res.ok || data.ok === false) {
        throw new Error(data.error || 'Ошибка запроса');
    }

    return data;
}

function renderSharedUsers() {
    el.sharedUsersList.innerHTML = '';

    if (!state.sharedUsers.length) {
        const li = document.createElement('li');
        li.className = 'empty';
        li.textContent = 'Пока никому не выдан доступ.';
        el.sharedUsersList.append(li);
        return;
    }

    state.sharedUsers.forEach((username) => {
        const li = document.createElement('li');

        const name = document.createElement('span');
        name.textContent = username;

        const revokeBtn = document.createElement('button');
        revokeBtn.type = 'button';
        revokeBtn.className = 'danger small revoke-share-btn';
        revokeBtn.dataset.username = username;
        revokeBtn.textContent = 'Отозвать';

        li.append(name, revokeBtn);
        el.sharedUsersList.append(li);
    });
}

async function loadSharedUsers(noteId, isOwner) {
    state.sharedUsers = [];

    if (!isOwner || !noteId) {
        renderSharedUsers();
        return;
    }

    const result = await api('listSharedUsers', { query: { id: noteId } });
    state.sharedUsers = result.users || [];
    renderSharedUsers();
}

function setAuthMode(mode) {
    state.mode = mode;
    const isLogin = mode === 'login';
    el.loginTab.classList.toggle('active', isLogin);
    el.registerTab.classList.toggle('active', !isLogin);
    el.authSubmit.textContent = isLogin ? 'Войти' : 'Зарегистрироваться';
    el.authPassword.autocomplete = isLogin ? 'current-password' : 'new-password';
}

function renderNotes() {
    el.notesList.innerHTML = '';

    if (!state.notes.length) {
        const li = document.createElement('li');
        li.className = 'empty';
        li.textContent = 'Нет заметок. Создайте первую заметку.';
        el.notesList.append(li);
        return;
    }

    state.notes.forEach((note) => {
        const li = document.createElement('li');
        li.className = state.selectedNoteId === Number(note.id) ? 'active' : '';

        const title = document.createElement('strong');
        title.textContent = note.title;

        const meta = document.createElement('small');
        const ownerLabel = Number(note.is_owner) ? 'Вы' : `Автор: ${note.owner_name}`;
        meta.textContent = `${ownerLabel} • ${new Date(note.updated_at).toLocaleString('ru-RU')}`;

        li.append(title, meta);
        li.onclick = () => selectNote(Number(note.id));
        el.notesList.append(li);
    });

    if (state.isLoadingNotes) {
        const loading = document.createElement('li');
        loading.className = 'empty loading-more';
        loading.textContent = 'Загрузка...';
        el.notesList.append(loading);
    } else if (state.hasMoreNotes) {
        const hint = document.createElement('li');
        hint.className = 'empty loading-more';
        hint.textContent = 'Прокрутите вниз, чтобы загрузить ещё.';
        el.notesList.append(hint);
    }
}

function selectNote(noteId) {
    const note = state.notes.find((item) => Number(item.id) === Number(noteId));
    if (!note) {
        return;
    }

    state.selectedNoteId = Number(note.id);
    el.noteId.value = note.id;
    el.noteTitle.value = note.title;
    el.noteContent.value = note.content;

    const isOwner = Number(note.is_owner) === 1;
    el.editorTitle.textContent = isOwner ? 'Редактирование заметки' : 'Совместное редактирование заметки';
    el.noteTitle.readOnly = false;
    el.noteContent.readOnly = false;
    el.deleteBtn.disabled = !isOwner;
    el.shareBlock.classList.toggle('hidden', !isOwner);
    loadSharedUsers(Number(note.id), isOwner).catch((error) => showMessage(error.message, 'error'));

    renderNotes();
}

function resetEditor() {
    state.selectedNoteId = null;
    el.noteForm.reset();
    el.noteId.value = '';
    el.editorTitle.textContent = 'Новая заметка';
    el.noteTitle.readOnly = false;
    el.noteContent.readOnly = false;
    el.deleteBtn.disabled = true;
    el.shareBlock.classList.add('hidden');
    state.sharedUsers = [];
    renderSharedUsers();
    renderNotes();
}

async function loadNotes(reset = false) {
    if (state.isLoadingNotes) {
        return;
    }

    if (reset) {
        state.notesOffset = 0;
        state.hasMoreNotes = true;
    }

    if (!state.hasMoreNotes) {
        return;
    }

    state.isLoadingNotes = true;
    renderNotes();

    const q = el.searchInput.value.trim();

    try {
        const data = await api('listNotes', {
            query: {
                q,
                scope: state.noteScope,
                offset: state.notesOffset,
                limit: state.notesLimit,
            },
        });

        const nextChunk = data.notes || [];

        if (reset) {
            state.notes = nextChunk;
        } else {
            state.notes = [...state.notes, ...nextChunk];
        }

        state.notesOffset = state.notes.length;
        state.hasMoreNotes = Boolean(data.hasMore);

        renderNotes();

        if (state.selectedNoteId) {
            const exists = state.notes.some((item) => Number(item.id) === state.selectedNoteId);
            if (exists) {
                selectNote(state.selectedNoteId);
            } else {
                resetEditor();
            }
        }
    } finally {
        state.isLoadingNotes = false;
        renderNotes();
    }
}

function handleNotesScroll() {
    const threshold = 40;
    const nearBottom = el.notesList.scrollTop + el.notesList.clientHeight >= el.notesList.scrollHeight - threshold;

    if (nearBottom) {
        loadNotes().catch((error) => showMessage(error.message, 'error'));
    }
}

let shareSearchTimer = null;

async function loadShareSuggestions() {
    const query = el.shareUsername.value.trim();

    if (query.length < 1) {
        el.shareUserSuggestions.innerHTML = '';
        return;
    }

    try {
        const result = await api('searchUsers', { query: { q: query } });
        el.shareUserSuggestions.innerHTML = '';

        (result.users || []).forEach((username) => {
            const option = document.createElement('option');
            option.value = username;
            el.shareUserSuggestions.append(option);
        });
    } catch (error) {
        console.error(error);
    }
}

async function bootstrap() {
    try {
        const info = await api('me');
        state.csrf = info.csrf;

        if (info.authenticated) {
            state.user = info.user;
            switchToApp();
            await loadNotes(true);
        } else {
            switchToAuth();
        }
    } catch (error) {
        showMessage(error.message, 'error');
    }
}

function switchToApp() {
    el.authSection.classList.add('hidden');
    el.notesSection.classList.remove('hidden');
    el.userPanel.classList.remove('hidden');
    el.welcomeText.textContent = `Здравствуйте, ${state.user.username}!`;
}

function switchToAuth() {
    el.authSection.classList.remove('hidden');
    el.notesSection.classList.add('hidden');
    el.userPanel.classList.add('hidden');
}

el.loginTab.addEventListener('click', () => setAuthMode('login'));
el.registerTab.addEventListener('click', () => setAuthMode('register'));

el.authForm.addEventListener('submit', async (event) => {
    event.preventDefault();

    try {
        const payload = {
            username: el.authUsername.value.trim(),
            password: el.authPassword.value,
        };

        if (state.mode === 'register') {
            await api('register', { method: 'POST', body: payload });
            showMessage('Регистрация успешна. Теперь выполните вход.', 'ok');
            setAuthMode('login');
            el.authPassword.value = '';
            return;
        }

        const result = await api('login', { method: 'POST', body: payload });
        state.user = { username: result.username };
        state.csrf = result.csrf;
        switchToApp();
        resetEditor();
        await loadNotes(true);
    } catch (error) {
        showMessage(error.message, 'error');
    }
});

el.logoutBtn.addEventListener('click', async () => {
    try {
        await api('logout', { method: 'POST' });
        state.user = null;
        state.notes = [];
        state.notesOffset = 0;
        state.hasMoreNotes = true;
        resetEditor();
        switchToAuth();
        showMessage('Вы вышли из системы.', 'ok');
    } catch (error) {
        showMessage(error.message, 'error');
    }
});

el.newNoteBtn.addEventListener('click', resetEditor);

el.searchInput.addEventListener('input', async () => {
    try {
        await loadNotes(true);
    } catch (error) {
        showMessage(error.message, 'error');
    }
});

el.noteScopeFilter.addEventListener('change', async () => {
    state.noteScope = el.noteScopeFilter.value;
    try {
        await loadNotes(true);
    } catch (error) {
        showMessage(error.message, 'error');
    }
});

el.notesList.addEventListener('scroll', handleNotesScroll);

el.noteForm.addEventListener('submit', async (event) => {
    event.preventDefault();

    try {
        const id = Number(el.noteId.value || 0);
        const payload = {
            id,
            title: el.noteTitle.value.trim(),
            content: el.noteContent.value.trim(),
        };

        if (!payload.title) {
            throw new Error('Заполните заголовок заметки.');
        }

        if (id > 0) {
            await api('updateNote', { method: 'POST', body: payload });
            showMessage('Заметка обновлена.', 'ok');
        } else {
            const result = await api('createNote', { method: 'POST', body: payload });
            state.selectedNoteId = Number(result.id);
            showMessage('Заметка создана.', 'ok');
        }

        await loadNotes(true);
    } catch (error) {
        showMessage(error.message, 'error');
    }
});

el.deleteBtn.addEventListener('click', async () => {
    const id = Number(el.noteId.value || 0);

    if (!id) {
        return;
    }

    if (!window.confirm('Удалить заметку? Это действие необратимо.')) {
        return;
    }

    try {
        await api('deleteNote', { method: 'POST', body: { id } });
        showMessage('Заметка удалена.', 'ok');
        resetEditor();
        await loadNotes(true);
    } catch (error) {
        showMessage(error.message, 'error');
    }
});

el.shareUsername.addEventListener('input', () => {
    if (shareSearchTimer) {
        clearTimeout(shareSearchTimer);
    }

    shareSearchTimer = setTimeout(loadShareSuggestions, 250);
});

el.shareUsername.addEventListener('focus', loadShareSuggestions);

el.shareBtn.addEventListener('click', async () => {
    const id = Number(el.noteId.value || 0);
    const username = el.shareUsername.value.trim();

    if (!id || !username) {
        showMessage('Укажите логин пользователя для совместного доступа.', 'error');
        return;
    }

    try {
        await api('shareNote', { method: 'POST', body: { id, username } });
        el.shareUsername.value = '';
        el.shareUserSuggestions.innerHTML = '';
        showMessage('Доступ успешно выдан.', 'ok');
        await loadSharedUsers(id, true);
    } catch (error) {
        showMessage(error.message, 'error');
    }
});

el.sharedUsersList.addEventListener('click', async (event) => {
    const target = event.target;
    if (!(target instanceof HTMLElement) || !target.classList.contains('revoke-share-btn')) {
        return;
    }

    const noteId = Number(el.noteId.value || 0);
    const username = target.dataset.username || '';

    if (!noteId || !username) {
        return;
    }

    if (!window.confirm(`Отозвать доступ для пользователя ${username}?`)) {
        return;
    }

    try {
        await api('revokeShare', { method: 'POST', body: { id: noteId, username } });
        showMessage('Доступ пользователя отозван.', 'ok');
        await loadSharedUsers(noteId, true);
        await loadNotes(true);
    } catch (error) {
        showMessage(error.message, 'error');
    }
});

bootstrap();
