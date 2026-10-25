<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

require_post();
log_out();
header('Location: ' . url('index.php'));
exit;
