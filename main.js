/* ==============================================================
 *  KETEBE NEXUS - Frontend Logic
 * ==============================================================
 *  - SSE للاتصال بـ api.php?action=download
 *  - تحديث شريط التقدم + الرسائل
 *  - عرض النتيجة + Toast notifications
 *  - Particles background canvas
 *  - Boot screen animation
 * ============================================================== */

(function() {
    'use strict';

    // ====== Boot Screen ======
    window.addEventListener('load', () => {
        setTimeout(() => {
            const boot = document.getElementById('bootScreen');
            if (boot) {
                boot.classList.add('hidden');
                setTimeout(() => boot.remove(), 700);
            }
        }, 1500);
    });

    // ====== Particles Background ======
    const canvas = document.getElementById('particles');
    if (canvas && canvas.getContext) {
        const ctx = canvas.getContext('2d');
        let particles = [];
        let mouseX = -1000, mouseY = -1000;

        function resize() {
            canvas.width = window.innerWidth;
            canvas.height = window.innerHeight;
            // عدد الجسيمات حسب حجم الشاشة
            const count = Math.min(60, Math.floor(canvas.width * canvas.height / 25000));
            particles = [];
            for (let i = 0; i < count; i++) {
                particles.push({
                    x: Math.random() * canvas.width,
                    y: Math.random() * canvas.height,
                    vx: (Math.random() - 0.5) * 0.3,
                    vy: (Math.random() - 0.5) * 0.3,
                    size: Math.random() * 1.5 + 0.5,
                    alpha: Math.random() * 0.5 + 0.2,
                    color: Math.random() > 0.5 ? '0, 255, 157' : '0, 229, 255',
                });
            }
        }

        function drawParticles() {
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            particles.forEach(p => {
                p.x += p.vx;
                p.y += p.vy;
                if (p.x < 0 || p.x > canvas.width) p.vx *= -1;
                if (p.y < 0 || p.y > canvas.height) p.vy *= -1;

                // تأثير الماوس - جسيمات تهرب من الماوس
                const dx = p.x - mouseX;
                const dy = p.y - mouseY;
                const dist = Math.sqrt(dx * dx + dy * dy);
                if (dist < 100) {
                    p.x += (dx / dist) * 0.8;
                    p.y += (dy / dist) * 0.8;
                }

                ctx.beginPath();
                ctx.arc(p.x, p.y, p.size, 0, Math.PI * 2);
                ctx.fillStyle = `rgba(${p.color}, ${p.alpha})`;
                ctx.fill();
            });

            // خطوط بين الجسيمات القريبة
            for (let i = 0; i < particles.length; i++) {
                for (let j = i + 1; j < particles.length; j++) {
                    const dx = particles[i].x - particles[j].x;
                    const dy = particles[i].y - particles[j].y;
                    const dist = Math.sqrt(dx * dx + dy * dy);
                    if (dist < 120) {
                        ctx.beginPath();
                        ctx.moveTo(particles[i].x, particles[i].y);
                        ctx.lineTo(particles[j].x, particles[j].y);
                        ctx.strokeStyle = `rgba(0, 255, 157, ${0.15 * (1 - dist / 120)})`;
                        ctx.lineWidth = 0.5;
                        ctx.stroke();
                    }
                }
            }
            requestAnimationFrame(drawParticles);
        }

        resize();
        window.addEventListener('resize', resize);
        window.addEventListener('mousemove', e => { mouseX = e.clientX; mouseY = e.clientY; });
        window.addEventListener('mouseout', () => { mouseX = -1000; mouseY = -1000; });
        drawParticles();
    }

    // ====== Server Clock ======
    const clockEl = document.getElementById('serverTime');
    if (clockEl) {
        setInterval(() => {
            const now = new Date();
            const h = String(now.getHours()).padStart(2, '0');
            const m = String(now.getMinutes()).padStart(2, '0');
            const s = String(now.getSeconds()).padStart(2, '0');
            clockEl.textContent = `${h}:${m}:${s}`;
        }, 1000);
    }

    // ====== Form ======
    const form = document.getElementById('downloadForm');
    const urlInput = document.getElementById('urlInput');
    const submitBtn = document.getElementById('submitBtn');
    const formatInput = document.getElementById('formatInput');
    const statusArea = document.getElementById('statusArea');
    const statusMessage = document.getElementById('statusMessage');
    const statusDetail = document.getElementById('statusDetail');
    const statusPercent = document.getElementById('statusPercent');
    const progressFill = document.getElementById('progressFill');
    const resultArea = document.getElementById('resultArea');
    const resultImage = document.getElementById('resultImage');
    const downloadLink = document.getElementById('downloadLink');
    const resetBtn = document.getElementById('resetBtn');

    // ====== Format Toggle + Password Modal ======
    const passModal       = document.getElementById('passModal');
    const passModalInput  = document.getElementById('passModalInput');
    const passModalTitle  = document.getElementById('passModalTitle');
    const passFmtDesc     = document.getElementById('passFmtDesc');
    const passModalClose  = document.getElementById('passModalClose');
    const passModalCancel = document.getElementById('passModalCancel');
    const passModalConfirm= document.getElementById('passModalConfirm');
    const fmtDescText     = document.getElementById('fmtDescText');

    const SESS_KEY = 'nexus_fmt_pass'; // sessionStorage key

    const FMT_INFO = {
        webp: {
            desc: 'مضغوطة وخفيفة — مثالية للعرض الرقمي والمشاركة عبر الإنترنت',
            locked: false,
        },
        jpg: {
            desc: 'متوافقة عالمياً — مثالية للطباعة والأرشفة الاحترافية عالية الدقة',
            locked: true,
        },
        png: {
            desc: 'ضغط بدون فقدان — مثالية للجودة القصوى والتحرير اللاحق',
            locked: true,
        },
    };

    let pendingFmt = null; // الصيغة التي ينتظر المودال تأكيدها

    function applyFormat(fmt) {
        document.querySelectorAll('.fmt-btn').forEach(b => b.classList.remove('active'));
        const btn = document.querySelector(`.fmt-btn[data-fmt="${fmt}"]`);
        if (btn) btn.classList.add('active');
        formatInput.value = fmt;
        if (fmtDescText) fmtDescText.textContent = FMT_INFO[fmt]?.desc || '';
    }

    function openModal(fmt) {
        pendingFmt = fmt;
        if (passModalTitle) passModalTitle.textContent = `ACCESS_REQUEST // ${fmt.toUpperCase()}`;
        if (passFmtDesc)    passFmtDesc.textContent    = FMT_INFO[fmt]?.desc || '';
        if (passModalInput) passModalInput.value        = '';
        passModal.hidden = false;
        requestAnimationFrame(() => passModal.classList.add('visible'));
        setTimeout(() => passModalInput?.focus(), 120);
    }

    function closeModal(revert = true) {
        passModal.classList.remove('visible');
        setTimeout(() => { passModal.hidden = true; }, 280);
        if (revert) applyFormat('webp'); // يرجع لـ WebP لو ألغى
        pendingFmt = null;
    }

    // معالجة النقر على أزرار الصيغة
    document.querySelectorAll('.fmt-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const fmt = btn.dataset.fmt;
            if (!FMT_INFO[fmt]?.locked) {
                applyFormat(fmt);
                return;
            }
            // صيغة محمية — هل عنده باسوورد محفوظ؟
            const saved = sessionStorage.getItem(SESS_KEY);
            if (saved) {
                applyFormat(fmt); // استخدم المحفوظ
            } else {
                openModal(fmt);
            }
        });
    });

    // تأكيد الباسوورد
    passModalConfirm?.addEventListener('click', () => {
        const val = passModalInput?.value.trim();
        if (!val) { passModalInput?.focus(); return; }
        sessionStorage.setItem(SESS_KEY, val);
        applyFormat(pendingFmt);
        closeModal(false);
    });

    // Enter في خانة الباسوورد
    passModalInput?.addEventListener('keydown', e => {
        if (e.key === 'Enter') passModalConfirm?.click();
    });

    // إلغاء
    passModalClose?.addEventListener('click',  () => closeModal(true));
    passModalCancel?.addEventListener('click', () => closeModal(true));
    passModal?.addEventListener('click', e => { if (e.target === passModal) closeModal(true); });

    // تطبيق الحالة الافتراضية (WebP)
    applyFormat('webp');

    if (!form) return;

    form.addEventListener('submit', e => {
        e.preventDefault();
        const url = urlInput.value.trim();
        if (!url) {
            showToast('الرجاء إدخال رابط أو معرف الصورة', 'error');
            urlInput.focus();
            return;
        }
        startDownload(url);
    });

    resetBtn.addEventListener('click', () => {
        form.reset();
        urlInput.value = '';
        statusArea.hidden = true;
        resultArea.hidden = true;
        submitBtn.classList.remove('loading');
        submitBtn.disabled = false;
        urlInput.focus();
    });

    // ====== Demo button ======
    window.loadDemo = function(e) {
        e.preventDefault();
        urlInput.value = '8655';
        urlInput.focus();
        showToast('تم تحميل مثال: صورة رقم 8655', 'info');
    };

    // ====== Hacker Terminal helpers ======
    const hkLines   = document.getElementById('hkLines');
    const hkTarget  = document.getElementById('hkTarget');
    const hkBlockBar = document.getElementById('hkBlockBar');
    let hkLogCount  = 0;

    const HK_PREFIXES = [
        '[SYS]', '[NET]', '[DZI]', '[IMG]', '[MEM]', '[PKT]', '[EXT]',
    ];

    function hkAddLine(text, type = 'info') {
        if (!hkLines) return;
        const div = document.createElement('div');
        div.className = 'hk-line-item';
        const prefix = HK_PREFIXES[hkLogCount % HK_PREFIXES.length];
        hkLogCount++;
        div.innerHTML = `<span class="hl-${type}">${prefix}</span> ${escHtml(text)}`;
        hkLines.appendChild(div);
        // keep last 4 lines only
        while (hkLines.children.length > 4) hkLines.removeChild(hkLines.firstChild);
    }

    function hkUpdateBar(pct) {
        if (!hkBlockBar) return;
        const filled = Math.round(pct / 10);
        hkBlockBar.textContent = '▰'.repeat(filled) + '▱'.repeat(10 - filled);
    }

    function escHtml(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

    function hkReset() {
        if (hkLines) hkLines.innerHTML = '';
        if (hkTarget) hkTarget.textContent = 'unknown';
        if (hkBlockBar) hkBlockBar.textContent = '▱▱▱▱▱▱▱▱▱▱';
        hkLogCount = 0;
    }

    // ====== SSE Download ======
    function startDownload(url) {
        const format  = formatInput ? formatInput.value : 'webp';
        const passVal = (FMT_INFO[format]?.locked) ? (sessionStorage.getItem(SESS_KEY) || '') : '';
        const params  = new URLSearchParams({ action: 'download', url, format, quality: '100' });
        if (passVal) params.set('fmt_pass', passVal);

        // إظهار شاشة الهاكر
        hkReset();
        if (hkTarget) hkTarget.textContent = url.length > 28 ? url.slice(0, 28) + '…' : url;
        statusArea.hidden = false;
        resultArea.hidden = true;
        submitBtn.classList.add('loading');
        submitBtn.disabled = true;
        statusMessage.textContent = 'ESTABLISHING CONNECTION...';
        statusDetail.textContent  = '';
        statusPercent.textContent = '0%';
        progressFill.style.width  = '0%';
        hkUpdateBar(0);

        const es = new EventSource('api.php?' + params.toString());

        es.addEventListener('start', e => {
            const data = JSON.parse(e.data);
            statusMessage.textContent = 'CONNECTION_ESTABLISHED';
            hkAddLine('Stream open — job: ' + data.job_id.slice(0,12), 'ok');
            statusDetail.textContent = '> JOB_ID: ' + data.job_id.slice(0, 12) + '...';
        });

        es.addEventListener('progress', e => {
            const data = JSON.parse(e.data);
            const pct  = data.total > 0 ? Math.round(data.done * 100 / data.total) : (data.percent || 0);
            statusMessage.textContent = data.message || 'PROCESSING...';
            statusPercent.textContent = pct + '%';
            progressFill.style.width  = pct + '%';
            hkUpdateBar(pct);
            if (data.total > 0) {
                statusDetail.textContent = `> CHUNKS: ${data.done}/${data.total}`;
                if (data.done % 5 === 0 || data.done === 1) {
                    hkAddLine(`chunk ${data.done}/${data.total} — ${pct}% complete`, 'info');
                }
            }
        });

        es.addEventListener('done', e => {
            const data = JSON.parse(e.data);
            es.close();
            hkAddLine('EXTRACTION COMPLETE — access granted ✓', 'ok');
            progressFill.style.width  = '100%';
            statusPercent.textContent = '100%';
            hkUpdateBar(100);
            setTimeout(() => {
                showResult(data.result);
                submitBtn.classList.remove('loading');
                submitBtn.disabled = false;
                statusArea.hidden  = true;
                showToast('✓ تم تنزيل الصورة بنجاح!', 'success');
            }, 600);
        });

        es.addEventListener('error', e => {
            if (e.data) {
                try {
                    const data = JSON.parse(e.data);
                    showToast('✗ ' + (data.error || 'حدث خطأ'), 'error');
                    hkAddLine('ERROR: ' + (data.error || 'unknown'), 'warn');
                } catch (_) {
                    showToast('✗ حدث خطأ في الاتصال', 'error');
                }
            } else {
                if (es.readyState === EventSource.CLOSED) return;
                showToast('✗ انقطع الاتصال بالخادم', 'error');
                hkAddLine('CONNECTION LOST — abort', 'warn');
            }
            es.close();
            submitBtn.classList.remove('loading');
            submitBtn.disabled = false;
            statusArea.hidden  = true;
        });

        es.addEventListener('close', () => es.close());

        setTimeout(() => {
            if (es.readyState !== EventSource.CLOSED) { /* still running */ }
        }, 280000);
    }

    function updateProgress(done, total, message) {
        statusMessage.textContent = message;
        if (total > 0) {
            statusDetail.textContent = `${done} / ${total} بلاطة`;
        }
    }

    function showResult(result) {
        resultArea.hidden = false;

        // المعاينة: عبر serve.php (لا يحذف الملف)
        const previewUrl = result.url + '&preview=1';
        resultImage.src  = previewUrl;

        // التنزيل: عبر serve.php?dl=1 (يرسل Content-Disposition ويحذف الملف بعده)
        downloadLink.href     = result.url + '&dl=1';
        downloadLink.removeAttribute('download'); // serve.php يتولى اسم الملف

        // البيانات
        document.getElementById('infoTitle').textContent = result.title || '—';
        document.getElementById('infoArtist').textContent = result.artist || '—';
        const dims = (result.width && result.height) ? `${result.width} × ${result.height} px` : '—';
        document.getElementById('infoDimensions').textContent = dims;
        document.getElementById('infoSize').textContent = result.size_human || '—';

        if (result.failed_tiles && result.failed_tiles > 0) {
            showToast(`⚠️ ${result.failed_tiles} جزء لم يتم تحميله بالكامل`, 'info');
        }

        // التمرير للنتيجة
        resultArea.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    // ====== Toast ======
    function showToast(message, type = 'info') {
        const container = document.getElementById('toastContainer');
        if (!container) return;

        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;

        const icons = {
            success: '✓',
            error: '✗',
            info: 'ℹ',
        };

        toast.innerHTML = `<span style="font-size:1.2rem;color:var(--neon-${type === 'success' ? 'green' : type === 'error' ? 'pink' : 'cyan'})">${icons[type] || 'ℹ'}</span><span>${message}</span>`;
        container.appendChild(toast);

        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transform = 'translateX(-30px)';
            toast.style.transition = 'all 0.3s ease';
            setTimeout(() => toast.remove(), 300);
        }, 4500);
    }

    // ====== Click على معاينة الصورة = تنزيل ======
    if (resultImage) {
        resultImage.parentElement.addEventListener('click', () => {
            downloadLink.click();
        });
    }

    // ====== Shortcuts ======
    document.addEventListener('keydown', e => {
        // Ctrl/Cmd + Enter = submit
        if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
            e.preventDefault();
            if (!submitBtn.disabled) form.requestSubmit();
        }
        // Esc = reset
        if (e.key === 'Escape' && !resultArea.hidden) {
            resetBtn.click();
        }
    });

    // ====== Focus على input عند فتح الصفحة ======
    setTimeout(() => {
        if (urlInput && !urlInput.value) urlInput.focus();
    }, 1800);

    // ====== Console ASCII art ======
    console.log('%c NEXUS DL ', 'background: #00ff9d; color: #02110a; font-size: 16px; font-weight: bold; padding: 4px 8px;');
    console.log('%c Gaming Dark Edition v2.1 ', 'color: #00e5ff; font-size: 11px; font-family: monospace;');
    console.log('%c تم تحويل المنطق من بايثون إلى PHP ', 'color: #8a99b8; font-size: 11px; font-family: monospace;');
})();
