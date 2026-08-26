<?php
/**
 * serve.php - تقديم الملفات المحمّلة مع الحذف التلقائي
 * =====================================================
 * ?file=filename.jpg          → عرض للمعاينة (no delete)
 * ?file=filename.jpg&dl=1     → تنزيل إجباري (+ حذف بعده)
 * حذف تلقائي لأي ملف عمره > 20 دقيقة
 */

require_once __DIR__ . '/config.php';

// ── تنظيف الملفات القديمة (> 20 دقيقة) ──────────────────────────
$maxAge = 20 * 60; // ثانية
foreach (glob(DOWNLOAD_DIR . '/*.{jpg,jpeg,webp,png}', GLOB_BRACE) ?: [] as $old) {
    if (basename($old) !== '.history.json' && filemtime($old) < time() - $maxAge) {
        @unlink($old);
    }
}

// ── التحقق من اسم الملف ──────────────────────────────────────────
$raw = $_GET['file'] ?? '';

// منع path traversal: أسماء بسيطة فقط بدون مجلدات
if ($raw === '' || str_contains($raw, '/') || str_contains($raw, '\\') || str_contains($raw, '..')) {
    http_response_code(400);
    exit('Invalid filename.');
}

// امتداد مسموح به فقط
if (!preg_match('/\.(jpg|jpeg|webp|png)$/i', $raw)) {
    http_response_code(400);
    exit('Unsupported format.');
}

$path = DOWNLOAD_DIR . '/' . $raw;

if (!file_exists($path)) {
    http_response_code(404);
    exit('File not found or already downloaded.');
}

// ── إعداد الاستجابة ───────────────────────────────────────────────
$ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$mime = match ($ext) {
    'webp'        => 'image/webp',
    'png'         => 'image/png',
    default       => 'image/jpeg',
};

$isDl = isset($_GET['dl']); // تنزيل إجباري

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Cache-Control: no-store, no-cache');
header('X-Content-Type-Options: nosniff');

if ($isDl) {
    $safeFilename = rawurlencode(basename($path));
    header("Content-Disposition: attachment; filename*=UTF-8''{$safeFilename}");
}

// ── إرسال الملف ──────────────────────────────────────────────────
readfile($path);

// ── حذف الملف بعد التنزيل فقط ────────────────────────────────────
if ($isDl) {
    @unlink($path);
}
