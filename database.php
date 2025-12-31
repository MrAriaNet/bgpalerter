<?php
/**
 * Database Connection and Schema Management
 */

class Database {
    private $pdo;
    private $config;

    public function __construct($config) {
        $this->config = $config;
        $this->connect();
        $this->initializeSchema();
    }

    private function connect() {
        try {
            if ($this->config['database']['type'] === 'sqlite') {
                $dbPath = $this->config['database']['sqlite_path'];
                $dir = dirname($dbPath);
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                $this->pdo = new PDO('sqlite:' . $dbPath);
                $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            } else {
                $mysql = $this->config['database']['mysql'];
                $dsn = "mysql:host={$mysql['host']};dbname={$mysql['dbname']};charset=utf8mb4";
                $this->pdo = new PDO($dsn, $mysql['username'], $mysql['password']);
                $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            }
        } catch (PDOException $e) {
            die("Database connection failed: " . $e->getMessage());
        }
    }

    private function initializeSchema() {
        if ($this->config['database']['type'] === 'mysql') {
            // MySQL schema
            $sql = "
            CREATE TABLE IF NOT EXISTS route_history (
                id INT PRIMARY KEY AUTO_INCREMENT,
                prefix VARCHAR(50) NOT NULL,
                description TEXT,
                previous_path TEXT,
                current_path TEXT,
                status VARCHAR(20) NOT NULL DEFAULT 'active',
                as_path TEXT,
                asn_names TEXT,
                detected_at DATETIME NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            CREATE INDEX IF NOT EXISTS idx_prefix ON route_history(prefix);
            CREATE INDEX IF NOT EXISTS idx_detected_at ON route_history(detected_at);
            CREATE INDEX IF NOT EXISTS idx_status ON route_history(status);

            CREATE TABLE IF NOT EXISTS route_current (
                id INT PRIMARY KEY AUTO_INCREMENT,
                prefix VARCHAR(50) NOT NULL UNIQUE,
                description TEXT,
                path TEXT,
                as_path TEXT,
                asn_names TEXT,
                status VARCHAR(20) NOT NULL DEFAULT 'active',
                last_checked DATETIME NOT NULL,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            );

            CREATE INDEX IF NOT EXISTS idx_prefix_current ON route_current(prefix);

            CREATE TABLE IF NOT EXISTS monitored_prefixes (
                id INT PRIMARY KEY AUTO_INCREMENT,
                prefix VARCHAR(50) NOT NULL UNIQUE,
                description TEXT,
                token_name VARCHAR(100),
                added_by_token VARCHAR(255),
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                is_active TINYINT(1) DEFAULT 1
            );

            CREATE INDEX IF NOT EXISTS idx_prefix_monitored ON monitored_prefixes(prefix);
            CREATE INDEX IF NOT EXISTS idx_token_monitored ON monitored_prefixes(added_by_token);
            ";
        } else {
            // SQLite schema
            $sql = "
            CREATE TABLE IF NOT EXISTS route_history (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                prefix VARCHAR(50) NOT NULL,
                description TEXT,
                previous_path TEXT,
                current_path TEXT,
                status VARCHAR(20) NOT NULL DEFAULT 'active',
                as_path TEXT,
                asn_names TEXT,
                detected_at DATETIME NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            CREATE INDEX IF NOT EXISTS idx_prefix ON route_history(prefix);
            CREATE INDEX IF NOT EXISTS idx_detected_at ON route_history(detected_at);
            CREATE INDEX IF NOT EXISTS idx_status ON route_history(status);

            CREATE TABLE IF NOT EXISTS route_current (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                prefix VARCHAR(50) NOT NULL UNIQUE,
                description TEXT,
                path TEXT,
                as_path TEXT,
                asn_names TEXT,
                status VARCHAR(20) NOT NULL DEFAULT 'active',
                last_checked DATETIME NOT NULL,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            CREATE INDEX IF NOT EXISTS idx_prefix_current ON route_current(prefix);

            CREATE TABLE IF NOT EXISTS monitored_prefixes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                prefix VARCHAR(50) NOT NULL UNIQUE,
                description TEXT,
                token_name VARCHAR(100),
                added_by_token VARCHAR(255),
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                is_active INTEGER DEFAULT 1
            );

            CREATE INDEX IF NOT EXISTS idx_prefix_monitored ON monitored_prefixes(prefix);
            CREATE INDEX IF NOT EXISTS idx_token_monitored ON monitored_prefixes(added_by_token);
            ";
        }

        $this->pdo->exec($sql);
        
        // Migrate existing tables to add asn_names column if it doesn't exist
        $this->migrateSchema();
    }

    private function migrateSchema() {
        try {
            // Check if asn_names column exists
            $exists = false;
            
            if ($this->config['database']['type'] === 'mysql') {
                $stmt = $this->pdo->query("SHOW COLUMNS FROM route_history LIKE 'asn_names'");
                $exists = $stmt->rowCount() > 0;
            } else {
                // SQLite
                $stmt = $this->pdo->query("PRAGMA table_info(route_history)");
                $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($columns as $col) {
                    if (isset($col['name']) && $col['name'] === 'asn_names') {
                        $exists = true;
                        break;
                    }
                }
            }
            
            if (!$exists) {
                $this->pdo->exec("ALTER TABLE route_history ADD COLUMN asn_names TEXT");
                $this->pdo->exec("ALTER TABLE route_current ADD COLUMN asn_names TEXT");
            }
        } catch (PDOException $e) {
            // Column might already exist, ignore error
            error_log("Migration note: " . $e->getMessage());
        }
    }

    public function getPDO() {
        return $this->pdo;
    }

    public function saveRouteHistory($prefix, $description, $previousPath, $currentPath, $status, $asPath = null, $asnNames = null) {
        $stmt = $this->pdo->prepare("
            INSERT INTO route_history (prefix, description, previous_path, current_path, status, as_path, asn_names, detected_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        return $stmt->execute([
            $prefix,
            $description,
            $previousPath,
            $currentPath,
            $status,
            $asPath,
            $asnNames,
            date('Y-m-d H:i:s')
        ]);
    }

    public function getCurrentRoute($prefix) {
        $stmt = $this->pdo->prepare("SELECT * FROM route_current WHERE prefix = ?");
        $stmt->execute([$prefix]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function updateCurrentRoute($prefix, $description, $path, $status, $asPath = null, $asnNames = null) {
        if ($this->config['database']['type'] === 'sqlite') {
            // SQLite doesn't support ON DUPLICATE KEY UPDATE, use REPLACE instead
            $stmt = $this->pdo->prepare("
                REPLACE INTO route_current (prefix, description, path, as_path, asn_names, status, last_checked)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
        } else {
            // MySQL supports ON DUPLICATE KEY UPDATE
            $stmt = $this->pdo->prepare("
                INSERT INTO route_current (prefix, description, path, as_path, asn_names, status, last_checked)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    description = VALUES(description),
                    path = VALUES(path),
                    as_path = VALUES(as_path),
                    asn_names = VALUES(asn_names),
                    status = VALUES(status),
                    last_checked = VALUES(last_checked),
                    updated_at = CURRENT_TIMESTAMP
            ");
        }

        return $stmt->execute([
            $prefix,
            $description,
            $path,
            $asPath,
            $asnNames,
            $status,
            date('Y-m-d H:i:s')
        ]);
    }

    public function getHistory($prefix = null, $limit = 50, $offset = 0, $dateFrom = null, $dateTo = null) {
        $conditions = [];
        $params = [];
        
        if ($prefix) {
            $conditions[] = "prefix = ?";
            $params[] = $prefix;
        }
        
        if ($dateFrom) {
            // Check if it's a datetime (contains T or space) or just date
            if (strpos($dateFrom, 'T') !== false || strpos($dateFrom, ' ') !== false) {
                // It's a datetime - convert to UTC for comparison
                try {
                    $dt = new DateTime($dateFrom, new DateTimeZone('Asia/Tehran'));
                    $dt->setTimezone(new DateTimeZone('UTC'));
                    $conditions[] = "detected_at >= ?";
                    $params[] = $dt->format('Y-m-d H:i:s');
                } catch (Exception $e) {
                    $conditions[] = "detected_at >= ?";
                    $params[] = $dateFrom;
                }
            } else {
                // It's just a date - convert to UTC start of day
                try {
                    $dt = new DateTime($dateFrom . ' 00:00:00', new DateTimeZone('Asia/Tehran'));
                    $dt->setTimezone(new DateTimeZone('UTC'));
                    $conditions[] = "detected_at >= ?";
                    $params[] = $dt->format('Y-m-d H:i:s');
                } catch (Exception $e) {
                    $conditions[] = "DATE(detected_at) >= ?";
                    $params[] = $dateFrom;
                }
            }
        }
        
        if ($dateTo) {
            // Check if it's a datetime (contains T or space) or just date
            if (strpos($dateTo, 'T') !== false || strpos($dateTo, ' ') !== false) {
                // It's a datetime - convert to UTC for comparison
                try {
                    $dt = new DateTime($dateTo, new DateTimeZone('Asia/Tehran'));
                    $dt->setTimezone(new DateTimeZone('UTC'));
                    $conditions[] = "detected_at <= ?";
                    $params[] = $dt->format('Y-m-d H:i:s');
                } catch (Exception $e) {
                    $conditions[] = "detected_at <= ?";
                    $params[] = $dateTo;
                }
            } else {
                // It's just a date - convert to UTC end of day
                try {
                    $dt = new DateTime($dateTo . ' 23:59:59', new DateTimeZone('Asia/Tehran'));
                    $dt->setTimezone(new DateTimeZone('UTC'));
                    $conditions[] = "detected_at <= ?";
                    $params[] = $dt->format('Y-m-d H:i:s');
                } catch (Exception $e) {
                    $conditions[] = "DATE(detected_at) <= ?";
                    $params[] = $dateTo;
                }
            }
        }
        
        $whereClause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";
        
        $stmt = $this->pdo->prepare("
            SELECT * FROM route_history
            {$whereClause}
            ORDER BY detected_at DESC
            LIMIT ? OFFSET ?
        ");
        
        $params[] = $limit;
        $params[] = $offset;
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function searchHistory($query, $limit = 50, $offset = 0, $dateFrom = null, $dateTo = null) {
        $conditions = [
            "(prefix LIKE ? OR description LIKE ? OR previous_path LIKE ? OR current_path LIKE ?)"
        ];
        $params = [];
        $searchTerm = "%{$query}%";
        $params = [$searchTerm, $searchTerm, $searchTerm, $searchTerm];
        
        if ($dateFrom) {
            // Check if it's a datetime (contains T or space) or just date
            if (strpos($dateFrom, 'T') !== false || strpos($dateFrom, ' ') !== false) {
                // It's a datetime - convert to UTC for comparison
                try {
                    $dt = new DateTime($dateFrom, new DateTimeZone('Asia/Tehran'));
                    $dt->setTimezone(new DateTimeZone('UTC'));
                    $conditions[] = "detected_at >= ?";
                    $params[] = $dt->format('Y-m-d H:i:s');
                } catch (Exception $e) {
                    $conditions[] = "detected_at >= ?";
                    $params[] = $dateFrom;
                }
            } else {
                // It's just a date - convert to UTC start of day
                try {
                    $dt = new DateTime($dateFrom . ' 00:00:00', new DateTimeZone('Asia/Tehran'));
                    $dt->setTimezone(new DateTimeZone('UTC'));
                    $conditions[] = "detected_at >= ?";
                    $params[] = $dt->format('Y-m-d H:i:s');
                } catch (Exception $e) {
                    $conditions[] = "DATE(detected_at) >= ?";
                    $params[] = $dateFrom;
                }
            }
        }
        
        if ($dateTo) {
            // Check if it's a datetime (contains T or space) or just date
            if (strpos($dateTo, 'T') !== false || strpos($dateTo, ' ') !== false) {
                // It's a datetime - convert to UTC for comparison
                try {
                    $dt = new DateTime($dateTo, new DateTimeZone('Asia/Tehran'));
                    $dt->setTimezone(new DateTimeZone('UTC'));
                    $conditions[] = "detected_at <= ?";
                    $params[] = $dt->format('Y-m-d H:i:s');
                } catch (Exception $e) {
                    $conditions[] = "detected_at <= ?";
                    $params[] = $dateTo;
                }
            } else {
                // It's just a date - convert to UTC end of day
                try {
                    $dt = new DateTime($dateTo . ' 23:59:59', new DateTimeZone('Asia/Tehran'));
                    $dt->setTimezone(new DateTimeZone('UTC'));
                    $conditions[] = "detected_at <= ?";
                    $params[] = $dt->format('Y-m-d H:i:s');
                } catch (Exception $e) {
                    $conditions[] = "DATE(detected_at) <= ?";
                    $params[] = $dateTo;
                }
            }
        }
        
        $whereClause = "WHERE " . implode(" AND ", $conditions);
        
        $stmt = $this->pdo->prepare("
            SELECT * FROM route_history
            {$whereClause}
            ORDER BY detected_at DESC
            LIMIT ? OFFSET ?
        ");
        
        $params[] = $limit;
        $params[] = $offset;
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getCurrentRoutes() {
        $stmt = $this->pdo->query("SELECT * FROM route_current ORDER BY prefix");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getStats() {
        $stats = [];
        
        $stmt = $this->pdo->query("SELECT COUNT(*) as total FROM route_history");
        $stats['total_changes'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        $stmt = $this->pdo->query("SELECT COUNT(*) as total FROM route_current WHERE status = 'active'");
        $stats['active_routes'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        $stmt = $this->pdo->query("SELECT COUNT(*) as total FROM route_current WHERE status = 'not_in_table'");
        $stats['withdrawn_routes'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        $stmt = $this->pdo->query("SELECT COUNT(DISTINCT prefix) as total FROM route_current");
        $stats['monitored_prefixes'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        return $stats;
    }

    // API Methods for managing monitored prefixes
    public function addMonitoredPrefix($prefix, $description, $tokenName, $token) {
        if ($this->config['database']['type'] === 'sqlite') {
            $stmt = $this->pdo->prepare("
                INSERT OR REPLACE INTO monitored_prefixes (prefix, description, token_name, added_by_token, is_active)
                VALUES (?, ?, ?, ?, 1)
            ");
        } else {
            $stmt = $this->pdo->prepare("
                INSERT INTO monitored_prefixes (prefix, description, token_name, added_by_token, is_active)
                VALUES (?, ?, ?, ?, 1)
                ON DUPLICATE KEY UPDATE
                    description = VALUES(description),
                    token_name = VALUES(token_name),
                    added_by_token = VALUES(added_by_token),
                    is_active = 1
            ");
        }
        
        return $stmt->execute([$prefix, $description, $tokenName, $token]);
    }

    public function getMonitoredPrefixes($activeOnly = true) {
        if ($activeOnly) {
            $stmt = $this->pdo->query("SELECT * FROM monitored_prefixes WHERE is_active = 1 ORDER BY prefix");
        } else {
            $stmt = $this->pdo->query("SELECT * FROM monitored_prefixes ORDER BY prefix");
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getMonitoredPrefixesAsArray($activeOnly = true) {
        $prefixes = $this->getMonitoredPrefixes($activeOnly);
        $result = [];
        foreach ($prefixes as $row) {
            $result[$row['prefix']] = $row['description'] ?: $row['prefix'];
        }
        return $result;
    }

    public function removeMonitoredPrefix($prefix) {
        $stmt = $this->pdo->prepare("UPDATE monitored_prefixes SET is_active = 0 WHERE prefix = ?");
        return $stmt->execute([$prefix]);
    }

    public function getPrefixesByToken($token) {
        $stmt = $this->pdo->prepare("SELECT * FROM monitored_prefixes WHERE added_by_token = ? AND is_active = 1");
        $stmt->execute([$token]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

