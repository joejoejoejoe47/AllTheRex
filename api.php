{\rtf1\ansi\ansicpg1252\cocoartf2870
\cocoatextscaling0\cocoaplatform0{\fonttbl\f0\fswiss\fcharset0 Helvetica;\f1\fswiss\fcharset0 ArialMT;}
{\colortbl;\red255\green255\blue255;\red26\green26\blue26;\red255\green255\blue255;}
{\*\expandedcolortbl;;\cssrgb\c13333\c13333\c13333;\cssrgb\c100000\c100000\c100000;}
\margl1440\margr1440\vieww11520\viewh8400\viewkind0
\pard\tx720\tx1440\tx2160\tx2880\tx3600\tx4320\tx5040\tx5760\tx6480\tx7200\tx7920\tx8640\pardirnatural\partightenfactor0

\f0\fs24 \cf0 <?php\
header('Content-Type: application/json');\
\
// --- UPDATE THESE 3 LINES WITH YOUR PLESK DATABASE DETAILS ---\
$db_user = \'91morsel\'92;\
$db_pass = '
\f1\fs20 \cf2 \cb3 \expnd0\expndtw0\kerning0
\outl0\strokewidth0 \strokec2 1xx0j24?P
\f0\fs24 \cf0 \cb1 \kerning1\expnd0\expndtw0 \outl0\strokewidth0 ';\
$db_name = \'91morseyo\'92;\
\
$conn = new mysqli('localhost', $db_user, $db_pass, $db_name);\
\
if ($conn->connect_error) \{\
    die(json_encode(['error' => 'Database connection failed']));\
\}\
\
// GET REQUEST: Load all movies saved in the database\
if ($_SERVER['REQUEST_METHOD'] === 'GET') \{\
    $result = $conn->query("SELECT * FROM movies ORDER BY id DESC");\
    $movies = [];\
    while ($row = $result->fetch_assoc()) \{\
        $movies[] = $row;\
    \}\
    echo json_encode($movies);\
    exit;\
\}\
\
// POST REQUEST: Save uploaded movie & files to Plesk permanently\
if ($_SERVER['REQUEST_METHOD'] === 'POST') \{\
    $title = $_POST['title'] ?? 'Untitled Movie';\
    $actor_name = $_POST['actor_name'] ?? '';\
\
    function uploadFile($fileKey) \{\
        if (!isset($_FILES[$fileKey]) || $_FILES[$fileKey]['error'] !== UPLOAD_ERR_OK) \{\
            return '';\
        \}\
        $targetDir = 'uploads/';\
        $fileName = time() . '_' . basename($_FILES[$fileKey]['name']);\
        $targetFilePath = $targetDir . $fileName;\
        if (move_uploaded_file($_FILES[$fileKey]['tmp_name'], $targetFilePath)) \{\
            return $targetFilePath;\
        \}\
        return '';\
    \}\
\
    $video_url = uploadFile('video');\
    $poster_url = uploadFile('poster');\
    $actor_url = uploadFile('actor');\
\
    if ($video_url && $poster_url) \{\
        $stmt = $conn->prepare("INSERT INTO movies (title, video_url, poster_url, actor_name, actor_url) VALUES (?, ?, ?, ?, ?)");\
        $stmt->bind_param("sssss", $title, $video_url, $poster_url, $actor_name, $actor_url);\
        $stmt->execute();\
        echo json_encode(['success' => true]);\
    \} else \{\
        echo json_encode(['error' => 'File upload failed. Ensure files are valid.']);\
    \}\
    exit;\
\}\
?>}