<?php
/** Shared session initialization for customer and staff authentication. */
function start_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
    // Expire sessions from before the unified identity schema.
    if (($_SESSION['_identity_schema'] ?? null) !== 2) {
        $_SESSION = ['_identity_schema' => 2];
        session_regenerate_id(true);
    }
}
