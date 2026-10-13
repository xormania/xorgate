<?php

$path = dirname(__DIR__).'/.env.local';
if (!is_file($path)) {
    $secret = getenv('APP_SECRET') ?: bin2hex(random_bytes(32));
    $token = getenv('XORGATE_MCP_TOKEN') ?: bin2hex(random_bytes(32));
    if (!preg_match('/\A[a-zA-Z0-9_-]{32,}\z/', $secret) || !preg_match('/\A[a-zA-Z0-9_-]{32,}\z/', $token)) {
        throw new RuntimeException('Bootstrap secrets must contain at least 32 letters, digits, underscores or dashes.');
    }
    $handle = fopen($path, 'x');
    if (false === $handle) {
        throw new RuntimeException('Could not create local environment configuration.');
    }
    chmod($path, 0600);
    fwrite($handle, "APP_SECRET=$secret\nXORGATE_MCP_TOKEN=$token\n");
    fclose($handle);
}
