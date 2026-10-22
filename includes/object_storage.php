<?php

function objectStorageConfigured(): bool
{
    return S3_ENDPOINT !== '' && S3_BUCKET !== '' && S3_ACCESS_KEY !== '' && S3_SECRET_KEY !== '';
}

function storageKeyFromPath(string $path): ?string
{
    $normalize = static function (string $value): string {
        $parts = [];
        foreach (explode('/', str_replace('\\', '/', $value)) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }
        return '/' . implode('/', $parts);
    };
    $root = rtrim($normalize(PROJECT_ROOT), '/');
    $normalized = $normalize($path);
    if (!str_starts_with($normalized, $root . '/uploads/')) {
        return null;
    }
    $key = substr($normalized, strlen($root . '/uploads/'));
    $segments = explode('/', $key);
    if ($key === '' || in_array('', $segments, true) || in_array('.', $segments, true) || in_array('..', $segments, true)) {
        return null;
    }
    return $key;
}

function storageRequest(string $method, string $key, string $body = '', string $contentType = ''): array
{
    if (!objectStorageConfigured()) {
        throw new RuntimeException('Persistent object storage is not configured.');
    }
    if (str_contains($key, "\0") || preg_match('~(^|/)\.\.?(/|$)~', $key)) {
        throw new InvalidArgumentException('Invalid object storage key.');
    }

    $endpoint = rtrim(S3_ENDPOINT, '/');
    $endpointParts = parse_url($endpoint);
    if (!is_array($endpointParts) || ($endpointParts['scheme'] ?? '') !== 'https' || empty($endpointParts['host'])) {
        throw new RuntimeException('S3_ENDPOINT must be a valid HTTPS URL.');
    }
    $host = $endpointParts['host'] . (isset($endpointParts['port']) ? ':' . $endpointParts['port'] : '');
    $pathPrefix = rtrim($endpointParts['path'] ?? '', '/');
    $encodedKey = implode('/', array_map('rawurlencode', explode('/', $key)));
    $canonicalUri = $pathPrefix . '/' . rawurlencode(S3_BUCKET) . '/' . $encodedKey;
    $url = $endpointParts['scheme'] . '://' . $host . $canonicalUri;
    $region = S3_REGION !== '' ? S3_REGION : 'auto';
    $service = 's3';
    $amzDate = gmdate('Ymd\THis\Z');
    $date = gmdate('Ymd');
    $payloadHash = hash('sha256', $body);
    $canonicalHeaders = '';
    $signedHeaderNames = [];
    $headers = [
        'x-amz-content-sha256: ' . $payloadHash,
        'x-amz-date: ' . $amzDate,
    ];
    if ($contentType !== '') {
        $headers[] = 'Content-Type: ' . $contentType;
    }
    $canonicalHeaderValues = [
        'host' => $host,
        'x-amz-content-sha256' => $payloadHash,
        'x-amz-date' => $amzDate,
    ];
    if ($contentType !== '') {
        $canonicalHeaderValues['content-type'] = trim($contentType);
    }
    ksort($canonicalHeaderValues, SORT_STRING);
    foreach ($canonicalHeaderValues as $name => $value) {
        $canonicalHeaders .= $name . ':' . $value . "\n";
        $signedHeaderNames[] = $name;
    }
    $signedHeaders = implode(';', $signedHeaderNames);
    $canonicalRequest = $method . "\n" . $canonicalUri . "\n\n" . $canonicalHeaders . "\n" . $signedHeaders . "\n" . $payloadHash;
    $scope = $date . '/' . $region . '/' . $service . '/aws4_request';
    $stringToSign = "AWS4-HMAC-SHA256\n{$amzDate}\n{$scope}\n" . hash('sha256', $canonicalRequest);
    $dateKey = hash_hmac('sha256', $date, 'AWS4' . S3_SECRET_KEY, true);
    $regionKey = hash_hmac('sha256', $region, $dateKey, true);
    $serviceKey = hash_hmac('sha256', $service, $regionKey, true);
    $signingKey = hash_hmac('sha256', 'aws4_request', $serviceKey, true);
    $signature = hash_hmac('sha256', $stringToSign, $signingKey);
    $headers[] = 'Authorization: AWS4-HMAC-SHA256 Credential=' . S3_ACCESS_KEY . '/' . $scope
        . ', SignedHeaders=' . $signedHeaders . ', Signature=' . $signature;

    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('Unable to initialize object storage request.');
    }
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 60,
    ]);
    if (in_array($method, ['PUT', 'POST'], true)) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
    }
    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $error = curl_error($curl);
    curl_close($curl);
    if ($response === false) {
        throw new RuntimeException('Object storage request failed: ' . $error);
    }
    return [
        'status' => $status,
        'headers' => substr($response, 0, $headerSize),
        'body' => substr($response, $headerSize),
    ];
}

function storageMoveUploadedFile(string $temporaryPath, string $destination): bool
{
    $key = storageKeyFromPath($destination);
    if ($key === null || !objectStorageConfigured()) {
        $directory = dirname($destination);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            return false;
        }
        return move_uploaded_file($temporaryPath, $destination);
    }
    $body = file_get_contents($temporaryPath);
    if ($body === false) {
        return false;
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($temporaryPath) ?: 'application/octet-stream';
    $response = storageRequest('PUT', $key, $body, $mime);
    return in_array($response['status'], [200, 201], true);
}

function storageExists(string $path): bool
{
    $key = storageKeyFromPath($path);
    if ($key === null || !objectStorageConfigured()) {
        return file_exists($path);
    }
    return storageRequest('HEAD', $key)['status'] === 200;
}

function storageRead(string $path): string|false
{
    $key = storageKeyFromPath($path);
    if ($key === null || !objectStorageConfigured()) {
        return file_get_contents($path);
    }
    $response = storageRequest('GET', $key);
    return $response['status'] === 200 ? $response['body'] : false;
}

function storageSize(string $path): int|false
{
    $key = storageKeyFromPath($path);
    if ($key === null || !objectStorageConfigured()) {
        return is_file($path) ? filesize($path) : false;
    }
    $response = storageRequest('HEAD', $key);
    if ($response['status'] !== 200 || !preg_match('/^content-length:\s*(\d+)\s*$/im', $response['headers'], $matches)) {
        return false;
    }
    return (int) $matches[1];
}

function storageModified(string $path): int|false
{
    $key = storageKeyFromPath($path);
    if ($key === null || !objectStorageConfigured()) {
        return is_file($path) ? filemtime($path) : false;
    }
    $response = storageRequest('HEAD', $key);
    if ($response['status'] !== 200 || !preg_match('/^last-modified:\s*(.+)\s*$/im', $response['headers'], $matches)) {
        return false;
    }
    $timestamp = strtotime($matches[1]);
    return $timestamp === false ? false : $timestamp;
}

function storageDelete(string $path): bool
{
    $key = storageKeyFromPath($path);
    if ($key === null || !objectStorageConfigured()) {
        return is_file($path) ? unlink($path) : !file_exists($path);
    }
    $status = storageRequest('DELETE', $key)['status'];
    return in_array($status, [200, 204, 404], true);
}
