<?php
// Test-only config: SQLite file, console SMS.
return [
    'db_dsn' => 'sqlite:' . (getenv('VCHAT_TEST_DB') ?: sys_get_temp_dir() . '/vchat-test.sqlite'),
    'session_secret' => 'test-secret-test-secret-test-secret',
    'sms_ip_limit' => 1000, 'google_client_id' => 'test-client',
];
