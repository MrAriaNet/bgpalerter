<?php
/**
 * BGP Route Monitor - Web Dashboard
 * Main dashboard for viewing route status and history
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';

$config = require __DIR__ . '/config.php';

// Set timezone
$timezone = $config['dashboard']['timezone'] ?? 'Asia/Tehran';
date_default_timezone_set($timezone);

$db = new Database($config);

// Handle add prefix form submission
$addPrefixMessage = null;
$addPrefixError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_prefix') {
    // Check if API is enabled
    if (!isset($config['api']['enabled']) || !$config['api']['enabled']) {
        $addPrefixError = 'API is disabled';
    } else {
        $token = trim($_POST['token'] ?? '');
        $prefix = trim($_POST['prefix'] ?? '');
        $description = trim($_POST['description'] ?? '');
        
        // Validate token
        $tokens = $config['api']['tokens'] ?? [];
        if (empty($token) || !isset($tokens[$token])) {
            $addPrefixError = 'Invalid or missing token';
        } elseif (empty($prefix)) {
            $addPrefixError = 'Prefix is required';
        } elseif (!preg_match('/^(\d{1,3}\.){3}\d{1,3}\/\d{1,2}$/', $prefix)) {
            $addPrefixError = 'Invalid prefix format. Expected format: X.X.X.X/XX';
        } else {
            // Check if prefix already exists
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
                $addPrefixError = "Prefix {$prefix} is already being monitored";
            } else {
                // Add prefix
                $tokenName = $tokens[$token];
                $result = $db->addMonitoredPrefix($prefix, $description, $tokenName, $token);
            
                if ($result) {
                    $addPrefixMessage = "Prefix {$prefix} added successfully! Monitor will check it shortly.";
                    
                    // Execute monitor.php in background
                    $monitorScript = __DIR__ . '/monitor.php';
                    if (file_exists($monitorScript)) {
                        if (PHP_OS_FAMILY === 'Windows') {
                            pclose(popen("start /B php \"$monitorScript\" > NUL 2>&1", "r"));
                        } else {
                            exec("php \"$monitorScript\" > /dev/null 2>&1 &");
                        }
                    }
                    
                    // Redirect to avoid resubmission
                    header("Location: " . $_SERVER['PHP_SELF'] . "?added=1");
                    exit;
                } else {
                    $addPrefixError = 'Failed to add prefix to database';
                }
            }
        }
    }
}

// Check if prefix was just added
if (isset($_GET['added']) && $_GET['added'] == 1) {
    $addPrefixMessage = "Prefix added successfully! Monitor will check it shortly.";
}

// Get parameters
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$prefixFilter = isset($_GET['prefix']) ? trim($_GET['prefix']) : '';
$dateType = isset($_GET['date_type']) ? $_GET['date_type'] : 'date';

// Get date/datetime values based on type
if ($dateType === 'datetime') {
    $dateFrom = isset($_GET['datetime_from']) ? trim($_GET['datetime_from']) : '';
    $dateTo = isset($_GET['datetime_to']) ? trim($_GET['datetime_to']) : '';
} else {
    $dateFrom = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
    $dateTo = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
}

$itemsPerPage = $config['dashboard']['items_per_page'];
$offset = ($page - 1) * $itemsPerPage;

// Debug: Check if we have any history at all (without date filter)
$allHistory = $db->getHistory(null, 1, 0, null, null);
$hasAnyHistory = !empty($allHistory);

// Get data
if (!empty($search)) {
    $history = $db->searchHistory($search, $itemsPerPage, $offset, $dateFrom, $dateTo);
    $totalItems = count($db->searchHistory($search, 10000, 0, $dateFrom, $dateTo)); // Approximate
} elseif (!empty($prefixFilter)) {
    $history = $db->getHistory($prefixFilter, $itemsPerPage, $offset, $dateFrom, $dateTo);
    $totalItems = count($db->getHistory($prefixFilter, 10000, 0, $dateFrom, $dateTo));
} else {
    $history = $db->getHistory(null, $itemsPerPage, $offset, $dateFrom, $dateTo);
    $totalItems = count($db->getHistory(null, 10000, 0, $dateFrom, $dateTo));
}

$currentRoutes = $db->getCurrentRoutes();
$stats = $db->getStats();
$totalPages = ceil($totalItems / $itemsPerPage);

// Get unique prefixes for filter
$allPrefixes = array_unique(array_column($currentRoutes, 'prefix'));
sort($allPrefixes);

// Get monitored prefixes with token information
$monitoredPrefixes = $db->getMonitoredPrefixes(true);
$tokens = $config['api']['tokens'] ?? [];

/**
 * Format date to Iran timezone format
 * @param string $dateTime Date time string
 * @return string Formatted date
 */
function formatIranDate($dateTime) {
    if (empty($dateTime)) {
        return '-';
    }
    try {
        $dt = new DateTime($dateTime, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone('Asia/Tehran'));
        return $dt->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        return $dateTime;
    }
}

/**
 * Convert AS path to tree format
 * @param string $path AS path string
 * @param string|null $asnNames ASN names string (if available)
 * @return string HTML tree structure
 */
function formatPathAsTree($path, $asnNames = null) {
    if (empty($path) || $path === 'Not in table' || $path === 'N/A (First check)') {
        return '<div class="path-tree-item"><span class="path-tree-asn">' . htmlspecialchars($path) . '</span></div>';
    }

    // Extract ASNs from path
    preg_match_all('/AS(\d+)\s*(?:\(([^)]+)\))?/', $path, $matches, PREG_SET_ORDER);
    
    if (empty($matches)) {
        // Try to extract just numbers if format is different
        preg_match_all('/\b(\d+)\b/', $path, $numMatches);
        if (empty($numMatches[1])) {
            return '<div class="path-tree-item"><span class="path-tree-asn">' . htmlspecialchars($path) . '</span></div>';
        }
        $asns = $numMatches[1];
        $asnNamesArray = [];
    } else {
        $asns = [];
        $asnNamesArray = [];
        foreach ($matches as $match) {
            $asns[] = $match[1];
            if (isset($match[2]) && !empty($match[2])) {
                $asnNamesArray[$match[1]] = $match[2];
            }
        }
    }

    if (empty($asns)) {
        return '<div class="path-tree-item"><span class="path-tree-asn">' . htmlspecialchars($path) . '</span></div>';
    }

    $html = '';
    $total = count($asns);
    
    foreach ($asns as $index => $asn) {
        $isLast = ($index === $total - 1);
        $connector = $isLast ? '└─' : '├─';
        
        $asnName = '';
        if (isset($asnNamesArray[$asn])) {
            $asnName = $asnNamesArray[$asn];
        } elseif ($asnNames && preg_match('/AS' . $asn . '\s*\(([^)]+)\)/', $asnNames, $nameMatch)) {
            $asnName = $nameMatch[1];
        }

        $html .= '<div class="path-tree-item">';
        $html .= '<span class="path-tree-connector">' . htmlspecialchars($connector) . '</span>';
        $html .= '<span class="path-tree-asn">';
        $html .= '<strong>AS' . htmlspecialchars($asn) . '</strong>';
        if ($asnName) {
            $html .= '<span class="path-tree-asn-name">(' . htmlspecialchars($asnName) . ')</span>';
        }
        $html .= '</span>';
        $html .= '</div>';
    }

    return $html;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($config['dashboard']['title']); ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
        }

        .header {
            background: white;
            border-radius: 10px;
            padding: 30px;
            margin-bottom: 20px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .header h1 {
            color: #333;
            margin-bottom: 10px;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .stat-card {
            background: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .stat-card h3 {
            color: #666;
            font-size: 14px;
            margin-bottom: 10px;
            text-transform: uppercase;
        }

        .stat-card .value {
            font-size: 32px;
            font-weight: bold;
            color: #667eea;
        }

        .filters {
            background: white;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .filters h2 {
            color: #333;
            margin-bottom: 20px;
            font-size: 20px;
            border-bottom: 2px solid #667eea;
            padding-bottom: 10px;
        }

        .filters form {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            width: 100%;
        }

        .form-group.full-width {
            width: 100%;
        }

        .form-row {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }

        .form-row .form-group {
            flex: 1;
            min-width: 250px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-size: 14px;
            font-weight: 600;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px;
            border: 2px solid #e9ecef;
            border-radius: 6px;
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .btn {
            padding: 10px 20px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            text-decoration: none;
            display: inline-block;
            width: auto;
            min-width: auto;
            text-align: center;
            white-space: nowrap;
        }

        .btn:hover {
            background: #5568d3;
        }

        .btn-secondary {
            background: #6c757d;
            width: auto;
            min-width: auto;
        }

        .btn-secondary:hover {
            background: #5a6268;
        }

        .btn-group {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }

        .table-container {
            background: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        th {
            background: #f8f9fa;
            font-weight: 600;
            color: #333;
            position: sticky;
            top: 0;
        }

        tr:hover {
            background: #f8f9fa;
        }

        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }

        .status-active {
            background: #d4edda;
            color: #155724;
        }

        .status-not-in-table {
            background: #f8d7da;
            color: #721c24;
        }

        .status-error {
            background: #fff3cd;
            color: #856404;
        }

        .path {
            font-family: 'Courier New', monospace;
            font-size: 13px;
            color: #495057;
        }

        .path-tree {
            font-family: 'Courier New', monospace;
            font-size: 12px;
            color: #495057;
            line-height: 1.8;
            margin-top: 8px;
            padding: 8px;
            background: #f8f9fa;
            border-radius: 6px;
            border: 1px solid #e9ecef;
        }

        .path-tree-item {
            display: flex;
            align-items: center;
            margin: 4px 0;
            transition: all 0.2s ease;
        }

        .path-tree-item:hover {
            transform: translateX(4px);
        }

        .path-tree-connector {
            color: #667eea;
            margin-right: 10px;
            font-weight: bold;
            min-width: 24px;
            font-size: 14px;
        }

        .path-tree-asn {
            background: linear-gradient(135deg, #f0f4ff 0%, #e8f0ff 100%);
            padding: 6px 12px;
            border-radius: 5px;
            border-left: 3px solid #667eea;
            flex: 1;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        .path-tree-asn strong {
            color: #667eea;
            font-weight: 600;
        }

        .path-tree-asn-name {
            color: #6c757d;
            font-size: 11px;
            margin-left: 10px;
            font-style: italic;
            font-weight: normal;
        }

        .pagination {
            margin-top: 20px;
            display: flex;
            justify-content: center;
            gap: 10px;
            align-items: center;
        }

        .pagination a,
        .pagination span {
            padding: 8px 12px;
            border-radius: 5px;
            text-decoration: none;
            color: #667eea;
        }

        .pagination a:hover {
            background: #f0f0f0;
        }

        .pagination .current {
            background: #667eea;
            color: white;
        }

        .current-routes {
            background: white;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .current-routes h2 {
            margin-bottom: 15px;
            color: #333;
        }

        .route-item {
            padding: 10px;
            border-bottom: 1px solid #eee;
        }

        .route-item:last-child {
            border-bottom: none;
        }

        .route-prefix {
            font-weight: 600;
            color: #667eea;
        }

        .no-data {
            text-align: center;
            padding: 40px;
            color: #999;
        }

        .form-group small {
            display: block;
            margin-top: 6px;
            color: #6c757d;
            font-size: 12px;
            line-height: 1.5;
        }
    </style>
    <script>
        function validateAddPrefixForm() {
            const prefix = document.getElementById('add_prefix').value;
            const token = document.getElementById('token').value;
            
            if (!token) {
                alert('Please enter your API token');
                return false;
            }
            
            if (!prefix) {
                alert('Please enter a prefix');
                return false;
            }
            
            // Validate prefix format
            const prefixPattern = /^(\d{1,3}\.){3}\d{1,3}\/\d{1,2}$/;
            if (!prefixPattern.test(prefix)) {
                alert('Invalid prefix format. Expected format: X.X.X.X/XX (e.g., 8.8.8.0/24)');
                return false;
            }
            
            return true;
        }

        function toggleDateTimeInputs() {
            const dateType = document.getElementById('date_type').value;
            const dateFrom = document.getElementById('date_from');
            const datetimeFrom = document.getElementById('datetime_from');
            const dateTo = document.getElementById('date_to');
            const datetimeTo = document.getElementById('datetime_to');
            
            if (dateType === 'datetime') {
                dateFrom.style.display = 'none';
                datetimeFrom.style.display = 'block';
                dateTo.style.display = 'none';
                datetimeTo.style.display = 'block';
                
                // Copy date value to datetime if date is set
                if (dateFrom.value) {
                    datetimeFrom.value = dateFrom.value + 'T00:00';
                }
                if (dateTo.value) {
                    datetimeTo.value = dateTo.value + 'T23:59';
                }
            } else {
                dateFrom.style.display = 'block';
                datetimeFrom.style.display = 'none';
                dateTo.style.display = 'block';
                datetimeTo.style.display = 'none';
                
                // Copy datetime value to date if datetime is set
                if (datetimeFrom.value) {
                    dateFrom.value = datetimeFrom.value.split('T')[0];
                }
                if (datetimeTo.value) {
                    dateTo.value = datetimeTo.value.split('T')[0];
                }
            }
        }

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            toggleDateTimeInputs();
        });
    </script>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><?php echo htmlspecialchars($config['dashboard']['title']); ?></h1>
            <p>Real-time BGP route monitoring and history</p>
        </div>

        <div class="stats">
            <div class="stat-card">
                <h3>Total Changes</h3>
                <div class="value"><?php echo number_format($stats['total_changes']); ?></div>
            </div>
            <div class="stat-card">
                <h3>Active Routes</h3>
                <div class="value"><?php echo number_format($stats['active_routes']); ?></div>
            </div>
            <div class="stat-card">
                <h3>Withdrawn Routes</h3>
                <div class="value"><?php echo number_format($stats['withdrawn_routes']); ?></div>
            </div>
            <div class="stat-card">
                <h3>Monitored Prefixes</h3>
                <div class="value"><?php echo number_format($stats['monitored_prefixes']); ?></div>
            </div>
        </div>

        <?php if (isset($config['api']['enabled']) && $config['api']['enabled']): ?>
        <div class="filters">
            <h2>Add New Prefix for Monitoring</h2>
            
            <?php if ($addPrefixMessage): ?>
                <div style="background: #d4edda; color: #155724; padding: 12px 15px; border-radius: 6px; margin-bottom: 15px; border-left: 4px solid #28a745;">
                    ✓ <?php echo htmlspecialchars($addPrefixMessage); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($addPrefixError): ?>
                <div style="background: #f8d7da; color: #721c24; padding: 12px 15px; border-radius: 6px; margin-bottom: 15px; border-left: 4px solid #dc3545;">
                    ✗ <strong>Error:</strong> <?php echo htmlspecialchars($addPrefixError); ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="" onsubmit="return validateAddPrefixForm()">
                <input type="hidden" name="action" value="add_prefix">
                
                <div class="form-group">
                    <label for="token">API Token <span style="color: #dc3545;">*</span></label>
                    <input type="password" id="token" name="token" 
                           value="<?php echo htmlspecialchars($_POST['token'] ?? ''); ?>" 
                           placeholder="Enter your API token" required>
                    <small>Your token is required to add prefixes for monitoring</small>
                </div>
                
                <div class="form-group">
                    <label for="add_prefix">IP Prefix <span style="color: #dc3545;">*</span></label>
                    <input type="text" id="add_prefix" name="prefix" 
                           value="<?php echo htmlspecialchars($_POST['prefix'] ?? ''); ?>" 
                           placeholder="8.8.8.0/24" required
                           pattern="^(\d{1,3}\.){3}\d{1,3}\/\d{1,2}$">
                    <small>Format: X.X.X.X/XX (e.g., 8.8.8.0/24, 1.1.1.0/24)</small>
                </div>
                
                <div class="form-group">
                    <label for="add_description">Description <span style="color: #6c757d; font-weight: normal;">(Optional)</span></label>
                    <input type="text" id="add_description" name="description" 
                           value="<?php echo htmlspecialchars($_POST['description'] ?? ''); ?>" 
                           placeholder="e.g., Google DNS, Cloudflare DNS">
                    <small>A brief description to identify this prefix</small>
                </div>
                
                <div class="form-group" style="margin-top: 5px;">
                    <button type="submit" class="btn">Add Prefix</button>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <?php if (!empty($monitoredPrefixes)): ?>
        <div class="current-routes">
            <h2>Monitored Prefixes (API Added)</h2>
            <div style="margin-bottom: 15px; font-size: 14px; color: #666;">
                Prefixes added via API with token information
            </div>
            <table style="width: 100%; margin-top: 10px; border-collapse: collapse;">
                <thead>
                    <tr style="background: #f8f9fa;">
                        <th style="padding: 12px; text-align: left; border-bottom: 2px solid #dee2e6;">Prefix</th>
                        <th style="padding: 12px; text-align: left; border-bottom: 2px solid #dee2e6;">Description</th>
                        <th style="padding: 12px; text-align: left; border-bottom: 2px solid #dee2e6;">Token Name</th>
                        <th style="padding: 12px; text-align: left; border-bottom: 2px solid #dee2e6;">Added At</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($monitoredPrefixes as $mp): ?>
                        <tr style="border-bottom: 1px solid #eee;">
                            <td style="padding: 10px;"><strong><?php echo htmlspecialchars($mp['prefix']); ?></strong></td>
                            <td style="padding: 10px;"><?php echo htmlspecialchars($mp['description'] ?: '-'); ?></td>
                            <td style="padding: 10px;">
                                <span class="status-badge status-active">
                                    <?php echo htmlspecialchars($mp['token_name']); ?>
                                </span>
                            </td>
                            <td style="padding: 10px; font-size: 12px; color: #666;">
                                <?php echo htmlspecialchars($mp['created_at']); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <div class="current-routes">
            <h2>Current Route Status</h2>
            <?php if (empty($currentRoutes)): ?>
                <div class="no-data">No routes being monitored yet</div>
            <?php else: ?>
                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 10px;">
                    <?php foreach ($currentRoutes as $route): ?>
                        <div class="route-item">
                            <div class="route-prefix"><?php echo htmlspecialchars($route['prefix']); ?></div>
                            <?php if ($route['description']): ?>
                                <div style="font-size: 12px; color: #666; margin-top: 4px;">
                                    <?php echo htmlspecialchars($route['description']); ?>
                                </div>
                            <?php endif; ?>
                            <div style="margin-top: 8px;">
                                <span class="status-badge status-<?php echo htmlspecialchars($route['status']); ?>">
                                    <?php echo htmlspecialchars($route['status']); ?>
                                </span>
                            </div>
                            <div class="path-tree">
                                <?php 
                                $displayPath = !empty($route['asn_names']) ? $route['asn_names'] : $route['path'];
                                echo formatPathAsTree($displayPath, $route['asn_names']);
                                ?>
                            </div>
                            <div style="font-size: 11px; color: #999; margin-top: 4px;">
                                Last checked: <?php echo htmlspecialchars($route['last_checked']); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="filters">
            <h2 style="margin-bottom: 15px;">Filter History</h2>
            <form method="GET" action="">
                <div class="form-row">
                    <div class="form-group">
                        <label for="search">Search</label>
                        <input type="text" id="search" name="search" 
                               value="<?php echo htmlspecialchars($search); ?>" 
                               placeholder="Search prefix, description, or path...">
                    </div>
                    <div class="form-group">
                        <label for="prefix">Filter by Prefix</label>
                        <select id="prefix" name="prefix">
                            <option value="">All Prefixes</option>
                            <?php foreach ($allPrefixes as $p): ?>
                                <option value="<?php echo htmlspecialchars($p); ?>" 
                                        <?php echo $prefixFilter === $p ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($p); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="date_type">Date Filter Type</label>
                        <select id="date_type" name="date_type" onchange="toggleDateTimeInputs()">
                            <option value="date" <?php echo (!isset($_GET['date_type']) || $_GET['date_type'] === 'date') ? 'selected' : ''; ?>>Date Only</option>
                            <option value="datetime" <?php echo (isset($_GET['date_type']) && $_GET['date_type'] === 'datetime') ? 'selected' : ''; ?>>Date & Time</option>
                        </select>
                    </div>
                    <div class="form-group" id="date_from_group">
                        <label for="date_from">From Date</label>
                        <input type="date" id="date_from" name="date_from" 
                               value="<?php echo htmlspecialchars($dateFrom); ?>"
                               style="display: <?php echo (!isset($_GET['date_type']) || $_GET['date_type'] === 'date') ? 'block' : 'none'; ?>;">
                        <input type="datetime-local" id="datetime_from" name="datetime_from" 
                               value="<?php echo htmlspecialchars($dateFrom); ?>"
                               style="display: <?php echo (isset($_GET['date_type']) && $_GET['date_type'] === 'datetime') ? 'block' : 'none'; ?>;">
                    </div>
                    <div class="form-group" id="date_to_group">
                        <label for="date_to">To Date</label>
                        <input type="date" id="date_to" name="date_to" 
                               value="<?php echo htmlspecialchars($dateTo); ?>"
                               style="display: <?php echo (!isset($_GET['date_type']) || $_GET['date_type'] === 'date') ? 'block' : 'none'; ?>;">
                        <input type="datetime-local" id="datetime_to" name="datetime_to" 
                               value="<?php echo htmlspecialchars($dateTo); ?>"
                               style="display: <?php echo (isset($_GET['date_type']) && $_GET['date_type'] === 'datetime') ? 'block' : 'none'; ?>;">
                    </div>
                </div>
                
                <div class="form-group" style="margin-top: 5px;">
                    <div class="btn-group">
                        <button type="submit" class="btn">Filter</button>
                        <a href="?" class="btn btn-secondary">Clear</a>
                    </div>
                </div>
            </form>
        </div>

        <div class="table-container">
            <h2 style="margin-bottom: 15px;">Route Change History</h2>
            <?php if (empty($history)): ?>
                <div class="no-data">
                    No history found
                    <?php if ($dateFrom || $dateTo): ?>
                        <br><small style="color: #999; margin-top: 10px; display: block;">
                            Filters: 
                            <?php if ($dateFrom): ?>From: <?php echo htmlspecialchars($dateFrom); ?><?php endif; ?>
                            <?php if ($dateTo): ?> To: <?php echo htmlspecialchars($dateTo); ?><?php endif; ?>
                        </small>
                        <?php if (!$hasAnyHistory): ?>
                            <br><small style="color: #f00; margin-top: 10px; display: block;">
                                ⚠️ No history records exist in database. Run monitor.php first to collect data.
                            </small>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Prefix</th>
                            <th>Description</th>
                            <th>Previous Path</th>
                            <th>Current Path</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history as $entry): ?>
                            <tr>
                                <td><?php echo formatIranDate($entry['detected_at']); ?></td>
                                <td><strong><?php echo htmlspecialchars($entry['prefix']); ?></strong></td>
                                <td><?php echo htmlspecialchars($entry['description'] ?: '-'); ?></td>
                                <td class="path"><?php echo htmlspecialchars($entry['previous_path']); ?></td>
                                <td class="path">
                                    <?php 
                                    // Display ASN names if available, otherwise show regular path
                                    $displayPath = !empty($entry['asn_names']) 
                                        ? htmlspecialchars($entry['asn_names']) 
                                        : htmlspecialchars($entry['current_path']);
                                    echo $displayPath; 
                                    ?>
                                </td>
                                <td>
                                    <span class="status-badge status-<?php echo htmlspecialchars($entry['status']); ?>">
                                        <?php echo htmlspecialchars($entry['status']); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if ($totalPages > 1): 
                    $dateParams = $dateType === 'datetime' 
                        ? 'datetime_from=' . urlencode($dateFrom) . '&datetime_to=' . urlencode($dateTo)
                        : 'date_from=' . urlencode($dateFrom) . '&date_to=' . urlencode($dateTo);
                ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&prefix=<?php echo urlencode($prefixFilter); ?>&date_type=<?php echo urlencode($dateType); ?>&<?php echo $dateParams; ?>">Previous</a>
                        <?php endif; ?>
                        
                        <span>Page <?php echo $page; ?> of <?php echo $totalPages; ?></span>
                        
                        <?php if ($page < $totalPages): ?>
                            <a href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&prefix=<?php echo urlencode($prefixFilter); ?>&date_type=<?php echo urlencode($dateType); ?>&<?php echo $dateParams; ?>">Next</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>

