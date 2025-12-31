<?php
/**
 * RIPE Stat API Integration
 * Fetches BGP route information from RIPE
 */

class RipeAPI {
    private $apiUrl;
    private $timeout;

    public function __construct($config) {
        $this->apiUrl = $config['ripe']['api_url'];
        $this->timeout = $config['ripe']['timeout'];
    }

    /**
     * Get BGP state for a specific prefix
     * @param string $prefix IP prefix (e.g., 8.8.8.0/24)
     * @param string|null $resource Optional resource filter
     * @return array|false BGP state data or false on error
     */
    public function getBgpState($prefix, $resource = null) {
        $params = [
            'resource' => $resource ?: $prefix,
            'timestamp' => time()
        ];

        $url = $this->apiUrl . '?' . http_build_query($params);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'BGP-Alerter/1.0');

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            error_log("RIPE API cURL error: " . $error);
            return false;
        }

        if ($httpCode !== 200) {
            error_log("RIPE API HTTP error: " . $httpCode);
            return false;
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("RIPE API JSON decode error: " . json_last_error_msg());
            return false;
        }

        return $data;
    }

    /**
     * Parse BGP state data and extract route information
     * @param array $bgpData Raw BGP state data from RIPE API
     * @return array Parsed route information
     */
    public function parseBgpState($bgpData) {
        $result = [
            'status' => 'not_in_table',
            'path' => 'Not in table',
            'as_path' => null,
            'paths' => []
        ];

        // Check if data exists
        if (!isset($bgpData['data']) || !isset($bgpData['data']['bgp_state'])) {
            return $result;
        }

        $bgpState = $bgpData['data']['bgp_state'];
        
        // Handle empty or null bgp_state
        if (empty($bgpState) || !is_array($bgpState)) {
            return $result;
        }

        $paths = [];

        foreach ($bgpState as $state) {
            if (isset($state['path'])) {
                $path = $state['path'];
                
                // Handle different path formats
                if (is_array($path)) {
                    $asPath = implode(' ', $path);
                } elseif (is_string($path)) {
                    $asPath = $path;
                } else {
                    continue;
                }
                
                $paths[] = [
                    'as_path' => $asPath,
                    'path' => $asPath
                ];
            }
        }

        if (!empty($paths)) {
            $result['status'] = 'active';
            $result['path'] = $paths[0]['path'];
            $result['as_path'] = $paths[0]['as_path'];
            $result['paths'] = $paths;
        }

        return $result;
    }

    /**
     * Get route information for a prefix
     * @param string $prefix IP prefix
     * @param string|null $resource Optional resource filter
     * @return array Route information
     */
    public function getRouteInfo($prefix, $resource = null) {
        $bgpData = $this->getBgpState($prefix, $resource);
        
        if ($bgpData === false) {
            return [
                'status' => 'error',
                'path' => 'API Error',
                'as_path' => null,
                'paths' => []
            ];
        }

        return $this->parseBgpState($bgpData);
    }

    /**
     * Get ASN information from RIPE API
     * @param string|int $asn ASN number
     * @return string|false ASN name or false on error
     */
    public function getAsnName($asn) {
        // Remove 'AS' prefix if present
        $asn = preg_replace('/^AS/i', '', trim($asn));
        
        if (empty($asn) || !is_numeric($asn)) {
            return false;
        }

        $url = "https://stat.ripe.net/data/as-overview/data.json?resource=AS{$asn}";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'BGP-Alerter/1.0');

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            return false;
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return false;
        }

        if (isset($data['data']['holder'])) {
            return $data['data']['holder'];
        }

        return false;
    }

    /**
     * Resolve ASN names from AS path
     * @param string $asPath AS path string (e.g., "12345 67890 11111")
     * @return string Formatted path with ASN names
     */
    public function resolveAsnNames($asPath) {
        if (empty($asPath) || $asPath === 'Not in table') {
            return $asPath;
        }

        // Extract ASNs from path
        preg_match_all('/\b(\d+)\b/', $asPath, $matches);
        if (empty($matches[1])) {
            return $asPath;
        }

        $asns = array_unique($matches[1]);
        $asnNames = [];
        $cache = [];

        foreach ($asns as $asn) {
            if (isset($cache[$asn])) {
                $asnNames[$asn] = $cache[$asn];
            } else {
                $name = $this->getAsnName($asn);
                if ($name !== false) {
                    $asnNames[$asn] = $name;
                    $cache[$asn] = $name;
                } else {
                    $asnNames[$asn] = "AS{$asn}";
                }
                // Small delay to avoid rate limiting
                usleep(100000); // 0.1 second
            }
        }

        // Replace ASNs in path with names
        $result = $asPath;
        foreach ($asnNames as $asn => $name) {
            $result = preg_replace('/\b' . $asn . '\b/', "AS{$asn} ({$name})", $result);
        }

        return $result;
    }
}

