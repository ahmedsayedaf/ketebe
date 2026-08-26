<?php
/**
 * ==============================================================
 *  Ketebe.org Downloader - إعدادات عامة
 * ==============================================================
 *  هذا الملف يحتوي على الإعدادات الأساسية للموقع.
 *  يمكنك تعديل القيم بالأسفل حسب احتياجك.
 * ==============================================================
 */

// إعدادات الموقع
const SITE_NAME      = 'NEXUS DL';
const SITE_TAGLINE   = 'حمّل أي صورة من الإنترنت بجودة عالية — بسرعة وسهولة';

// روابط Ketebe الأساسية
const MEDIA_BASE     = 'https://media.ketebe.org';
const SITE_BASE      = 'https://www.ketebe.org';

// إعدادات التنزيل
const MAX_WORKERS        = 8;       // عدد البلاطات المتوازية (PHP يستخدم batch)
const DEFAULT_QUALITY    = 95;      // جودة JPEG الافتراضية (1-100)
const TILE_RETRIES       = 3;       // عدد محاولات إعادة تنزيل البلاطة الفاشلة
const HTTP_TIMEOUT        = 30;      // مهلة HTTP بالثواني

// إعدادات الذاكرة والوقت (للصور الكبيرة)
const MEMORY_LIMIT        = '512M';  // رفع حد الذاكرة
const MAX_EXEC_TIME       = 300;     // 5 دقائق كحد أقصى للتنفيذ

// مسارات المجلدات
const DOWNLOAD_DIR   = __DIR__ . '/downloads';
const JOBS_DIR       = __DIR__ . '/jobs';

// إعدادات الأمان
const ALLOWED_DOMAINS = ['ketebe.org', 'www.ketebe.org', 'beta.ketebe.org', 'media.ketebe.org'];

// باسوورد الصيغ المحمية (JPG و PNG) — WebP مجاني للجميع
// غيّر هذه القيمة لأي باسوورد تريده
const FORMAT_PASSWORD = 'ahmed2025';

// قناة التواصل للحصول على الباسوورد
const CONTACT_FB = 'https://www.fb.com/ahmedsayedaf';

// رؤوس HTTP محاكاة لمتصفح حقيقي
function getDefaultHeaders(): array {
    return [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
        'Accept-Language: ar,en-US;q=0.9,en;q=0.8',
        'Referer: https://www.ketebe.org/',
    ];
}

// ضمان وجود المجلدات
foreach ([DOWNLOAD_DIR, JOBS_DIR] as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    // حماية: منع تصفح المجلد مباشرة
    $htaccess = $dir . '/.htaccess';
    if (!file_exists($htaccess)) {
        if ($dir === JOBS_DIR) {
            file_put_contents($htaccess, "Deny from all\n");
        } else {
            file_put_contents($htaccess, "Options -Indexes\n");
        }
    }
}

// رفع الحدود لمعالجة الصور الكبيرة
@ini_set('memory_limit', MEMORY_LIMIT);
@set_time_limit(MAX_EXEC_TIME);
