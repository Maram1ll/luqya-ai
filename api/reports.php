<?php
// نقطة النهاية لإدارة بلاغات المفقودات والمعثورات
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/gemini.php';
require_once __DIR__ . '/../config/matching.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$pdo = getDBConnection();

ensureSchemaUpgrades($pdo);

if (!$pdo) {
    jsonResponse(['success' => false, 'message' => 'فشل الاتصال بقاعدة البيانات.'], 500);
}

// 1. إنشاء بلاغ مفقود أو معثور عليه
if ($action === 'create') {
    requireCsrf();
    if (!isLoggedIn()) {
        jsonResponse(['success' => false, 'message' => 'يجب تسجيل الدخول أولاً لتقديم بلاغ.'], 401);
    }

    $currentUser = getCurrentUser();
    $type = normalizeReportType($_POST['type'] ?? 'lost', 'lost');

    // RBAC: ضباط الأمن لا يُسمح لهم بتقديم بلاغات مفقودات (فقط معثورات)
    if ($type === 'lost' && isSecurityOfficer()) {
        jsonResponse([
            'success' => false,
            'message' => 'عذراً، صلاحية تقديم بلاغات المفقودات مخصصة لطلاب الجامعة فقط. يمكنك تسجيل المعثورات عبر لوحة تحكم الأمن.'
        ], 403);
    }
    $title = trim($_POST['title'] ?? '');
    $category = trim($_POST['category'] ?? 'أخرى');
    $description = trim($_POST['description'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $date_event = trim((string) ($_POST['date_event'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_event)) {
        $date_event = date('Y-m-d');
    }
    $ai_tags = trim($_POST['ai_tags'] ?? '');
    $ocr_data = trim($_POST['ocr_data'] ?? '');
    $distinctive_features = trim($_POST['distinctive_features'] ?? '');

    // حدود الأطوال تُفرض على مستوى الخادم بمطابقة دقيقة لأعمدة جدول reports
    // (انظر config/schema.php). كانت القيم القديمة (200/250/1000) أوسع من
    // الأعمدة (150/150/255) فكان MariaDB في الوضع غير الصارم يقتطع النص بصمت.
    $lengthError = validateReportFieldLengths([
        'title'                => $title,
        'category'             => $category,
        'description'          => $description,
        'location'             => $location,
        'ai_tags'              => $ai_tags,
        'distinctive_features' => $distinctive_features,
    ]);
    if ($lengthError !== null) {
        jsonResponse(['success' => false, 'message' => $lengthError], 400);
    }

    if (empty($title) || empty($description) || empty($location)) {
        jsonResponse(['success' => false, 'message' => 'يرجى إدخال اسم العنصر والوصف والموقع.'], 400);
    }

    $image_url = 'assets/images/sample_bag.svg';
    $catLower = mb_strtolower($category, 'UTF-8');
    $titleLower = mb_strtolower($title, 'UTF-8');

    if (str_contains($catLower, 'بطاقة') || str_contains($titleLower, 'بطاقة') || str_contains($catLower, 'id')) {
        $image_url = 'assets/images/sample_id_card.svg';
    } elseif (str_contains($catLower, 'إلكترونيات') || str_contains($titleLower, 'لابتوب') || str_contains($titleLower, 'كمبيوتر') || str_contains($catLower, 'electronics')) {
        $image_url = 'assets/images/sample_laptop.svg';
    } elseif (str_contains($titleLower, 'سماعة') || str_contains($titleLower, 'airpods')) {
        $image_url = 'assets/images/sample_airpods.svg';
    } elseif (str_contains($catLower, 'مقتنيات') || str_contains($titleLower, 'محفظة') || str_contains($catLower, 'belongings')) {
        $image_url = 'assets/images/sample_wallet.svg';
    }

    // تُخزَّن الصور في مجلد uploads/ بجذر المشروع عبر دالة آمنة موحّدة:
    // فحص المحتوى الفعلي + الحد الأقصى للحجم + اشتقاق الامتداد من الصيغة المكتشفة + اسم عشوائي.
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $uploaded = handleImageUpload($_FILES['photo'], __DIR__ . '/../uploads/', 'item');
        if ($uploaded !== '') {
            $image_url = $uploaded;
        }
    } elseif (isset($_FILES['photo_camera']) && $_FILES['photo_camera']['error'] === UPLOAD_ERR_OK) {
        $uploaded = handleImageUpload($_FILES['photo_camera'], __DIR__ . '/../uploads/', 'item');
        if ($uploaded !== '') {
            $image_url = $uploaded;
        }
    }

    // تحسين الوصف بالذكاء الاصطناعي (Gemini) قبل الحفظ في قاعدة البيانات
    $description = enhanceDescriptionWithGemini($title, $category, $description);

    // توليد كود استلام فريد لكل معثور عليه جديد (متاح فوراً للتحقق في لوحة الأمن)
    $pickupCode = null;
    if ($type === 'found') {
        $pickupCode = generateRetrievalCode($pdo);
    }

    $stmt = $pdo->prepare("INSERT INTO reports (user_id, type, title, category, description, location, date_event, image_url, ai_tags, ocr_data, distinctive_features, status, pickup_code) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?)");

    $stmt->execute([
        $currentUser['id'],
        $type,
        $title,
        $category,
        $description,
        $location,
        $date_event,
        $image_url,
        $ai_tags,
        $ocr_data,
        $distinctive_features !== '' ? $distinctive_features : null,
        $pickupCode
    ]);

    $report_id = $pdo->lastInsertId();

    // تشغيل محرك المطابقة التلقائية
    require_once __DIR__ . '/ocr_sim.php';
    $matchResult = runAIMatchingEngine($report_id, $pdo);

    jsonResponse([
        'success' => true,
        'message' => 'تم إرسال البلاغ بنجاح!',
        'report_id' => $report_id,
        'pickup_code' => $matchResult['matched'] ? $matchResult['pickup_code'] : $pickupCode,
        'match_info' => $matchResult
    ]);
}

// 2. إغلاق/حذف البلاغ من قبل صاحبه (سبب إلزامي يحدده المستخدم)
if ($action === 'close') {
    requireCsrf();
    enforceRateLimit('close_report', 10, 300);
    if (!isLoggedIn()) {
        jsonResponse(['success' => false, 'message' => 'غير مصرح'], 401);
    }
    $currentUser = getCurrentUser();
    $report_id = intval($_POST['report_id'] ?? 0);

    // سبب الإغلاق إلزامي: found | mistaken | other (مع نص حر قصير عند "other")
    $reasonKey  = (string) ($_POST['reason_key'] ?? '');
    $reasonText = trim((string) ($_POST['reason'] ?? ''));
    if (!in_array($reasonKey, ['found', 'mistaken', 'other'], true)) {
        jsonResponse(['success' => false, 'message' => 'يرجى تحديد سبب إغلاق البلاغ.'], 400);
    }
    if ($reasonKey === 'other' && $reasonText === '') {
        jsonResponse(['success' => false, 'message' => 'يرجى كتابة السبب في حقل «سبب آخر».'], 400);
    }
    $reasonText = mb_substr($reasonText, 0, 120);

    // التأكد من أن البلاغ يخص المستخدم الحالي ولم يُغلق مسبقاً
    $own = $pdo->prepare("SELECT id, status, title FROM reports WHERE id = ? AND user_id = ?");
    $own->execute([$report_id, $currentUser['id']]);
    $report = $own->fetch();
    if (!$report) {
        jsonResponse(['success' => false, 'message' => 'البلاغ غير موجود أو لا تملك صلاحية إغلاقه.'], 404);
    }
    if (in_array($report['status'], ['resolved', 'cancelled'], true)) {
        jsonResponse(['success' => false, 'message' => 'هذا البلاغ مغلق مسبقاً.'], 409);
    }

    require_once __DIR__ . '/../config/matching.php';
    $idList = collectMatchedReportIds($pdo, [$report_id]);
    if (empty($idList)) $idList = [$report_id];

    if ($reasonKey === 'found') {
        // تم العثور على العنصر واسترداده: يُعَدّل البلاغ وقرينه المطابق كمكتمل/مستلم
        $ph = implode(',', array_fill(0, count($idList), '?'));
        $pdo->prepare("UPDATE reports SET status = 'resolved', resolved_at = NOW() WHERE id IN ($ph)")->execute($idList);
        $pdo->prepare("UPDATE matches SET status = 'verified', resolved_at = NOW() WHERE lost_report_id IN ($ph) OR found_report_id IN ($ph)")->execute(array_merge($idList, $idList));
        $statusLabel = 'resolved';
        $message = 'تم تحديث البلاغ وقرينه المطابق كمكتمل / مستلم بنجاح.';
    } else {
        // بلاغ خاطئ أو سبب آخر: إلغاء البلاغ مع فكّ أي مطابقة وردّ القرين إلى المخزون النشط
        $ownUpd = $pdo->prepare("UPDATE reports SET status = 'cancelled', resolved_at = NOW(), pickup_code = NULL WHERE id = ?");
        $ownUpd->execute([$report_id]);
        $mUpd = $pdo->prepare("UPDATE matches SET status = 'rejected', pickup_code = NULL WHERE lost_report_id = ? OR found_report_id = ?");
        $mUpd->execute([$report_id, $report_id]);
        $counterparts = array_values(array_diff($idList, [$report_id]));
        if (!empty($counterparts)) {
            $cph = implode(',', array_fill(0, count($counterparts), '?'));
            $cUpd = $pdo->prepare("UPDATE reports SET status = 'active' WHERE id IN ($cph) AND status = 'matched'");
            $cUpd->execute($counterparts);
        }
        $statusLabel = 'cancelled';
        $message = 'تم إغلاق البلاغ بنجاح وسيختفي من قوائم البحث النشطة.';
    }

    // سجل تدقيق بالسبب في activity_logs (دون تغيير بنية الجداول)
    $reasonLog = match ($reasonKey) {
        'found'    => 'تم العثور على العنصر واسترداده',
        'mistaken' => 'بلاغ خاطئ',
        default    => $reasonText !== '' ? $reasonText : 'سبب آخر',
    };
    $logStmt = $pdo->prepare("INSERT INTO activity_logs (action_type, item_title, details, user_name, created_at) VALUES ('user_close', ?, ?, ?, NOW())");
    $logStmt->execute([
        mb_substr($report['title'], 0, 200),
        'أغلق المستخدم بلاغه (#' . $report_id . ') — السبب: ' . $reasonLog,
        $currentUser['full_name'] !== '' ? $currentUser['full_name'] : ($currentUser['university_id'] ?: 'مستخدم'),
    ]);

    jsonResponse([
        'success'            => true,
        'message'            => $message,
        'status'             => $statusLabel,
        'updated_report_ids' => $idList
    ]);
}

// 3. جلب تفاصيل البلاغ (يتطلب تسجيل دخول — يمنع كشف بيانات المالك مثل البريد لزوار مجهولين)
if ($action === 'get') {
    if (!isLoggedIn()) {
        jsonResponse(['success' => false, 'message' => 'يجب تسجيل الدخول لعرض تفاصيل البلاغ.'], 401);
    }
    $currentUser = getCurrentUser();
    $report_id = intval($_GET['id'] ?? 0);
    $stmt = $pdo->prepare("SELECT r.*, u.full_name, u.university_id, u.email FROM reports r JOIN users u ON r.user_id = u.id WHERE r.id = ?");
    $stmt->execute([$report_id]);
    $report = $stmt->fetch();

    // إصلاح IDOR: الاسم/البريد/الرقم الجامعي للمالك لا يُعرَض إلا لصاحب البلاغ أو ضابط الأمن
    if ($report && (int) $report['user_id'] !== $currentUser['id'] && !isSecurityOfficer()) {
        jsonResponse(['success' => false, 'message' => 'غير مصرح بعرض بيانات هذا البلاغ.'], 403);
    }

    if ($report) {
        jsonResponse(['success' => true, 'report' => $report]);
    } else {
        jsonResponse(['success' => false, 'message' => 'لم يتم العثور على البلاغ.'], 404);
    }
}

// 4. مزامنة الحالات اللحظية لتطبيق الطالب (JSON/AJAX)
if ($action === 'my_status') {
    if (!isLoggedIn()) {
        jsonResponse(['success' => false, 'message' => 'غير مصرح'], 401);
    }
    $currentUser = getCurrentUser();
    $stmt = $pdo->prepare("SELECT id, status, pickup_code, resolved_at FROM reports WHERE user_id = ? ORDER BY created_at DESC");
    $stmt->execute([$currentUser['id']]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    jsonResponse(['success' => true, 'reports' => $rows]);
}

// 5. حالات كل البلاغات العامة (للواجهة الرئيسية — تُحدَّث مباشرة عند تسليم ضابط الأمن)
if ($action === 'statuses') {
    $stmt = $pdo->query("SELECT id, status, type FROM reports");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // توحيد حالة الزوج المطابق حتى لا تُعاد لحالة قديمة في الاستطلاع الدوري
    $rows = normalizeMatchedStatuses($pdo, $rows);
    $map = [];
    foreach ($rows as $row) {
        $map[$row['id']] = ['status' => $row['status'], 'type' => $row['type']];
    }
    jsonResponse(['success' => true, 'statuses' => $map]);
}

// 6. المطابقات المحتملة للطالب (محرك المطابقة الموزون)
if ($action === 'potential_matches') {
    if (!isLoggedIn()) {
        jsonResponse(['success' => false, 'message' => 'غير مصرح'], 401);
    }
    require_once __DIR__ . '/../config/matching.php';
    $currentUser = getCurrentUser();
    $matches = getPotentialMatchesForUser($currentUser['id'], $pdo, MATCH_STUDENT_THRESHOLD, 8);
    $rows = [];
    foreach ($matches as $m) {
        $rows[] = [
            'score'     => $m['score'],
            'breakdown' => $m['breakdown'],
            'lost'      => $m['lost'],
            'found'     => $m['found'],
        ];
    }
    jsonResponse(['success' => true, 'matches' => $rows]);
}

jsonResponse(['success' => false, 'message' => 'إجراء غير صالح.'], 400);
