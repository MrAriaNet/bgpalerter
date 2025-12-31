# BGP Monitor API Documentation

## Overview

The BGP Monitor API allows authorized users to add prefixes for monitoring via RESTful API calls. Each request is authenticated using a token, and the system tracks which user (token) added which prefix.

## Configuration

First, configure your API tokens in `config.php`:

```php
'api' => [
    'enabled' => true,
    'tokens' => [
        'your-secret-token-1' => 'User 1',
        'your-secret-token-2' => 'User 2',
        'another-token-here' => 'User 3',
    ]
]
```

**Important:** Change the default tokens to secure random strings!

## API Endpoints

### Base URL
```
http://your-server/bgpalerter/api.php
```

### Authentication

All API requests require authentication via one of these methods:

1. **Bearer Token in Header** (Recommended):
   ```
   Authorization: Bearer your-secret-token-1
   ```

2. **Token in Query Parameter**:
   ```
   GET /api.php?token=your-secret-token-1
   ```

3. **Token in POST Data**:
   ```
   POST /api.php
   token=your-secret-token-1
   ```

## Endpoints

### 1. Add Prefix for Monitoring

**POST** `/api.php`

Add a new prefix to monitor.

**Headers:**
```
Authorization: Bearer your-secret-token-1
Content-Type: application/json
```

**Request Body:**
```json
{
    "prefix": "8.8.8.0/24",
    "description": "Google DNS"
}
```

**Response (Success - 200):**
```json
{
    "success": true,
    "message": "Prefix added successfully",
    "data": {
        "prefix": "8.8.8.0/24",
        "description": "Google DNS",
        "added_by": "User 1",
        "monitor_executed": true
    }
}
```

**Response (Error - 400):**
```json
{
    "error": "Invalid prefix format. Expected format: X.X.X.X/XX"
}
```

**Response (Error - 401):**
```json
{
    "error": "Invalid or missing token"
}
```

### 2. Get My Prefixes

**GET** `/api.php?token=your-secret-token-1`

Get all prefixes added by your token.

**Response (Success - 200):**
```json
{
    "success": true,
    "count": 2,
    "data": [
        {
            "id": 1,
            "prefix": "8.8.8.0/24",
            "description": "Google DNS",
            "token_name": "User 1",
            "added_by_token": "your-secret-token-1",
            "created_at": "2024-01-15 10:30:00",
            "is_active": 1
        },
        {
            "id": 2,
            "prefix": "1.1.1.0/24",
            "description": "Cloudflare DNS",
            "token_name": "User 1",
            "added_by_token": "your-secret-token-1",
            "created_at": "2024-01-15 11:00:00",
            "is_active": 1
        }
    ]
}
```

## Examples

### Using cURL

**Add a prefix:**
```bash
curl -X POST http://your-server/bgpalerter/api.php \
  -H "Authorization: Bearer your-secret-token-1" \
  -H "Content-Type: application/json" \
  -d '{
    "prefix": "8.8.8.0/24",
    "description": "Google DNS"
  }'
```

**Get my prefixes:**
```bash
curl -X GET "http://your-server/bgpalerter/api.php?token=your-secret-token-1"
```

### Using PHP

```php
<?php
$token = 'your-secret-token-1';
$url = 'http://your-server/bgpalerter/api.php';

// Add prefix
$data = [
    'prefix' => '8.8.8.0/24',
    'description' => 'Google DNS'
];

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $token,
    'Content-Type: application/json'
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

$response = curl_exec($ch);
$result = json_decode($response, true);

if ($result['success']) {
    echo "Prefix added: " . $result['data']['prefix'] . "\n";
} else {
    echo "Error: " . $result['error'] . "\n";
}
?>
```

### Using Python

```python
import requests

token = 'your-secret-token-1'
url = 'http://your-server/bgpalerter/api.php'

# Add prefix
headers = {
    'Authorization': f'Bearer {token}',
    'Content-Type': 'application/json'
}

data = {
    'prefix': '8.8.8.0/24',
    'description': 'Google DNS'
}

response = requests.post(url, headers=headers, json=data)
result = response.json()

if result['success']:
    print(f"Prefix added: {result['data']['prefix']}")
else:
    print(f"Error: {result['error']}")
```

## How It Works

1. When a prefix is added via API:
   - The prefix is saved to the `monitored_prefixes` table
   - The token and user name are recorded
   - `monitor.php` is automatically executed in the background to fetch initial route data

2. The monitoring system:
   - Reads prefixes from both `config.php` and the database
   - Database prefixes take priority over config prefixes
   - All prefixes are monitored together

3. Tracking:
   - Each prefix is linked to the token that added it
   - The dashboard shows which user added which prefix
   - You can see all prefixes added by each token

## Security Notes

1. **Change default tokens** - Never use the example tokens in production
2. **Use HTTPS** - Always use HTTPS in production to protect tokens
3. **Token security** - Treat tokens like passwords - keep them secret
4. **Rate limiting** - Consider adding rate limiting for production use

## Error Codes

- **200** - Success
- **400** - Bad Request (invalid input)
- **401** - Unauthorized (invalid or missing token)
- **405** - Method Not Allowed
- **503** - Service Unavailable (API disabled)

## Dashboard

The web dashboard (`index.php`) shows:
- All monitored prefixes added via API
- Which token/user added each prefix
- When each prefix was added
- Current route status for all prefixes

