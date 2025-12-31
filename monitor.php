<?php
/**
 * BGP Route Monitor
 * Main script that checks routes and detects changes
 * 
 * Usage: php monitor.php
 * Run via cron: *\/5 * * * * /usr/bin/php /path/to/monitor.php
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/ripe_api.php';
require_once __DIR__ . '/telegram_bot.php';

$config = require __DIR__ . '/config.php';

// Initialize components
$db = new Database($config);
$ripe = new RipeAPI($config);
$telegram = new TelegramBot($config);

// Get prefixes to monitor - first from database, then merge with config
$dbPrefixes = $db->getMonitoredPrefixesAsArray(true);
$configPrefixes = $config['monitoring']['prefixes'] ?? [];

// Merge: database prefixes take priority, but config prefixes are also included
$prefixes = array_merge($configPrefixes, $dbPrefixes);

// Remove duplicates (database entries take priority)
$uniquePrefixes = [];
foreach ($prefixes as $prefix => $description) {
    if (!isset($uniquePrefixes[$prefix])) {
        $uniquePrefixes[$prefix] = $description;
    }
}
$prefixes = $uniquePrefixes;

if (empty($prefixes)) {
    echo "No prefixes configured for monitoring.\n";
    exit(1);
}

echo "Starting BGP route monitoring...\n";
echo "Monitoring " . count($prefixes) . " prefix(es)\n";

// Check Telegram status
$telegramEnabled = isset($config['telegram']['enabled']) ? $config['telegram']['enabled'] : true;
if ($telegramEnabled) {
    echo "Telegram alerts: ENABLED\n";
} else {
    echo "Telegram alerts: DISABLED (web dashboard only)\n";
}
echo "\n";

$changesDetected = 0;

foreach ($prefixes as $prefix => $description) {
    echo "Checking prefix: {$prefix} ({$description})...\n";
    
    // Get current route from database
    $currentRoute = $db->getCurrentRoute($prefix);
    
    // Get route information from RIPE API
    $routeInfo = $ripe->getRouteInfo($prefix, $config['monitoring']['resource']);
    
    if ($routeInfo['status'] === 'error') {
        echo "  Error: Failed to fetch route information from RIPE API\n";
        continue;
    }
    
    $newPath = $routeInfo['path'];
    $newStatus = $routeInfo['status'];
    $newAsPath = $routeInfo['as_path'];
    
    // Resolve ASN names if enabled
    $showAsnNames = isset($config['monitoring']['show_asn_names']) && $config['monitoring']['show_asn_names'];
    $newPathWithNames = $newPath;
    $newAsnNames = null;
    
    if ($showAsnNames && $newAsPath && $newStatus !== 'not_in_table') {
        echo "  Resolving ASN names...\n";
        $newPathWithNames = $ripe->resolveAsnNames($newAsPath);
        $newAsnNames = $newPathWithNames;
    }
    
    // Check if this is a new route or if there's a change
    if ($currentRoute === false) {
        // First time monitoring this prefix
        echo "  First time monitoring - saving initial state\n";
        $db->updateCurrentRoute($prefix, $description, $newPath, $newStatus, $newAsPath, $newAsnNames);
        
        // Save to history
        $db->saveRouteHistory(
            $prefix,
            $description,
            'N/A (First check)',
            $newPathWithNames,
            $newStatus,
            $newAsPath,
            $newAsnNames
        );
        
        // Send initial notification
        $telegram->sendRouteChangeAlert(
            $prefix,
            $description,
            'N/A (First check)',
            $newPathWithNames,
            $newStatus
        );
        
        $changesDetected++;
    } else {
        // Compare with previous state
        $previousPath = $currentRoute['path'];
        $previousPathWithNames = $currentRoute['asn_names'] ?: $previousPath;
        $previousStatus = $currentRoute['status'];
        
        $pathChanged = ($previousPath !== $newPath);
        $statusChanged = ($previousStatus !== $newStatus);
        
        if ($pathChanged || $statusChanged) {
            echo "  ⚠️  CHANGE DETECTED!\n";
            echo "    Previous: {$previousPathWithNames} ({$previousStatus})\n";
            echo "    Current:  {$newPathWithNames} ({$newStatus})\n";
            
            // Resolve previous path names if needed
            $previousPathResolved = $previousPathWithNames;
            if ($showAsnNames && $currentRoute['as_path'] && $previousStatus !== 'not_in_table' && !$currentRoute['asn_names']) {
                $previousPathResolved = $ripe->resolveAsnNames($currentRoute['as_path']);
            }
            
            // Save to history
            $db->saveRouteHistory(
                $prefix,
                $description,
                $previousPathResolved,
                $newPathWithNames,
                $newStatus,
                $newAsPath,
                $newAsnNames
            );
            
            // Update current route
            $db->updateCurrentRoute($prefix, $description, $newPath, $newStatus, $newAsPath, $newAsnNames);
            
            // Send Telegram alert
            $telegram->sendRouteChangeAlert(
                $prefix,
                $description,
                $previousPathResolved,
                $newPathWithNames,
                $newStatus
            );
            
            $changesDetected++;
        } else {
            echo "  ✓ No changes detected\n";
        }
        
        // Update last_checked even if no change
        $db->updateCurrentRoute($prefix, $description, $newPath, $newStatus, $newAsPath, $newAsnNames);
    }
    
    echo "\n";
}

echo "Monitoring complete. Changes detected: {$changesDetected}\n";

