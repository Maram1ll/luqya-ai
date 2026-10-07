<?php
require_once __DIR__ . '/../config/db.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/matching.php';
$user = getCurrentUser();
$isSec = isSecurityOfficer();

// الصفحة الحالية لتمييز الرابط النشط
$currentScript   = basename($_SERVER['PHP_SELF'] ?? '');
$isSecDashboard  = ($currentScript === 'security_dashboard.php');
$isProfilePage   = ($currentScript === 'profile.php');
$isHomePage      = ($currentScript === 'index.php');

// عداد عناصر المراجعة/المعلقة (بانتظار الاستلام أو التوثيق + مقترحات المطابقة) — من نفس مصدر القائمة
$secPendingCount = 0;
if ($isSec) {
    $secPdo = getDBConnection();
    if ($secPdo) {
        try {
            // عدّ للشارة فقط: الطبقة الدلالية لا تغيّر العدد إطلاقاً، لكن تشغيلها
            // هنا كان يجعل كل صفحة للضابط تستدعي Gemini متزامناً (≈5.6 ثانية)
            // قبل أن تُرسم أصلاً — وheader.php يُحمَّل مع كل صفحة بما فيها
            // index.php وprofile.php. المسار المحلي هنا هو الصحيح.
            $secPendingCount = count(buildPendingReviewItems($secPdo, 15, 50, false));
        } catch (Throwable $e) {
            $secPendingCount = 0;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>لُقيا AI - نظام المفقودات والمعثورات الذكي بالجامعة</title>
  <meta name="description" content="لُقيا AI - منصة المفقودات والمعثورات الذكية بالجامعة المدعومة بالماسح الضوئي للبطاقات ورؤية الذكاء الاصطناعي.">
  <script>
    // ضمان فتح الصفحة من الأعلى دائماً: تعطيل استعادة موضع التمرير السابق من المتصفح،
    // وإعادة تثبيت موضع التمرير (scrollTop = 0) في جميع مراحل التحميل.
    if ('scrollRestoration' in history) {
      history.scrollRestoration = 'manual';
    }
    var lockPageTop = function () {
      if (location.hash) return;
      window.scrollTo(0, 0);
      if (document.documentElement) document.documentElement.scrollTop = 0;
      if (document.body) document.body.scrollTop = 0;
    };
    window.addEventListener('pageshow', lockPageTop);
    document.addEventListener('DOMContentLoaded', lockPageTop);
    window.addEventListener('load', lockPageTop);
  </script>
  <link rel="icon" type="image/svg+xml" href="assets/images/luqya-favicon.svg">
  <link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">
  <?php // كاش-بريكر مبني على تاريخ تعديل الملف لا على الوقت الحالي: كان time() يجعل
        // العنوان يتغيّر في كل طلب، فيُعاد تنزيل 79.5KB من CSS بلا فائدة. ?>
  <link rel="stylesheet" href="assets/css/style.css?v=<?php
      $cssFile = __DIR__ . '/../assets/css/style.css';
      echo filemtime($cssFile) ?: '1';
  ?>">

  <script>
    window.IS_LOGGED_IN = <?= $user ? 'true' : 'false'; ?>;
    window.IS_SECURITY = <?= $isSec ? 'true' : 'false'; ?>;
    window.LUQYA_CSRF = <?= json_encode(csrfToken(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
  </script>
  <script>
    // تحميل الإشعارات من قاعدة البيانات ثم استطلاعها دورياً كل 15 ثانية.
    window.loadNotifications = function () {
      return fetch('api/notifications.php')
        .then(r => r.json())
        .then(data => {
          const badge = document.getElementById('notifCount');
          const list  = document.getElementById('notifList');
          if (badge) {
            const n = data.unread_count || 0;
            badge.textContent = n;
            badge.style.display = n > 0 ? '' : 'none';
          }
          if (!list) return;
          list.innerHTML = '';
          const items = Array.isArray(data.notifications) ? data.notifications : [];
          if (items.length === 0) {
            list.innerHTML = '<div class="notif-item"><div class="notif-msg" style="color:var(--text-muted);">'
              + (window.IS_LOGGED_IN ? 'لا توجد إشعارات جديدة حالياً.' : 'سجّل الدخول لعرض إشعارات بلاغاتك ومطابقاتك.')
              + '</div></div>';
            return;
          }
          items.forEach(function (n) {
            const item = document.createElement('div');
            item.className = 'notif-item' + (Number(n.is_read) === 0 ? ' unread' : '');
            const title = document.createElement('div');
            title.className = 'notif-title';
            const icon = document.createElement('span');
            icon.className = 'notif-icon' + (n.type === 'match_found' ? ' notif-icon-match' : '');
            const iconI = document.createElement('i');
            iconI.setAttribute('aria-hidden', 'true');
            iconI.className = n.type === 'match_found' ? 'fa-solid fa-wand-magic-sparkles'
              : (n.type === 'resolved' ? 'fa-solid fa-circle-check' : 'fa-solid fa-bell');
            icon.appendChild(iconI);
            const tspan = document.createElement('span');
            tspan.textContent = String(n.title || 'إشعار').replace(/^[\u{1F000}-\u{1FAFF}\u{2600}-\u{27BF}\u{FE0F}\s]+/u, '');
            title.appendChild(icon);
            title.appendChild(tspan);
            const msg = document.createElement('div');
            msg.className = 'notif-msg';
            msg.textContent = n.message || '';
            const time = document.createElement('div');
            time.className = 'notif-time';
            time.textContent = n.created_at || '';
            item.appendChild(title);
            item.appendChild(msg);
            item.appendChild(time);
            list.appendChild(item);
          });
        })
        .catch(function () {});
    };

    window.markNotificationsRead = function () {
      fetch('api/notifications.php?action=mark_read', {
        method: 'POST',
        cache: 'no-store',
        headers: { 'X-CSRF-Token': window.LUQYA_CSRF }
      })
        .then(function () { window.loadNotifications(); })
        .catch(function () {});
    };

    document.addEventListener('DOMContentLoaded', function () {
      // فتح الجرس = تعليم الكل كمقروء ثم تحديث القائمة
      const bellBtn = document.getElementById('notifBellBtn');
      if (bellBtn) {
        bellBtn.addEventListener('click', function () {
          window.markNotificationsRead();
        });
      }
      window.loadNotifications();
      setInterval(window.loadNotifications, 15000);

      // عداد الأغراض المعلقة لضابط الأمن: تحديث مباشر كل 15 ثانية من قاعدة البيانات
      if (window.IS_SECURITY) {
        const refreshPendingCount = function () {
          fetch('api/security.php?action=pending_count', { cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
              const badge = document.getElementById('secPendingCount');
              if (!badge) return;
              const n = (d && typeof d.count === 'number') ? d.count : 0;
              badge.textContent = n;
              badge.style.display = n > 0 ? '' : 'none';
            })
            .catch(function () {});
        };
        refreshPendingCount();
        setInterval(refreshPendingCount, 15000);
      }
    });
  </script>
  <script src="assets/js/toast.js" defer></script>
  <script src="assets/js/ocr_ai.js" defer></script>
  <script src="assets/js/app.js" defer></script>
</head>
<body>

<!-- شاشة البداية (Splash Screen) — نمط إطلاق X/Twitter -->
<div class="splash-screen" id="appSplash" aria-hidden="true">
  <div class="splash-inner">
    <img class="splash-logo" src="assets/images/luqya-magnifier.svg" alt="" width="120" height="129">
    <div class="splash-brand">
      <span class="splash-name notranslate" translate="no">لُقيا</span>
      <strong class="splash-ai notranslate" translate="no">AI</strong>
    </div>
  </div>
</div>
<script>
(function () {
  var splash = document.getElementById('appSplash');
  if (!splash) return;

  // تحديد نوع التحميل: 'reload' عند التحديث الصريح من المتصفح (F5 / Ctrl+F5)
  // أو 'navigate' عند التنقل بين صفحات الموقع.
  var navType = 'navigate';
  try {
    var navEntries = performance.getEntriesByType('navigation');
    navType = navEntries.length ? navEntries[0].type : 'navigate';
  } catch (err) {
    try {
      if (performance.navigation && performance.navigation.type === 1) navType = 'reload';
      else if (performance.navigation && performance.navigation.type === 2) navType = 'back_forward';
    } catch (err2) {}
  }

  // تُعرض الشاشة فوراً عند أول تحميل للجلسة فقط، وتُتذكر عبر sessionStorage
  // كي لا تظهر مجدداً أثناء التنقل بين الصفحات الداخلية، مع استثناء التحديث
  // الصريح (التحميل) الذي يعيد تشغيلها كما يطلب المستخدم.
  var SPLASH_KEY = 'luqya_splash_shown';
  var alreadyShown = false;
  try { alreadyShown = sessionStorage.getItem(SPLASH_KEY) === '1'; } catch (err) {}

  if (navType !== 'reload' && alreadyShown) {
    if (splash.parentNode) splash.parentNode.removeChild(splash);
    return;
  }
  try { sessionStorage.setItem(SPLASH_KEY, '1'); } catch (err) {}

  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var FADE_MS = reduce ? 150 : 550;

  // تثبيت موضع الصفحة في الأعلى (scrollTop = 0) طوال مدة الشاشة،
  // حتى لا تظهر لوحة العرض من منتصف الصفحة أو أسفلها عند التلاشي.
  var lockTop = function () {
    window.scrollTo(0, 0);
    if (document.documentElement) document.documentElement.scrollTop = 0;
    if (document.body) document.body.scrollTop = 0;
  };
  document.documentElement.style.scrollBehavior = 'auto';
  var lockTimer = setInterval(lockTop, 200);

  // تظهر الشاشة فوراً وتدوم 3 ثوانٍ ثم تتلاشى بسلاسة لتكشف لوحة العرض
  setTimeout(function () {
    clearInterval(lockTimer);
    lockTop();
    splash.classList.add('is-leaving');
    setTimeout(function () {
      if (splash.parentNode) splash.parentNode.removeChild(splash);
      document.documentElement.style.scrollBehavior = '';
    }, FADE_MS);
  }, 3000);
})();
</script>

<header class="navbar">
  <a href="index.php" class="navbar-brand" aria-label="لُقيا AI">
    <!-- Brand wordmark (right side in RTL) -->
    <span class="brand-ar notranslate" translate="no">لُقيا</span>
    <!-- Luqya AI animated icon (left side in RTL) — ported from LuqyaLogo React component -->
    <svg class="luqya-logo luqya-logo-nav" viewBox="196 27 162 174" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="لُقيا AI logo">
      <defs>
        <radialGradient id="lqGlow" cx="50%" cy="45%" r="55%">
          <stop offset="0%" stop-color="#7DD3FC" stop-opacity="0.3"/>
          <stop offset="55%" stop-color="#0a4a7a" stop-opacity="0.15"/>
          <stop offset="100%" stop-color="#001423" stop-opacity="0.05"/>
        </radialGradient>
        <linearGradient id="lqFrame" x1="0%" y1="0%" x2="100%" y2="100%">
          <stop offset="0%" stop-color="#0077d0"/>
          <stop offset="45%" stop-color="#0056A3"/>
          <stop offset="100%" stop-color="#003366"/>
        </linearGradient>
        <linearGradient id="lqHandle" x1="0%" y1="0%" x2="100%" y2="0%">
          <stop offset="0%" stop-color="#0056A3"/>
          <stop offset="100%" stop-color="#002a4d"/>
        </linearGradient>
        <linearGradient id="lqSweep" x1="0%" y1="0%" x2="100%" y2="0%">
          <stop offset="0%" stop-color="#ffffff" stop-opacity="0"/>
          <stop offset="50%" stop-color="#ffffff" stop-opacity="0.55"/>
          <stop offset="100%" stop-color="#ffffff" stop-opacity="0"/>
        </linearGradient>
        <filter id="lqGlowF" x="-60%" y="-60%" width="220%" height="220%">
          <feGaussianBlur stdDeviation="3.2" result="b"/>
          <feMerge><feMergeNode in="b"/><feMergeNode in="SourceGraphic"/></feMerge>
        </filter>
        <filter id="lqAiGlow" x="-80%" y="-80%" width="260%" height="260%">
          <feGaussianBlur stdDeviation="4.5" result="b"/>
          <feMerge><feMergeNode in="b"/><feMergeNode in="SourceGraphic"/></feMerge>
        </filter>
        <clipPath id="lqLensClip"><circle cx="100" cy="100" r="62"/></clipPath>
        <style>
          #lq-root .circuit { stroke: #7DD3FC; stroke-width: 1.6; fill: none; stroke-linecap: round; stroke-linejoin: round; }
          #lq-root .node { fill: #7DD3FC; }
          #lq-root .ai-text { font-family: "Inter", var(--font-family, "Segoe UI", Arial, sans-serif); font-weight: 900; fill: #FFFFFF; }
          @keyframes lqPulseA { 0%, 100% { opacity: 0.55; } 50% { opacity: 1; } }
          @keyframes lqPulseB { 0%, 100% { opacity: 0.4; } 50% { opacity: 0.95; } }
          @keyframes lqAiMark { 0%, 100% { opacity: 0.85; } 50% { opacity: 1; } }
          #lq-root .pulse { animation: lqPulseA 2.6s ease-in-out infinite; }
          #lq-root .pulse-2 { animation: lqPulseB 3.4s ease-in-out infinite; }
          #lq-root .pulse-3 { animation: lqPulseA 3.0s ease-in-out infinite; animation-delay: -1.2s; }
          #lq-root .ai-pulse { animation: lqAiMark 2.2s ease-in-out infinite; }
          @media (prefers-reduced-motion: reduce) {
            #lq-root .pulse, #lq-root .pulse-2, #lq-root .pulse-3, #lq-root .ai-pulse { animation: none; }
          }
        </style>
      </defs>

      <g id="lq-root">
        <!-- Magnifying Glass Icon (compact, immediately LEFT of the wordmark, RTL) -->
        <g id="magnifier" transform="translate(185 15) scale(0.85)">
          <!-- Handle -->
          <g filter="url(#lqGlowF)" class="pulse-2">
            <rect x="150" y="150" width="62" height="18" rx="9" fill="url(#lqHandle)" transform="rotate(45 150 150)"/>
            <rect x="150" y="150" width="62" height="6" rx="3" fill="#7DD3FC" opacity="0.5" transform="rotate(45 150 150)"/>
          </g>

          <!-- Frame ring -->
          <circle cx="100" cy="100" r="68" fill="none" stroke="url(#lqFrame)" stroke-width="10"/>
          <circle cx="100" cy="100" r="68" fill="none" stroke="#7DD3FC" stroke-width="1.2" opacity="0.5"/>
          <circle cx="100" cy="100" r="74" fill="none" stroke="#0056A3" stroke-width="2" opacity="0.5"/>

          <!-- Lens background -->
          <circle cx="100" cy="100" r="62" fill="#001a2e"/>
          <circle cx="100" cy="100" r="62" fill="url(#lqGlow)"/>

          <!-- Circuit pattern inside lens -->
          <g clip-path="url(#lqLensClip)" filter="url(#lqGlowF)">
            <g class="pulse">
              <path class="circuit" d="M100 38 L100 58 L80 78 L80 100"/>
              <path class="circuit" d="M100 38 L100 58 L120 78 L120 100"/>
              <path class="circuit" d="M38 100 L58 100 L78 80 L100 80"/>
              <path class="circuit" d="M162 100 L142 100 L122 80 L100 80"/>
              <path class="circuit" d="M100 162 L100 142 L80 122 L80 100"/>
              <path class="circuit" d="M100 162 L100 142 L120 122 L120 100"/>
              <path class="circuit" d="M48 52 L72 72 L72 90"/>
              <path class="circuit" d="M152 52 L128 72 L128 90"/>
              <path class="circuit" d="M48 148 L72 128 L72 110"/>
              <path class="circuit" d="M152 148 L128 128 L128 110"/>
              <path class="circuit" d="M70 100 L90 100"/>
              <path class="circuit" d="M110 100 L130 100"/>
            </g>
            <g class="pulse-3">
              <path class="circuit" d="M60 60 L84 84"/>
              <path class="circuit" d="M140 60 L116 84"/>
              <path class="circuit" d="M60 140 L84 116"/>
              <path class="circuit" d="M140 140 L116 116"/>
            </g>
            <g class="node pulse-2">
              <circle cx="100" cy="38" r="2.4"/>
              <circle cx="100" cy="162" r="2.4"/>
              <circle cx="38" cy="100" r="2.4"/>
              <circle cx="162" cy="100" r="2.4"/>
              <circle cx="80" cy="78" r="1.8"/>
              <circle cx="120" cy="78" r="1.8"/>
              <circle cx="80" cy="122" r="1.8"/>
              <circle cx="120" cy="122" r="1.8"/>
              <circle cx="72" cy="72" r="1.6"/>
              <circle cx="128" cy="72" r="1.6"/>
              <circle cx="72" cy="128" r="1.6"/>
              <circle cx="128" cy="128" r="1.6"/>
            </g>
          </g>

          <!-- AI letters in lens center -->
          <ellipse cx="100" cy="100" rx="48" ry="31" fill="#001a2e" opacity="0.5"/>
          <g class="ai-pulse" filter="url(#lqAiGlow)">
            <text x="100" y="123" text-anchor="middle" class="ai-text" font-size="64">AI</text>
          </g>

          <!-- Light sweep across lens -->
          <g clip-path="url(#lqLensClip)">
            <rect x="-30" y="38" width="60" height="124" fill="url(#lqSweep)" opacity="0.5">
              <animate attributeName="x" from="-80" to="200" dur="4.5s" repeatCount="indefinite"/>
            </rect>
          </g>

          <!-- Lens rim highlight -->
          <circle cx="100" cy="100" r="62" fill="none" stroke="#7DD3FC" stroke-width="0.8" opacity="0.3"/>
        </g>
      </g>
    </svg>
  </a>

  <ul class="nav-menu">
    <?php if (!$isSec): ?>
      <li><a href="index.php" class="nav-link<?= $isHomePage ? ' active' : ''; ?>">الرئيسية</a></li>
    <?php endif; ?>

    <?php if ($isSec): ?>
      <!-- قائمة ضابط الأمن: روابط العمل فقط (بدون الرئيسية والأسئلة الشائعة وحوافز الطلاب) -->
      <li>
        <a href="security_dashboard.php" class="nav-link<?= $isSecDashboard ? ' active' : ''; ?>" style="color: var(--mint-success); font-weight: 700; display: flex; align-items: center; gap: 6px;">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
          لوحة تحكم الأمن والمخزون
          <span class="sec-pending-badge" id="secPendingCount"<?= $secPendingCount > 0 ? '' : ' style="display:none;"'; ?>><?= $secPendingCount; ?></span>
        </a>
      </li>
      <li><a href="profile.php" class="nav-link<?= $isProfilePage ? ' active' : ''; ?>">السجلات والنشاط</a></li>
    <?php else: ?>
      <!-- قائمة الطالب والزائر العادي -->
      <li><a href="javascript:void(0)" onclick="checkAuthAndOpenModal('modalReportLost')" class="nav-link">الإبلاغ عن مفقود</a></li>
      <li><a href="javascript:void(0)" onclick="checkAuthAndOpenModal('modalReportFound')" class="nav-link">الإبلاغ عن معثور عليه</a></li>
      <?php if ($user): ?>
        <li><a href="profile.php" class="nav-link<?= $isProfilePage ? ' active' : ''; ?>">ملفي والبلاغات</a></li>
      <?php endif; ?>
    <?php endif; ?>

    <?php if (!$isSec): ?>
      <li><a href="index.php#faq-section" class="nav-link">الأسئلة الشائعة</a></li>
    <?php endif; ?>
  </ul>

  <div class="nav-actions">
    <!-- Notification Bell with SVG -->
    <div class="notif-wrapper">
      <button class="notif-bell-btn" id="notifBellBtn" title="الإشعارات" style="display:flex;align-items:center;justify-content:center;">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>
        </svg>
        <span class="notif-badge" id="notifCount" style="display:none;">0</span>
      </button>
      <div class="notif-dropdown" id="notifDropdown">
        <div class="notif-header">
          <span>الإشعارات</span>
          <small style="font-size: 0.75rem; color: var(--mint-success);" translate="no" class="notranslate">لُقيا AI</small>
        </div>
        <div class="notif-list" id="notifList">
          <div class="notif-item">
            <div class="notif-msg" style="color:var(--text-muted);">جاري تحميل الإشعارات...</div>
          </div>
        </div>
      </div>
    </div>

    <!-- User Auth State Links with SVG Icons -->
    <?php if ($user): ?>
      <span class="nav-user-info">
        <svg class="user-avatar-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        <span class="nav-user-name"><?= htmlspecialchars($user['full_name']); ?> <span class="nav-user-id">(<?= htmlspecialchars($user['university_id']); ?>)</span></span>
      </span>
      <form method="post" action="api/auth.php?action=logout" class="d-inline" style="margin:0;display:inline-block;">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
        <button type="submit" class="btn btn-sm btn-secondary">تسجيل الخروج</button>
      </form>
    <?php else: ?>
      <a href="login.php" class="btn btn-sm btn-outline">تسجيل الدخول</a>
      <a href="register.php" class="btn btn-sm btn-primary">إنشاء حساب</a>
    <?php endif; ?>
  </div>
</header>

<!-- LOGIN REQUIRED MODAL DIALOG WITH SVG -->
<div class="modal-overlay" id="modalLoginRequired">
  <div class="modal-container" style="max-width: 420px; text-align: center; padding: 2rem; border-radius: 16px;">
    <div style="width:60px;height:60px;background:rgba(0,168,181,0.1);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 12px;">
      <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="var(--navy-primary)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>
      </svg>
    </div>
    <h3 style="margin: 0 0 8px 0; color: var(--navy-primary); font-size: 1.3rem;">تسجيل الدخول مطلوب</h3>
    <p style="font-size: 0.9rem; color: var(--text-muted); margin-bottom: 1.5rem;">
      يجب عليك تسجيل الدخول بحسابك الجامعي للتمكن من تقديم البلاغات ومتابعة المفقودات.
    </p>
    <div style="display: flex; gap: 10px; justify-content: center;">
      <a href="login.php" class="btn btn-primary">تسجيل الدخول الآن</a>
      <a href="register.php" class="btn btn-outline">إنشاء حساب جديد</a>
    </div>
    <div style="margin-top: 1rem;">
      <button class="btn btn-secondary btn-sm" onclick="closeModal('modalLoginRequired')">إلغاء</button>
    </div>
  </div>
</div>
