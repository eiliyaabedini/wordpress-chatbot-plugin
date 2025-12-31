<?php
/**
 * CORS Preflight Handler for Embed API
 *
 * This file handles OPTIONS preflight requests directly, bypassing WordPress.
 * It's a fallback for servers where WordPress doesn't handle CORS properly.
 *
 * URL: /wp-content/plugins/chatbot-plugin/embed-cors.php?action=preflight
 */

// Send CORS headers immediately
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE, HEAD');
header('Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept, Authorization, X-Session-ID, Cache-Control, X-WP-Nonce');
header('Access-Control-Max-Age: 86400');
header('Access-Control-Expose-Headers: X-WP-Total, X-WP-TotalPages');
header('Access-Control-Allow-Credentials: false');

// Handle OPTIONS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Content-Type: text/plain; charset=UTF-8');
    header('Content-Length: 0');
    http_response_code(200);
    exit(0);
}

// For non-OPTIONS requests, redirect to WordPress REST API
$action = isset($_GET['action']) ? $_GET['action'] : '';
$token = isset($_GET['token']) ? $_GET['token'] : '';

if ($action === 'preflight') {
    // Just return success for preflight test
    header('Content-Type: application/json');
    echo json_encode(['status' => 'ok', 'cors' => 'enabled']);
    exit(0);
}

// For actual API requests, we need to load WordPress
// But this file should only be used for preflight/CORS testing
header('Content-Type: application/json');
http_response_code(400);
echo json_encode([
    'error' => 'Use the WordPress REST API for actual requests',
    'endpoint' => '/wp-json/chatbot-plugin/v1/embed/{token}/...'
]);
exit(1);
