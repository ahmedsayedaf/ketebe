<?php
/**
 * ==============================================================
 *  api.php - واجهة برمجية للموقع
 * ==============================================================
 *  Endpoints:
 *   GET  api.php?action=info&url=...
 *        → معلومات اللوحة فقط (سريع)
 *
 *   GET  api.php?action=download&url=...&quality=95&level=
 *        → SSE stream مع تحديثات التقدم، النتيجة في الحدث الأخير
 *
 *   GET  api.php?action=status&job=...
 *        → حالة وظيفة (احتياطي لو SSE انقطع)
 *
 *   GET  api.php?action=history
 *        → قائمة آخر التنزيلات
 * ==============================================================
 */

require_once __DIR__ . '/KetebeDownloader.php';

// CORS headers (احتياج لو الواجهة على نطاق مختلف)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$action = $_GET['action'] ?? '';
$url    = trim($_GET['url'] ?? '');
$quality = 100; // دائمًا أعلى جودة
$format  = in_array($_GET['format'] ?? '', ['jpg', 'webp', 'png']) ? $_GET['format'] : 'webp';
$level   = null; // دائمًا أعلى مستوى

// ── حماية JPG و PNG بباسوورد ─────────────────────────────────────
if (in_array($format, ['jpg', 'png']) && $action === 'download') {
    $submitted = trim($_GET['fmt_pass'] ?? '');
    if (!hash_equals(FORMAT_PASSWORD, $submitted)) {
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        $label = strtoupper($format);
        $err = json_encode(['error' => "كلمة المرور غير صحيحة — صيغة {$label} للعملاء فقط."]);
        echo "event: error\ndata: {$err}\n\n";
        flush();
        exit;
    }
}


try {
    switch ($action) {

        // ============================================================
        // 1) معلومات اللوحة فقط (سريع - بدون تنزيل)
        // ============================================================
        case 'info':
            if ($url === '') {
                respondJson(['error' => 'الرابط مطلوب'], 400);
            }
            $downloader = new KetebeDownloader();
            $artworkId = KetebeDownloader::parseArtworkId($url);
            $html = $downloader->fetchArtworkPage($artworkId);
            $info = $downloader->extractArtworkInfo($html, $artworkId);

            respondJson([
                'success' => true,
                'data'    => [
                    'artwork_id'  => $info['artwork_id'],
                    'title'       => $info['title'],
                    'artist'      => $info['artist_name'],
                    'has_dzi'     => !empty($info['dzi_key']),
                    'r2_key'      => $info['r2_key'],
                    'dzi_key'     => $info['dzi_key'],
                    'width'       => $info['width'],
                    'height'      => $info['height'],
                    'page_url'    => SITE_BASE . '/ar/artwork/' . $info['artwork_id'],
                ],
            ]);
            break;

        // ============================================================
        // 2) تنزيل اللوحة عبر SSE
        // ============================================================
        case 'download':
            if ($url === '') {
                respondJson(['error' => 'الرابط مطلوب'], 400);
            }
            // التحقق من الجودة
            if ($quality < 1 || $quality > 100) {
                $quality = DEFAULT_QUALITY;
            }

            $jobId = bin2hex(random_bytes(8));

            // تفعيل SSE
            setupSse();

            $downloader = new KetebeDownloader();
            $downloader->setJobId($jobId);
            $downloader->setProgressCallback(function($done, $total, $message) use ($jobId) {
                $pct = $total > 0 ? (int)($done * 100 / $total) : 0;
                sseEvent('progress', [
                    'job_id'   => $jobId,
                    'done'     => $done,
                    'total'    => $total,
                    'percent'  => $pct,
                    'message'  => $message,
                    'timestamp'=> time(),
                ]);

                // حفظ الحالة في ملف للاسترجاع
                file_put_contents(JOBS_DIR . '/' . $jobId . '.json', json_encode([
                    'job_id'  => $jobId,
                    'status'  => 'processing',
                    'done'    => $done,
                    'total'   => $total,
                    'percent' => $pct,
                    'message' => $message,
                    'updated' => time(),
                ]));
            });

            // إرسال حدث البدء
            sseEvent('start', [
                'job_id'  => $jobId,
                'url'     => $url,
                'quality' => $quality,
                'message' => 'بدء المعالجة...',
            ]);

            try {
                $result = $downloader->downloadArtwork($url, $quality, $level, $format);

                // نجاح
                file_put_contents(JOBS_DIR . '/' . $jobId . '.json', json_encode([
                    'job_id'  => $jobId,
                    'status'  => 'done',
                    'percent' => 100,
                    'result'  => $result,
                    'updated' => time(),
                ]));

                sseEvent('done', [
                    'job_id'   => $jobId,
                    'success'  => true,
                    'result'   => [
                        'filename'    => $result['filename'],
                        'url'         => $result['url'],
                        'width'       => $result['width'],
                        'height'      => $result['height'],
                        'size_bytes'  => $result['size_bytes'],
                        'size_human'  => formatBytes($result['size_bytes']),
                        'title'       => $result['artwork']['title'],
                        'artist'      => $result['artwork']['artist_name'] ?? null,
                        'artwork_id'  => $result['artwork']['artwork_id'],
                        'engine'      => $result['engine'],
                        'failed_tiles'=> $result['failed_tiles'] ?? 0,
                    ],
                ]);

                // تسجيل في السجل (history)
                appendHistory($result);

            } catch (Throwable $e) {
                file_put_contents(JOBS_DIR . '/' . $jobId . '.json', json_encode([
                    'job_id'  => $jobId,
                    'status'  => 'error',
                    'error'   => $e->getMessage(),
                    'updated' => time(),
                ]));

                sseEvent('error', [
                    'job_id' => $jobId,
                    'error'  => $e->getMessage(),
                ]);
            }

            sseClose();
            break;

        // ============================================================
        // 3) حالة الوظيفة (fallback)
        // ============================================================
        case 'status':
            $jobId = $_GET['job'] ?? '';
            if (!$jobId || !preg_match('/^[a-f0-9]+$/', $jobId)) {
                respondJson(['error' => 'معرّف وظيفة غير صالح'], 400);
            }
            $file = JOBS_DIR . '/' . $jobId . '.json';
            if (!file_exists($file)) {
                respondJson(['error' => 'الوظيفة غير موجودة'], 404);
            }
            $data = json_decode(file_get_contents($file), true);
            respondJson(['success' => true, 'data' => $data]);
            break;

        // ============================================================
        // 4) سجل التنزيلات
        // ============================================================
        case 'history':
            $historyFile = DOWNLOAD_DIR . '/.history.json';
            $history = [];
            if (file_exists($historyFile)) {
                $history = json_decode(file_get_contents($historyFile), true) ?: [];
            }
            // آخر 20 فقط
            $history = array_slice($history, 0, 20);
            respondJson(['success' => true, 'data' => $history]);
            break;

        // ============================================================
        // 5) معلومات النظام (للأقنوم الأدمن)
        // ============================================================
        case 'system':
            respondJson([
                'success' => true,
                'data' => [
                    'php_version'   => PHP_VERSION,
                    'has_gd'        => extension_loaded('gd'),
                    'has_imagick'   => extension_loaded('imagick'),
                    'has_curl'       => extension_loaded('curl'),
                    'memory_limit'  => ini_get('memory_limit'),
                    'max_exec_time' => ini_get('max_execution_time'),
                    'gd_info'       => extension_loaded('gd') ? gd_info() : null,
                ],
            ]);
            break;

        default:
            respondJson(['error' => 'إجراء غير معروف'], 400);
    }

} catch (Throwable $e) {
    if (headers_sent() === false) {
        respondJson(['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()], 500);
    } else {
        sseEvent('error', ['error' => $e->getMessage()]);
        sseClose();
    }
}


// ================================================================
// دوال مساعدة
// ================================================================

function respondJson(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function setupSse(): void
{
    // تعطيل التخزين المؤقت
    @ini_set('zlib.output_compression', '0');
    @ini_set('output_buffering', '0');
    @ini_set('implicit_flush', '1');
    while (ob_get_level() > 0) {
        ob_end_flush();
    }

    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no'); // nginx

    // تعطيل limit المهلة
    @set_time_limit(MAX_EXEC_TIME);
    echo ': ' . str_repeat(' ', 2048) . "\n\n"; // padding
    flush();
}

function sseEvent(string $event, array $data): void
{
    echo "event: {$event}\n";
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    flush();
}

function sseClose(): void
{
    echo "event: close\ndata: {}\n\n";
    flush();
}

function formatBytes(int $bytes): string
{
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return number_format($bytes / 1024, 1) . ' KB';
    if ($bytes < 1073741824) return number_format($bytes / 1048576, 2) . ' MB';
    return number_format($bytes / 1073741824, 2) . ' GB';
}

function appendHistory(array $result): void
{
    $file = DOWNLOAD_DIR . '/.history.json';
    $history = [];
    if (file_exists($file)) {
        $history = json_decode(file_get_contents($file), true) ?: [];
    }
    array_unshift($history, [
        'filename'   => $result['filename'],
        'url'        => $result['url'],
        'title'      => $result['artwork']['title'],
        'artist'     => $result['artwork']['artist_name'] ?? null,
        'artwork_id' => $result['artwork']['artwork_id'],
        'width'      => $result['width'],
        'height'     => $result['height'],
        'size_bytes' => $result['size_bytes'],
        'size_human' => formatBytes($result['size_bytes']),
        'engine'     => $result['engine'],
        'timestamp'  => time(),
        'date'       => date('Y-m-d H:i'),
    ]);
    // احتفظ بآخر 50 فقط
    $history = array_slice($history, 0, 50);
    file_put_contents($file, json_encode($history, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}
