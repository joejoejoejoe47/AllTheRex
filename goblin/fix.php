<?php
$dir = __DIR__ . '/saved_apps';
if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
    chmod($dir, 0777);
    echo "Folder created with full permissions!";
} else {
    chmod($dir, 0777);
    echo "Folder permissions updated to 777!";
}
?>