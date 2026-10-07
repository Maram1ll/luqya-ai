<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/matching.php';

// فصل الأدوار: الرئيسية مخصصة للطلاب والزوار، وضباط الأمن ينتقلون للوحة الأمن.
if (isSecurityOfficer()) { header('Location: security_dashboard.php'); exit; }

// ══════════════════════════════════════════════════════════════════
// TEMPORARY DEPLOYMENT DEBUG BLOCK — safe to delete once the database
// connects. Shows the exact PDO/MySQL error instead of a blank page.
//
//   index.php?dbdebug=<token>
//
// The token is LUQYA_SETUP_CHECK_KEY from config/config.local.php.
// Without the right token nothing is printed at all, so this cannot be
// used to probe the server by visitors. A password or API key is NEVER
// printed here.
// ══════════════════════════════════════════════════════════════════
(function () {
    $expected = (string) luqyaLocalConfig('LUQYA_SETUP_CHECK_KEY', '');
    $given    = (string) ($_GET['dbdebug'] ?? '');
    if ($expected === '' || $given === '' || !hash_equals($expected, $given)) {
        return;
    }

    $pdo  = getDBConnection();
    $err  = getDBLastError();
    $safe = static function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };

    echo '<!doctype html><meta charset="utf-8"><title>DB debug</title>';
    echo '<body style="font:13px/1.6 ui-monospace,Consolas,monospace;margin:20px;background:#fbfbfd;color:#1a1a1a;direction:ltr">';
    echo '<h1 style="font:700 17px system-ui;color:#1B365D">Luqya AI — database debug</h1>';

    $line = static function ($k, $v, $color = '#333') {
        echo '<div style="padding:3px 0"><b style="color:#666">' . $v . '</b></div>';
    };

    echo '<div style="background:#fff;border:1px solid #e3e3e8;border-radius:8px;padding:14px 16px;margin:12px 0">';
    echo '<div style="font-weight:700;margin-bottom:8px">Environment</div>';
    echo '<div>PHP version     : ' . $safe(PHP_VERSION) . (version_compare(PHP_VERSION, '8.0', '<') ? '  <span style="color:#b42318;font-weight:700">TOO OLD — this app needs PHP 8.0+</span>' : '  <span style="color:#0f7b46">ok</span>') . '</div>';
    echo '<div>pdo_mysql ext   : ' . (extension_loaded('pdo_mysql') ? 'loaded' : '<span style="color:#b42318;font-weight:700">MISSING</span>') . '</div>';
    echo '<div>.env on server  : ' . (is_file(__DIR__ . '/.env') ? '<span style="color:#b42318;font-weight:700">YES — it overrides config.local.php, delete it</span>' : 'no') . '</div>';
    echo '</div>';

    echo '<div style="background:#fff;border:1px solid #e3e3e8;border-radius:8px;padding:14px 16px;margin:12px 0">';
    echo '<div style="font-weight:700;margin-bottom:8px">Effective settings (password never shown)</div>';
    echo '<div>DB_HOST : ' . $safe(DB_HOST) . '</div>';
    echo '<div>DB_PORT : ' . $safe(DB_PORT) . '</div>';
    echo '<div>DB_USER : ' . $safe(DB_USER) . '</div>';
    echo '<div>DB_NAME : ' . $safe(DB_NAME) . '</div>';
    $pw = (string) DB_PASS;
    $ws = '';
    if ($pw !== '' && $pw !== trim($pw)) {
        $ws = '  <span style="color:#b42318;font-weight:700">WARNING: leading/trailing '
            . 'whitespace &mdash; this can NEVER authenticate</span>';
    } elseif ($pw === '') {
        $ws = '  <span style="color:#b42318;font-weight:700">WARNING: empty</span>';
    }
    echo '<div>DB_PASS : <i>hidden</i> &middot; ' . strlen($pw) . ' chars' . $ws . '</div>';
    echo '</div>';

    echo '<div style="background:#fff;border:1px solid #e3e3e8;border-radius:8px;padding:14px 16px;margin:12px 0">';
    echo '<div style="font-weight:700;margin-bottom:8px">Result</div>';
    if ($pdo) {
        echo '<div style="color:#0f7b46;font-weight:700">CONNECTED</div>';
        try {
            echo '<div>server version : ' . $safe($pdo->query('SELECT VERSION()')->fetchColumn()) . '</div>';
            $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
            echo '<div>tables         : ' . count($tables) . '</div>';
            foreach ($tables as $t) {
                echo '<div style="padding-left:12px">' . $safe($t) . ' → ' . (int) $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn() . ' rows</div>';
            }
        } catch (Throwable $e) {
            echo '<div style="color:#b42318">connected, but a query failed: ' . $safe($e->getMessage()) . '</div>';
        }
    } else {
        echo '<div style="color:#b42318;font-weight:700">CONNECTION FAILED</div>';
        echo '<div style="margin-top:8px;white-space:pre-wrap">'
           . $safe($err['message'] ?? 'no error captured') . '</div>';
        $hints = [
            '1045' => 'Wrong username or password. On InfinityFree the MySQL password is separate from the hosting login password.',
            '1044' => 'This user is not attached to that database. Add it with all privileges.',
            '1049' => 'Database does not exist. Check DB_NAME (it must be prefixed with your account name).',
            '2002' => 'Cannot reach the host. Wrong DB_HOST, or MySQL is still provisioning.',
            '2003' => 'Cannot reach the host. Wrong DB_HOST/DB_PORT.',
            '403'  => 'Host resolved but connection refused — usually a wrong hostname.',
        ];
        $code = is_array($err) && preg_match('/SQLSTATE\[[^\]]*\] \[(\d+)\]/', $err['message'], $m) ? $m[1] : '';
        if ($code !== '' && isset($hints[$code])) {
            echo '<div style="margin-top:10px;padding:8px 10px;background:#eef7f1;border-radius:6px;color:#0f7b46">'
               . $safe($hints[$code]) . '</div>';
        }

        // If authentication was rejected, determine whether the stored password merely
        // LOOKS delimited when copied out of the control panel ("[secret]" / "(secret)").
        // Only a tiny fixed set of delimiter shapes is tried, only for this account's own
        // credentials, and no candidate value is ever printed.
        if ($code === '1045') {
            echo '<div style="margin-top:14px;padding-top:12px;border-top:1px solid #e3e3e8">';
            echo '<div style="font-weight:700;margin-bottom:6px">Password delimiter check</div>';
            $raw      = (string) DB_PASS;
            $stripped = trim($raw, "[]()<>{} \t\n\r\"'");
            $cands    = ['as stored' => $raw];
            if ($stripped !== '' && $stripped !== $raw) {
                $cands['without surrounding delimiters'] = $stripped;
            }
            if ($stripped !== '') {
                $cands['wrapped in [ ]']   = '[' . $stripped . ']';
                $cands['wrapped in ( )']   = '(' . $stripped . ')';
                $cands['wrapped in " "']   = '"' . $stripped . '"';
            }
            $hit = null;
            foreach ($cands as $label => $pw) {
                try {
                    $test = new PDO(
                        'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                        DB_USER,
                        $pw,
                        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10]
                    );
                    $hit = $label;
                    break;
                } catch (Throwable $e) {
                    // expected for the wrong shapes; keep going
                }
            }
            echo '<div style="color:#666;font-size:12px">tested ' . count($cands)
               . ' shapes of the password; no value was printed.</div>';
            if ($hit !== null) {
                echo '<div style="margin-top:6px;color:#0f7b46;font-weight:700">A password matching &ldquo;'
                   . $safe($hit) . '&rdquo; was ACCEPTED.</div>'
                   . '<div style="margin-top:4px">Set <code>\'DB_PASS\'</code> in config/config.local.php to that exact shape.</div>';
            } else {
                echo '<div style="margin-top:6px;color:#b42318;font-weight:700">NONE of the delimiter shapes were accepted.</div>'
                   . '<div style="margin-top:4px">So the password itself is wrong. In the InfinityFree control panel open '
                   . '<b>SQL Databases</b> and click <b>Show</b> next to the SQL password '
                   . '(it is a different password from your client-area login).</div>';
            }
            echo '</div>';
        }
    }
    echo '</div>';

    echo '<p style="color:#777">Full diagnostic: <b>setup_check.php?key=' . $safe($expected) . '</b></p>';
    echo '<p style="color:#777;font-weight:600">Delete this debug block from index.php once the database connects.</p>';
    echo '</body>';
    exit;
})();
// ═════════════════════ end debug block ══════════════════════════

require_once __DIR__ . '/views/header.php';

$pdo = getDBConnection();

$totalLost      = 0;
$totalFound     = 0;
$totalResolved  = 0;
$activeSearches = 0;
$reports        = [];

if ($pdo) {
    $totalLost      = $pdo->query("SELECT COUNT(*) FROM reports WHERE type = 'lost'")->fetchColumn();
    $totalFound     = $pdo->query("SELECT COUNT(*) FROM reports WHERE type = 'found'")->fetchColumn();
    $totalResolved  = $pdo->query("SELECT COUNT(*) FROM reports WHERE status = 'resolved'")->fetchColumn();
    $activeSearches = $pdo->query("SELECT COUNT(*) FROM reports WHERE status IN ('active','matched','pending_pickup')")->fetchColumn();

    $stmt    = $pdo->query("SELECT r.*, u.full_name, u.university_id FROM reports r JOIN users u ON r.user_id = u.id ORDER BY r.created_at DESC");
    $reports = $stmt->fetchAll();

    // توحيد حالة الزوج المطابق للعرض: إذا كان أحد الطرفين "تم الاستلام" تظهر بطاقتاه معاً بالحالة نفسها
    $reports = normalizeMatchedStatuses($pdo, $reports);
}

function statusAr(string $s): string {
    return match($s) {
        'active'         => 'نشط',
        'matched'        => 'مطابق بالذكاء الاصطناعي',
        'pending_pickup' => 'بانتظار الاستلام',
        'resolved'       => 'تم الاستلام',
        default          => $s,
    };
}

function typeAr(string $t): string {
    return $t === 'lost' ? 'مفقود' : 'معثور عليه';
}

function categoryAr(string $c): string {
    return match($c) {
        'Personal Belongings', 'مقتنيات شخصية' => 'مقتنيات شخصية',
        'University ID / ATM Card', 'البطاقة الجامعية / الصراف', 'بطاقة جامعية / صراف' => 'البطاقة الجامعية / الصراف',
        'Electronics', 'إلكترونيات' => 'إلكترونيات',
        'Documents', 'وثائق ومستندات' => 'وثائق ومستندات',
        'Keys', 'مفاتيح' => 'مفاتيح',
        'Backpack / Bags', 'حقائب وحقائب ظهر' => 'حقائب وحقائب ظهر',
        'Apparel / Accessories', 'ملابس وإكسسوارات' => 'ملابس وإكسسوارات',
        default => $c,
    };
}
?>

<main class="main-layout">

  <!-- HERO BANNER -->
  <section class="hero-banner">
    <div class="laser-scan-line" aria-hidden="true"></div>
    <h1 class="hero-title">نظام المفقودات والمعثورات الذكي بالجامعة</h1>
    <p class="hero-subtitle">
      أبلغ عن المفقودات أو المعثورات في جميع أنحاء الجامعة. يقوم النظام بالتعرف الضوئي على النصوص ومطابقة العناصر بالرؤية الذكية تلقائياً وتوليد كود تسليم آمن.
    </p>
    <div class="hero-actions">
      <button class="btn btn-primary hero-action-btn" onclick="checkAuthAndOpenModal('modalReportLost')">الإبلاغ عن مفقود</button>
      <button class="btn btn-success hero-action-btn" onclick="checkAuthAndOpenModal('modalReportFound')">الإبلاغ عن معثور عليه (ماسح البطاقات)</button>
    </div>
  </section>

  <!-- METRICS BAR -->
  <section class="metrics-grid">
    <div class="metric-card">
      <div class="metric-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m20.5 20.5-4.2-4.2"/></svg>
      </div>
      <div>
        <div class="metric-val"><?= $totalLost; ?></div>
        <div class="metric-label">إجمالي المفقودات</div>
      </div>
    </div>
    <div class="metric-card">
      <div class="metric-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m7.5 4.27 9 5.15"/><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/></svg>
      </div>
      <div>
        <div class="metric-val"><?= $totalFound; ?></div>
        <div class="metric-label">إجمالي المعثورات</div>
      </div>
    </div>
    <div class="metric-card mint">
      <div class="metric-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21.8 10A10 10 0 1 1 17 3.34"/><path d="m9 11 3 3L22 4"/></svg>
      </div>
      <div>
        <div class="metric-val"><?= $totalResolved; ?></div>
        <div class="metric-label">تمت الاستعادة بنجاح</div>
      </div>
    </div>
    <div class="metric-card">
      <div class="metric-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M19.07 4.93A10 10 0 0 0 6.99 3.34"/><path d="M4 6h.01"/><path d="M2.29 9.62A10 10 0 1 0 21.31 8.35"/><path d="M16.24 7.76A6 6 0 1 0 8.23 16.67"/><path d="M12 18h.01"/><path d="M17.99 11.66A6 6 0 0 1 15.77 16.67"/><circle cx="12" cy="12" r="2"/><path d="m13.41 10.59 5.66-5.66"/></svg>
      </div>
      <div>
        <div class="metric-val"><?= $activeSearches; ?></div>
        <div class="metric-label">عمليات البحث النشطة</div>
      </div>
    </div>
  </section>

  <!-- SEARCH & FILTER BAR -->
  <section class="controls-bar">
    <div class="search-box">
      <span class="search-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m20.5 20.5-4.2-4.2"/></svg></span>
      <input type="text" class="search-input" id="searchInput" placeholder="ابحث باسم العنصر، الموقع، الكلمات المفتاحية أو رقم البطاقة...">
    </div>
    <div class="filter-group">
      <select class="filter-select" id="categoryFilter">
        <option value="">جميع التصنيفات</option>
        <option value="مقتنيات شخصية">مقتنيات شخصية</option>
        <option value="البطاقة الجامعية / الصراف">البطاقة الجامعية / الصراف</option>
        <option value="إلكترونيات">إلكترونيات</option>
        <option value="وثائق ومستندات">وثائق ومستندات</option>
        <option value="مفاتيح">مفاتيح</option>
        <option value="حقائب وحقائب ظهر">حقائب وحقائب ظهر</option>
        <option value="ملابس وإكسسوارات">ملابس وإكسسوارات</option>
        <option value="أخرى">أخرى</option>
      </select>

      <select class="filter-select" id="statusFilter">
        <option value="">جميع الحالات</option>
        <option value="active">نشط</option>
        <option value="matched">مطابق بالذكاء الاصطناعي</option>
        <option value="pending_pickup">بانتظار الاستلام</option>
        <option value="resolved">تم الاستلام</option>
      </select>

      <select class="filter-select" id="typeFilter">
        <option value="">جميع الأنواع</option>
        <option value="lost">المفقودات</option>
        <option value="found">المعثورات</option>
      </select>

      <!-- زر مساعد لُقيا AI الذكي التفاعلي -->
      <button type="button" class="btn" id="aiChatOpenBtn" onclick="openAIChatModal()" aria-label="مساعد لُقيا AI الذكي">
        <img src="assets/images/luqya-magnifier.svg" alt="" class="ai-fab-logo" width="32" height="34">
      </button>
    </div>
  </section>


  <!-- ITEMS GRID HEADER -->
  <div class="grid-title-row">
    <h3 class="grid-title">بلاغات المفقودات والمعثورات</h3>
    <span style="font-size:0.88rem;color:var(--text-muted);"><?= count($reports); ?> بلاغ مسجّل</span>
  </div>

  <!-- ITEMS GRID -->
  <div class="items-grid" id="itemsGrid">
    <?php foreach ($reports as $item): ?>
      <div class="item-card"
           data-id="<?= intval($item['id']); ?>"
           data-title="<?= mb_strtolower(htmlspecialchars($item['title']), 'UTF-8'); ?>"
           data-category="<?= htmlspecialchars(categoryAr($item['category'])); ?>"
           data-status="<?= htmlspecialchars($item['status']); ?>"
           data-type="<?= htmlspecialchars($item['type']); ?>"
           data-location="<?= mb_strtolower(htmlspecialchars($item['location']), 'UTF-8'); ?>"
           data-tags="<?= mb_strtolower(htmlspecialchars($item['ai_tags'] ?? ''), 'UTF-8'); ?>">

        <div class="item-img-wrap">
          <img src="<?= htmlspecialchars($item['image_url'] ?? 'assets/images/sample_bag.svg'); ?>"
               alt="<?= htmlspecialchars($item['title']); ?>">
          <span class="type-pill <?= $item['type']; ?>"><?= typeAr($item['type']); ?></span>
          <span class="status-badge <?= $item['status']; ?>"><?= statusAr($item['status']); ?></span>
        </div>

        <div class="item-body">
          <div class="item-cat"><?= htmlspecialchars(categoryAr($item['category'])); ?></div>
          <h4 class="item-name"><?= htmlspecialchars($item['title']); ?></h4>
          <p class="item-desc"><?= htmlspecialchars($item['description']); ?></p>

          <?php if (!empty($item['ai_tags'])): ?>
            <div class="tags-row">
              <?php foreach (explode('،', str_replace(',', '،', $item['ai_tags'])) as $tag): ?>
                <?php if (!empty(trim($tag))): ?>
                  <span class="ai-tag"><?= htmlspecialchars(trim($tag)); ?></span>
                <?php endif; ?>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <div class="item-meta">
            <div><strong>رقم البلاغ:</strong> #<?= intval($item['id']); ?></div>
            <div><strong>الموقع:</strong> <?= htmlspecialchars($item['location']); ?></div>
            <div><strong>التاريخ:</strong> <?= htmlspecialchars($item['date_event']); ?> &nbsp;|&nbsp; <svg class="user-avatar-icon" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg> <?= htmlspecialchars($item['full_name']); ?></div>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- EMPTY STATE -->
  <div id="noResultsMessage" style="display:none;text-align:center;padding:3.5rem 2rem;background:#FFFFFF;border-radius:14px;border:1px solid var(--border-color);box-shadow:var(--shadow-sm);margin-bottom:2rem;">
    <div style="font-size:3rem;margin-bottom:12px;"></div>
    <h3 style="color:var(--navy-primary);margin:0 0 6px 0;">لم يتم العثور على عناصر تطابق فلتر البحث</h3>
    <p style="font-size:0.9rem;color:var(--text-muted);margin-bottom:1.5rem;">جرّب تعديل كلمات البحث أو تغيير التصنيفات المحددة.</p>
    <button class="btn btn-outline btn-sm" onclick="resetSearchFilters()">إعادة ضبط الفلاتر</button>
  </div>

  <!-- HOW IT WORKS -->
  <section style="background:#FFFFFF;border-radius:14px;padding:2.5rem;border:1px solid var(--border-color);margin:3rem 0;box-shadow:var(--shadow-sm);">
    <h3 style="font-size:1.5rem;font-weight:800;color:var(--navy-primary);margin-top:0;">كيف يعمل نظام <span translate="no" class="notranslate brand-wordmark" dir="rtl" style="display: inline-block; unicode-bidi: isolate;">لُقيا AI</span>؟</h3>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1.5rem;margin-top:1.5rem;">
      <div style="text-align:center;padding:1rem;">
        <div class="howto-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3v4a1 1 0 0 0 1 1h4"/><path d="M17 21H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7l5 5v11a2 2 0 0 1-2 2Z"/><path d="M12 11v6"/><path d="M9 14h6"/></svg>
        </div>
        <h4 style="color:var(--navy-primary);margin:0 0 6px 0;">أبلغ عن مفقود أو معثور</h4>
        <p style="font-size:0.88rem;color:var(--text-muted);">أدخل تفاصيل العنصر المفقود، أو امسح بطاقة جامعية موجودة بالماسح الضوئي.</p>
      </div>
      <div style="text-align:center;padding:1rem;">
        <div class="howto-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="4" width="16" height="16" rx="2"/><rect x="9" y="9" width="6" height="6"/><path d="M9 2v2M15 2v2M9 20v2M15 20v2M2 9h2M2 15h2M20 9h2M20 15h2"/></svg>
        </div>
        <h4 style="color:var(--navy-primary);margin:0 0 6px 0;">المطابقة التلقائية بالذكاء الاصطناعي</h4>
        <p style="font-size:0.88rem;color:var(--text-muted);">يقارن <span translate="no" class="notranslate brand-wordmark" dir="rtl" style="display: inline-block; unicode-bidi: isolate;">لُقيا AI</span> المواقع وبيانات البطاقة والوسوم الذكية بدقة تتجاوز 85%.</p>
      </div>
      <div style="text-align:center;padding:1rem;">
        <div class="howto-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/></svg>
        </div>
        <h4 style="color:var(--navy-primary);margin:0 0 6px 0;">كود التوثيق الآمن</h4>
        <p style="font-size:0.88rem;color:var(--text-muted);">عند المطابقة، يُولَّد كود استلام فريد (RET-XXXXXX) ويُرسَل فوراً إلى المالك.</p>
      </div>
      <div style="text-align:center;padding:1rem;">
        <div class="howto-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m16 16 2 2 4-4"/><path d="M21 10V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l2-1.14"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/></svg>
        </div>
        <h4 style="color:var(--navy-primary);margin:0 0 6px 0;">التسليم في مكتب الأمن</h4>
        <p style="font-size:0.88rem;color:var(--text-muted);">يتحقق ضابط الأمن من الكود عبر لوحة التحكم ويسلّم المفقود للطالب فوراً.</p>
      </div>
    </div>
  </section>

  <!-- FAQ ACCORDION -->
  <section class="faq-section" id="faq-section">
    <h3 class="grid-title" style="margin-bottom:1.25rem;">الأسئلة الشائعة</h3>

    <div class="accordion-item">
      <div class="accordion-header">
        <span>ماذا أفعل إذا وجدت غرضاً مفقوداً في الجامعة؟</span>
        <span class="accordion-icon">▼</span>
      </div>
      <div class="accordion-content">
        أبلغ عنها من خلال نموذج "الإبلاغ عن معثور عليه". بالنسبة للبطاقات الجامعية استخدم الماسح الضوئي، ثم سلّم العنصر المادي مباشرة لمكتب الأمن.
      </div>
    </div>

    <div class="accordion-item">
      <div class="accordion-header">
        <span>كيف تعمل عملية المطابقة بالذكاء الاصطناعي؟</span>
        <span class="accordion-icon">▼</span>
      </div>
      <div class="accordion-content">
        يحلل النظام التصنيفات والمواقع والأوصاف وبيانات الصور. عند اكتشاف مطابقة بنسبة أعلى من 85% يتلقى المالك وضابط الأمن إشعاراً فورياً يحتوي على كود الاستلام السداسي.
      </div>
    </div>

    <div class="accordion-item">
      <div class="accordion-header">
        <span>كيف أستلم مفقوداتي من مكتب الأمن؟</span>
        <span class="accordion-icon">▼</span>
      </div>
      <div class="accordion-content">
        توجّه إلى مكتب الأمن واعرض بطاقتك الجامعية وكود التوثيق (مثال: RET-849201). سيتحقق ضابط الأمن من الكود ويسلّمك المفقود فوراً.
      </div>
    </div>
  </section>

</main>

<script>
function resetSearchFilters() {
  ['searchInput','categoryFilter','statusFilter','typeFilter'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.value = '';
  });
  document.querySelectorAll('.item-card').forEach(c => c.style.display = 'flex');
  const msg = document.getElementById('noResultsMessage');
  if (msg) msg.style.display = 'none';
}

// ============================================================================
// مزامنة لحظية لواجهة العرض العامة: عند اعتماد مطابقة أو تسليم غرض في لوحة الأمن،
// تتحدث شارة حالة البطاقة هنا مباشرة عبر JSON دون إعادة تحميل الصفحة.
// ============================================================================
(function liveGridStatusSync() {
  const LABELS = {
    active: 'نشط',
    matched: 'مطابق بالذكاء الاصطناعي',
    pending_pickup: 'بانتظار الاستلام',
    resolved: 'تم الاستلام',
    cancelled: 'ملغي'
  };

  async function poll() {
    try {
      const res = await fetch('api/reports.php?action=statuses', { cache: 'no-store' });
      const data = await res.json();
      if (!data.success || !data.statuses) return;

      document.querySelectorAll('.item-card').forEach(card => {
        const id = card.dataset.id;
        const info = data.statuses[id];
        if (!info) return;
        if (card.dataset.status === info.status) return;

        card.dataset.status = info.status;
        const badge = card.querySelector('.status-badge');
        if (badge) {
          badge.className = 'status-badge ' + info.status;
          badge.textContent = LABELS[info.status] || info.status;
        }
      });
    } catch (err) {
      // تجاهل الأخطاء المؤقتة في الاستطلاع
    }
  }

  setInterval(poll, 20000);
  setTimeout(poll, 1500);
})();
</script>

<?php
require_once __DIR__ . '/views/modal_report_lost.php';
require_once __DIR__ . '/views/modal_report_found.php';
require_once __DIR__ . '/views/modal_ai_chat.php';
require_once __DIR__ . '/views/footer.php';
?>
