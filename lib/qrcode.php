<?php
/**
 * QR Code generation using the free api.qrserver.com service.
 */

/**
 * Generate a QR code PNG for the given URL, save it locally,
 * and return an identifier + file path.
 *
 * @param  string $url         The URL to encode
 * @param  string $upload_dir  Base upload directory (UPLOAD_DIR constant)
 * @return array{identifier: string, path: string}|false
 */
function generate_qr_code(string $url, string $upload_dir): array|false
{
    // Ensure the qrcodes sub-directory exists
    $qr_dir = rtrim($upload_dir, '/') . '/qrcodes/';
    if (!is_dir($qr_dir)) {
        mkdir($qr_dir, 0755, true);
    }

    // Generate a random 8-character hex identifier
    $identifier = bin2hex(random_bytes(4)); // 8 hex chars

    $filename  = "qr_{$identifier}.png";
    $file_path = $qr_dir . $filename;

    // Build the API URL
    $api_url = 'https://api.qrserver.com/v1/create-qr-code/?'
        . http_build_query([
            'size'  => '300x300',
            'data'  => $url,
            'color' => '1B3A1B',   // dark forest green foreground
            'bgcolor' => 'FFFFFF', // white background
            'format'  => 'png',
            'margin'  => '10',
        ]);

    // Try cURL first, fall back to file_get_contents
    $image_data = false;

    if (function_exists('curl_init')) {
        $ch = curl_init($api_url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'SGIR-Feedback/1.0',
        ]);
        $image_data = curl_exec($ch);
        $http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($image_data === false || $http_code !== 200) {
            $image_data = false;
        }
    }

    if ($image_data === false) {
        // Fallback: file_get_contents (requires allow_url_fopen = On)
        $context = stream_context_create([
            'http' => [
                'timeout'    => 15,
                'user_agent' => 'SGIR-Feedback/1.0',
            ],
        ]);
        $image_data = @file_get_contents($api_url, false, $context);
    }

    if ($image_data === false || strlen($image_data) < 100) {
        error_log("QR code generation failed for URL: {$url}");
        return false;
    }

    // Save to disk
    $written = file_put_contents($file_path, $image_data);
    if ($written === false) {
        error_log("QR code: could not write file {$file_path}");
        return false;
    }

    return [
        'identifier' => $identifier,
        'path'       => 'uploads/qrcodes/' . $filename,  // relative to sgir_php root
    ];
}
