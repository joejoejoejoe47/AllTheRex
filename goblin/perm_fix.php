<?php
$dir = __DIR__ . '/saved_apps';

// Force wide permissions on the folder
if (is_dir($dir)) {
    if (chmod($dir, 0777)) {
        echo "SUCCESS: Folder permissions successfully updated to 0777!<br>";
    } else {
        echo "FAILED to update permissions. Server ownership restriction active.<br>";
    }
} else {
    echo "saved_apps folder not found.<br>";
}

// Test writing a sample file
$testFile = $dir . '/test.txt';
if (@file_put_contents($testFile, 'test')) {
    echo "SUCCESS: PHP can write files into saved_apps!";
    unlink($testFile); // Clean up test file
} else {
    echo "ERROR: PHP is still blocked from writing files.";
}
?>