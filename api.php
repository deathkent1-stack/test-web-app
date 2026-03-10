<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

$config = require __DIR__ . '/config.php';
session_name($config['app']['session_name']);
session_start();

header('Content-Type: application/json; charset=utf-8');

set_exception_handler(static function (Throwable $exception): void {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Внутренняя ошибка сервера. Проверьте настройки БД и структуру таблиц.',
        'details' => $exception->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
});

function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function requestData(): array
{
    $input = file_get_contents('php://input');
    if ($input === false || $input === '') {
        return [];
    }

    $decoded = json_decode($input, true);
    return is_array($decoded) ? $decoded : [];
}

function requireAuth(): int
{
    if (empty($_SESSION['user_id'])) {
        respond(['ok' => false, 'error' => 'Требуется авторизация.'], 401);
    }

    return (int) $_SESSION['user_id'];
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verifyCsrf(string $headerName): void
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $sessionToken = $_SESSION['csrf_token'] ?? '';

    if ($token === '' || $sessionToken === '' || !hash_equals($sessionToken, $token)) {
        respond(['ok' => false, 'error' => 'Недействительный CSRF-токен.'], 403);
    }
}

function getNoteForUser(PDO $pdo, int $noteId, int $userId): ?array
{
    $sql = "
        SELECT n.id, n.owner_id, n.title, n.content, n.updated_at,
               u.username AS owner_name,
               (n.owner_id = :user_id_view) AS is_owner
        FROM notes n
        JOIN users u ON u.id = n.owner_id
        WHERE n.id = :note_id
          AND (
              n.owner_id = :user_id_owner
              OR EXISTS (
                  SELECT 1 FROM note_shares s
                  WHERE s.note_id = n.id AND s.shared_with_user_id = :user_id_shared
              )
          )
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':note_id' => $noteId,
        ':user_id_view' => $userId,
        ':user_id_owner' => $userId,
        ':user_id_shared' => $userId,
    ]);

    $note = $stmt->fetch();
    return $note ?: null;
}

$pdo = getPdo();
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($action === 'csrf' && $method === 'GET') {
    respond(['ok' => true, 'csrf' => csrfToken()]);
}

if ($action === 'register' && $method === 'POST') {
    $data = requestData();
    $username = trim((string) ($data['username'] ?? ''));
    $password = (string) ($data['password'] ?? '');

    if ($username === '' || mb_strlen($username) < 3) {
        respond(['ok' => false, 'error' => 'Логин должен быть не короче 3 символов.'], 422);
    }

    if (mb_strlen($password) < 6) {
        respond(['ok' => false, 'error' => 'Пароль должен быть не короче 6 символов.'], 422);
    }

    $check = $pdo->prepare('SELECT id FROM users WHERE username = :username');
    $check->execute([':username' => $username]);
    if ($check->fetch()) {
        respond(['ok' => false, 'error' => 'Пользователь с таким логином уже существует.'], 409);
    }

    $insert = $pdo->prepare('INSERT INTO users (username, password_hash) VALUES (:username, :password_hash)');
    $insert->execute([
        ':username' => $username,
        ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
    ]);

    respond(['ok' => true, 'message' => 'Регистрация выполнена. Войдите в систему.'], 201);
}

if ($action === 'login' && $method === 'POST') {
    $data = requestData();
    $username = trim((string) ($data['username'] ?? ''));
    $password = (string) ($data['password'] ?? '');

    $stmt = $pdo->prepare('SELECT id, password_hash FROM users WHERE username = :username');
    $stmt->execute([':username' => $username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        respond(['ok' => false, 'error' => 'Неверный логин или пароль.'], 401);
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['username'] = $username;
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    respond(['ok' => true, 'username' => $username, 'csrf' => $_SESSION['csrf_token']]);
}

if ($action === 'logout' && $method === 'POST') {
    verifyCsrf($config['app']['csrf_header']);
    session_unset();
    session_destroy();
    respond(['ok' => true]);
}

if ($action === 'me' && $method === 'GET') {
    if (!empty($_SESSION['user_id'])) {
        respond([
            'ok' => true,
            'authenticated' => true,
            'user' => [
                'id' => (int) $_SESSION['user_id'],
                'username' => (string) $_SESSION['username'],
            ],
            'csrf' => csrfToken(),
        ]);
    }

    respond(['ok' => true, 'authenticated' => false, 'csrf' => csrfToken()]);
}

$userId = requireAuth();

if (in_array($action, ['createNote', 'updateNote', 'deleteNote', 'shareNote', 'revokeShare'], true)) {
    verifyCsrf($config['app']['csrf_header']);
}

if ($action === 'listNotes' && $method === 'GET') {
    $q = trim((string) ($_GET['q'] ?? ''));
    $scope = (string) ($_GET['scope'] ?? 'all');
    $offset = max(0, (int) ($_GET['offset'] ?? 0));
    $limit = (int) ($_GET['limit'] ?? 20);
    $limit = max(1, min($limit, 100));

    if (!in_array($scope, ['all', 'mine', 'shared'], true)) {
        $scope = 'all';
    }

    $sql = "
        SELECT DISTINCT n.id, n.title, n.content, n.updated_at,
               u.username AS owner_name,
               (n.owner_id = :user_id_view) AS is_owner
        FROM notes n
        JOIN users u ON u.id = n.owner_id
        LEFT JOIN note_shares s ON s.note_id = n.id
        WHERE (n.owner_id = :user_id_owner OR s.shared_with_user_id = :user_id_shared)
    ";

    $params = [
        ':user_id_view' => $userId,
        ':user_id_owner' => $userId,
        ':user_id_shared' => $userId,
    ];

    if ($q !== '') {
        $sql .= ' AND (n.title LIKE :query_title OR n.content LIKE :query_content OR u.username LIKE :query_owner)';
        $searchLike = '%' . $q . '%';
        $params[':query_title'] = $searchLike;
        $params[':query_content'] = $searchLike;
        $params[':query_owner'] = $searchLike;
    }

    $sql .= ' ORDER BY n.updated_at DESC LIMIT :limit OFFSET :offset';

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        if (in_array($key, [':offset', ':limit', ':user_id_view', ':user_id_owner', ':user_id_shared'], true)) {
            $stmt->bindValue($key, (int) $value, PDO::PARAM_INT);
        } else {
            $stmt->bindValue($key, (string) $value, PDO::PARAM_STR);
        }
    }
    $stmt->execute();
    $notes = $stmt->fetchAll();

    respond([
        'ok' => true,
        'notes' => $notes,
        'hasMore' => count($notes) === $limit,
    ]);
}


if ($action === 'listSharedUsers' && $method === 'GET') {
    $noteId = (int) ($_GET['id'] ?? 0);

    if ($noteId <= 0) {
        respond(['ok' => false, 'error' => 'Некорректный идентификатор заметки.'], 422);
    }

    $note = getNoteForUser($pdo, $noteId, $userId);
    if (!$note || !(bool) $note['is_owner']) {
        respond(['ok' => false, 'error' => 'Список доступа доступен только владельцу заметки.'], 403);
    }

    $stmt = $pdo->prepare(
        'SELECT u.username
         FROM note_shares s
         JOIN users u ON u.id = s.shared_with_user_id
         WHERE s.note_id = :note_id
         ORDER BY u.username ASC'
    );
    $stmt->execute([':note_id' => $noteId]);

    $users = array_map(static fn (array $row): string => (string) $row['username'], $stmt->fetchAll());

    respond(['ok' => true, 'users' => $users]);
}

if ($action === 'searchUsers' && $method === 'GET') {
    $q = trim((string) ($_GET['q'] ?? ''));

    if ($q === '') {
        respond(['ok' => true, 'users' => []]);
    }

    $stmt = $pdo->prepare(
        'SELECT username
         FROM users
         WHERE username LIKE :query AND id <> :current_user_id
         ORDER BY username ASC
         LIMIT 10'
    );
    $stmt->execute([
        ':query' => $q . '%',
        ':current_user_id' => $userId,
    ]);

    $users = array_map(static fn (array $row): string => (string) $row['username'], $stmt->fetchAll());

    respond(['ok' => true, 'users' => $users]);
}

if ($action === 'createNote' && $method === 'POST') {
    $data = requestData();
    $title = trim((string) ($data['title'] ?? ''));
    $content = trim((string) ($data['content'] ?? ''));

    if ($title === '') {
        respond(['ok' => false, 'error' => 'Заголовок обязателен.'], 422);
    }

    $stmt = $pdo->prepare('INSERT INTO notes (owner_id, title, content) VALUES (:owner_id, :title, :content)');
    $stmt->execute([
        ':owner_id' => $userId,
        ':title' => $title,
        ':content' => $content,
    ]);

    respond(['ok' => true, 'id' => (int) $pdo->lastInsertId()], 201);
}

if ($action === 'updateNote' && $method === 'POST') {
    $data = requestData();
    $noteId = (int) ($data['id'] ?? 0);
    $title = trim((string) ($data['title'] ?? ''));
    $content = trim((string) ($data['content'] ?? ''));

    if ($noteId <= 0 || $title === '') {
        respond(['ok' => false, 'error' => 'Некорректные данные заметки.'], 422);
    }

    $note = getNoteForUser($pdo, $noteId, $userId);
    if (!$note) {
        respond(['ok' => false, 'error' => 'Заметка не найдена или доступ запрещён.'], 403);
    }

    $stmt = $pdo->prepare('UPDATE notes SET title = :title, content = :content WHERE id = :id');
    $stmt->execute([
        ':title' => $title,
        ':content' => $content,
        ':id' => $noteId,
    ]);

    respond(['ok' => true]);
}

if ($action === 'deleteNote' && $method === 'POST') {
    $data = requestData();
    $noteId = (int) ($data['id'] ?? 0);

    if ($noteId <= 0) {
        respond(['ok' => false, 'error' => 'Некорректный идентификатор заметки.'], 422);
    }

    $note = getNoteForUser($pdo, $noteId, $userId);
    if (!$note || !(bool) $note['is_owner']) {
        respond(['ok' => false, 'error' => 'Можно удалять только свои заметки.'], 403);
    }

    $stmt = $pdo->prepare('DELETE FROM notes WHERE id = :id');
    $stmt->execute([':id' => $noteId]);

    respond(['ok' => true]);
}

if ($action === 'shareNote' && $method === 'POST') {
    $data = requestData();
    $noteId = (int) ($data['id'] ?? 0);
    $username = trim((string) ($data['username'] ?? ''));

    if ($noteId <= 0 || $username === '') {
        respond(['ok' => false, 'error' => 'Укажите заметку и логин пользователя для доступа.'], 422);
    }

    $note = getNoteForUser($pdo, $noteId, $userId);
    if (!$note || !(bool) $note['is_owner']) {
        respond(['ok' => false, 'error' => 'Вы можете делиться только своими заметками.'], 403);
    }

    $userStmt = $pdo->prepare('SELECT id FROM users WHERE username = :username');
    $userStmt->execute([':username' => $username]);
    $targetUser = $userStmt->fetch();

    if (!$targetUser) {
        respond(['ok' => false, 'error' => 'Пользователь не найден.'], 404);
    }

    $targetId = (int) $targetUser['id'];
    if ($targetId === $userId) {
        respond(['ok' => false, 'error' => 'Нельзя предоставить доступ самому себе.'], 422);
    }

    $shareStmt = $pdo->prepare(
        'INSERT INTO note_shares (note_id, shared_with_user_id) VALUES (:note_id, :shared_with_user_id)
         ON DUPLICATE KEY UPDATE created_at = CURRENT_TIMESTAMP'
    );
    $shareStmt->execute([
        ':note_id' => $noteId,
        ':shared_with_user_id' => $targetId,
    ]);

    respond(['ok' => true, 'message' => 'Доступ выдан.']);
}

if ($action === 'revokeShare' && $method === 'POST') {
    $data = requestData();
    $noteId = (int) ($data['id'] ?? 0);
    $username = trim((string) ($data['username'] ?? ''));

    if ($noteId <= 0 || $username === '') {
        respond(['ok' => false, 'error' => 'Укажите заметку и логин пользователя для отзыва доступа.'], 422);
    }

    $note = getNoteForUser($pdo, $noteId, $userId);
    if (!$note || !(bool) $note['is_owner']) {
        respond(['ok' => false, 'error' => 'Вы можете отзывать доступ только к своим заметкам.'], 403);
    }

    $userStmt = $pdo->prepare('SELECT id FROM users WHERE username = :username');
    $userStmt->execute([':username' => $username]);
    $targetUser = $userStmt->fetch();

    if (!$targetUser) {
        respond(['ok' => false, 'error' => 'Пользователь не найден.'], 404);
    }

    $revokeStmt = $pdo->prepare(
        'DELETE FROM note_shares
         WHERE note_id = :note_id AND shared_with_user_id = :shared_with_user_id'
    );
    $revokeStmt->execute([
        ':note_id' => $noteId,
        ':shared_with_user_id' => (int) $targetUser['id'],
    ]);

    if ($revokeStmt->rowCount() === 0) {
        respond(['ok' => false, 'error' => 'У пользователя нет доступа к этой заметке.'], 404);
    }

    respond(['ok' => true, 'message' => 'Доступ отозван.']);
}

respond(['ok' => false, 'error' => 'Маршрут не найден.'], 404);
