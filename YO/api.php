<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// DB Credentials — Adjust as needed
$db_host = '127.0.0.1'; // Use 'localhost' if 127.0.0.1 gives socket errors
$db_port = '3306';
$db_name = 'your_database_name'; 
$db_user = 'your_database_user'; 
$db_pass = 'your_database_password'; 

$upload_dir = __DIR__ . '/uploads/';
$upload_url = 'uploads/';

if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

$pdo = null;
$use_db = false;

try {
    $dsn = "mysql:host={$db_host};port={$db_port};dbname={$db_name};charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT            => 5,
    ];
    
    $pdo = new PDO($dsn, $db_user, $db_pass, $options);
    $use_db = true;

    $createTableQuery = "
        CREATE TABLE IF NOT EXISTS movies (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            actor_name VARCHAR(255) DEFAULT NULL,
            video_url TEXT NOT NULL,
            poster_url TEXT NOT NULL,
            actor_url TEXT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    $pdo->exec($createTableQuery);

} catch (PDOException $e) {
    $use_db = false;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    if ($use_db && $pdo) {
        try {
            $stmt = $pdo->query("SELECT id, title, actor_name, video_url, poster_url, actor_url FROM movies ORDER BY id DESC");
            echo json_encode($stmt->fetchAll());
            exit();
        } catch (Exception $e) {}
    }
    echo json_encode([]);
    exit();
}

if ($method === 'POST') {
    $title = isset($_POST['title']) ? trim($_POST['title']) : 'Untitled';
    $actor_name = isset($_POST['actor_name']) ? trim($_POST['actor_name']) : '';

    $video_path = '';
    $poster_path = '';
    $actor_path = '';

    if (isset($_FILES['video']) && $_FILES['video']['error'] === UPLOAD_ERR_OK) {
        $ext = pathinfo($_FILES['video']['name'], PATHINFO_EXTENSION);
        $filename = 'video_' . time() . '_' . uniqid() . '.' . $ext;
        if (move_uploaded_file($_FILES['video']['tmp_name'], $upload_dir . $filename)) {
            $video_path = $upload_url . $filename;
        }
    }

    if (isset($_FILES['poster']) && $_FILES['poster']['error'] === UPLOAD_ERR_OK) {
        $ext = pathinfo($_FILES['poster']['name'], PATHINFO_EXTENSION);
        $filename = 'poster_' . time() . '_' . uniqid() . '.' . $ext;
        if (move_uploaded_file($_FILES['poster']['tmp_name'], $upload_dir . $filename)) {
            $poster_path = $upload_url . $filename;
        }
    }

    if (isset($_FILES['actor']) && $_FILES['actor']['error'] === UPLOAD_ERR_OK) {
        $ext = pathinfo($_FILES['actor']['name'], PATHINFO_EXTENSION);
        $filename = 'actor_' . time() . '_' . uniqid() . '.' . $ext;
        if (move_uploaded_file($_FILES['actor']['tmp_name'], $upload_dir . $filename)) {
            $actor_path = $upload_url . $filename;
        }
    }

    if (empty($video_path) || empty($poster_path)) {
        echo json_encode(['success' => false, 'error' => 'Failed to save files. Check folder permissions on /uploads/.']);
        exit();
    }

    if ($use_db && $pdo) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO movies (title, actor_name, video_url, poster_url, actor_url) 
                VALUES (:title, :actor_name, :video_url, :poster_url, :actor_url)
            ");
            $stmt->execute([
                ':title'      => $title,
                ':actor_name' => $actor_name,
                ':video_url'  => $video_path,
                ':poster_url' => $poster_path,
                ':actor_url'  => $actor_path
            ]);

            echo json_encode(['success' => true, 'message' => 'Published to database successfully!']);
            exit();
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => 'Database query failed: ' . $e->getMessage()]);
            exit();
        }
    }

    echo json_encode([
        'success' => true, 
        'warning' => 'File uploaded locally, but MySQL connection could not be established.'
    ]);
    exit();
}