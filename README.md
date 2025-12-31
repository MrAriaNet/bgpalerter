# BGP Route Monitor & Alerter

A comprehensive PHP-based BGP route monitoring system that tracks route changes, sends Telegram alerts, and provides a web dashboard for viewing history and current status.

![PHP Version](https://img.shields.io/badge/PHP-7.4%2B-blue)
![License](https://img.shields.io/badge/license-Open%20Source-green)

## 🌟 Features

- **Real-time BGP Monitoring**: Monitors BGP routes using RIPE Stat API
- **Telegram Alerts**: Sends instant notifications when routes change or are withdrawn (can be enabled/disabled)
- **Route History**: Complete history of all route changes with timestamps
- **Web Dashboard**: Beautiful, responsive web interface to view:
  - Current route status with tree-view AS path visualization
  - Route change history with search and filtering
  - Date/Time filtering with timezone support (default: Asia/Tehran)
  - Statistics and metrics
  - Add new prefixes directly from dashboard
- **Status Detection**: Detects when routes are active, changed, or "not in table" (withdrawn)
- **AS Path Tracking**: Tracks and displays AS path information in a hierarchical tree format
- **ASN Name Resolution**: Optional feature to resolve and display Upstream/ASN names (e.g., "AS15169 (Google LLC)") instead of just AS numbers
- **RESTful API**: Add prefixes for monitoring via API with token-based authentication
- **Multi-User Support**: Track which user (token) added which prefix
- **Duplicate Prevention**: Prevents adding duplicate prefixes across all sources
- **Database Support**: Works with both SQLite (default) and MySQL

## 📋 Requirements

- PHP 7.4 or higher
- cURL extension enabled
- PDO extension with SQLite or MySQL support
- Web server (Apache/Nginx) for dashboard
- Telegram Bot Token (optional, for alerts)

## 🚀 Installation

### 1. Clone or Download

```bash
git clone https://github.com/MrAriaNet/bgpalerter.git
cd bgpalerter
```

Or download and extract the ZIP file.

### 2. Configure the System

Edit `config.php` and set:

- **Database configuration** (SQLite or MySQL)
- **Telegram bot token and chat ID** (optional)
- **Prefixes to monitor** (can also add via API/dashboard)
- **API tokens** for programmatic access
- **Timezone** for dashboard (default: Asia/Tehran)

### 3. Set up Telegram Bot (Optional)

1. Create a bot via [@BotFather](https://t.me/BotFather) on Telegram
2. Get your bot token
3. Get your chat ID (you can use [@userinfobot](https://t.me/userinfobot))
4. Add both to `config.php` under the `telegram` section
5. Set `'enabled' => true` to enable alerts, or `false` to disable

### 4. Set up Database

The database will be created automatically on first run. For SQLite, ensure the `data/` directory is writable:

```bash
mkdir -p data
chmod 755 data
```

For MySQL, create the database first:

```sql
CREATE DATABASE bgp_monitor CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### 5. Set up Web Server

Point your web server document root to this directory, or configure a virtual host.

**Apache Example:**
```apache
<VirtualHost *:80>
    ServerName bgpalerter.local
    DocumentRoot /path/to/bgpalerter
    <Directory /path/to/bgpalerter>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

**Nginx Example:**
```nginx
server {
    listen 80;
    server_name bgpalerter.local;
    root /path/to/bgpalerter;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php7.4-fpm.sock;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
```

## ⚙️ Configuration

### config.php

```php
return [
    // Database Configuration
    'database' => [
        'type' => 'sqlite', // 'sqlite' or 'mysql'
        'sqlite_path' => __DIR__ . '/data/bgp_monitor.db',
        'mysql' => [
            'host' => 'localhost',
            'dbname' => 'bgp_monitor',
            'username' => 'root',
            'password' => ''
        ]
    ],

    // Telegram Configuration
    'telegram' => [
        'enabled' => true,  // Set to false to disable Telegram alerts
        'bot_token' => 'YOUR_BOT_TOKEN',
        'chat_id' => 'YOUR_CHAT_ID'
    ],

    // Monitoring Configuration
    'monitoring' => [
        'prefixes' => [
            '8.8.8.0/24' => 'Google DNS',
            '1.1.1.0/24' => 'Cloudflare DNS',
        ],
        'show_asn_names' => true  // Display ASN names like "AS15169 (Google LLC)"
    ],

    // Dashboard Configuration
    'dashboard' => [
        'title' => 'BGP Route Monitor',
        'timezone' => 'Asia/Tehran',  // Default timezone for date display
        'items_per_page' => 50
    ],

    // API Configuration
    'api' => [
        'enabled' => true,
        'tokens' => [
            'your-secret-token-1' => 'User 1',
            'your-secret-token-2' => 'User 2',
        ]
    ]
];
```

## 📖 Usage

### Running the Monitor

**Manual execution:**
```bash
php monitor.php
```

**Cron job (recommended):**
```bash
# Check every 5 minutes
*/5 * * * * /usr/bin/php /path/to/bgpalerter/monitor.php

# Or check every minute (for more frequent monitoring)
* * * * * /usr/bin/php /path/to/bgpalerter/monitor.php
```

**Windows Task Scheduler:**
Create a scheduled task that runs:
```
php.exe C:\path\to\bgpalerter\monitor.php
```

### Accessing the Dashboard

Open your web browser and navigate to:
```
http://your-server/bgpalerter/
```

### Dashboard Features

- **Statistics Overview**: View total changes, active routes, withdrawn routes, and monitored prefixes
- **Current Route Status**: See all currently monitored routes with their AS paths displayed as trees
- **Route Change History**: Browse complete history of route changes
- **Search**: Search by prefix, description, or path
- **Filter**: Filter history by specific prefix
- **Date/Time Filtering**: Filter by date only or date & time (timezone-aware)
- **Add Prefixes**: Add new prefixes for monitoring directly from the dashboard (requires API token)
- **Pagination**: Navigate through history pages

### Using the API

The system includes a RESTful API for adding prefixes programmatically. See [API_README.md](API_README.md) for complete API documentation.

**Quick Example:**
```bash
curl -X POST http://your-server/bgpalerter/api.php \
  -H "Authorization: Bearer your-token" \
  -H "Content-Type: application/json" \
  -d '{"prefix": "8.8.8.0/24", "description": "Google DNS"}'
```

**Python Example:**
```python
import requests

url = "http://your-server/bgpalerter/api.php"
headers = {
    "Authorization": "Bearer your-token",
    "Content-Type": "application/json"
}
data = {
    "prefix": "8.8.8.0/24",
    "description": "Google DNS"
}

response = requests.post(url, json=data, headers=headers)
print(response.json())
```

**PHP Example:**
```php
$url = "http://your-server/bgpalerter/api.php";
$data = [
    "prefix" => "8.8.8.0/24",
    "description" => "Google DNS"
];

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer your-token",
    "Content-Type: application/json"
]);

$response = curl_exec($ch);
curl_close($ch);

echo $response;
```

## 🔧 How It Works

1. **Monitoring Script** (`monitor.php`):
   - Fetches current BGP state from RIPE Stat API for each configured prefix
   - Compares with previously stored state
   - Detects changes in path or status
   - Resolves ASN names if enabled
   - Saves changes to database
   - Sends Telegram alerts when changes are detected (if enabled)

2. **Database**:
   - `route_current`: Stores current state of all monitored routes
   - `route_history`: Stores complete history of all route changes
   - `monitored_prefixes`: Stores prefixes added via API/dashboard

3. **Web Dashboard** (`index.php`):
   - Displays current route status with tree-view visualization
   - Shows route change history with search and filtering
   - Allows adding new prefixes via token authentication
   - Converts UTC timestamps to configured timezone for display

4. **API** (`api.php`):
   - Provides RESTful endpoint for adding prefixes
   - Token-based authentication
   - Prevents duplicate prefix entries
   - Triggers monitoring script after adding prefix

## 🌐 API Integration

This system uses the [RIPE Stat API](https://stat.ripe.net/docs/data_api) to fetch BGP route information. The API is free and doesn't require authentication for basic usage.

The system also uses RIPE API to resolve ASN names when `show_asn_names` is enabled.

## 📊 Status Types

- **active**: Route is active and reachable
- **not_in_table**: Route has been withdrawn (no longer in routing table)
- **error**: Error occurred while fetching route information

## 🗂️ File Structure

```
bgpalerter/
├── config.php              # Configuration file
├── database.php            # Database class and schema
├── ripe_api.php            # RIPE API integration
├── telegram_bot.php        # Telegram bot integration
├── monitor.php             # Main monitoring script
├── index.php               # Web dashboard
├── api.php                 # RESTful API endpoint
├── README.md               # This file
├── API_README.md           # API documentation
├── .gitignore              # Git ignore file
└── data/                   # Database directory (created automatically)
    └── bgp_monitor.db      # SQLite database (created automatically)
```

## 🐛 Troubleshooting

### Telegram alerts not working
- Verify bot token and chat ID in `config.php`
- Check that `'enabled' => true` in telegram configuration
- Check that cURL can reach Telegram API
- Check PHP error logs
- Test with: `php -r "require 'telegram_bot.php'; $t = new TelegramBot(require 'config.php'); $t->sendMessage('Test');"`

### Database errors
- Ensure database directory is writable (for SQLite): `chmod 755 data`
- Check database credentials (for MySQL)
- Verify PDO extension is enabled: `php -m | grep pdo`
- Check PHP error logs

### API errors
- Check internet connectivity
- Verify RIPE API is accessible: `curl https://stat.ripe.net/data/bgp-state/data.json?resource=8.8.8.0/24`
- Check PHP error logs for details
- Verify API tokens are correctly configured

### Prefix not being monitored
- Check if prefix is in `config.php` or added via API
- Verify prefix format: `X.X.X.X/XX` (e.g., `8.8.8.0/24`)
- Check if prefix already exists (duplicates are prevented)
- Run `monitor.php` manually to see any errors

### Dashboard not displaying correctly
- Check PHP version (7.4+ required)
- Verify web server is configured correctly
- Check browser console for JavaScript errors
- Ensure timezone is correctly set in `config.php`

## 🔒 Security Considerations

1. **API Tokens**: Change default tokens in `config.php` to secure random strings
2. **Database**: Use strong passwords for MySQL, restrict file permissions for SQLite
3. **Web Server**: Configure proper access controls, consider adding authentication to dashboard
4. **Telegram**: Keep bot token secret, don't commit it to version control
5. **File Permissions**: Restrict write access to `data/` directory

## 📝 License

This project is open source and available for use and modification.

## 🤝 Contributing

Contributions are welcome! Please ensure code follows the existing style and includes proper error handling.

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Test thoroughly
5. Submit a pull request

## 📞 Support

For issues or questions:
1. Check the [Troubleshooting](#-troubleshooting) section
2. Review the configuration in `config.php`
3. Check PHP error logs
4. Open an issue on GitHub

## 🙏 Acknowledgments

- [RIPE NCC](https://www.ripe.net/) for providing the RIPE Stat API
- [Telegram](https://telegram.org/) for the Bot API

---

**Made with ❤️ for network monitoring**
