<?php
/**
 * ==============================================================
 *  index.php - الصفحة الرئيسية
 * ==============================================================
 *  واجهة مستخدم بستايل جيمنج دارك مع أنيميشن بسيط
 *  تقوم بالاتصال بـ api.php عبر SSE لعرض التقدم
 * ==============================================================
 */

require_once __DIR__ . '/config.php';



$hasGd      = extension_loaded('gd');
$hasCurl    = extension_loaded('curl');
$sysOk = $hasGd && $hasCurl;
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= htmlspecialchars(SITE_TAGLINE) ?>">
    <title><?= SITE_NAME ?> • Gaming Dark Edition</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;900&family=Orbitron:wght@500;700;900&family=Share+Tech+Mono&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <link rel="icon" href="data:,">
</head>
<body>
    <!-- خلفية متحركة -->
    <div class="bg-grid" aria-hidden="true"></div>
    <div class="bg-glow" aria-hidden="true"></div>
    <canvas id="particles" aria-hidden="true"></canvas>

    <!-- شاشة تحميل أولية -->
    <div id="bootScreen" class="boot-screen">
        <div class="boot-content">
            <div class="boot-logo">
                <span class="boot-bracket">[</span>
                <span class="boot-text" id="bootText">INITIALIZING</span>
                <span class="boot-bracket">]</span>
            </div>
            <div class="boot-bar"><div class="boot-bar-fill"></div></div>
        </div>
    </div>

    <main class="container">
        <!-- ============= الهيدر ============= -->
        <header class="header">
            <div class="logo">
                <div class="logo-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M6 12h12M12 6v12"/>
                        <circle cx="12" cy="12" r="9"/>
                    </svg>
                </div>
                <div class="logo-text">
                    <h1><?= SITE_NAME ?></h1>
                    <p class="tagline"><?= SITE_TAGLINE ?></p>
                </div>
            </div>

        </header>

        <?php if (!$sysOk): ?>
        <div class="alert alert-error">
            <strong>⚠️ تحذير النظام:</strong>
            امتدادات PHP المطلوبة غير مفعّلة. يرجى تفعيل <code>php-curl</code> و <code>php-gd</code>.
        </div>
        <?php endif; ?>

        <!-- ============= البطاقة الرئيسية ============= -->
        <section class="hero-card glass" id="mainCard">
            <div class="hero-glow"></div>
            <div class="card-header">
                <h2>
                    <span class="prefix">[ MISSION ]</span>
                    حمّل أي صورة بدقة عالية من الرابط
                </h2>
                <p class="card-sub">الصورة بدقتها الأصلية 100% — بدون أي نقصان</p>
            </div>

            <form id="downloadForm" class="download-form">
                <div class="input-group">
                    <div class="input-wrap">
                        <span class="input-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/>
                                <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>
                            </svg>
                        </span>
                        <input
                            type="text"
                            id="urlInput"
                            name="url"
                            placeholder="الصق رابط الصورة أو معرّفها (مثال: 8655)"
                            autocomplete="off"
                            spellcheck="false"
                            required
                        >
                    </div>

                    <button type="submit" id="submitBtn" class="btn-primary" <?= !$sysOk ? 'disabled' : '' ?>>
                        <span class="btn-text">
                            <svg class="btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                                <polyline points="7 10 12 15 17 10"/>
                                <line x1="12" y1="15" x2="12" y2="3"/>
                            </svg>
                            <span>حمّل الآن</span>
                        </span>
                        <span class="btn-glow"></span>
                    </button>
                </div>

                <div class="options">
                    <div class="format-section">
                        <span class="format-label">صيغة الإخراج:</span>
                        <div class="format-toggle">
                            <!-- WebP: مجاني - افتراضي -->
                            <button type="button" id="fmtWebp" class="fmt-btn active" data-fmt="webp">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
                                WebP
                            </button>
                            <!-- JPG: محمي -->
                            <button type="button" id="fmtJpg" class="fmt-btn fmt-locked" data-fmt="jpg">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M8.5 15V9h3a2.5 2.5 0 0 1 0 5H8.5z"/></svg>
                                JPG
                                <svg class="lock-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                            </button>
                            <!-- PNG: محمي -->
                            <button type="button" id="fmtPng" class="fmt-btn fmt-locked" data-fmt="png">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><polyline points="3 9 9 9 9 3"/></svg>
                                PNG
                                <svg class="lock-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                            </button>
                        </div>
                        <!-- نبذة الصيغة المحددة -->
                        <div class="fmt-desc" id="fmtDesc">
                            <span class="fmt-desc-dot"></span>
                            <span id="fmtDescText">مضغوطة وخفيفة — مثالية للعرض الرقمي والمشاركة عبر الإنترنت</span>
                        </div>
                        <input type="hidden" id="formatInput" name="format" value="webp">
                    </div>

                    <a href="?demo=1" id="demoBtn" class="btn-link" onclick="loadDemo(event)">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                        مثال سريع
                    </a>
                </div>
            </form>

            <!-- ============= شاشة الهاكر ============= -->
            <div id="statusArea" class="hk-terminal" hidden>
                <div class="hk-bar">
                    <div class="hk-dots">
                        <span class="hk-dot r"></span>
                        <span class="hk-dot y"></span>
                        <span class="hk-dot g"></span>
                    </div>
                    <span class="hk-bar-title">NEXUS_DL // BREACH MODULE v2.1</span>
                    <span class="hk-percent" id="statusPercent">0%</span>
                </div>
                <div class="hk-body" id="hkBody">
                    <div class="hk-log-line">
                        <span class="hk-prompt">root@nexus:~#</span>
                        <span class="hk-cmd"> ./extract --target <span id="hkTarget" class="hk-val">unknown</span></span>
                    </div>
                    <div id="hkLines" class="hk-lines"></div>
                    <div class="hk-active-line">
                        <span class="hk-prompt">sys</span>
                        <span class="hk-arrow"> ❯❯ </span>
                        <span class="hk-live-msg" id="statusMessage">INITIALIZING...</span>
                        <span class="hk-cursor-blink">█</span>
                    </div>
                    <div class="hk-detail-line" id="statusDetail"></div>
                </div>
                <div class="hk-progress-wrap">
                    <span class="hk-prog-label">EXTRACTION_PROGRESS</span>
                    <div class="hk-prog-track">
                        <div class="hk-prog-fill" id="progressFill"></div>
                    </div>
                    <span class="hk-prog-pct" id="hkBlockBar">▱▱▱▱▱▱▱▱▱▱</span>
                </div>
                <div class="hk-scanline"></div>
            </div>


            <!-- ============= النتيجة ============= -->
            <div id="resultArea" class="result-area" hidden>
                <div class="result-banner">
                    <div class="result-check">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                    </div>
                    <div class="result-text">
                        <h3>اكتملت المهمة بنجاح</h3>
                        <p>الصورة جاهزة للتنزيل</p>
                    </div>
                </div>

                <div class="result-grid">
                    <div class="result-preview glass-dark">
                        <div class="preview-frame">
                            <img id="resultImage" alt="معاينة" />
                            <div class="preview-overlay">
                                <span>اضغط لتحميل الصورة</span>
                            </div>
                        </div>
                    </div>

                    <div class="result-info">
                        <div class="info-row">
                            <span class="info-label">العنوان</span>
                            <span class="info-value" id="infoTitle">—</span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">الفنّان</span>
                            <span class="info-value" id="infoArtist">—</span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">الأبعاد</span>
                            <span class="info-value" id="infoDimensions">—</span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">حجم الملف</span>
                            <span class="info-value" id="infoSize">—</span>
                        </div>


                        <div class="result-actions">
                            <a id="downloadLink" class="btn-primary btn-large">
                                تنزيل الصورة
                            </a>
                            <button type="button" id="resetBtn" class="btn-ghost">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
                                مهمة جديدة
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </section>





        <!-- ============= الفوتر ============= -->
        <footer class="footer">
            <div class="footer-dev">

                <div class="dev-terminal-body">
                    <div class="dev-output">
                        <span class="dev-comment">// DEVELOPED BY</span>
                    </div>
                    <div class="footer-dev-team">
                        <a href="https://www.fb.com/ahmedsayedaf" target="_blank" rel="noopener" class="dev-link" data-glitch="ENG. Ahmed Sayed Ali">
                            <span class="dev-prompt-arrow">&#x276F;</span>
                            <span class="dev-name">ENG. Ahmed Sayed Ali</span>
                        </a>
                        <span class="dev-connector">&#x2500;&#x2500;</span>
                        <a href="https://www.facebook.com/ethycalhack" target="_blank" rel="noopener" class="dev-link" data-glitch="ENG. Mustafa Mahmoud">
                            <span class="dev-prompt-arrow">&#x276F;</span>
                            <span class="dev-name">ENG. Mustafa Mahmoud</span>
                        </a>
                    </div>
                    <div class="dev-cursor-line">
                        <span class="dev-prompt-sym">$</span>
                        <span class="dev-blink-cursor">&#x258B;</span>
                    </div>
                </div>
                <div class="dev-scanline"></div>
            </div>
        </footer>

    </main>

    <!-- ============= إشعارات Toast ============= -->
    <div id="toastContainer" class="toast-container"></div>

    <!-- ============= مودال الباسوورد ============= -->
    <div id="passModal" class="pass-modal-overlay" hidden>
        <div class="pass-modal">
            <div class="pass-modal-bar">
                <div class="hk-dots">
                    <span class="hk-dot r"></span>
                    <span class="hk-dot y"></span>
                    <span class="hk-dot g"></span>
                </div>
                <span class="pass-modal-title" id="passModalTitle">ACCESS_REQUEST // JPG</span>
                <button class="pass-modal-close" id="passModalClose" title="إلغاء">&#x2715;</button>
            </div>
            <div class="pass-modal-body">
                <div class="pass-fmt-info" id="passFmtInfo">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    <span id="passFmtDesc"></span>
                </div>
                <div class="pass-input-wrap">
                    <span class="pass-prompt">root@nexus:~# enter_key</span>
                    <div class="pass-field">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        <input type="password" id="passModalInput"
                            placeholder="••••••••••••"
                            autocomplete="off" spellcheck="false" dir="ltr">
                    </div>
                </div>
                <div class="pass-contact">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                    <span>لا تملك كلمة المرور؟ </span>
                    <a href="<?= CONTACT_FB ?>" target="_blank" rel="noopener" class="pass-contact-link">تواصل مع المطوّرين</a>
                </div>
            </div>
            <div class="pass-modal-footer">
                <button class="pass-btn-cancel" id="passModalCancel">إلغاء</button>
                <button class="pass-btn-confirm" id="passModalConfirm">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                    تأكيد
                </button>
            </div>
        </div>
    </div>

    <script src="main.js"></script>
</body>
</html>
