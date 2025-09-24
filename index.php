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
// Dynamische Ermittlung des Build-Verzeichnisses (falls Struktur auf Server anders ist)
$buildDirCandidates = [
    $root . '/frontend/build',
    $root . '/build',
    $root . '/public',
    $root . '/frontend/DontPickMe/build'
];
$resolvedBuildDir = null;
foreach ($buildDirCandidates as $cand) {
    if (is_dir($cand) && file_exists($cand . '/index.html')) { $resolvedBuildDir = $cand; break; }
}
if ($resolvedBuildDir === null) {
    // Fallback: nimm ersten Kandidaten trotzdem
    $resolvedBuildDir = $buildDirCandidates[0];
}
$buildDir = $resolvedBuildDir; // überschreibe ursprünglichen Wert

if (isset($_GET['__debug'])) {
    header('Content-Type: application/json');
    echo json_encode([
        'requested_uri' => $uri,
        'chosen_build_dir' => $buildDir,
        'exists_build_dir' => is_dir($buildDir),
        'has_index' => file_exists($buildDir . '/index.html'),
        'static_main_js_found' => glob($buildDir . '/static/js/main.*.js') ?: [],
        'candidates_checked' => $buildDirCandidates
    ], JSON_PRETTY_PRINT);
    exit;
}

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
    // Versuche Datei in allen Build-Kandidaten
    $file = null;
    foreach ($buildDirCandidates as $cand) {
        $candidateFile = safePath($cand, $uri);
        if ($candidateFile && is_file($candidateFile)) { $file = $candidateFile; break; }
    }

    // Fallback: alte Hash-Datei -> nimm neueste main.*.js aus einem der Kandidaten
    if (!$file && preg_match('#^/static/js/main\.[A-Za-z0-9]+\.js$#', $uri)) {
        foreach ($buildDirCandidates as $cand) {
            $candidates = glob($cand . '/static/js/main.*.js');
            if ($candidates && count($candidates) > 0) {
                usort($candidates, function($a,$b){ return filemtime($b) <=> filemtime($a); });
                $file = $candidates[0];
                header('X-Static-Fallback: 1');
                header('X-Served-File: '.basename($file));
                header('X-Served-From: '.$cand);
                break;
            }
        }
    }

    if ($file && is_file($file)) {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $mimeMap = [
            'js'=>'application/javascript','css'=>'text/css','json'=>'application/json','map'=>'application/json',
            'png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','gif'=>'image/gif','svg'=>'image/svg+xml',
            'ico'=>'image/x-icon','mp3'=>'audio/mpeg','wav'=>'audio/wav','mp4'=>'video/mp4'
        ];
        if (isset($mimeMap[$ext])) header('Content-Type: '.$mimeMap[$ext]); else header('Content-Type: application/octet-stream');
        header('X-Resolved-Path: '.basename($file));
        readfile($file);
        exit;
    }
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Datei nicht gefunden (requested: ' . $uri . ')';
    exit;
}

// Default: Single Page App ausliefern
$indexFile = $buildDir . '/index.html';
// Wenn index.html nicht gefunden -> versuche andere Kandidaten
if (!is_file($indexFile)) {
    foreach ($buildDirCandidates as $cand) {
        if (file_exists($cand . '/index.html')) { $indexFile = $cand . '/index.html'; break; }
    }
}

if (is_file($indexFile)) {
    header('Content-Type: text/html; charset=utf-8');
    readfile($indexFile);
    exit;
}

http_response_code(500);
header('Content-Type: text/plain; charset=utf-8');
print "Build index.html nicht gefunden. Bitte zuerst Frontend bauen (z.B. 'npm run build').";
//