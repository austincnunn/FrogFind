<?php
// Shared helpers for FrogFind pages.

//replace chars that old machines probably can't handle
function clean_str($str) {
    $str = str_replace( "‘", "'", $str );
    $str = str_replace( "’", "'", $str );
    $str = str_replace( "“", '"', $str );
    $str = str_replace( "”", '"', $str );
    $str = str_replace( "–", '-', $str );
    $str = str_replace( "&#x27;", "'", $str );

    return $str;
}

// escape text for safe output inside HTML and attribute values
function h($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

// true only for http(s) URLs whose host resolves exclusively to public IPs,
// so the proxy can't be pointed at localhost, the LAN or cloud metadata (SSRF)
function is_public_url($url) {
    $parts = parse_url($url);
    if (!$parts || empty($parts['host']) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'])) {
        return false;
    }

    $host = trim($parts['host'], '[]');
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $ips = [$host];
    } else {
        $ips = gethostbynamel($host) ?: [];
        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            $ips[] = $record['ipv6'];
        }
    }
    if (!$ips) {
        return false;
    }

    $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
    if (defined('FILTER_FLAG_GLOBAL_RANGE')) { // PHP 8.2+, also covers CGNAT etc.
        $flags |= FILTER_FLAG_GLOBAL_RANGE;
    }
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, $flags)) {
            return false;
        }
    }
    return true;
}

// resolve a redirect Location header against the URL that returned it
function resolve_location($base, $location) {
    if (preg_match('#^https?://#i', $location)) {
        return $location;
    }
    $b = parse_url($base);
    $origin = $b['scheme'] . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
    if (substr($location, 0, 2) == '//') {
        return $b['scheme'] . ':' . $location;
    }
    if (substr($location, 0, 1) == '/') {
        return $origin . $location;
    }
    return $origin . rtrim(dirname($b['path'] ?? '/x'), '/') . '/' . $location;
}

// GET a remote URL, checking every redirect hop with is_public_url().
// Returns ['url' => final url, 'headers' => [lowercase name => value], 'body' => string] or false.
// Reads at most $max_bytes of the body.
// ponytail: the host is resolved once for the check and again by the fetch, so DNS rebinding
// can still slip past; pin the IP (curl CURLOPT_RESOLVE) or firewall egress if that matters.
function safe_fetch($url, $max_bytes = 8000000) {
    for ($hops = 0; $hops < 5; $hops++) {
        if (!is_public_url($url)) {
            return false;
        }

        $context = stream_context_create(['http' => [
            'follow_location' => 0,
            'ignore_errors' => true,
            'timeout' => 15,
            // PHP sends no User-Agent by default and sites like Wikipedia 403 without one.
            // Not set globally (ini user_agent) so index.php's DuckDuckGo request stays as it was.
            'user_agent' => 'FrogFind/1.0 (+https://github.com/ActionRetro/FrogFind)',
        ]]);
        unset($http_response_header); // don't reuse the previous hop's headers
        $body = @file_get_contents($url, false, $context, 0, $max_bytes);
        if (!isset($http_response_header)) {
            return false;
        }

        $status = 0;
        $headers = [];
        foreach ($http_response_header as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m)) {
                $status = (int)$m[1];
            } elseif (strpos($line, ':') !== false) {
                list($name, $value) = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
        }

        if ($status >= 300 && $status < 400 && isset($headers['location'])) {
            $url = resolve_location($url, $headers['location']);
            continue;
        }
        if ($status >= 400 || $body === false) {
            return false;
        }
        return ['url' => $url, 'headers' => $headers, 'body' => $body];
    }
    return false;
}
