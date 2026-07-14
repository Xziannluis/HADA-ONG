<?php
session_start();
header('Content-Type: application/json');
require __DIR__ . DIRECTORY_SEPARATOR . 'config.php';

$birthdayPassword = '071703';
$uploadDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads';
$uploadPublicPath = 'uploads/';

function respond($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function readJsonBody() {
    $body = json_decode(file_get_contents('php://input'), true);
    return is_array($body) ? $body : [];
}

function cleanText($value, $limit) {
    $value = is_string($value) ? trim($value) : '';
    $value = preg_replace('/\s+/', ' ', $value);
    return substr($value, 0, $limit);
}

function cleanMessage($value, $limit) {
    $value = is_string($value) ? trim($value) : '';
    return substr($value, 0, $limit);
}

function database($dbHost, $dbName, $dbUser, $dbPass) {
    try {
        $pdo = new PDO(
            'mysql:host=' . $dbHost . ';dbname=' . $dbName . ';charset=utf8mb4',
            $dbUser,
            $dbPass
        );
    } catch (PDOException $error) {
        if ($error->getCode() !== 1049 && strpos($error->getMessage(), 'Unknown database') === false) {
            throw $error;
        }

        $server = new PDO('mysql:host=' . $dbHost . ';charset=utf8mb4', $dbUser, $dbPass);
        $server->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $server->exec(
            'CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '``', $dbName) . '`
             CHARACTER SET utf8mb4
             COLLATE utf8mb4_unicode_ci'
        );

        $pdo = new PDO(
            'mysql:host=' . $dbHost . ';dbname=' . $dbName . ';charset=utf8mb4',
            $dbUser,
            $dbPass
        );
    }

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS wishes (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            sender_name VARCHAR(80) NOT NULL,
            relationship VARCHAR(80) NOT NULL,
            message TEXT NOT NULL,
            photo_path VARCHAR(255) NOT NULL DEFAULT "",
            video_path VARCHAR(255) NOT NULL DEFAULT "",
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    ensureColumn($pdo, 'wishes', 'photo_path', 'VARCHAR(255) NOT NULL DEFAULT ""');
    ensureColumn($pdo, 'wishes', 'video_path', 'VARCHAR(255) NOT NULL DEFAULT ""');

    return $pdo;
}

function ensureColumn($pdo, $table, $column, $definition) {
    $statement = $pdo->prepare(
        'SELECT COUNT(*)
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name
           AND COLUMN_NAME = :column_name'
    );
    $statement->execute([
        ':table_name' => $table,
        ':column_name' => $column,
    ]);

    if ((int) $statement->fetchColumn() === 0) {
        $pdo->exec(
            'ALTER TABLE `' . str_replace('`', '``', $table) . '`
             ADD COLUMN `' . str_replace('`', '``', $column) . '` ' . $definition
        );
    }
}

function requireBirthdayAccess() {
    if (empty($_SESSION['birthday_access'])) {
        respond(['error' => 'Birthday access required.'], 401);
    }
}

function uploadMedia($field, $uploadDir, $publicPath, $allowedTypes, $maxBytes) {
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return '';
    }

    $file = $_FILES[$field];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        respond(['error' => 'Upload failed. Please try another file.'], 422);
    }

    if ($file['size'] > $maxBytes) {
        respond(['error' => 'One of the uploaded files is too large.'], 422);
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);
    if (!array_key_exists($mimeType, $allowedTypes)) {
        respond(['error' => 'Unsupported file type.'], 422);
    }

    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $extension = $allowedTypes[$mimeType];
    $filename = bin2hex(random_bytes(16)) . '.' . $extension;
    $target = $uploadDir . DIRECTORY_SEPARATOR . $filename;

    if (!move_uploaded_file($file['tmp_name'], $target)) {
        respond(['error' => 'Could not save the uploaded file.'], 500);
    }

    return $publicPath . $filename;
}

function removeUploadedFile($path) {
    if ($path === '' || strpos($path, 'uploads/') !== 0) {
        return;
    }

    $fullPath = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
    if (is_file($fullPath)) {
        unlink($fullPath);
    }
}

$isMultipart = isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'multipart/form-data') !== false;
$body = $isMultipart ? $_POST : readJsonBody();
$action = isset($body['action']) ? $body['action'] : ($_GET['action'] ?? '');

try {
    switch ($action) {
        case 'login':
            $password = isset($body['password']) ? trim((string) $body['password']) : '';
            if (hash_equals($GLOBALS['birthdayPassword'], $password)) {
                $_SESSION['birthday_access'] = true;
                respond(['ok' => true]);
            }

            respond(['error' => 'Incorrect password. Try again.'], 401);

        case 'logout':
            $_SESSION = [];
            session_destroy();
            respond(['ok' => true]);

        case 'checkSession':
            respond(['authenticated' => !empty($_SESSION['birthday_access'])]);

        case 'submitWish':
            $name = cleanText($body['name'] ?? '', 80);
            $relationship = cleanText($body['relationship'] ?? '', 80);
            $message = cleanMessage($body['message'] ?? '', 1500);

            if ($name === '' || $relationship === '' || $message === '') {
                respond(['error' => 'Name, relationship, and message are required.'], 422);
            }

            $videoPath = uploadMedia(
                'video',
                $GLOBALS['uploadDir'],
                $GLOBALS['uploadPublicPath'],
                [
                    'video/mp4' => 'mp4',
                    'video/webm' => 'webm',
                    'video/ogg' => 'ogv',
                    'video/quicktime' => 'mov',
                ],
                80 * 1024 * 1024
            );

            $pdo = database($GLOBALS['dbHost'], $GLOBALS['dbName'], $GLOBALS['dbUser'], $GLOBALS['dbPass']);
            $statement = $pdo->prepare(
                'INSERT INTO wishes (sender_name, relationship, message, photo_path, video_path)
                 VALUES (:sender_name, :relationship, :message, :photo_path, :video_path)'
            );
            $statement->execute([
                ':sender_name' => $name,
                ':relationship' => $relationship,
                ':message' => $message,
                ':photo_path' => '',
                ':video_path' => $videoPath,
            ]);

            respond(['ok' => true, 'message' => 'Your message was sent.']);

        case 'listWishes':
            requireBirthdayAccess();
            $pdo = database($GLOBALS['dbHost'], $GLOBALS['dbName'], $GLOBALS['dbUser'], $GLOBALS['dbPass']);
            $statement = $pdo->query(
                'SELECT id,
                        sender_name AS senderName,
                        relationship,
                        message,
                        photo_path AS photoPath,
                        video_path AS videoPath,
                        created_at AS createdAt
                 FROM wishes
                 ORDER BY created_at DESC, id DESC'
            );
            respond(['wishes' => $statement->fetchAll(PDO::FETCH_ASSOC)]);

        case 'deleteWish':
            requireBirthdayAccess();
            $id = isset($body['id']) ? (int) $body['id'] : 0;
            if ($id <= 0) {
                respond(['error' => 'Invalid wish.'], 422);
            }

            $pdo = database($GLOBALS['dbHost'], $GLOBALS['dbName'], $GLOBALS['dbUser'], $GLOBALS['dbPass']);
            $findStatement = $pdo->prepare('SELECT photo_path, video_path FROM wishes WHERE id = :id');
            $findStatement->execute([':id' => $id]);
            $wish = $findStatement->fetch(PDO::FETCH_ASSOC);

            $statement = $pdo->prepare('DELETE FROM wishes WHERE id = :id');
            $statement->execute([':id' => $id]);

            if ($wish) {
                removeUploadedFile($wish['photo_path']);
                removeUploadedFile($wish['video_path']);
            }

            respond(['ok' => true]);

        default:
            respond(['error' => 'Unknown action.'], 400);
    }
} catch (Throwable $error) {
    respond(['error' => 'Server error: ' . $error->getMessage()], 500);
}
