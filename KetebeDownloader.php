<?php
/**
 * ==============================================================
 *  KetebeDownloader - محرّك التنزيل الأساسي
 * ==============================================================
 *  تحويل منطق سكريبت بايثون إلى PHP Class كامل.
 *  يقوم بـ:
 *   1) استخراج artwork_id من الرابط
 *   2) جلب صفحة اللوحة واستخراج r2_key + dzi_key + الأبعاد
 *   3) تنزيل ملف DZI XML
 *   4) تنزيل كل البلاطات وتجميعها بدقة (مع overlap)
 *   5) حفظ الصورة النهائية بدقة 100%
 * ==============================================================
 */

require_once __DIR__ . '/config.php';

class KetebeDownloader
{
    private $progressCallback = null;
    private $jobId = null;

    /** هيكل بيانات اللوحة */
    public $artworkInfo = [
        'artwork_id'   => null,
        'title'        => '',
        'r2_key'       => null,
        'dzi_key'      => null,
        'width'        => null,
        'height'       => null,
        'artist_name'  => null,
    ];

    /** بيانات الـ DZI manifest */
    public $dziManifest = [
        'width'      => 0,
        'height'     => 0,
        'tile_size'  => 254,
        'overlap'    => 1,
        'format'     => 'jpeg',
        'base_url'   => '',
        'max_level'  => 0,
    ];

    /**
     * ضبط callback لتحديث التقدم
     * function(int $done, int $total, string $message): void
     */
    public function setProgressCallback(callable $cb): void
    {
        $this->progressCallback = $cb;
    }

    public function setJobId(string $id): void
    {
        $this->jobId = $id;
    }

    /** إرسال تحديث تقدم */
    private function progress(int $done, int $total, string $message = ''): void
    {
        if ($this->progressCallback) {
            ($this->progressCallback)($done, $total, $message);
        }
    }

    /** تسجيل رسالة في ملف الوظيفة */
    public function log(string $message): void
    {
        if ($this->jobId) {
            $logFile = JOBS_DIR . '/' . $this->jobId . '.log';
            $line = '[' . date('H:i:s') . '] ' . $message . "\n";
            @file_put_contents($logFile, $line, FILE_APPEND);
        }
    }

    // ==================================================================
    // 1) استخراج رقم اللوحة من الرابط
    // ==================================================================

    public static function parseArtworkId(string $input): int
    {
        $input = trim($input);
        if (ctype_digit($input)) {
            return (int)$input;
        }

        if (preg_match('#/artwork/(\d+)#', $input, $m)) {
            return (int)$m[1];
        }
        if (preg_match('#/eserler/(\d+)#', $input, $m)) {
            return (int)$m[1];
        }

        throw new InvalidArgumentException('تعذّر استخراج رقم اللوحة من: ' . $input);
    }

    // ==================================================================
    // 2) جلب صفحة اللوحة
    // ==================================================================

    public function fetchArtworkPage(int $artworkId): string
    {
        $url = SITE_BASE . '/ar/artwork/' . $artworkId;
        $html = $this->httpGet($url);
        if ($html === null) {
            throw new RuntimeException("فشل جلب صفحة اللوحة من: $url");
        }
        return $html;
    }

    /**
     * HTTP GET باستخدام cURL مع محاكاة رؤوس المتصفح
     */
    private function httpGet(string $url, int $timeout = HTTP_TIMEOUT): ?string
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_HTTPHEADER     => getDefaultHeaders(),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_ENCODING       => '',
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($response === false || $httpCode >= 400) {
            $this->log("HTTP $httpCode خطأ في: $url - $error");
            return null;
        }
        return $response;
    }

    /** HTTP GET لإرجاع البايتات الخام (للبلاطات) */
    private function httpGetBytes(string $url, int $timeout = HTTP_TIMEOUT, int &$httpCode = 0): ?string
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_HTTPHEADER     => getDefaultHeaders(),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $response;
    }

    // ==================================================================
    // 3) استخراج بيانات اللوحة من HTML
    // ==================================================================

    public function extractArtworkInfo(string $html, int $artworkId): array
    {
        $r2Key  = null;
        $dziKey = null;
        $width  = null;
        $height = null;

        // نمط r2_key (مع \ أو بدون)
        $patterns = [
            '/\\\\?"r2_key\\\\?"\s*,\s*\\\\?"(masters\/[^"\\\\]+\.jpg)\\\\?"/',
            '/"r2_key"\s*,\s*"(masters\/[^"]+\.jpg)"/',
        ];
        foreach ($patterns as $p) {
            if (preg_match($p, $html, $m)) {
                $r2Key = $m[1];
                break;
            }
        }

        // نمط dzi_key
        $patterns = [
            '/\\\\?"dzi_key\\\\?"\s*,\s*\\\\?"(dzi\/[^"\\\\]+\.dzi)\\\\?"/',
            '/"dzi_key"\s*,\s*"(dzi\/[^"]+\.dzi)"/',
        ];
        foreach ($patterns as $p) {
            if (preg_match($p, $html, $m)) {
                $dziKey = $m[1];
                break;
            }
        }

        // الأبعاد بعد blurhash
        $dimPattern = '/\\\\?"dzi_key\\\\?"\s*,\s*\\\\?"[^"\\\\]+\.dzi\\\\?"\s*,\s*\\\\?"blurhash\\\\?"\s*,\s*\\\\?"[^"\\\\]*\\\\?"\s*,\s*(\d+)\s*,\s*(\d+)/';
        if (preg_match($dimPattern, $html, $m)) {
            $width  = (int)$m[1];
            $height = (int)$m[2];
        } else {
            $altPattern = '/\\\\?"r2_key\\\\?"\s*,\s*\\\\?"[^"\\\\]+\.jpg\\\\?"[^0-9]{0,80}?(\d{2,5})\s*,\s*(\d{2,5})/';
            if (preg_match($altPattern, $html, $m)) {
                $width  = (int)$m[1];
                $height = (int)$m[2];
            }
        }

        // العنوان من <title>
        $title = "image_{$artworkId}";
        if (preg_match('/<title>([^<]+)<\/title>/', $html, $m)) {
            $rawTitle = trim($m[1]);
            $rawTitle = preg_replace('/\s*\|\s*Ketebe\s*$/u', '', $rawTitle);
            $clean = preg_replace('/[\\\\\/:*?"<>|\r\n\t]+/', ' ', $rawTitle);
            $clean = preg_replace('/\s{2,}/', ' ', $clean);
            $clean = trim($clean);
            if ($clean !== '') {
                $title = mb_substr($clean, 0, 180);
            }
        }

        // اسم الفنان من JSON-LD
        $artist = null;
        if (preg_match('/"@type":"Person","name":"([^"]+)"/', $html, $m)) {
            $artist = trim($m[1]);
        }

        $this->artworkInfo = [
            'artwork_id'  => $artworkId,
            'title'       => $title,
            'r2_key'      => $r2Key,
            'dzi_key'     => $dziKey,
            'width'       => $width,
            'height'      => $height,
            'artist_name' => $artist,
        ];
        return $this->artworkInfo;
    }

    // ==================================================================
    // 4) جلب ملف الـ DZI XML
    // ==================================================================

    public function fetchDziManifest(string $dziKey): array
    {
        $dziUrl = MEDIA_BASE . '/' . $dziKey;
        $xmlContent = $this->httpGet($dziUrl);
        if ($xmlContent === null) {
            throw new RuntimeException("فشل جلب ملف DZI من: $dziUrl");
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlContent);
        if ($xml === false) {
            throw new RuntimeException("ملف DZI غير صالح");
        }

        $format  = strtolower((string)($xml['Format'] ?? 'jpeg'));
        $overlap = (int)($xml['Overlap'] ?? 0);
        $tileSz  = (int)($xml['TileSize'] ?? 254);

        $sizeEl = $xml->Size;
        if (!$sizeEl) {
            throw new RuntimeException("ملف DZI لا يحتوي على عنصر <Size>");
        }
        $w = (int)$sizeEl['Width'];
        $h = (int)$sizeEl['Height'];

        // أعلى مستوى = ceil(log2(max(w,h)))
        $maxDim = max($w, $h);
        $maxLevel = 0;
        while ((1 << $maxLevel) < $maxDim) {
            $maxLevel++;
        }

        // base_url: dzi/2026/06/17079.dzi → dzi/2026/06/17079_files
        $base = MEDIA_BASE . '/' . substr($dziKey, 0, -4) . '_files';

        $this->dziManifest = [
            'width'      => $w,
            'height'     => $h,
            'tile_size'  => $tileSz,
            'overlap'    => $overlap,
            'format'     => $format,
            'base_url'   => $base,
            'max_level'  => $maxLevel,
        ];
        return $this->dziManifest;
    }

    // ==================================================================
    // 5) تنزيل وتجميع البلاطات
    // ==================================================================

    /** امتداد البلاطة الصحيح (السر: .jpeg وليس .jpg) */
    private function tileExtension(string $fmt): string
    {
        $f = strtolower($fmt);
        if (in_array($f, ['jpg', 'jpeg'])) {
            return 'jpeg';
        }
        if ($f === 'png') return 'png';
        if ($f === 'webp') return 'webp';
        return $f;
    }

    private function tileUrl(array $manifest, int $level, int $col, int $row): string
    {
        $ext = $this->tileExtension($manifest['format']);
        return $manifest['base_url'] . "/{$level}/{$col}_{$row}.{$ext}";
    }

    private function downloadTile(string $url): ?string
    {
        $lastErr = null;
        for ($attempt = 0; $attempt < TILE_RETRIES; $attempt++) {
            $httpCode = 0;
            $data = $this->httpGetBytes($url, HTTP_TIMEOUT, $httpCode);
            if ($httpCode === 200 && $data !== false && $data !== '') {
                return $data;
            }
            if ($httpCode === 404) {
                return null; // بلاطة خارج النطاق
            }
            $lastErr = "HTTP $httpCode";
        }
        $this->log("⚠️ فشل تنزيل بلاط بعد " . TILE_RETRIES . " محاولات: $url ($lastErr)");
        return null;
    }

    private function levelDimensions(array $manifest, int $level): array
    {
        $scale = 1 << ($manifest['max_level'] - $level);
        $w = intdiv($manifest['width']  + $scale - 1, $scale);
        $h = intdiv($manifest['height'] + $scale - 1, $scale);
        return [$w, $h];
    }

    private function tileGrid(array $manifest, int $level): array
    {
        [$w, $h] = $this->levelDimensions($manifest, $level);
        $cols = intdiv($w + $manifest['tile_size'] - 1, $manifest['tile_size']);
        $rows = intdiv($h + $manifest['tile_size'] - 1, $manifest['tile_size']);
        return [$cols, $rows];
    }

    /**
     * تنزيل وتجميع كل البلاطات في صورة واحدة.
     * يستخدم GD library لإنشاء صورة كبيرة.
     */
    public function downloadFullImage(array $manifest, int $level = null, bool $useImagick = null): array
    {
        if ($level === null) {
            $level = $manifest['max_level'];
        }

        [$lvlW, $lvlH] = $this->levelDimensions($manifest, $level);
        [$cols, $rows] = $this->tileGrid($manifest, $level);
        $total = $cols * $rows;

        $this->log("• المستوى: $level / {$manifest['max_level']}");
        $this->log("• أبعاد عند المستوى: {$lvlW} × {$lvlH} px");
        $this->log("• شبكة البلاطات: {$cols} × {$rows} = {$total} بلاطة");
        $this->log("• TileSize={$manifest['tile_size']}  Overlap={$manifest['overlap']}  Format={$manifest['format']}");

        // تحديد المحرك: Imagick لو متاح (للصور الكبيرة)، وإلا GD
        $hasImagick = extension_loaded('imagick');
        if ($useImagick === null) {
            $useImagick = $hasImagick && ($lvlW * $lvlH > 25000000); // >25MP → Imagick
        } elseif ($useImagick && !$hasImagick) {
            $useImagick = false;
            $this->log("• Imagick غير متاح، استخدام GD");
        }

        if (!$useImagick && !function_exists('imagecreatetruecolor')) {
            throw new RuntimeException("GD library غير متاحة - لا يمكن معالجة الصور");
        }

        // إنشاء لوحة فارغة بالأبعاد النهائية
        if ($useImagick) {
            $canvas = new Imagick();
            $canvas->newImage($lvlW, $lvlH, new ImagickPixel('#000000'));
            $canvas->setImageFormat('jpeg');
        } else {
            $canvas = imagecreatetruecolor($lvlW, $lvlH);
            $black = imagecolorallocate($canvas, 0, 0, 0);
            imagefilledrectangle($canvas, 0, 0, $lvlW, $lvlH, $black);
        }

        // توليد روابط البلاطات
        $tileJobs = [];
        for ($r = 0; $r < $rows; $r++) {
            for ($c = 0; $c < $cols; $c++) {
                $tileJobs[] = [$c, $r, $this->tileUrl($manifest, $level, $c, $r)];
            }
        }

        $done = 0;
        $failed = 0;

        // تنزيل متوازي باستخدام cURL multi
        $batchSize = MAX_WORKERS;
        $batches = array_chunk($tileJobs, $batchSize);

        foreach ($batches as $batchIdx => $batch) {
            $responses = $this->multiGet(array_column($batch, 2));

            foreach ($batch as $i => [$c, $r, $url]) {
                $data = $responses[$i];
                if ($data === null) {
                    $failed++;
                } else {
                    // لصق البلاطة في مكانها (مع مراعاة overlap)
                    $x = $c * $manifest['tile_size'] - ($c > 0 ? $manifest['overlap'] : 0);
                    $y = $r * $manifest['tile_size'] - ($r > 0 ? $manifest['overlap'] : 0);

                    if ($useImagick) {
                        $tile = new Imagick();
                        $tile->readImageBlob($data);
                        $canvas->compositeImage($tile, Imagick::COMPOSITE_OVER, $x, $y);
                        $tile->destroy();
                    } else {
                        $tile = imagecreatefromstring($data);
                        if ($tile !== false) {
                            $tw = imagesx($tile);
                            $th = imagesy($tile);
                            imagecopy($canvas, $tile, $x, $y, 0, 0, $tw, $th);
                            imagedestroy($tile);
                        }
                    }
                }
                $done++;
            }

            $pct = (int)($done * 100 / $total);
            $this->progress($done, $total, "جارٍ معالجة الصورة: {$done}/{$total}");
        }

        if ($failed > 0) {
            $this->log("⚠️ {$failed} بلاطة فشل تنزيلها (قد تظهر فراغات)");
        }

        // اقتصاص للأبعاد النهائية الصحيحة (في حال تجاوز الـ overlap)
        $expectedW = $level === $manifest['max_level'] ? $manifest['width'] : $this->levelDimensions($manifest, $level)[0];
        $expectedH = $level === $manifest['max_level'] ? $manifest['height'] : $this->levelDimensions($manifest, $level)[1];

        if ($useImagick) {
            if ($canvas->getImageWidth() != $expectedW || $canvas->getImageHeight() != $expectedH) {
                $canvas->cropImage($expectedW, $expectedH, 0, 0);
            }
        } else {
            if (imagesx($canvas) != $expectedW || imagesy($canvas) != $expectedH) {
                $cropped = imagecrop($canvas, ['x' => 0, 'y' => 0, 'width' => $expectedW, 'height' => $expectedH]);
                if ($cropped !== false) {
                    imagedestroy($canvas);
                    $canvas = $cropped;
                }
            }
        }

        return [
            'canvas'   => $canvas,
            'engine'   => $useImagick ? 'imagick' : 'gd',
            'width'    => $expectedW,
            'height'   => $expectedH,
            'failed'   => $failed,
        ];
    }

    /**
     * تنزيل متوازي لعدة URLs باستخدام cURL multi
     */
    private function multiGet(array $urls): array
    {
        $handles = [];
        $mh = curl_multi_init();
        $results = array_fill(0, count($urls), null);

        foreach ($urls as $i => $url) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_HTTPHEADER     => getDefaultHeaders(),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 5,
                CURLOPT_TIMEOUT        => HTTP_TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$i] = $ch;
        }

        $active = null;
        do {
            $status = curl_multi_exec($mh, $active);
            if ($active) {
                curl_multi_select($mh);
            }
        } while ($active && $status === CURLM_OK);

        foreach ($handles as $i => $ch) {
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $data = curl_multi_getcontent($ch);
            if ($httpCode === 200 && $data !== '' && $data !== false) {
                $results[$i] = $data;
            }
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
        return $results;
    }

    // ==================================================================
    // 6) مسار احتياطي: نسخة detail
    // ==================================================================

    public function downloadDetailFallback(array $info): array
    {
        if (empty($info['r2_key'])) {
            throw new RuntimeException("لا توجد DZI ولا r2_key - لا يمكن تنزيل الصورة");
        }
        $url = MEDIA_BASE . '/i/detail/' . $info['r2_key'];
        $this->log("• تنزيل النسخة التفصيلية (fallback): $url");
        $httpCode = 0;
        $data = $this->httpGetBytes($url, 60, $httpCode);
        if ($httpCode !== 200 || !$data) {
            throw new RuntimeException("فشل تنزيل النسخة التفصيلية: HTTP $httpCode");
        }
        return ['data' => $data, 'ext' => 'jpg'];
    }

    // ==================================================================
    // 7) اسم ملف آمن
    // ==================================================================

    public static function safeFilename(string $s, int $maxLen = 120): string
    {
        $s = preg_replace('/[\\\\\/:*?"<>|\r\n\t]+/', ' ', $s);
        $s = preg_replace('/\s{2,}/', ' ', $s);
        $s = trim($s);
        if ($s === '') $s = 'artwork';
        return mb_substr($s, 0, $maxLen);
    }

    // ==================================================================
    // 8) حفظ الصورة النهائية
    // ==================================================================

    public function saveImage($canvas, string $engine, string $outPath, int $quality = DEFAULT_QUALITY, string $format = 'jpg'): bool
    {
        $webpQuality = 82;

        if ($engine === 'imagick') {
            $fmt = match ($format) {
                'webp' => 'webp',
                'png'  => 'png',
                default => 'jpeg',
            };
            $canvas->setImageFormat($fmt);
            if ($fmt === 'jpeg') {
                $canvas->setImageCompressionQuality($quality);
                $canvas->setInterlaceScheme(Imagick::INTERLACE_PLANE);
            } elseif ($fmt === 'webp') {
                $canvas->setImageCompressionQuality($webpQuality);
            }
            // PNG: lossless by default in Imagick
            return $canvas->writeImage($outPath);
        } else {
            if ($format === 'webp' && function_exists('imagewebp')) {
                return imagewebp($canvas, $outPath, $webpQuality);
            }
            if ($format === 'png' && function_exists('imagepng')) {
                return imagepng($canvas, $outPath, 6); // 0-9: 6 = balance size/speed
            }
            return imagejpeg($canvas, $outPath, $quality);
        }
    }

    /**
     * تحرير الموارد
     */
    public function destroyCanvas($canvas, string $engine): void
    {
        if ($engine === 'imagick') {
            $canvas->destroy();
        } else {
            imagedestroy($canvas);
        }
    }

    // ==================================================================
    // 9) نقطة الدخول الرئيسية
    // ==================================================================

    public function downloadArtwork(string $input, int $quality = DEFAULT_QUALITY, ?int $level = null, string $format = 'jpg'): array
    {
        $artworkId = self::parseArtworkId($input);
        $this->log("🔍 معرّف الصورة: {$artworkId}");

        $this->progress(0, 100, "جلب بيانات الصورة #{$artworkId}...");

        // --- 1) جلب الصفحة ---
        $html = $this->fetchArtworkPage($artworkId);
        $info = $this->extractArtworkInfo($html, $artworkId);

        $this->log("• العنوان: {$info['title']}");
        if ($info['artist_name']) {
            $this->log("• الفنّان: {$info['artist_name']}");
        }
        $this->log("• r2_key: " . ($info['r2_key'] ?? '—'));
        $this->log("• dzi_key: " . ($info['dzi_key'] ?? '—'));

        $this->progress(5, 100, "تم استخراج بيانات الصورة: {$info['title']}");

        // --- 2) مسار DZI ---
        if (!empty($info['dzi_key'])) {
            $this->progress(10, 100, "جلب ملف DZI...");
            $manifest = $this->fetchDziManifest($info['dzi_key']);

            $this->log("• أبعاد أصلية: {$manifest['width']} × {$manifest['height']} px");
            $this->progress(15, 100, "جارٍ تحميل الصورة ({$manifest['width']}×{$manifest['height']} px)...");

            $result = $this->downloadFullImage($manifest, $level);
            $ext = match ($format) {
                'webp' => 'webp',
                'png'  => 'png',
                default => in_array($manifest['format'], ['jpg','jpeg']) ? 'jpg' : $manifest['format'],
            };

        } else {
            // مسار احتياطي
            $this->log("⚠️ لا توجد DZI - استخدام نسخة detail");
            $this->progress(20, 100, "تنزيل النسخة التفصيلية (fallback)");

            $fallback = $this->downloadDetailFallback($info);
            $ext = $fallback['ext'];

            // حفظ مباشر
            $fname = $artworkId . '_' . self::safeFilename($info['title']) . '.' . $ext;
            $fpath = DOWNLOAD_DIR . '/' . $fname;
            file_put_contents($fpath, $fallback['data']);

            $this->progress(100, 100, "اكتمل التنزيل");

            return [
                'path'        => $fpath,
                'filename'    => $fname,
                'url'         => 'serve.php?file=' . rawurlencode($fname),
                'width'       => null,
                'height'      => null,
                'size_bytes'  => filesize($fpath),
                'artwork'     => $info,
                'engine'      => 'direct',
            ];
        }

        // --- 3) الحفظ ---
        $fname = $artworkId . '_' . self::safeFilename($info['title']) . '.' . $ext;
        $fpath = DOWNLOAD_DIR . '/' . $fname;

        $this->progress(95, 100, "حفظ الصورة النهائية...");
        $this->saveImage($result['canvas'], $result['engine'], $fpath, $quality, $format);
        $this->destroyCanvas($result['canvas'], $result['engine']);

        $this->progress(100, 100, "اكتمل التنزيل بنجاح ✓");

        return [
            'path'        => $fpath,
            'filename'    => $fname,
            'url'         => 'serve.php?file=' . rawurlencode($fname),
            'width'       => $result['width'],
            'height'      => $result['height'],
            'size_bytes'  => filesize($fpath),
            'artwork'     => $info,
            'engine'      => $result['engine'],
            'failed_tiles'=> $result['failed'] ?? 0,
        ];
    }
}
