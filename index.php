<?php
// Einfacher Frontend + Backend Router
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($uri === false) { $uri = '/'; }

// Polyfill für PHP < 8
if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle) {
        return $needle === '' || strpos($haystack, $needle) === 0;
    }
}

// Normalisiere doppelte Slashes
$uri = preg_replace('#/+#','/',$uri);

$root = __DIR__;
$buildDir = $root . '/frontend/build';
$backendDir = $root . '/backend';

// Hilfsfunktion: sichere Pfadauflösung innerhalb eines Basis-Verzeichnisses
function safePath(string $base, string $relative): ?string {
    $candidate = $base . '/' . ltrim($relative,'/');
    $realBase = realpath($base);
    $realCand = realpath($candidate);
    if ($realCand !== false && $realBase !== false && strpos($realCand, $realBase) === 0) {
        return $realCand;
    }
    // Fallback falls realpath fehlschlägt aber Datei existiert (z.B. Rechte / Symlink)
    if (file_exists($candidate)) return $candidate;
    return null;
}

// Backend-Endpunkte (alles unter /backend/ direkt durchreichen)
if (str_starts_with($uri, '/backend/')) {
    $target = safePath($backendDir, substr($uri, strlen('/backend/')));
    if ($target && is_file($target)) {
        require $target;
        exit;
    }
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['success'=>false,'message'=>'Backend-Endpoint nicht gefunden']);
    exit;
}

// Statische Dateien aus dem Build (CSS, JS, Media, Manifest, Favicon)
$staticPrefixes = ['/static/','/images/','/sounds/'];
$staticFiles = ['favicon.ico','asset-manifest.json','manifest.json','logo192.png','logo512.png'];
$serveStatic = false;

foreach ($staticPrefixes as $p) {
    if (str_starts_with($uri, $p)) { $serveStatic = true; break; }
}
if (!$serveStatic && in_array(ltrim($uri,'/'), $staticFiles, true)) {
    $serveStatic = true;
}

if ($serveStatic) {
    $file = safePath($buildDir, $uri);

    // Fallback: falls angefordertes main.*.js nicht existiert, versuche vorhandenes zu finden
    if (!$file && preg_match('#^/static/js/main\.[A-Za-z0-9]+\.js$#', $uri)) {
        $candidates = glob($buildDir . '/static/js/main.*.js');
        if ($candidates && count($candidates) > 0) {
            // Nimm das erste (oder das neueste)
            usort($candidates, function($a,$b){ return filemtime($b) <=> filemtime($a); });
            $file = $candidates[0];
            header('X-Static-Fallback: 1');
            header('X-Served-File: '.basename($file));
        }
    }

    if ($file && is_file($file)) {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $mimeMap = [
            'js'=>'application/javascript','css'=>'text/css','json'=>'application/json','map'=>'application/json',
            'png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','gif'=>'image/gif','svg'=>'image/svg+xml',
            'ico'=>'image/x-icon','mp3'=>'audio/mpeg','wav'=>'audio/wav','mp4'=>'video/mp4'
        ];
        if (isset($mimeMap[$ext])) header('Content-Type: '.$mimeMap[$ext]);
        header('X-Resolved-Path: '.basename($file));
        readfile($file);
        exit;
    } else {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Datei nicht gefunden (requested: ' . $uri . ')';
        exit;
    }
}

// Default: Single Page App ausliefern
$indexFile = $buildDir . '/index.html';
if (is_file($indexFile)) {
    header('Content-Type: text/html; charset=utf-8');
    readfile($indexFile);
    exit;
}

http_response_code(500);
header('Content-Type: text/plain; charset=utf-8');
print "Build index.html nicht gefunden. Bitte zuerst Frontend bauen (z.B. 'npm run build').";
