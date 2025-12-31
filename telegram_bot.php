<?php
/**
 * Telegram Bot Integration
 * Sends alerts to Telegram when BGP routes change
 */

class TelegramBot {
    private $botToken;
    private $chatId;
    private $apiUrl;
    private $enabled;

    public function __construct($config) {
        $this->enabled = isset($config['telegram']['enabled']) ? $config['telegram']['enabled'] : true;
        $this->botToken = $config['telegram']['bot_token'];
        $this->chatId = $config['telegram']['chat_id'];
        $this->apiUrl = "https://api.telegram.org/bot{$this->botToken}/";
    }

    /**
     * Send a message to Telegram
     * @param string $message Message to send
     * @return bool Success status
     */
    public function sendMessage($message) {
        // Check if Telegram is enabled
        if (!$this->enabled) {
            return false;
        }

        if (empty($this->botToken) || empty($this->chatId)) {
            error_log("Telegram bot not configured");
            return false;
        }

        $url = $this->apiUrl . 'sendMessage';
        $data = [
            'chat_id' => $this->chatId,
            'text' => $message,
            'parse_mode' => 'HTML'
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            error_log("Telegram API cURL error: " . $error);
            return false;
        }

        if ($httpCode !== 200) {
            error_log("Telegram API HTTP error: " . $httpCode . " - " . $response);
            return false;
        }

        return true;
    }

    /**
     * Format and send BGP route change alert
     * @param string $prefix IP prefix
     * @param string $description Prefix description
     * @param string $previousPath Previous route path
     * @param string $currentPath Current route path
     * @param string $status Route status
     * @return bool Success status
     */
    public function sendRouteChangeAlert($prefix, $description, $previousPath, $currentPath, $status) {
        // Check if Telegram is enabled
        if (!$this->enabled) {
            return false;
        }
        $emoji = $status === 'not_in_table' ? '⚠️' : '🔄';
        $statusText = $status === 'not_in_table' ? 'WITHDRAWN' : 'CHANGED';

        $message = "{$emoji} <b>BGP Route {$statusText}</b>\n\n";
        $message .= "<b>Prefix:</b> <code>{$prefix}</code>\n";
        
        if ($description) {
            $message .= "<b>Description:</b> {$description}\n";
        }
        
        $message .= "<b>Previous Path:</b> <code>{$previousPath}</code>\n";
        $message .= "<b>Current Path:</b> <code>{$currentPath}</code>\n";
        $message .= "<b>Status:</b> {$status}\n";
        $message .= "<b>Time:</b> " . date('Y-m-d H:i:s UTC') . "\n";

        return $this->sendMessage($message);
    }
}

