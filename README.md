# Сервис заметок (PHP + MySQL + JS)

Учебное веб-приложение для управления заметками с поддержкой:

- регистрации и входа пользователей;
- добавления, редактирования и удаления заметок;
- совместного доступа к заметкам (редактирование для пользователей с доступом) и отзыва доступа;
- поиска и фильтрации заметок (все / мои / общие);
- AJAX-взаимодействия без перезагрузки страницы;
- защиты от SQL-инъекций (PDO prepared statements);
- защиты от CSRF (токены в сессии + заголовок `X-CSRF-Token`).

## Быстрый старт

1. Создайте БД и таблицы:

```bash
mysql -u root -p < init.sql
```

2. Настройте переменные окружения (или отредактируйте `config.php`):

- `DB_HOST`
- `DB_PORT`
- `DB_NAME`
- `DB_USER`
- `DB_PASS`

3. Запустите встроенный сервер PHP:

```bash
php -S 0.0.0.0:8000
```

4. Откройте в браузере: `http://localhost:8000`

## API для Selenium/xUnit тестов

Все маршруты находятся в `api.php?action=...`.

- `GET action=me`
- `POST action=register`
- `POST action=login`
- `POST action=logout`
- `GET action=listNotes&q=...&scope=all|mine|shared&offset=0&limit=20`
- `POST action=createNote`
- `POST action=updateNote`
- `POST action=deleteNote`
- `POST action=shareNote`
- `POST action=revokeShare`
- `GET action=listSharedUsers&id=...`
- `GET action=searchUsers&q=...`

Формат — JSON.

## Архитектура интерфейса (Single View)

Да, приложение работает в **одном View / одной странице** (`index.php`).

- переключение между блоками авторизации и рабочего пространства заметок происходит динамически через JS;
- все операции (вход, регистрация, CRUD заметок, поиск, шаринг) выполняются через AJAX (`fetch`) без перезагрузки страницы;
- сервер возвращает JSON-ответы через `api.php`.
