<?php
declare(strict_types=1);
?><!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Сервис заметок</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
<div class="app-container" id="mainView">
    <header class="app-header">
        <div>
            <h1>📝 Сервис заметок</h1>
            <p>Создавайте, редактируйте, ищите и делитесь заметками без перезагрузки страницы.</p>
        </div>
        <div id="userPanel" class="user-panel hidden">
            <span id="welcomeText"></span>
            <button id="logoutBtn" class="secondary">Выйти</button>
        </div>
    </header>

    <section id="authSection" class="card auth-card">
        <div class="tabs">
            <button id="loginTab" class="tab active">Вход</button>
            <button id="registerTab" class="tab">Регистрация</button>
        </div>

        <form id="authForm" class="auth-form">
            <label>
                Логин
                <input type="text" id="authUsername" required minlength="3" autocomplete="username">
            </label>
            <label>
                Пароль
                <input type="password" id="authPassword" required minlength="6" autocomplete="current-password">
            </label>
            <button id="authSubmit" type="submit">Войти</button>
        </form>
    </section>

    <section id="notesSection" class="hidden">
        <div class="toolbar card">
            <input type="search" id="searchInput" placeholder="Поиск по заголовку, содержимому или автору...">
            <button id="newNoteBtn">Новая заметка</button>
        </div>

        <div class="notes-layout">
            <aside class="notes-list card">
                <h2>Список заметок</h2>
                <ul id="notesList"></ul>
            </aside>

            <main class="editor card">
                <h2 id="editorTitle">Редактор заметки</h2>
                <form id="noteForm">
                    <input type="hidden" id="noteId">
                    <label>
                        Заголовок
                        <input type="text" id="noteTitle" required>
                    </label>
                    <label>
                        Содержимое
                        <textarea id="noteContent" rows="10" placeholder="Введите текст заметки..."></textarea>
                    </label>
                    <div class="actions">
                        <button type="submit" id="saveBtn">Сохранить</button>
                        <button type="button" class="danger" id="deleteBtn">Удалить</button>
                    </div>
                </form>

                <div id="shareBlock" class="share-block hidden">
                    <h3>Совместный доступ</h3>
                    <p>Введите логин пользователя, чтобы дать ему доступ на чтение заметки.</p>
                    <div class="share-controls">
                        <input type="text" id="shareUsername" placeholder="Логин пользователя">
                        <button id="shareBtn" type="button">Поделиться</button>
                    </div>
                </div>
            </main>
        </div>
    </section>

    <div id="message" class="message hidden" role="alert"></div>
</div>

<script src="app.js" defer></script>
</body>
</html>
