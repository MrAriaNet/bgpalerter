<?php
/**
 * BGP Monitor API
 * RESTful API for adding prefixes to monitor
 * 
 * Usage:
 * POST /api.php
 * Headers: Authorization: Bearer YOUR_TOKEN
 * Body: {
 *   "prefix": "8.8.8.0/24",
 *   "description": "Google DNS"
 * }
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';

$config = require __DIR__ . '/config.php';

// Check if API is enabled
if (!isset($config['api']['enabled']) || !$config['api']['enabled']) {
    http_response_code(503);
    echo json_encode(['error' => 'API is disabled']);
    exit;
}

// Get authorization token
$token = null;
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
    $token = $matches[1];
} elseif (isset($_GET['token'])) {
    $token = $_GET['token'];
} elseif (isset($_POST['token'])) {
    $token = $_POST['token'];
}

// Validate token
$tokens = $config['api']['tokens'] ?? [];
if (empty($token) || !isset($tokens[$token])) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid or missing token']);
    exit;
}

$tokenName = $tokens[$token];
$db = new Database($config);

// Handle different request methods
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'POST':
        handleAddPrefix($db, $token, $tokenName, $config);
        break;
    
    case 'GET':
        handleGetPrefixes($db, $token);
        break;
    
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        break;
}

function handleAddPrefix($db, $token, $tokenName, $config) {
    // Get JSON input
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    // Also check POST data
    if (empty($data)) {
        $data = $_POST;
    }
    
    // Validate required fields
    if (empty($data['prefix'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Prefix is required']);
        return;
    }
    
    $prefix = trim($data['prefix']);
    $description = isset($data['description']) ? trim($data['description']) : '';
    
    // Validate prefix format (basic validation)
    if (!preg_match('/^(\d{1,3}\.){3}\d{1,3}\/\d{1,2}$/', $prefix)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid prefix format. Expected format: X.X.X.X/XX']);
        return;
    }
    
    // Check if prefix already exists in monitored_prefixes
    $existingMonitored = $db->getMonitoredPrefixes(false);
    $prefixExists = false;
    foreach ($existingMonitored as $mp) {
        if ($mp['prefix'] === $prefix && $mp['is_active'] == 1) {
            $prefixExists = true;
            break;
        }
    }
    
    // Also check config prefixes
    $configPrefixes = $config['monitoring']['prefixes'] ?? [];
    if (isset($configPrefixes[$prefix])) {
        $prefixExists = true;
    }
    
    if ($prefixExists) {
        http_response_code(409);
        echo json_encode(['error' => "Prefix {$prefix} is already being monitored"]);
        return;
    }
    
    // Add new prefix
    $result = $db->addMonitoredPrefix($prefix, $description, $tokenName, $token);
    if (!$result) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to add prefix to database']);
        return;
    }
    $message = 'Prefix added successfully';
    
    // Execute monitor.php in background to fetch initial data
    $monitorScript = __DIR__ . '/monitor.php';
    if (file_exists($monitorScript)) {
        // Execute in background (non-blocking)
        if (PHP_OS_FAMILY === 'Windows') {
            // Windows
            pclose(popen("start /B php \"$monitorScript\" > NUL 2>&1", "r"));
        } else {
            // Unix/Linux
            exec("php \"$monitorScript\" > /dev/null 2>&1 &");
        }
    }
    
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => $message,
        'data' => [
            'prefix' => $prefix,
            'description' => $description,
            'added_by' => $tokenName,
            'monitor_executed' => true
        ]
    ]);
}

function handleGetPrefixes($db, $token) {
    $prefixes = $db->getPrefixesByToken($token);
    
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'count' => count($prefixes),
        'data' => $prefixes
    ]);
}

