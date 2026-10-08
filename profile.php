<?php
require_once __DIR__ . '/config/db.php';
if (!isLoggedIn()) { header('Location: login.php'); exit; }

$currentUser = getCurrentUser();
$pdo         = getDBConnection();
$myReports   = [];
$potentialMatches = [];

if ($pdo) {
    $stmt = $pdo->prepare("SELECT * FROM reports WHERE user_id = ? ORDER BY created_at DESC");
    $stmt->execute([$currentUser['id']]);
    $myReports = $stmt->fetchAll();

    // المعثورات المحتملة المطابقة لبلاغات الطالب المفقودة (محرك المطابقة الموزون)
    require_once __DIR__ . '/config/matching.php';
    // توحيد حالة الزوج المطابق للعرض (المفقود + المعثور) عند إغلاق أحدهما
    $myReports = normalizeMatchedStatuses($pdo, $myReports);
    $potentialMatches = getPotentialMatchesForUser($currentUser['id'], $pdo, MATCH_STUDENT_THRESHOLD, 8);

    // إحصائيات سريعة لعرضها في رأس الملف الشخصي
    $statsTotal    = 0;
    $statsTracking = 0;
    $statsResolved = 0;
    foreach ($myReports as $rpt) {
        $statsTotal++;
        if ($rpt['status'] === 'resolved') {
            $statsResolved++;
        } elseif ($rpt['status'] !== 'cancelled') {
            $statsTracking++;
        }
    }
}

function statusAr(string $s): string {
    return match($s) {
        'active'         => 'نشط',
        'matched'        => 'مطابق بالذكاء الاصطناعي',
        'pending_pickup' => 'بانتظار الاستلام',
        'resolved'       => 'تم الاستلام',
        'cancelled'      => 'ملغي',
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

require_once __DIR__ . '/views/header.php';
?>

<main class="main-layout">

  <!-- PROFILE HEADER -->
  <div style="background:var(--navy-primary);color:#FFFFFF;border-radius:14px;padding:2rem;margin-bottom:2rem;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem;">
    <div>
      <h2 style="margin:0 0 6px 0;"><svg class="user-avatar-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg> <?= htmlspecialchars($currentUser['full_name']); ?></h2>
      <p style="margin:0;color:#CBD5E1;font-size:0.95rem;">
        <?php
        $studentIdValue = escapeOutput($currentUser['university_id'] ?? '');
        $collegeValue = trim((string) ($currentUser['college'] ?? ''));
        $majorValue = trim((string) ($currentUser['major'] ?? ''));
        if ($collegeValue !== '') {
            $headerSub = 'الكلية: <strong style="color:#FFFFFF;font-weight:600;">' . escapeOutput($collegeValue) . '</strong>';
        } elseif ($majorValue !== '') {
            $headerSub = 'التخصص: <strong style="color:#FFFFFF;font-weight:600;">' . escapeOutput($majorValue) . '</strong>';
        } else {
            $headerSub = 'الكلية: <strong style="color:#FFFFFF;font-weight:600;">كلية العلوم</strong>';
        }
        ?>
        <span><?= 'الرقم الجامعي:' ?> <strong style="color:var(--mint-success);font-weight:700;"><?= $studentIdValue ?></strong></span>
        <span>&nbsp;|&nbsp; <?= $headerSub ?></span>
      </p>
    </div>
    <div style="display:flex;flex-direction:column;align-items:flex-end;gap:10px;">
      <div class="profile-quick-stats">
        <div class="profile-stat">
          <span class="profile-stat-num"><?= $statsTotal; ?></span>
          <span class="profile-stat-label">إجمالي البلاغات</span>
        </div>
        <span class="profile-stat-divider"></span>
        <div class="profile-stat">
          <span class="profile-stat-num"><?= $statsTracking; ?></span>
          <span class="profile-stat-label">قيد التتبع</span>
        </div>
        <span class="profile-stat-divider"></span>
        <div class="profile-stat">
          <span class="profile-stat-num"><?= $statsResolved; ?></span>
          <span class="profile-stat-label">تم الاستلام</span>
        </div>
      </div>
      <?php if (isSecurityOfficer()): ?>
        <span style="font-size:0.8rem;color:#CBD5E1;text-align:left;"> إدارة المعثورات تتم عبر <a href="security_dashboard.php" style="color:var(--mint-success);font-weight:700;">لوحة تحكم الأمن</a></span>
      <?php endif; ?>
    </div>
  </div>

  <?php if (isSecurityOfficer()): ?>
    <div style="background:rgba(168,146,100,0.12);border:1px solid var(--cyan-primary);border-radius:12px;padding:1rem 1.25rem;margin-bottom:2rem;font-size:0.9rem;color:var(--navy-primary);">
      <strong>سجل نشاط ضابط الأمن:</strong> تعرض هذه الصفحة سجلات المعثورات التي سجّلتها في مخزون مكتب الأمن مع مسار تتبع حالتها. تتم الإضافة والتعديل والحذف والتحقق عبر <a href="security_dashboard.php" style="font-weight:700;color:var(--cyan-primary);">لوحة تحكم الأمن</a>.
    </div>
  <?php endif; ?>

  <!-- REPORTS SECTION -->
  <div class="grid-title-row">
    <h3 class="grid-title">بلاغاتي ومسار التتبع</h3>
    <span style="font-size:0.9rem;color:var(--text-muted);"><?= count($myReports); ?> بلاغ مسجّل</span>
  </div>

  <?php if (count($myReports) === 0): ?>
    <div style="background:#FFFFFF;border-radius:14px;padding:3rem;text-align:center;color:var(--text-muted);border:1px solid var(--border-color);">
      <div style="font-size:3rem;margin-bottom:12px;"></div>
      <h3>لم تقم بتسجيل أي بلاغ حتى الآن</h3>
      <p>انقر أدناه للإبلاغ عن مفقود أو مسح بطاقة جامعية موجودة.</p>
      <button class="btn btn-primary" onclick="openModal('modalReportLost')">الإبلاغ عن مفقود</button>
    </div>
  <?php else: ?>
    <div id="profileReportsView" style="display:flex;flex-direction:column;gap:1rem;">
      <?php foreach ($myReports as $rep): $repStatus = htmlspecialchars($rep['status']); ?>
        <div class="report-card" data-report-id="<?= intval($rep['id']); ?>" data-status="<?= $repStatus; ?>" style="background:#FFFFFF;border-radius:14px;border:1px solid var(--border-color);box-shadow:var(--shadow-sm);">

          <!-- SUMMARY (always visible): two-column header + title/date row -->
          <div class="report-summary" onclick="toggleReportDetails(this)">
            <div class="report-header">
              <div class="report-header-right">
                <span class="type-pill <?= htmlspecialchars($rep['type']); ?>"><?= typeAr($rep['type']); ?></span>
                <span class="report-id">رقم البلاغ: #<?= intval($rep['id']); ?></span>
              </div>
              <div class="report-header-left">
                <span class="status-badge <?= $repStatus; ?>" data-status="<?= $repStatus; ?>" data-resolved-at="<?= htmlspecialchars($rep['resolved_at'] ?? ''); ?>"><?= statusAr($rep['status']); ?></span>
                <button type="button" class="track-toggle-btn" onclick="event.stopPropagation();toggleReportDetails(this)" aria-expanded="false">
                  <span class="track-toggle-label">عرض تفاصيل التتبع</span>
                  <svg class="caret" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                </button>
              </div>
            </div>
            <div class="report-summary-meta">
              <h3 class="report-summary-title"><?= htmlspecialchars($rep['title']); ?></h3>
              <span class="report-summary-date"><strong>التاريخ:</strong> <?= htmlspecialchars($rep['date_event']); ?></span>
            </div>
          </div>

          <!-- FULL DETAILS (accordion body) -->
          <div class="report-details" hidden>
            <div class="report-details-inner">
              <div style="font-size:0.85rem;color:var(--text-muted);display:flex;flex-wrap:wrap;align-items:center;gap:6px 18px;margin-bottom:12px;">
                <span><strong>التصنيف:</strong> <?= htmlspecialchars(categoryAr($rep['category'])); ?></span>
                <span><strong>الموقع:</strong> <?= htmlspecialchars($rep['location']); ?></span>
                <?php if (!empty($rep['pickup_code'])): ?>
                  <span style="background:rgba(0,168,181,0.1);padding:4px 12px;border-radius:8px;border:1px solid var(--cyan-primary);">
                    <strong>كود الاستلام:</strong>
                    <code style="font-weight:700;color:var(--cyan-primary);font-size:0.95rem;"><?= htmlspecialchars($rep['pickup_code']); ?></code>
                  </span>
                <?php endif; ?>
              </div>

              <p style="font-size:0.9rem;color:var(--text-primary);margin:0 0 0.25rem;line-height:1.6;">
                <?= htmlspecialchars($rep['description']); ?>
              </p>

              <!-- LIFECYCLE STEP TRACKER -->
              <div class="lifecycle-tracker report-lifecycle">
                <div class="step-item complete">
                  <div class="step-node">1</div>
                  <div class="step-label">تم الإرسال</div>
                </div>
                <div class="step-item <?= in_array($rep['status'], ['matched','pending_pickup','resolved']) ? 'complete' : ($rep['status'] === 'active' ? 'active' : ''); ?>">
                  <div class="step-node">2</div>
                  <div class="step-label">مطابقة ذكية</div>
                </div>
                <div class="step-item <?= in_array($rep['status'], ['pending_pickup','resolved']) ? 'complete' : ($rep['status'] === 'matched' ? 'active' : ''); ?>">
                  <div class="step-node">3</div>
                  <div class="step-label">جاهز للاستلام</div>
                </div>
                <div class="step-item <?= $rep['status'] === 'resolved' ? 'complete' : ''; ?>">
                  <div class="step-node">4</div>
                  <div class="step-label">تم الاستلام</div>
                </div>
              </div>

              <?php if (!in_array($rep['status'], ['resolved', 'cancelled'], true)): ?>
                <div class="report-actions" style="display:flex;justify-content:flex-end;gap:10px;margin-top:1rem;padding-top:10px;border-top:1px dashed var(--border-color);">
                  <button class="btn btn-outline btn-sm" onclick="closeMyReport(<?= intval($rep['id']); ?>)">إغلاق / حذف البلاغ</button>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if (!isSecurityOfficer() && $potentialMatches): ?>
    <!-- المطابقات المحتملة -->
    <div class="grid-title-row" style="margin-top:2.5rem;">
      <h3 class="grid-title">مطابقات محتملة لبلاغاتك المفقودة</h3>
      <span style="font-size:0.9rem;color:var(--text-muted);">حسب محرك المطابقة الموزون — تُحدَّث لحظياً</span>
    </div>
    <div id="potentialMatchesContainer" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:1.25rem;">
      <?php foreach ($potentialMatches as $pm): ?>
        <div class="potential-match-card" data-found-id="<?= intval($pm['found']['id']); ?>" data-match-score="<?= intval($pm['score']); ?>" style="background:#FFFFFF;border:1px solid rgba(0,168,181,0.35);border-radius:14px;padding:1.25rem;box-shadow:var(--shadow-sm);display:flex;flex-direction:column;gap:10px;">
          <div style="display:flex;align-items:center;gap:10px;">
            <?php
            $fImg = $pm['found']['image_url'] ?: 'assets/images/sample_bag.svg';
            ?>
            <img src="<?= htmlspecialchars($fImg); ?>" alt="" style="width:56px;height:56px;border-radius:10px;object-fit:cover;border:1px solid var(--border-color);background:#F8FAFC;">
            <div style="flex:1;">
              <strong style="color:var(--navy-primary);display:block;font-size:0.98rem;"><?= htmlspecialchars($pm['found']['title']); ?></strong>
              <span style="font-size:0.8rem;color:var(--text-muted);"><strong>الموقع:</strong> <?= htmlspecialchars($pm['found']['location']); ?> &nbsp;·&nbsp; <strong>رقم البلاغ:</strong> #<?= intval($pm['found']['id']); ?></span>
            </div>
            <span class="match-score-pill" data-score="<?= intval($pm['score']); ?>" style="background:<?= $pm['score'] >= 75 ? 'rgba(76,217,161,0.2)' : 'rgba(0,168,181,0.15)'; ?>;color:<?= $pm['score'] >= 75 ? '#0E9F6E' : 'var(--cyan-primary)'; ?>;font-weight:800;padding:5px 10px;border-radius:8px;font-size:0.85rem;">
              <?= intval($pm['score']); ?>%
            </span>
          </div>

          <?php if (!empty($pm['found']['distinctive_features'])): ?>
            <div style="font-size:0.82rem;color:var(--navy-primary);background:rgba(168,146,100,0.12);border:1px dashed var(--cyan-primary);border-radius:8px;padding:6px 10px;">
               العلامات المميزة: <?= htmlspecialchars($pm['found']['distinctive_features']); ?>
            </div>
          <?php endif; ?>

          <div style="font-size:0.78rem;color:var(--text-muted);display:flex;gap:6px;flex-wrap:wrap;">
            <?php $b = $pm['breakdown']; ?>
            <span style="background:#EEF1F5;padding:2px 7px;border-radius:5px;">التصنيف <?= $b['category']; ?></span>
            <span style="background:#EEF1F5;padding:2px 7px;border-radius:5px;">الموقع <?= $b['location']; ?></span>
            <span style="background:#EEF1F5;padding:2px 7px;border-radius:5px;">النصوص <?= $b['keywords']; ?></span>
            <span style="background:#EEF1F5;padding:2px 7px;border-radius:5px;">المميزات <?= $b['distinctive_features']; ?></span>
            <?php if ($b['ocr'] > 0): ?><span style="background:rgba(76,217,161,0.15);padding:2px 7px;border-radius:5px;color:#0E9F6E;">OCR <?= $b['ocr']; ?></span><?php endif; ?>
          </div>

          <div style="font-size:0.78rem;color:var(--text-muted);border-top:1px dashed var(--border-color);padding-top:8px;">
            يطابق بلاغك المفقود: <strong style="color:var(--navy-primary);"><?= htmlspecialchars($pm['lost']['title']); ?></strong> <span style="color:var(--cyan-primary);font-weight:700;">(رقم البلاغ: #<?= intval($pm['lost']['id']); ?>)</span>
            <span style="display:block;margin-top:4px;"> توجّه إلى مكتب الأمن لإبراز كود الاستلام بعد اعتماد المطابقة.</span>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

</main>

<script>
// ============================================================================
// "My Reports & Tracking" — accordion toggle between summary & full tracking details
// ============================================================================
function toggleReportDetails(target) {
  const card = target.closest('.report-card');
  if (!card) return;
  const body = card.querySelector('.report-details');
  const btn  = card.querySelector('.track-toggle-btn');
  const label = card.querySelector('.track-toggle-label');
  if (!body) return;

  const open = body.hidden;
  body.hidden = !open;
  card.classList.toggle('is-open', open);
  if (btn) btn.setAttribute('aria-expanded', open ? 'true' : 'false');
  if (label) label.textContent = open ? 'إخفاء تفاصيل التتبع' : 'عرض تفاصيل التتبع';
}

function closeMyReport(id) {
  const existing = document.getElementById('luqyaCloseModal');
  if (existing) existing.remove();

  const REASONS = [
    { key: 'found',    label: 'تم العثور على العنصر واسترداده' },
    { key: 'mistaken', label: 'بلاغ خاطئ (أدخلته بالخطأ)' },
    { key: 'other',    label: 'سبب آخر' }
  ];

  const overlay = document.createElement('div');
  overlay.id = 'luqyaCloseModal';
  overlay.setAttribute('role', 'dialog');
  overlay.setAttribute('aria-modal', 'true');
  overlay.style.cssText = 'position:fixed;inset:0;background:rgba(10,25,41,0.55);z-index:9999;display:flex;align-items:center;justify-content:center;padding:1rem;direction:rtl;';

  const panel = document.createElement('div');
  panel.style.cssText = 'background:#FFFFFF;border-radius:14px;padding:1.6rem;width:100%;max-width:400px;box-shadow:var(--shadow-md);border:1px solid var(--border-color);box-sizing:border-box;';

  let html = '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">'
    + '<h3 style="margin:0;font-size:1.05rem;color:var(--navy-primary);">إغلاق / حذف البلاغ (#' + id + ')</h3>'
    + '<button type="button" aria-label="إغلاق النافذة" style="border:none;background:none;cursor:pointer;font-size:1.2rem;color:var(--text-muted);line-height:1;">&times;</button>'
    + '</div>'
    + '<p style="margin:0 0 1rem;font-size:0.82rem;color:var(--text-muted);">يرجى تحديد سبب الإغلاق — لا يمكن التراجع بعد التأكيد.</p>';

  REASONS.forEach((r, i) => {
    html += '<label style="display:flex;align-items:flex-start;gap:10px;padding:10px 12px;margin-bottom:8px;border:1px solid var(--border-color);border-radius:10px;cursor:pointer;background:#FFFFFF;">'
      + '<input type="radio" name="luqyaCloseReason" value="' + r.key + '" ' + (i === 0 ? 'checked' : '') + ' style="margin-top:3px;accent-color:var(--cyan-primary);">'
      + '<span style="font-size:0.9rem;color:var(--navy-primary);font-weight:600;">' + r.label + '</span>'
      + '</label>';
  });

  html += '<div id="luqyaCloseOtherWrap" style="display:none;margin-bottom:1rem;">'
    + '<input type="text" id="luqyaCloseOther" maxlength="120" placeholder="اكتب السبب هنا..." style="width:100%;box-sizing:border-box;padding:10px 12px;border:1px solid var(--border-color);border-radius:10px;font-size:0.9rem;font-family:inherit;outline:none;">'
    + '</div>';

  html += '<div style="display:flex;gap:10px;margin-top:1rem;">'
    + '<button type="button" id="luqyaCloseSubmit" class="btn btn-primary" style="flex:1;">تأكيد الإغلاق</button>'
    + '<button type="button" id="luqyaCloseCancel" class="btn btn-outline" style="flex:1;">إلغاء</button>'
    + '</div>';

  panel.innerHTML = html;
  overlay.appendChild(panel);
  document.body.appendChild(overlay);

  function closeOverlay() {
    const m = document.getElementById('luqyaCloseModal');
    if (m) m.remove();
  }
  panel.querySelector('#luqyaCloseCancel').addEventListener('click', closeOverlay);
  panel.querySelector('[aria-label="إغلاق النافذة"]').addEventListener('click', closeOverlay);
  overlay.addEventListener('click', (e) => { if (e.target === overlay) closeOverlay(); });

  const otherWrap = panel.querySelector('#luqyaCloseOtherWrap');
  const radios = panel.querySelectorAll('input[name="luqyaCloseReason"]');
  function syncOther() {
    const sel = panel.querySelector('input[name="luqyaCloseReason"]:checked');
    otherWrap.style.display = (sel && sel.value === 'other') ? 'block' : 'none';
  }
  radios.forEach(r => r.addEventListener('change', syncOther));

  panel.querySelector('#luqyaCloseSubmit').addEventListener('click', () => {
    const sel = panel.querySelector('input[name="luqyaCloseReason"]:checked');
    if (!sel) { showToast('يرجى تحديد سبب الإغلاق', 'danger'); return; }
    const fd = new FormData();
    fd.append('report_id', id);
    fd.append('reason_key', sel.value);
    fd.append('reason', sel.value === 'other' ? (panel.querySelector('#luqyaCloseOther').value || '') : '');
    fd.append('_csrf', window.LUQYA_CSRF);

    const submitBtn = panel.querySelector('#luqyaCloseSubmit');
    submitBtn.disabled = true;
    submitBtn.textContent = 'جاري الإغلاق...';

    fetch('api/reports.php?action=close', { method: 'POST', body: fd })
      .then(r => r.json())
      .then(data => {
        if (data.success) {
          closeOverlay();
          showToast(data.message, 'success');
          setTimeout(() => window.location.reload(), 1000);
        } else {
          submitBtn.disabled = false;
          submitBtn.textContent = 'تأكيد الإغلاق';
          showToast(data.message || 'حدث خطأ في إغلاق البلاغ', 'danger');
        }
      })
      .catch(() => {
        submitBtn.disabled = false;
        submitBtn.textContent = 'تأكيد الإغلاق';
        showToast('خطأ في الاتصال بالشبكة', 'danger');
      });
  });
}

// ============================================================================
// مزامنة لحظية ثنائية الاتجاه: عندما يسلّم ضابط الأمن غرضاً / يعتمد مطابقة،
// تُحدَّث بطاقة البلاغ على تطبيق الطالب فوراً عبر JSON عبر استطلاع دوري خفيف.
// ============================================================================
(function syncReportStatuses() {
  const STATUS_LABELS = {
    active: 'نشط',
    matched: 'مطابق بالذكاء الاصطناعي',
    pending_pickup: 'بانتظار الاستلام',
    resolved: 'تم الاستلام',
    cancelled: 'ملغي'
  };

  function refreshLifecycle(tracker, status) {
    if (!tracker) return;
    const steps = tracker.querySelectorAll('.step-item');
    if (steps.length < 4) return;
    const s1 = steps[0]; // دائماً complete
    const s2 = steps[1]; // matched
    const s3 = steps[2]; // pick-up
    const s4 = steps[3]; // resolved

    s1.classList.add('complete');
    s2.classList.remove('active', 'complete');
    s3.classList.remove('active', 'complete');
    s4.classList.remove('active', 'complete');

    if (status === 'active') s2.classList.add('active');
    if (['matched', 'pending_pickup', 'resolved'].indexOf(status) !== -1) s2.classList.add('complete');
    if (status === 'matched') s3.classList.add('active');
    if (['pending_pickup', 'resolved'].indexOf(status) !== -1) s3.classList.add('complete');
    if (status === 'resolved') s4.classList.add('complete');
  }

  async function poll() {
    try {
      const res = await fetch('api/reports.php?action=my_status', { cache: 'no-store' });
      const data = await res.json();
      if (!data.success || !Array.isArray(data.reports)) return;

      let anyChange = false;
      data.reports.forEach(rep => {
        const card = document.querySelector('.report-card[data-report-id="' + rep.id + '"]');
        if (!card) return;
        const oldStatus = card.dataset.status;
        if (oldStatus === rep.status) return;

        card.dataset.status = rep.status;
        anyChange = true;

        const badge = card.querySelector('.status-badge[data-status]');
        if (badge) {
          badge.className = 'status-badge ' + rep.status;
          badge.setAttribute('data-status', rep.status);
          badge.setAttribute('data-resolved-at', rep.resolved_at || '');
          badge.style.cssText = 'position:static;display:inline-block;';
          badge.textContent = rep.resolved_at ? (STATUS_LABELS[rep.status] || rep.status) + ' - ' + rep.resolved_at.replace('T', ' ') : (STATUS_LABELS[rep.status] || rep.status);
        }

        refreshLifecycle(card.querySelector('.report-lifecycle'), rep.status);

        const actions = card.querySelector('.report-actions');
        if (rep.status === 'resolved' || rep.status === 'cancelled') {
          if (actions) actions.remove();
        } else if (!actions) {
          const div = document.createElement('div');
          div.className = 'report-actions';
          div.style.cssText = 'display:flex;justify-content:flex-end;gap:10px;margin-top:1rem;padding-top:10px;border-top:1px dashed var(--border-color);';
          const btn = document.createElement('button');
          btn.className = 'btn btn-outline btn-sm';
          btn.textContent = 'إغلاق / حذف البلاغ';
          btn.addEventListener('click', () => closeMyReport(rep.id));
          div.appendChild(btn);
          card.appendChild(div);
        }
      });

      if (anyChange && typeof showToast === 'function') {
        showToast('تم تحديث حالة بلاغاتك تلقائياً', 'success', 3000);
      }
    } catch (err) {
      // تجاهل أخطاء الاستطلاع المؤقتة (الشبكة غير مستقرة أحياناً)
    }
  }

  // استطلاع كل 15 ثانية مع استطلاع أولي بعد ثانيتين
  setInterval(poll, 15000);
  setTimeout(poll, 2000);
})();

// ============================================================================
// مزامنة لحظية لقطاع "المطابقات المحتملة" في لوحة الطالب
// ============================================================================
(function liveMatches() {
  const container = document.getElementById('potentialMatchesContainer');

  function escapeHtml(value) {
    return String(value === null || value === undefined ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function breakdownChips(b) {
    b = b || {};
    let chips = '';
    const chip = (label, val, hi) => '<span style="background:' + (hi ? 'rgba(76,217,161,0.15)' : '#EEF1F5') + ';' + (hi ? 'color:#0E9F6E;' : 'color:var(--text-muted);') + 'padding:2px 7px;border-radius:5px;">' + label + ' ' + val + '</span>';
    chips += chip('التصنيف', b.category || 0, b.category > 0);
    chips += chip('الموقع', b.location || 0, b.location > 0);
    chips += chip('النصوص', b.keywords || 0, b.keywords > 0);
    chips += chip('المميزات', b.distinctive_features || 0, b.distinctive_features > 0);
    if (b.ocr > 0) chips += chip('OCR', b.ocr, true);
    return chips;
  }

  function renderMatches(data) {
    if (!container || !data.success || !Array.isArray(data.matches)) return;
    if (data.matches.length === 0) {
      container.innerHTML = '<div style="grid-column:1/-1;text-align:center;color:var(--text-muted);padding:1rem;border:1px dashed var(--border-color);border-radius:12px;">لا توجد معثورات مطابقة حالياً — ستظهر فور تسجيل ضابط الأمن لغرض مشابه.</div>';
      return;
    }
    let html = '';
    data.matches.forEach(m => {
      const f = m.found || {};
      const l = m.lost || {};
      const img = (f.image_url || 'assets/images/sample_bag.svg');
      const feats = f.distinctive_features
        ? '<div style="font-size:0.82rem;color:var(--navy-primary);background:rgba(168,146,100,0.12);border:1px dashed var(--cyan-primary);border-radius:8px;padding:6px 10px;"> العلامات المميزة: ' + escapeHtml(f.distinctive_features) + '</div>'
        : '';
      html += `
        <div class="potential-match-card" data-found-id="${f.id}" data-match-score="${m.score}" style="background:#FFFFFF;border:1px solid rgba(0,168,181,0.35);border-radius:14px;padding:1.25rem;box-shadow:var(--shadow-sm);display:flex;flex-direction:column;gap:10px;">
          <div style="display:flex;align-items:center;gap:10px;">
            <img src="${escapeHtml(img)}" alt="" style="width:56px;height:56px;border-radius:10px;object-fit:cover;border:1px solid var(--border-color);background:#F8FAFC;">
            <div style="flex:1;">
              <strong style="color:var(--navy-primary);display:block;font-size:0.98rem;">${escapeHtml(f.title)}</strong>
              <span style="font-size:0.8rem;color:var(--text-muted);"><strong>الموقع:</strong> ${escapeHtml(f.location)} · <strong>رقم البلاغ:</strong> #${escapeHtml(f.id)}</span>
            </div>
            <span class="match-score-pill" data-score="${m.score}" style="background:${m.score >= 75 ? 'rgba(76,217,161,0.2)' : 'rgba(0,168,181,0.15)'};color:${m.score >= 75 ? '#0E9F6E' : 'var(--cyan-primary)'};font-weight:800;padding:5px 10px;border-radius:8px;font-size:0.85rem;">${m.score}%</span>
          </div>
          ${feats}
          <div style="font-size:0.78rem;color:var(--text-muted);display:flex;gap:6px;flex-wrap:wrap;">${breakdownChips(m.breakdown)}</div>
          <div style="font-size:0.78rem;color:var(--text-muted);border-top:1px dashed var(--border-color);padding-top:8px;">
            يطابق بلاغك المفقود: <strong style="color:var(--navy-primary);">${escapeHtml(l.title)}</strong> <span style="color:var(--cyan-primary);font-weight:700;">(رقم البلاغ: #${escapeHtml(l.id || '')})</span>
            <span style="display:block;margin-top:4px;"> توجّه إلى مكتب الأمن لإبراز كود الاستلام بعد اعتماد المطابقة.</span>
          </div>
        </div>`;
    });
    container.innerHTML = html;
  }

  async function refresh() {
    try {
      const mRes = await fetch('api/reports.php?action=potential_matches', { cache: 'no-store' });
      const mData = await mRes.json();
      renderMatches(mData);
    } catch (err) { /* تجاهل أخطاء الاستطلاع المؤقتة */ }
  }

  setInterval(refresh, 15000);
  setTimeout(refresh, 1500);
})();
</script>

<?php
if (!isSecurityOfficer()) {
    require_once __DIR__ . '/views/modal_report_lost.php';
}
require_once __DIR__ . '/views/modal_report_found.php';
require_once __DIR__ . '/views/footer.php';
?>
