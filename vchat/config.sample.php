<?php
/**
 * Copy to config.php (same folder) and fill in. config.php is git-ignored: create it on the
 * server with Plesk -> Files, never through Git. Use a NEW database for vchat.
 */
return [
    'db_host' => 'localhost',
    'db_port' => 3306,
    'db_name' => 'YOUR_VCHAT_DATABASE',
    'db_user' => 'YOUR_DB_USER',
    'db_pass' => 'YOUR_DB_PASSWORD',

    // REQUIRED: long random string (e.g. 64 hex chars). Signs login cookies and SMS codes.
    'session_secret' => '',
    // Needed once to run setup.php (creates the tables). Delete setup.php afterwards.
    'setup_token' => '',

    'session_days'     => 14,
    'max_participants' => 20,          // hard-capped at 20
    'trust_proxy'      => false,       // true only behind a proxy you control (X-Forwarded-*)
    'debug'            => false,

    // --- Google sign-in ---
    // Google Cloud Console > Credentials > OAuth client ID (Web). Add your site to
    // "Authorized JavaScript origins".
    'google_client_id' => '',

    // --- Phone sign-in: SMS code, then authenticator-app code ---
    'sms_mode'    => 'console',        // 'console' = write codes to the PHP error log (dev only), 'twilio' = real SMS
    'twilio_sid'  => '',
    'twilio_token'=> '',
    'twilio_from' => '',
    'totp_issuer' => 'AllTheRex vchat',
    'sms_ip_limit'=> 15,               // max SMS codes per IP per hour

    // --- WebRTC ---
    'stun_urls'     => ['stun:stun.l.google.com:19302'],
    // TURN strongly recommended (about 1 in 8 users can't connect without it).
    'turn_urls'     => [],             // e.g. ['turn:turn.example.com:3478', 'turns:turn.example.com:5349']
    'turn_secret'   => '',             // coturn "use-auth-secret" -> short-lived credentials
    'turn_username' => '',             // ...or static credentials from a TURN provider
    'turn_credential' => '',

    // --- Signalling over HTTP polling ---
    // 0 = plain short polling (works everywhere). Raise to ~5 for long-polling if your host allows
    // many concurrent PHP processes: lower latency, but each viewer holds a PHP worker.
    'poll_hold_seconds' => 0,
];
