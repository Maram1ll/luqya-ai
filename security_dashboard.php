<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/matching.php';

if (!isSecurityOfficer()) {
    header('Location: login.php');
    exit;
}

$currentUser = getCurrentUser();
$pdo = getDBConnection();

$inventoryItems = [];
$totalFound = 0;
$totalPendingPickup = 0;
$totalResolved = 0;

if ($pdo) {
    // جلب قائمة المعثورات
    $stmt = $pdo->query("
        SELECT r.*, u.full_name AS recorder_name, u.university_id AS recorder_student_id
        FROM reports r
        JOIN users u ON r.user_id = u.id
        WHERE r.type = 'found'
        ORDER BY r.created_at DESC
    ");
    $inventoryItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // توحيد حالة الزوج المطابق: يظهر المعثور "تم الاستلام" إذا كان قرينه المفقود كذلك
    $inventoryItems = normalizeMatchedStatuses($pdo, $inventoryItems);

    // إحصائيات سريعة
    $totalFound = count($inventoryItems);
    foreach ($inventoryItems as $item) {
        if ($item['status'] === 'pending_pickup' || $item['status'] === 'matched') $totalPendingPickup++;
        if ($item['status'] === 'resolved') $totalResolved++;
    }
}

function statusAr(string $s): string {
    return match($s) {
        'active'         => 'نشط بالمخزون',
        'matched'        => 'مطابق بالذكاء الاصطناعي',
        'pending_pickup' => 'بانتظار الاستلام',
        'resolved'       => 'تم الاستلام',
        'cancelled'      => 'ملغي',
        default          => $s,
    };
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

<style>
/* ===== لوحة الأمن: أقسام المراجعة وبطاقاتها ===== */
.sec-review-section{background:#FFFFFF;border-radius:16px;padding:1.75rem;border:1px solid var(--border-color);box-shadow:var(--shadow-sm);margin-bottom:2rem;}
.sec-review-head{display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:1rem;margin-bottom:1.35rem;}
.sec-review-title{display:flex;align-items:center;gap:12px;min-width:0;}
.sec-review-icon{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.sec-review-heading{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:0;color:var(--navy-primary);font-size:1.2rem;font-weight:800;}
.sec-review-sub{color:var(--text-muted);font-size:0.85rem;margin:3px 0 0;line-height:1.6;}
.sec-count-pill{display:inline-flex;align-items:center;justify-content:center;min-width:26px;height:24px;padding:0 9px;border-radius:999px;background:rgba(0,168,181,0.12);color:var(--cyan-primary);font-size:0.8rem;font-weight:800;}
.sec-count-pill--mint{background:rgba(76,217,161,0.18);color:#0E9F6E;}
.sec-review-actions{display:flex;gap:8px;flex-wrap:wrap;}
.sec-review-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,300px),1fr));gap:1rem;align-items:start;}
.sec-empty{grid-column:1/-1;text-align:center;color:var(--text-muted);font-size:0.9rem;padding:2rem 1rem;background:#F8FAFC;border:1px dashed var(--border-color);border-radius:12px;}
.sec-ai-notice{grid-column:1/-1;background:rgba(245,158,11,0.1);border:1px solid rgba(245,158,11,0.35);color:#92400E;border-radius:10px;padding:10px 14px;font-size:0.82rem;}
.sec-card{background:#F8FAFC;border:1px solid var(--border-color);border-radius:14px;padding:1.25rem;display:flex;flex-direction:column;gap:14px;box-shadow:0 1px 2px rgba(15,23,42,0.03);transition:box-shadow .18s ease,border-color .18s ease;}
.sec-card:hover{box-shadow:var(--shadow-sm);border-color:rgba(0,168,181,0.35);}
.sec-card-head{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;}
.sec-card-label{font-size:0.82rem;font-weight:800;}
.sec-card-badge{font-weight:800;padding:4px 10px;border-radius:7px;font-size:0.78rem;white-space:nowrap;}
.sec-card-badge--mint{background:rgba(76,217,161,0.16);color:#0E9F6E;}
.sec-card-badge--amber{background:rgba(245,158,11,0.16);color:#B45309;}
.sec-card-badge--cyan{background:rgba(0,168,181,0.15);color:var(--cyan-primary);}
.sec-card-body{display:flex;flex-direction:column;gap:10px;}
.sec-card-item{font-size:0.88rem;line-height:1.6;}
.sec-card-item-name{font-weight:800;}
.sec-card-item-name--lost{color:#EF4444;}
.sec-card-item-name--found{color:#0E9F6E;}
.sec-card-meta{color:var(--text-muted);font-size:0.79rem;margin-top:3px;}
.sec-card-feature{font-size:0.79rem;margin-top:4px;color:var(--navy-primary);}
.sec-card-feature--found{color:#0E9F6E;}
.sec-card-divider{border-top:1px dashed var(--border-color);}
.sec-card-code{background:rgba(76,217,161,0.12);border:1px dashed var(--mint-success);border-radius:10px;padding:10px 12px;font-size:0.85rem;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;}
.sec-card-code code{color:var(--cyan-primary);font-weight:800;direction:ltr;unicode-bidi:isolate;letter-spacing:0.5px;font-size:0.95rem;}
.sec-chip-row{display:flex;gap:6px;flex-wrap:wrap;}
.sec-chip{font-size:0.72rem;font-weight:600;padding:3px 8px;border-radius:6px;background:#EEF1F5;color:var(--text-muted);}
.sec-chip--on{background:rgba(76,217,161,0.18);color:#0E9F6E;}
.sec-ai-box{background:linear-gradient(135deg,rgba(147,51,234,0.08),rgba(76,217,161,0.05));border:1px solid rgba(147,51,234,0.28);border-radius:10px;padding:10px 12px;}
.sec-ai-box-head{display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:4px;}
.sec-ai-box-title{font-size:0.78rem;font-weight:800;color:var(--navy-primary);}
.sec-ai-score{font-weight:800;font-size:0.95rem;}
.sec-ai-verdict{font-size:0.78rem;font-weight:800;margin-bottom:4px;}
.sec-ai-reason{font-size:0.8rem;color:var(--navy-primary);line-height:1.65;}
.sec-ai-flags{display:flex;flex-wrap:wrap;gap:5px;margin-top:6px;}
.sec-ai-flag{background:rgba(220,38,38,0.08);color:#B91C1C;padding:2px 8px;border-radius:5px;font-size:0.72rem;}
.sec-card-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:auto;}
.sec-card-actions .btn{flex:1 1 150px;justify-content:center;text-align:center;line-height:1.35;}
.sec-btn-danger{background:#FEF2F2;color:#B91C1C;border:1px solid #FECACA;}
.sec-btn-danger:hover{background:#FEE2E2;border-color:#FCA5A5;color:#991B1B;}
@media (max-width:640px){
  .sec-review-section{padding:1.15rem;border-radius:14px;}
  .sec-review-actions{width:100%;}
  .sec-review-actions .btn{flex:1 1 auto;}
  .sec-card{padding:1.05rem;gap:12px;}
  .sec-card-actions .btn{flex:1 1 100%;}
}
</style>

<main class="main-layout" style="max-width:1280px;margin:0 auto;padding:1.5rem 1rem;">

  <!-- ترويسة لوحة التحكم الأمنية -->
  <div style="background:linear-gradient(135deg,var(--navy-primary) 0%,var(--navy-dark) 100%);color:#FFFFFF;border-radius:16px;padding:2rem;margin-bottom:2rem;box-shadow:var(--shadow-md);position:relative;overflow:hidden;">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1.5rem;">
      <div style="display:flex;align-items:center;gap:16px;">
        <div style="width:56px;height:56px;background:rgba(0,168,181,0.2);border:2px solid var(--cyan-primary);border-radius:14px;display:flex;align-items:center;justify-content:center;">
          <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#00A8B5" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
            <path d="m9 12 2 2 4-4"/>
          </svg>
        </div>
        <div>
          <h2 style="margin:0 0 4px;font-size:1.6rem;font-weight:800;">مركز القيادة والتحقق لضباط الأمن</h2>
          <p style="color:#CBD5E1;margin:0;font-size:0.95rem;">
            مرحباً بك، <strong><?= htmlspecialchars($currentUser['full_name']); ?></strong> | وحدة أمن الجامعة
          </p>
        </div>
      </div>
      <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <button class="btn btn-primary" onclick="openAddFoundModal()" style="display:flex;align-items:center;gap:8px;">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
          </svg>
          تسجيل معثور عليه في المخزون
        </button>
        <button class="btn btn-outline" onclick="reviewSmartMatches()" style="color:#FFFFFF;border-color:rgba(255,255,255,0.4);display:flex;align-items:center;gap:8px;">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M2 12h5"/><path d="M17 12h5"/><path d="M9 12a3 3 0 1 0 6 0 3 3 0 1 0-6 0Z"/>
          </svg>
          مراجعة المطابقات الذكية
        </button>
      </div>
    </div>

    <!-- بطاقات الإحصائيات السريعة -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem;margin-top:1.75rem;">
      <div style="background:rgba(255,255,255,0.08);border-radius:12px;padding:1rem 1.25rem;backdrop-filter:blur(6px);">
        <div style="font-size:0.85rem;color:#CBD5E1;">إجمالي المعثورات بالمخزون</div>
        <div style="font-size:1.8rem;font-weight:800;color:#FFFFFF;margin-top:4px;"><?= $totalFound; ?></div>
      </div>
      <div style="background:rgba(255,255,255,0.08);border-radius:12px;padding:1rem 1.25rem;backdrop-filter:blur(6px);">
        <div style="font-size:0.85rem;color:#CBD5E1;">بانتظار الاستلام والتوثيق</div>
        <div style="font-size:1.8rem;font-weight:800;color:var(--cyan-primary);margin-top:4px;"><?= $totalPendingPickup; ?></div>
      </div>
      <div style="background:rgba(255,255,255,0.08);border-radius:12px;padding:1rem 1.25rem;backdrop-filter:blur(6px);">
        <div style="font-size:0.85rem;color:#CBD5E1;">تم تسليمها وإغلاقها</div>
        <div style="font-size:1.8rem;font-weight:800;color:var(--mint-success);margin-top:4px;"><?= $totalResolved; ?></div>
      </div>
    </div>
  </div>

  <!-- القسم الأول: التحقق السريع والتسليم المادي -->
  <section style="background:#FFFFFF;border-radius:16px;padding:1.75rem;border:1px solid var(--border-color);box-shadow:var(--shadow-sm);margin-bottom:2rem;">
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:1rem;">
      <div style="width:36px;height:36px;background:rgba(0,168,181,0.1);border-radius:8px;display:flex;align-items:center;justify-content:center;">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--cyan-primary)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>
        </svg>
      </div>
      <h3 style="margin:0;color:var(--navy-primary);font-size:1.25rem;font-weight:700;">التحقق السريع من كود الاستلام (RET-XXXXXX)</h3>
    </div>
    
    <div style="display:flex;gap:12px;flex-wrap:wrap;max-width:700px;">
      <input type="text" class="form-control" id="secVerifyCodeInput"
             placeholder="أدخل كود الاستلام (مثال: RET-849201) أو الرقم الجامعي (S000000001)"
             style="flex:1;font-size:1.05rem;font-weight:700;letter-spacing:0.5px;">
      <button class="btn btn-primary" onclick="verifyPickupCode()" style="display:flex;align-items:center;gap:8px;">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>
        </svg>
        التحقق والمطابقة
      </button>
    </div>

    <!-- صندوق نتيجة التحقق -->
    <div id="verifyResultDisplay" style="margin-top:1.5rem;display:none;"></div>
  </section>

  <!-- القسم الثاني: الأغراض المطابقة المعتمدة بانتظار الاستلام والتوثيق -->
  <section id="pendingCollectionSection" class="sec-review-section">
    <div class="sec-review-head">
      <div class="sec-review-title">
        <div class="sec-review-icon" style="background:rgba(76,217,161,0.14);">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--mint-success)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M20 6 9 17l-5-5"/>
          </svg>
        </div>
        <div>
          <h3 class="sec-review-heading">
            <span>بانتظار الاستلام والتوثيق</span>
            <span id="pendingCollectionCount" class="sec-count-pill sec-count-pill--mint">0</span>
          </h3>
          <p class="sec-review-sub">أغراض تمت مطابقتها واعتمادها — راجع كود الاستلام وأكمل التحقق والتسليم المادي.</p>
        </div>
      </div>
      <div class="sec-review-actions">
        <button type="button" class="btn btn-outline btn-sm" data-pending-refresh onclick="loadPendingMatches()">تحديث القائمة ⟳</button>
      </div>
    </div>
    <div id="pendingCollectionContainer" class="sec-review-grid">
      <div class="sec-empty">جاري تحميل الأغراض بانتظار الاستلام...</div>
    </div>
  </section>

  <!-- القسم الثالث: مقترحات المطابقة الذكية للمراجعة (Human-in-the-Loop) -->
  <section id="smartMatchingSection" class="sec-review-section" style="scroll-margin-top:96px;">
    <div class="sec-review-head">
      <div class="sec-review-title">
        <div class="sec-review-icon" style="background:rgba(147,51,234,0.14);">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#7C3AED" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>
          </svg>
        </div>
        <div>
          <h3 class="sec-review-heading">
            <span>المطابقات الذكية المقترحة</span>
            <span id="smartSuggestionsCount" class="sec-count-pill">0</span>
          </h3>
          <p class="sec-review-sub">
            يقترحها محرك <span translate="no" class="notranslate brand-wordmark" dir="rtl" style="display:inline-block;unicode-bidi:isolate;font-weight:700;color:#00A8B5;">لُقيا AI</span> ويحللها Gemini دلالياً — اعتمد المطابقة أو ألغِها يدوياً.
          </p>
        </div>
      </div>
      <div class="sec-review-actions">
        <button type="button" class="btn btn-outline btn-sm" data-pending-refresh onclick="loadPendingMatches()">تحديث المقترحات ⟳</button>
        <button type="button" class="btn btn-sm" style="background:linear-gradient(135deg,#8B5CF6,#6D28D9);color:#FFFFFF;border:none;display:inline-flex;align-items:center;gap:6px;" onclick="runAIAnalysis(true)">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2a3 3 0 0 1 3 3v1.5A5 5 0 0 1 12 17a5 5 0 0 1-3-1.5V5a3 3 0 0 1 3-3Z"/><path d="M5 14c0 2 2 4 4 4M19 14c0 2-2 4-4 4M8 4h8M8 7h8"/></svg>
          تحليل بالذكاء الاصطناعي (Gemini)
        </button>
      </div>
    </div>
    <div id="smartSuggestionsContainer" class="sec-review-grid">
      <div class="sec-empty">جاري تحميل مقترحات المطابقة...</div>
    </div>
  </section>

  <!-- القسم الرابع: جدول إدارة مخزون المعثورات الكامل (CRUD) -->
  <section style="background:#FFFFFF;border-radius:16px;padding:1.75rem;border:1px solid var(--border-color);box-shadow:var(--shadow-sm);">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem;margin-bottom:1.5rem;">
      <div>
        <h3 style="margin:0;color:var(--navy-primary);font-size:1.25rem;font-weight:700;">سجل مخزون المعثورات المادية بمكتب الأمن</h3>
        <p style="color:var(--text-muted);font-size:0.85rem;margin:2px 0 0;">
          إدارة كاملة للمعثورات المحفوظة فعلياً في خزائن مكتب الأمن (تعديل، حذف، وتسليم).
        </p>
      </div>
      <button class="btn btn-primary btn-sm" onclick="openAddFoundModal()" style="display:flex;align-items:center;gap:6px;">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
          <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
        </svg>
        إضافة معثور عليه
      </button>
    </div>

    <?php if (count($inventoryItems) === 0): ?>
      <div style="text-align:center;padding:3rem 1rem;color:var(--text-muted);">
        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom:12px;opacity:0.5;">
          <path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/>
          <path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/>
        </svg>
        <h4>لا توجد عناصر في مخزون مكتب الأمن حالياً</h4>
        <p style="font-size:0.88rem;">يمكنك تسجيل معثورات جديدة مباشرة بالضغط على زر "إضافة معثور عليه".</p>
      </div>
    <?php else: ?>
      <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;text-align:right;font-size:0.92rem;">
          <thead>
            <tr style="background:#F8FAFC;border-bottom:2px solid var(--border-color);color:var(--navy-primary);">
              <th style="padding:12px 10px;">رقم البلاغ</th>
              <th style="padding:12px 10px;">الصورة</th>
              <th style="padding:12px 10px;">العنصر</th>
              <th style="padding:12px 10px;">التصنيف</th>
              <th style="padding:12px 10px;">الموقع ومكان الحفظ</th>
              <th style="padding:12px 10px;">تاريخ العثور</th>
              <th style="padding:12px 10px;">تاريخ التسليم</th>
              <th style="padding:12px 10px;">كود الاستلام</th>
              <th style="padding:12px 10px;">الحالة</th>
              <th style="padding:12px 10px;text-align:center;">الإجراءات</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($inventoryItems as $inv): ?>
              <tr data-report-id="<?= (int)$inv['id']; ?>" style="border-bottom:1px solid var(--border-color);">
                <td style="padding:10px;">
                  <code style="color:var(--cyan-primary);font-weight:800;background:rgba(0,168,181,0.08);padding:3px 8px;border-radius:6px;">#<?= (int)$inv['id']; ?></code>
                </td>
                <td style="padding:10px;">
                  <img src="<?= htmlspecialchars($inv['image_url'] ?: 'assets/images/sample_bag.svg'); ?>"
                       alt="<?= htmlspecialchars($inv['title']); ?>"
                       style="width:48px;height:48px;border-radius:8px;object-fit:cover;border:1px solid var(--border-color);background:#F8FAFC;">
                </td>
                <td style="padding:10px;">
                  <strong style="color:var(--navy-primary);display:block;"><?= htmlspecialchars($inv['title']); ?></strong>
                  <span style="font-size:0.8rem;color:var(--text-muted);display:block;max-width:220px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                    <?= htmlspecialchars($inv['description']); ?>
                  </span>
                </td>
                <td style="padding:10px;font-size:0.85rem;">
                  <?= htmlspecialchars(categoryAr($inv['category'])); ?>
                </td>
                <td style="padding:10px;font-size:0.85rem;">
                  <?= htmlspecialchars($inv['location']); ?>
                </td>
                <td style="padding:10px;font-size:0.85rem;color:var(--text-muted);">
                  <?= htmlspecialchars($inv['date_event']); ?>
                </td>
                <td style="padding:10px;font-size:0.85rem;color:var(--text-muted);">
                  <?php if (!empty($inv['resolved_at'])): ?>
                    <?= htmlspecialchars(date('Y-m-d H:i', strtotime($inv['resolved_at']))); ?>
                  <?php else: ?>
                    <span style="color:#B0B7C3;font-size:0.8rem;">-</span>
                  <?php endif; ?>
                </td>
                <td style="padding:10px;">
                  <?php if (!empty($inv['pickup_code'])): ?>
                    <code style="color:var(--cyan-primary);font-weight:700;background:rgba(0,168,181,0.08);padding:3px 8px;border-radius:6px;">
                      <?= htmlspecialchars($inv['pickup_code']); ?>
                    </code>
                  <?php else: ?>
                    <span style="color:var(--text-muted);font-size:0.8rem;">-</span>
                  <?php endif; ?>
                </td>
                <td style="padding:10px;">
                  <span class="status-badge <?= $inv['status']; ?>" style="position:static;display:inline-block;font-size:0.8rem;">
                    <?= statusAr($inv['status']); ?>
                  </span>
                </td>
                <td style="padding:10px;text-align:center;">
                  <div style="display:flex;gap:6px;justify-content:center;flex-wrap:wrap;">
                    <?php if (!empty($inv['pickup_code']) && $inv['status'] !== 'resolved'): ?>
                      <button class="btn btn-success btn-sm" title="تسليم للطالب"
                        onclick="setTestCode('<?= htmlspecialchars($inv['pickup_code']); ?>'); verifyPickupCode();">
                        تسليم
                      </button>
                    <?php endif; ?>
                    
                    <button class="btn btn-outline btn-sm" title="تعديل"
                      onclick='openEditModal(<?= json_encode($inv, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>)'>
                      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                      </svg>
                    </button>

                    <button class="btn btn-sm" style="background:rgba(239,68,68,0.1);color:#EF4444;border:none;" title="حذف"
                      onclick="deleteFoundItem(<?= $inv['id']; ?>)">
                      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                      </svg>
                    </button>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

</main>

<!-- Modal: تسجيل معثور عليه جديد من لوحة الأمن -->
<div class="modal" id="modalAddFound">
  <div class="modal-content" style="max-width:580px;border-radius:16px;">
    <div class="modal-header">
      <h3 style="margin:0;color:var(--navy-primary);display:flex;align-items:center;gap:8px;">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="var(--cyan-primary)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/>
        </svg>
        تسجيل معثور عليه جديد بمكتب الأمن
      </h3>
      <button class="close-modal" onclick="closeAddFoundModal()">&times;</button>
    </div>

    <form id="secAddFoundForm" onsubmit="submitSecAddFound(event)">
      <div class="modal-body" style="display:flex;flex-direction:column;gap:1rem;">
        
        <!-- قسم رفع وتحليل الصورة بـ Gemini Vision (نفس تجربة نموذج الطالب) -->
        <div style="background:#F8FAFC;border:2px dashed var(--border-color);border-radius:12px;padding:1.25rem;text-align:center;">
          <label class="btn-upload">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/><polyline points="16 11 12 15 8 11"/></svg>
            التقاط بالكاميرا أو رفع صورة العنصر
            <input type="file" name="photo" id="secFoundImageInput" accept="image/*" style="display:none;" onchange="analyzeImageWithAI(this,'SecFound')">
          </label>
          <div id="secImagePreviewBox" style="margin-top:6px;"></div>
        </div>

        <div>
          <label class="form-label" style="font-weight:700;">اسم / عنوان الغرض المعثور عليه *</label>
          <input type="text" class="form-control" name="title" id="secItemTitle" required placeholder="مثال: محفظة جلدية، بطاقة جامعية، لابتوب HP..." oninput="autoGenerateTagsFromInput('SecFound')">
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
          <div>
            <label class="form-label" style="font-weight:700;">التصنيف *</label>
            <select class="form-control" name="category" id="secItemCategory" required onchange="autoGenerateTagsFromInput('SecFound')">
              <option value="مقتنيات شخصية">مقتنيات شخصية</option>
              <option value="البطاقة الجامعية / الصراف">البطاقة الجامعية / الصراف</option>
              <option value="إلكترونيات">إلكترونيات</option>
              <option value="وثائق ومستندات">وثائق ومستندات</option>
              <option value="حقائب وحقائب ظهر">حقائب وحقائب ظهر</option>
              <option value="مفاتيح">مفاتيح</option>
              <option value="ملابس وإكسسوارات">ملابس وإكسسوارات</option>
              <option value="أخرى">أخرى</option>
            </select>
          </div>
          <div>
            <label class="form-label" style="font-weight:700;">تاريخ العثور / الاستلام *</label>
            <input type="date" class="form-control" name="date_event" id="secItemDate" value="<?= date('Y-m-d'); ?>" required>
          </div>
        </div>

        <div>
          <label class="form-label" style="font-weight:700;">الرقم الجامعي المسجّل على الغرض (من البطاقة أو يدوياً)</label>
          <small style="display:block;color:var(--cyan-primary);font-size:0.78rem;margin-bottom:6px;">
            يُستخرج تلقائياً من صورة البطاقة الجامعية عبر الرؤية الذكية (Gemini Vision)، ويمكن تعديله أو إدخاله يدوياً.
          </small>
          <input type="text" class="form-control" name="extracted_student_id" id="secExtractedStudentId"
                 placeholder="مثال: S000000001" dir="ltr" oninput="syncSecOcrData()">
        </div>

        <div>
          <label class="form-label" style="font-weight:700;">مكان العثور عليه أو الخزانة بمكتب الأمن *</label>
          <input type="text" class="form-control" name="location" id="secItemLocation" value="مكتب الأمن الرئيسي - خزانة رقم 1" required>
        </div>

        <div>
          <label class="form-label" style="font-weight:700;">الوصف والمواصفات الدقيقة *</label>
          <textarea class="form-control" name="description" id="secItemDesc" rows="3" required placeholder="اكتب وصفاً مفصلاً للون، الماركة، العلامات المميزة..." oninput="autoGenerateTagsFromInput('SecFound')"></textarea>
        </div>

        <div>
          <label class="form-label" style="font-weight:700;">العلامات المميزة (علامات مميزة)</label>
          <small style="display:block;color:var(--cyan-primary);font-size:0.78rem;margin-bottom:6px;">
            تُستخدم لرفع نسبة المطابقة مع بلاغ الطالب الحقيقي (مثال: خدش بالزاوية، ملصق، سلسلة زرقاء، نقش محدد).
          </small>
          <input type="text" class="form-control" name="distinctive_features" id="secItemFeatures"
                 placeholder="مثال: خدش صغير في الجهة الأمامية + ملصق جامعي"
                 oninput="autoGenerateTagsFromInput('SecFound')">
        </div>

        <!-- الوسوم الذكية المستخرجة بالرؤية الذكية -->
        <div style="background:rgba(0,168,181,0.05);padding:12px;border-radius:10px;border:1px dashed var(--cyan-primary);">
          <strong style="font-size:0.85rem;color:var(--navy-primary);display:flex;align-items:center;gap:6px;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--cyan-primary)" stroke-width="2"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
            الوسوم المستخرجة بالرؤية الذكية
          </strong>
          <div id="secItemTagsPreview" class="tags-row" style="margin-top:6px;">
            <span class="ai-tag"> مقتنيات شخصية</span>
          </div>
        </div>

        <input type="hidden" name="ai_tags" id="secItemTags">
        <input type="hidden" name="ocr_data" id="secItemOcrData">
        <input type="hidden" name="image_url" id="secItemSavedImagePath">

      </div>
      <div class="modal-footer" style="padding:1rem 1.5rem;display:flex;justify-content:flex-end;gap:10px;">
        <button type="button" class="btn btn-outline" onclick="closeAddFoundModal()">إلغاء</button>
        <button type="submit" class="btn btn-primary" id="btnSecSubmit">حفظ وتسجيل بالمخزون</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: تعديل غرض معثور عليه -->
<div class="modal" id="modalEditFound">
  <div class="modal-content" style="max-width:540px;border-radius:16px;">
    <div class="modal-header">
      <h3 style="margin:0;color:var(--navy-primary);">تعديل بيانات المعثور عليه</h3>
      <button class="close-modal" onclick="closeEditModal()">&times;</button>
    </div>
    <form id="secEditFoundForm" onsubmit="submitSecEditFound(event)">
      <div class="modal-body" style="display:flex;flex-direction:column;gap:1rem;">
        <input type="hidden" name="id" id="editItemId">
        
        <div>
          <label class="form-label" style="font-weight:700;">العنوان *</label>
          <input type="text" class="form-control" name="title" id="editItemTitle" required>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
          <div>
            <label class="form-label" style="font-weight:700;">التصنيف *</label>
            <select class="form-control" name="category" id="editItemCategory" required>
              <option value="مقتنيات شخصية">مقتنيات شخصية</option>
              <option value="البطاقة الجامعية / الصراف">البطاقة الجامعية / الصراف</option>
              <option value="إلكترونيات">إلكترونيات</option>
              <option value="وثائق ومستندات">وثائق ومستندات</option>
              <option value="حقائب وحقائب ظهر">حقائب وحقائب ظهر</option>
              <option value="مفاتيح">مفاتيح</option>
              <option value="ملابس وإكسسوارات">ملابس وإكسسوارات</option>
              <option value="أخرى">أخرى</option>
            </select>
          </div>
          <div>
            <label class="form-label" style="font-weight:700;">الحالة</label>
            <select class="form-control" name="status" id="editItemStatus">
              <option value="active">نشط بالمخزون</option>
              <option value="pending_pickup">بانتظار الاستلام</option>
              <option value="resolved">تم الاستلام</option>
            </select>
          </div>
        </div>

        <div>
          <label class="form-label" style="font-weight:700;">الموقع *</label>
          <input type="text" class="form-control" name="location" id="editItemLocation" required>
        </div>

        <div>
          <label class="form-label" style="font-weight:700;">الوصف *</label>
          <textarea class="form-control" name="description" id="editItemDesc" rows="3" required></textarea>
        </div>

        <div>
          <label class="form-label" style="font-weight:700;">العلامات المميزة</label>
          <input type="text" class="form-control" name="distinctive_features" id="editItemFeatures"
                 placeholder="مثال: خدش صغير في الجهة الأمامية + ملصق جامعي">
        </div>
      </div>
      <div class="modal-footer" style="display:flex;justify-content:flex-end;gap:10px;">
        <button type="button" class="btn btn-outline" onclick="closeEditModal()">إلغاء</button>
        <button type="submit" class="btn btn-primary">حفظ التعديلات</button>
      </div>
    </form>
  </div>
</div>

<script>
function escapeHtml(value) {
  return String(value === null || value === undefined ? '' : value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function setTestCode(code) {
  document.getElementById('secVerifyCodeInput').value = code;
}

function statusLabelAr(s, resolvedAt) {
  if (s === 'active') return 'متوفر بالمخزون';
  if (s === 'matched') return 'مطابق بالذكاء الاصطناعي';
  if (s === 'pending_pickup') return 'بانتظار الاستلام';
  if (s === 'resolved') return resolvedAt ? ('تم الاستلام - ' + resolvedAt) : 'تم الاستلام';
  if (s === 'cancelled') return 'ملغي';
  return s;
}

function verifyPickupCode() {
  const code    = document.getElementById('secVerifyCodeInput').value.trim();
  const display = document.getElementById('verifyResultDisplay');

  if (!code) {
    showToast('يرجى إدخال كود الاستلام أو الرقم الجامعي', 'danger');
    return;
  }

  display.style.display = 'block';
  display.innerHTML = `<p style="color:var(--cyan-primary);"> جاري التحقق من الكود في قاعدة بيانات <span translate="no" class="notranslate brand-wordmark" dir="rtl" style="display: inline-block; unicode-bidi: isolate;">لُقيا AI</span>...</p>`;

  const fd = new FormData();
  fd.append('code', code);
  fd.append('_csrf', window.LUQYA_CSRF);

  fetch('api/security.php?action=verify', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      if (data.success && data.reports && data.reports.length > 0) {
        const rep = data.reports[0];
        const resolvedAt = rep.resolved_at ? rep.resolved_at.replace('T', ' ') : '';
        const paired = (data.reports || []);
        const partnersHtml = paired.map(r => {
          const ra = r.resolved_at ? r.resolved_at.replace('T', ' ') : '';
          const role = r.type === 'lost' ? 'مفقود' : 'معثور عليه';
          const roleColor = r.type === 'lost' ? 'var(--cyan-primary)' : 'var(--mint-success)';
          return `
            <div data-report-id="${escapeHtml(r.id)}" style="display:flex;justify-content:space-between;align-items:center;gap:10px;background:#fff;border:1px solid rgba(76,217,161,0.3);border-radius:10px;padding:0.75rem 1rem;margin-bottom:0.5rem;flex-wrap:wrap;">
              <div style="display:flex;align-items:center;gap:8px;">
                <span style="font-weight:700;color:${roleColor};">${r.type === 'lost' ? '' : ''} ${role}:</span>
                <span style="font-weight:600;color:var(--navy-primary);">${escapeHtml(r.title)}</span>
                <code style="color:var(--cyan-primary);font-weight:800;font-size:0.78rem;background:rgba(0,168,181,0.08);padding:2px 7px;border-radius:6px;">رقم البلاغ: #${escapeHtml(r.id)}</code>
              </div>
              <span class="status-badge ${escapeHtml(r.status)}" style="position:static;">${escapeHtml(statusLabelAr(r.status, ra))}</span>
            </div>`;
        }).join('');

        display.innerHTML = `
          <div style="background:rgba(76,217,161,0.08);border:2px solid var(--mint-success);border-radius:14px;padding:1.5rem;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;flex-wrap:wrap;gap:8px;">
              <span style="font-weight:800;color:var(--navy-primary);font-size:1.15rem;display:flex;align-items:center;gap:6px;">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="var(--mint-success)" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                تم تأكيد الكود وهوية الطالب المالك
              </span>
            </div>

            <div style="margin-bottom:1.25rem;">${partnersHtml}</div>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:1.25rem;margin-bottom:1.5rem;background:#fff;padding:1.25rem;border-radius:10px;border:1px solid rgba(76,217,161,0.3);">
              <div>
                <strong style="color:var(--navy-primary);display:block;margin-bottom:4px;">بيانات العنصر:</strong>
                <div><strong>رقم البلاغ:</strong> <code style="color:var(--cyan-primary);font-weight:800;">#${escapeHtml(rep.id)}</code></div>
                <div><strong>الاسم:</strong> ${escapeHtml(rep.title)}</div>
                <div><strong>التصنيف:</strong> ${escapeHtml(rep.category)}</div>
                <div><strong>الموقع المسجل:</strong> ${escapeHtml(rep.location)}</div>
                <div style="font-size:0.85rem;color:var(--text-muted);margin-top:4px;"><strong>الوصف:</strong> ${escapeHtml(rep.description)}</div>
                <div style="font-size:0.85rem;color:var(--text-muted);margin-top:4px;"><strong>تاريخ العثور:</strong> ${escapeHtml(rep.date_event)}</div>
              </div>
              <div>
                <strong style="color:var(--navy-primary);display:block;margin-bottom:4px;">بيانات المالك:</strong>
                <div><strong>الاسم الكامل:</strong> ${escapeHtml(rep.owner_name)}</div>
                <div><strong>الرقم الجامعي:</strong> <code style="color:var(--cyan-primary);font-weight:700;">${escapeHtml(rep.owner_student_id)}</code></div>
                <div><strong>البريد:</strong> ${escapeHtml(rep.owner_email || '-')}</div>
                <div><strong>الهاتف:</strong> ${escapeHtml(rep.owner_phone || '-')}</div>
                <div style="margin-top:6px;"><strong>كود الاستلام:</strong> <code style="color:var(--mint-success);font-weight:800;font-size:1.05rem;">${escapeHtml(rep.pickup_code || code)}</code></div>
              </div>
            </div>

            ${paired.some(p => p.status !== 'resolved') ? `
              <button class="btn btn-success" style="width:100%;font-size:1.05rem;font-weight:700;display:flex;align-items:center;justify-content:center;gap:8px;padding:12px;"
                onclick="confirmHandover('${escapeHtml(rep.pickup_code || code)}', ${Number(rep.id) || 0})">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                تسليم المفقودات مادياً وإغلاق البلاغ
              </button>
            ` : `
              <div style="text-align:center;color:var(--mint-success);font-weight:700;">
                 تم تسليم العنصر وإغلاق كلا البلاغين (المفقود والمعثور عليه).${resolvedAt ? ' وقت التسليم الفعلي: ' + escapeHtml(resolvedAt) : ''}
              </div>
            `}
          </div>`;
        showToast('تم التحقق من بيانات الكود والمالك بنجاح!', 'success');
      } else {
        display.innerHTML = `
          <div style="background:rgba(239,68,68,0.08);border:1.5px solid #EF4444;border-radius:12px;padding:1.25rem;color:#EF4444;display:flex;align-items:center;gap:10px;">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#EF4444" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
            <div>لم يتم العثور على سجل نشط يطابق الكود: <strong>${escapeHtml(code)}</strong></div>
          </div>`;
        showToast('كود غير صحيح أو لا يوجد سجل نشط', 'danger');
      }
    })
    .catch(() => showToast('خطأ في الاتصال بالخادم', 'danger'));
}

function confirmHandover(pickupCode, reportId) {
  if (!confirm('هل أنت متأكد من تسليم المفقود مادياً للطالب وإغلاق البلاغ؟')) return;

  const fd = new FormData();
  fd.append('pickup_code', pickupCode);
  fd.append('_csrf', window.LUQYA_CSRF);
  // إرسال رقم البلاغ أيضاً ليعثر الخادم على الزوج من جدول المطابقات حتى لو اختلف الكود
  if (reportId) fd.append('report_id', reportId);

  fetch('api/security.php?action=handover', { method:'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        // إعادة تحميل كاملة بعد نجاح التسليم: تضمن أن PHP يعيد جلب كلا البلاغين
        // (المفقود + المعثور) من قاعدة البيانات ويرسمهما بحالة "تم الاستلام" دون أي مشاكل مزامنة.
        window.location.reload();
      } else {
        showToast(data.message || 'فشل تأكيد التسليم', 'danger');
      }
    })
    .catch(() => showToast('خطأ في الاتصال بالخادم', 'danger'));
}

let pendingAIStatus = '';

function pendingAiBlock(s) {
  const ai = s.ai;
  if (!ai || typeof ai.score !== 'number') return '';
  const scoreColor = ai.score >= 75 ? '#16A34A' : (ai.score >= 45 ? '#D97706' : '#DC2626');
  const flagsHtml = (ai.flags && ai.flags.length)
    ? '<div class="sec-ai-flags">' + ai.flags.map(function (f) {
        return '<span class="sec-ai-flag"> ' + escapeHtml(f) + '</span>';
      }).join('') + '</div>'
    : '';
  return '<div class="sec-ai-box">'
    + '<div class="sec-ai-box-head">'
    + '<span class="sec-ai-box-title"> تحليل الذكاء الاصطناعي (Gemini)</span>'
    + '<span class="sec-ai-score" style="color:' + scoreColor + ';">' + escapeHtml(ai.score) + '%</span></div>'
    + '<div class="sec-ai-verdict" style="color:' + scoreColor + ';">' + escapeHtml(ai.verdict_label) + '</div>'
    + '<div class="sec-ai-reason">' + escapeHtml(ai.reason) + '</div>'
    + flagsHtml + '</div>';
}

// توزيع عناصر المراجعة على قسمين مستقلين: بانتظار الاستلام + مقترحات المطابقة الذكية
function renderReviewItems(items) {
  const pendingContainer = document.getElementById('pendingCollectionContainer');
  const suggContainer = document.getElementById('smartSuggestionsContainer');
  if (!pendingContainer || !suggContainer) return;

  const list = Array.isArray(items) ? items : [];
  const pending = list.filter(s => s.kind === 'pending_pickup');
  const suggestions = list.filter(s => s.kind !== 'pending_pickup');

  const pendingCountEl = document.getElementById('pendingCollectionCount');
  const suggCountEl = document.getElementById('smartSuggestionsCount');
  if (pendingCountEl) pendingCountEl.textContent = pending.length;
  if (suggCountEl) suggCountEl.textContent = suggestions.length;

  pendingContainer.innerHTML = pending.length
    ? pending.map(renderPendingPickupCard).join('')
    : '<div class="sec-empty">لا توجد أغراض بانتظار الاستلام حالياً.</div>';

  const aiNotice = pendingAIStatus ? '<div class="sec-ai-notice">' + pendingAIStatus + '</div>' : '';
  suggContainer.innerHTML = aiNotice + (suggestions.length
    ? suggestions.map(renderSuggestionCard).join('')
    : '<div class="sec-empty">لا توجد مقترحات مطابقة جديدة بانتظار المراجعة حالياً.</div>');
}

// بطاقة غرض معثور عليه بانتظار الاستلام/التوثيق — جاهز لاتخاذ إجراء فوري
function renderPendingPickupCard(s) {
  const found = s.found || {};
  const lost = s.lost || null;
  const code = found.pickup_code || '';
  const isMatched = found.status === 'matched';
  const statusText = isMatched ? 'مطابق — بانتظار التوثيق' : 'بانتظار الاستلام';
  const statusColor = isMatched ? '#B45309' : '#0E9F6E';
  const statusBadgeClass = isMatched ? 'sec-card-badge--amber' : 'sec-card-badge--mint';

  const lostHtml = lost
    ? `<div class="sec-card-item">
         <span class="sec-card-item-name sec-card-item-name--lost">المفقود:</span> ${escapeHtml(lost.title)}${lost.full_name ? ' <span class="sec-card-meta">(' + escapeHtml(lost.full_name) + ')</span>' : ''}
         <div class="sec-card-meta">${escapeHtml(lost.location || '')}${lost.category ? ' | ' + escapeHtml(lost.category) : ''}</div>
       </div>`
    : '<div class="sec-card-item" style="color:var(--text-muted);">لا يوجد بلاغ مفقود مرتبط مباشرةً — يمكن التسليم بعد التحقق من هوية المستلم.</div>';

  const featuresFound = found.distinctive_features
    ? `<div class="sec-card-feature sec-card-feature--found"> مميزات المعثور: ${escapeHtml(found.distinctive_features)}</div>`
    : '';

  const codeHtml = code
    ? `<div class="sec-card-code"><span>كود الاستلام</span><code translate="no">${escapeHtml(code)}</code></div>`
    : '';

  return `
    <div class="sec-card" data-report-id="${escapeHtml(found.id || '')}" data-lost-id="${escapeHtml((lost && lost.id) || '')}">
      <div class="sec-card-head">
        <span class="sec-card-label" style="color:${statusColor};"> ${statusText}</span>
        <span class="sec-card-badge ${statusBadgeClass}">جاهز لاتخاذ إجراء</span>
      </div>

      <div class="sec-card-body">
        ${lostHtml}
        <div class="sec-card-divider"></div>
        <div class="sec-card-item">
          <span class="sec-card-item-name sec-card-item-name--found">المعثور عليه:</span> ${escapeHtml(found.title || '')}
          <div class="sec-card-meta">${escapeHtml(found.location || '')} |  رقم البلاغ: #${escapeHtml(found.id || '')}</div>
          ${featuresFound}
        </div>
        ${codeHtml}
      </div>

      <div class="sec-card-actions">
        <button class="btn btn-primary btn-sm" onclick="goVerifyCode('${escapeHtml(code)}')"> التحقق وإتمام التسليم</button>
      </div>
    </div>
  `;
}

// بطاقة مقترح مطابقة جديد — اعتماد أو إلغاء (Human-in-the-Loop)
function renderSuggestionCard(s) {
  const b = s.breakdown || {};
  const featuresLost = s.lost.distinctive_features
    ? '<div class="sec-card-feature"> مميزات المفقود: ' + escapeHtml(s.lost.distinctive_features) + '</div>' : '';
  const featuresFound = s.found.distinctive_features
    ? '<div class="sec-card-feature sec-card-feature--found"> مميزات المعثور: ' + escapeHtml(s.found.distinctive_features) + '</div>' : '';
  const chip = (val, label) => '<span class="sec-chip' + (val > 0 ? ' sec-chip--on' : '') + '">' + label + ' ' + (val || 0) + '</span>';

  return `
    <div class="sec-card" data-report-id="${escapeHtml(s.found.id || '')}" data-lost-id="${escapeHtml(s.lost.id || '')}">
      <div class="sec-card-head">
        <span class="sec-card-label" style="color:#7C3AED;">مقترح مطابقة</span>
        <span class="sec-card-badge sec-card-badge--cyan">نسبة الثقة: ${s.confidence_score}%</span>
      </div>

      <div class="sec-card-body">
        <div class="sec-card-item">
          <span class="sec-card-item-name sec-card-item-name--lost">المفقود:</span> ${escapeHtml(s.lost.title)} <span class="sec-card-meta">(${escapeHtml(s.lost.full_name)})</span>
          <div class="sec-card-meta">${escapeHtml(s.lost.location)} | ${escapeHtml(s.lost.category)} |  رقم البلاغ: #${escapeHtml(s.lost.id)}</div>
          ${featuresLost}
        </div>
        <div class="sec-card-divider"></div>
        <div class="sec-card-item">
          <span class="sec-card-item-name sec-card-item-name--found">المعثور عليه:</span> ${escapeHtml(s.found.title)}
          <div class="sec-card-meta">${escapeHtml(s.found.location)} |  رقم البلاغ: #${escapeHtml(s.found.id)}</div>
          ${featuresFound}
        </div>

        <div class="sec-chip-row">
          ${chip(b.category, 'التصنيف')}
          ${chip(b.location, 'الموقع')}
          ${chip(b.keywords, 'النصوص')}
          ${chip(b.distinctive_features, 'المميزات')}
          ${b.ocr > 0 ? '<span class="sec-chip sec-chip--on">OCR ' + b.ocr + '</span>' : ''}
        </div>

        ${pendingAiBlock(s)}
      </div>

      <div class="sec-card-actions">
        <button class="btn btn-primary btn-sm" onclick="confirmMatchFromOfficer(${s.lost.id}, ${s.found.id}, ${s.confidence_score})"> اعتماد المطابقة وإصدار كود الاستلام</button>
        <button class="btn btn-sm sec-btn-danger" onclick="rejectMatchFromOfficer(${s.lost.id}, ${s.found.id})"> إلغاء التطابق</button>
      </div>
    </div>
  `;
}

// التمرير السلس إلى قسم المطابقات الذكية ثم تحميل المقترحات فوراً ليرى الضابط العناصر المعلقة
function reviewSmartMatches() {
  const section = document.getElementById('smartMatchingSection');
  if (section) {
    const reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    section.scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth', block: 'start' });
  }
  loadPendingMatches();
}

// نقل كود الاستلام إلى قسم التحقق السريع وتشغيل التحقق فوراً
function goVerifyCode(code) {
  const input = document.getElementById('secVerifyCodeInput');
  if (input) {
    input.value = code;
    input.scrollIntoView({ behavior: 'smooth', block: 'center' });
    input.focus();
  }
  if (code) verifyPickupCode();
}

// حارس تزامن لطلب واحد معلّق: يمنع تراكم الطلبات عند النقر المتكرر أو المزدوج
// على أزرار التحديث، فكان كل ضغطة إضافية تطلق شبكة كاملة تُتجاهل عند وصولها.
let pendingMatchesInFlight = false;
let pendingMatchesQueued = false;

function setPendingRefreshDisabled(disabled) {
  document.querySelectorAll('[data-pending-refresh]').forEach(btn => {
    btn.disabled = !!disabled;
    btn.style.opacity = disabled ? '0.55' : '';
    btn.style.cursor = disabled ? 'progress' : '';
  });
}

function loadPendingMatches() {
  // إن كان هناك طلب جارٍ: لا نطلق ثانياً، بل نعلّم أن آخر نية يجب تنفيذها بعده.
  if (pendingMatchesInFlight) { pendingMatchesQueued = true; return; }

  const pendingContainer = document.getElementById('pendingCollectionContainer');
  const suggContainer = document.getElementById('smartSuggestionsContainer');
  if (pendingContainer) pendingContainer.innerHTML = '<div class="sec-empty"> جاري تحميل الأغراض بانتظار الاستلام...</div>';
  if (suggContainer) suggContainer.innerHTML = '<div class="sec-empty"> جاري حساب نسب التطابق الذكي...</div>';
  pendingAIStatus = '';
  pendingMatchesInFlight = true;
  setPendingRefreshDisabled(true);

  fetch('api/security.php?action=list_pending_matches')
    .then(r => r.json())
    .then(data => {
      if (data.success && data.suggestions && data.suggestions.length > 0) {
        renderReviewItems(data.suggestions);
        // تشغيل التحليل الدلالي بـ Gemini تلقائياً لمقترحات المطابقة غير المحللة بعد (تخطّي المعلقة)
        const needsAI = data.suggestions.some(s => s.kind === 'suggestion' && !s.ai);
        if (needsAI) runAIAnalysis(false);
      } else {
        renderReviewItems([]);
      }
    })
    .catch(() => {
      if (pendingContainer) pendingContainer.innerHTML = '<div class="sec-empty" style="color:#EF4444;">تعذر تحميل عناصر المراجعة.</div>';
      if (suggContainer) suggContainer.innerHTML = '<div class="sec-empty" style="color:#EF4444;">تعذر تحميل مقترحات المطابقة.</div>';
    })
    .finally(() => {
      pendingMatchesInFlight = false;
      setPendingRefreshDisabled(false);
      // نقرات متتابعة أثناء التحميل: نعيد الجلب مرة واحدة فقط بعدها.
      if (pendingMatchesQueued) { pendingMatchesQueued = false; loadPendingMatches(); }
    });
}

function runAIAnalysis(force) {
  const container = document.getElementById('smartSuggestionsContainer');
  if (!container) return;
  let statusEl = document.getElementById('aiAnalysisStatus');
  if (!statusEl) {
    statusEl = document.createElement('div');
    statusEl.id = 'aiAnalysisStatus';
    statusEl.setAttribute('style', 'grid-column:1/-1;margin-bottom:0.35rem;');
    container.insertBefore(statusEl, container.firstChild);
  }
  statusEl.innerHTML = '<span style="color:var(--cyan-primary);"> جاري تحليل الأوصاف ومطابقتها بالذكاء الاصطناعي (Gemini)...</span>';

  const fd = new FormData();
  fd.append('_csrf', window.LUQYA_CSRF);
  if (force) fd.append('force', '1');

  fetch('api/security.php?action=ai_analyze_matches', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      if (data.success && data.suggestions) {
        if (data.gemini_error) {
          pendingAIStatus = ' تعذر الاتصال بخدمة الذكاء الاصطناعي (Gemini) حالياً، تم عرض نتائج المطابقة المحلية فقط.';
          renderReviewItems(data.suggestions);
          showToast('المطابقة المحلية ظاهرة؛ Gemini غير متاح حالياً.', 'warning');
        } else if (force && data.analyzed === 0) {
          pendingAIStatus = 'النتائج محدّثة بالفعل حسب أحدث تحليل للذكاء الاصطناعي.';
          renderReviewItems(data.suggestions);
        } else {
          pendingAIStatus = '';
          renderReviewItems(data.suggestions);
          if (data.analyzed > 0) {
            showToast('تم تحليل ' + data.analyzed + ' مقترح بالمحرك الدلالي Gemini.', 'success', 4000);
          }
        }
      }
    })
    .catch(() => {
      pendingAIStatus = ' فشل تشغيل التحليل بالذكاء الاصطناعي.';
      showToast('فشل تحليل الذكاء الاصطناعي', 'danger');
    });
}

function confirmMatchFromOfficer(lostId, foundId, score) {
  const fd = new FormData();
  fd.append('lost_id', lostId);
  fd.append('found_id', foundId);
  fd.append('confidence', score);
  fd.append('_csrf', window.LUQYA_CSRF);

  fetch('api/security.php?action=confirm_match', { method:'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        showToast(data.message, 'success', 5000);
        loadPendingMatches();
        setTimeout(() => window.location.reload(), 1500);
      } else {
        showToast(data.message || 'فشل اعتماد المطابقة', 'danger');
      }
    });
}

// إلغاء / استبعاد مطابقة مقترحة خاطئة من قِبل ضابط الأمن
function rejectMatchFromOfficer(lostId, foundId) {
  if (!window.confirm('هل أنت متأكد من إلغاء هذا التطابق المقترح؟ سيتم استبعاده نهائياً من قائمة المراجعة.')) return;

  const fd = new FormData();
  fd.append('lost_id', lostId);
  fd.append('found_id', foundId);
  fd.append('_csrf', window.LUQYA_CSRF);

  fetch('api/security.php?action=reject_match', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        showToast(data.message || 'تم إلغاء التطابق المقترح.', 'success', 4000);
        loadPendingMatches();
      } else {
        showToast(data.message || 'تعذر إلغاء التطابق المقترح.', 'danger');
      }
    })
    .catch(() => showToast('خطأ في الاتصال بالخادم', 'danger'));
}

// Modal handling
function openAddFoundModal() {
  document.getElementById('modalAddFound').classList.add('active');
}
function closeAddFoundModal() {
  document.getElementById('modalAddFound').classList.remove('active');
  // إلغاء أي تحليل صورة جارٍ حتى لا تعود نتيجته المتأخرة لتعبئة النموذج
  if (typeof invalidateVisionScope === 'function') invalidateVisionScope('SecFound');
}

function submitSecAddFound(e) {
  e.preventDefault();
  // لا يُرسَل بلاغ ووصفه لا يزال "جاري التحليل..." — ينتظر اكتمال تحليل الصورة
  if (typeof isVisionAnalysisInFlight === 'function' && isVisionAnalysisInFlight('SecFound')) {
    showToast('جارٍ تحليل الصورة بالذكاء الاصطناعي… انتظر اكتماله ثم احفظ.', 'danger', 5000);
    return false;
  }
  const form = document.getElementById('secAddFoundForm');
  // مزامنة الرقم الجامعي المُدخل يدوياً مع بيانات OCR قبل الإرسال (ضمان الحفظ الآمن)
  if (typeof syncSecOcrData === 'function') syncSecOcrData();
  const fd = new FormData(form);
  fd.append('_csrf', window.LUQYA_CSRF);

  const submitBtn = document.getElementById('btnSecSubmit');
  submitBtn.disabled = true;
  submitBtn.innerText = 'جاري الحفظ والتحليل...';

  fetch('api/security.php?action=add_found', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      submitBtn.disabled = false;
      submitBtn.innerText = 'حفظ وتسجيل بالمخزون';
      if (data.success) {
        const code = data.pickup_code || '';
        const rid = data.report_id ? ' رقم البلاغ: #' + data.report_id : '';
        showToast(code
          ? data.message + rid + ' | كود الاستلام: ' + code
          : data.message + rid, 'success', 6000);
        closeAddFoundModal();
        setTimeout(() => window.location.reload(), 1200);
      } else {
        showToast(data.message || 'حدث خطأ أثناء الحفظ', 'danger');
      }
    })
    .catch(() => {
      submitBtn.disabled = false;
      submitBtn.innerText = 'حفظ وتسجيل بالمخزون';
      showToast('خطأ في الاتصال بالخادم', 'danger');
    });
}

function openEditModal(item) {
  document.getElementById('editItemId').value = item.id;
  document.getElementById('editItemTitle').value = item.title;
  document.getElementById('editItemCategory').value = item.category;
  document.getElementById('editItemLocation').value = item.location;
  document.getElementById('editItemDesc').value = item.description;
  document.getElementById('editItemFeatures').value = item.distinctive_features || '';
  document.getElementById('editItemStatus').value = item.status;
  document.getElementById('modalEditFound').classList.add('active');
}

function closeEditModal() {
  document.getElementById('modalEditFound').classList.remove('active');
}

function submitSecEditFound(e) {
  e.preventDefault();
  const form = document.getElementById('secEditFoundForm');
  const fd = new FormData(form);
  fd.append('_csrf', window.LUQYA_CSRF);

  fetch('api/security.php?action=update_found', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        showToast(data.message, 'success');
        closeEditModal();
        setTimeout(() => window.location.reload(), 1000);
      } else {
        showToast(data.message || 'فشل التعديل', 'danger');
      }
    });
}

function deleteFoundItem(id) {
  if (!confirm('هل أنت متأكد من رغبتك في حذف هذا المعثور عليه من سجلات المخزون؟')) return;

  const fd = new FormData();
  fd.append('id', id);
  fd.append('_csrf', window.LUQYA_CSRF);

  fetch('api/security.php?action=delete_found', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        showToast(data.message, 'success');
        setTimeout(() => window.location.reload(), 1000);
      } else {
        showToast(data.message || 'فشل الحذف', 'danger');
      }
    });
}

// تحميل المطابقات المقترحة عند فتح الصفحة
document.addEventListener('DOMContentLoaded', function() {
  loadPendingMatches();
});
</script>

<?php require_once __DIR__ . '/views/footer.php'; ?>
