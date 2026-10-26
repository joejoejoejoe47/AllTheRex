<?php
/**
 * Copy this file to config.php (same folder) and fill it in.
 * config.php is git-ignored, so your database password never goes to GitHub.
 * Create it on the server with Plesk -> Files, not through Git.
 */
return [
    'db_host' => 'a2nlmysql55plsk.secureserver.net',
    'db_port' => 3306,
    'db_name' => 'YOUR_DATABASE_NAME',
    'db_user' => 'YOUR_DATABASE_USER',
    'db_pass' => 'YOUR_DATABASE_PASSWORD',

    // Needed once to open setup.php (creates the tables). Pick a long random string.
    // Delete setup.php from the server when you're done.
    'setup_token' => '',

    'site_name' => 'AllTheRex',
    'debug'     => false,   // true shows technical errors on screen; keep false in production
];
