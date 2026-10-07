<?php
/**
 * لُقيا AI — نقطة النهاية المركزية لعمليات ضباط الأمن وإدارة المخزون
 * يدعم:
 *   - verify                : التحقق من كود الاستلام (RET-XXXXXX) أو الرقم الجامعي
 *   - handover              : تأكيد التسليم المادي وإغلاق البلاغ
 *   - add_found             : تسجيل غرض معثور عليه جديد في مكتب الأمن
 *   - update_found          : تعديل بيانات غرض معثور عليه
 *   - delete_found          : حذف بلاغ معثور عليه
 *   - list_pending_matches  : جلب قائمة المطابقات الذكية المقترحة للمراجعة
 *   - confirm_match         : الاعتماد اليدوي للمطابقة وإصدار كود الاستلام
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/gemini.php';
require_once __DIR__ . '/../config/matching.php';
require_once __DIR__ . '/ocr_sim.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$pdo = getDBConnection();

ensureSchemaUpgrades($pdo);

if (!$pdo) {
    jsonResponse(['success' => false, 'message' => 'فشل الاتصال بقاعدة البيانات.'], 500);
}

// 0. عداد مباشر لعناصر المراجعة/المعلقة (بانتظار الاستلام أو التوثيق + مقترحات جديدة)
//    يستخدم نفس دالة بناء القائمة المعروضة لضمان تطابق العدد مع العناصر القابلة للتنفيذ.
if ($action === 'pending_count') {
    if (!isSecurityOfficer()) {
        jsonResponse(['success' => false, 'message' => 'صلاحية ضابط الأمن مطلوبة لتنفيذ هذا الإجراء.'], 403);
    }
    try {
        // عدّ فقط: الطبقة الدلالية لا تغيّر العدد إطلاقاً، وتكلفة استدعاء Gemini
        // هنا كانت تُدفع على كل تحميل للوحة لمجرد عرض رقم في شارة.
        $count = count(buildPendingReviewItems($pdo, 15, 50, false));
    } catch (Throwable $e) {
        $count = 0;
    }
    jsonResponse(['success' => true, 'count' => $count]);
}

// ---- ذاكرة جلسة مختصرة لنتائج التحليل الدلالي Gemini (تفادي تكرار الاستدعاء المكلف) ----
function aiMatchCacheKey(int $lostId, int $foundId): string {
    return $lostId . '-' . $foundId;
}

function aiMatchCacheGet(int $lostId, int $foundId): ?array {
    $key  = aiMatchCacheKey($lostId, $foundId);
    $pool = $_SESSION['ai_match_cache'] ?? [];
    if (isset($pool[$key]) && (time() - (int) $pool[$key]['ts']) < 900) {
        return $pool[$key]['data'];
    }
    return null;
}

function aiMatchCacheSet(int $lostId, int $foundId, array $data): void {
    $key  = aiMatchCacheKey($lostId, $foundId);
    $pool = $_SESSION['ai_match_cache'] ?? [];
    $pool[$key] = ['data' => $data, 'ts' => time()];
    if (count($pool) > 80) {
        $pool = array_slice($pool, -80, null, true);
    }
    $_SESSION['ai_match_cache'] = $pool;
}

function aiMatchCacheClear(int $lostId, int $foundId): void {
    $key = aiMatchCacheKey($lostId, $foundId);
    $pool = $_SESSION['ai_match_cache'] ?? [];
    unset($pool[$key]);
    $_SESSION['ai_match_cache'] = $pool;
}

// 1. التحقق من كود الاستلام أو الرقم الجامعي (متاح لضابط الأمن)
if ($action === 'verify') {
    requireCsrf();
    if (!isSecurityOfficer()) {
        jsonResponse(['success' => false, 'message' => 'صلاحية ضابط الأمن مطلوبة لتنفيذ هذا الإجراء.'], 403);
    }

    $code = strtoupper(trim($_POST['code'] ?? ''));
    if (empty($code)) {
        jsonResponse(['success' => false, 'message' => 'يرجى إدخال كود الاستلام أو الرقم الجامعي.'], 400);
    }

    // البحث عن البلاغ بواسطة كود الاستلام أو الرقم الجامعي للمالك أو بيانات OCR
    $stmt = $pdo->prepare("
        SELECT r.*, u.full_name as owner_name, u.university_id as owner_student_id, u.email as owner_email, u.phone as owner_phone
        FROM reports r
        JOIN users u ON r.user_id = u.id
        WHERE r.pickup_code = ? OR u.university_id = ? OR r.ocr_data LIKE ?
        ORDER BY r.created_at DESC
    ");
    $stmt->execute([$code, $code, "%$code%"]);
    $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($reports && count($reports) > 0) {
        $stmtMatch = $pdo->prepare("
            SELECT m.*, lr.title as lost_title, fr.title as found_title
            FROM matches m
            LEFT JOIN reports lr ON m.lost_report_id = lr.id
            LEFT JOIN reports fr ON m.found_report_id = fr.id
            WHERE m.pickup_code = ? OR m.lost_report_id = ? OR m.found_report_id = ?
            ORDER BY m.matched_at DESC LIMIT 1
        ");
        $stmtMatch->execute([$code, $reports[0]['id'], $reports[0]['id']]);
        $match = $stmtMatch->fetch(PDO::FETCH_ASSOC);

        // ضمان عرض كلا الطرفين (المفقود + المعثور) في الاستجابة حتى لو طابق الكود طرفاً واحداً فقط:
        // نوسّع معرفات النتائج إلى الزوج الكامل عبر جدول المطابقات وكود الاستلام المشترك.
        $idsSeen = [];
        foreach ($reports as $r) {
            $idsSeen[(int)$r['id']] = true;
        }
        $pairIds = collectMatchedReportIds($pdo, array_map(fn($r) => (int)$r['id'], $reports));
        foreach ($pairIds as $pid) {
            if ($pid > 0 && !isset($idsSeen[$pid])) {
                $pstmt = $pdo->prepare("
                    SELECT r.*, u.full_name as owner_name, u.university_id as owner_student_id, u.email as owner_email, u.phone as owner_phone
                    FROM reports r
                    JOIN users u ON r.user_id = u.id
                    WHERE r.id = ?
                ");
                $pstmt->execute([$pid]);
                $peer = $pstmt->fetch(PDO::FETCH_ASSOC);
                if ($peer) {
                    $reports[] = $peer;
                    $idsSeen[$pid] = true;
                }
            }
        }

        jsonResponse([
            'success' => true,
            'code'    => $code,
            'reports' => $reports,
            'match'   => $match
        ]);
    } else {
        jsonResponse(['success' => false, 'message' => 'لم يتم العثور على سجل نشط يطابق الكود أو الرقم الجامعي: ' . $code], 404);
    }
}

// 2. تأكيد التسليم المادي وإغلاق البلاغ
if ($action === 'handover') {
    requireCsrf();
    if (!isSecurityOfficer()) {
        jsonResponse(['success' => false, 'message' => 'صلاحية ضابط الأمن مطلوبة لتنفيذ هذا الإجراء.'], 403);
    }

    $pickup_code = strtoupper(trim($_POST['pickup_code'] ?? ''));
    $report_id   = intval($_POST['report_id'] ?? 0);

    if (empty($pickup_code) && $report_id === 0) {
        jsonResponse(['success' => false, 'message' => 'كود الاستلام أو رقم البلاغ مفقود.'], 400);
    }

    $secUser = getCurrentUser();
    $officerName = $secUser['full_name'] ?? 'ضابط الأمن';

    // ---- تحديد الزوج الكامل (المفقود lost_report_id + المعثور found_report_id) صراحةً من جدول المطابقات ----
    $pairSeed = [];

    if (!empty($pickup_code)) {
        $stmt = $pdo->prepare("SELECT lost_report_id, found_report_id FROM matches WHERE pickup_code = ?");
        $stmt->execute([$pickup_code]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $pairSeed[] = (int) $row['lost_report_id'];
            $pairSeed[] = (int) $row['found_report_id'];
        }
    }

    if ($report_id > 0) {
        $stmt = $pdo->prepare("SELECT lost_report_id, found_report_id FROM matches WHERE lost_report_id = ? OR found_report_id = ?");
        $stmt->execute([$report_id, $report_id]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $pairSeed[] = (int) $row['lost_report_id'];
            $pairSeed[] = (int) $row['found_report_id'];
        }
    }

    // احتياط: أي بلاغ يحمل كود الاستلام نفسه أو رقم البلاغ المُرسل
    if (!empty($pickup_code)) {
        $stmt = $pdo->prepare("SELECT id FROM reports WHERE pickup_code = ?");
        $stmt->execute([$pickup_code]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $rid) {
            $pairSeed[] = (int) $rid;
        }
    }
    if ($report_id > 0) {
        $pairSeed[] = $report_id;
    }

    // توسيع إضافي (يغطي سلاسل المطابقات) مع الاحتفاظ الصريح بالطرفين
    $idList = collectMatchedReportIds($pdo, $pairSeed);

    if (empty($idList)) {
        jsonResponse(['success' => false, 'message' => 'لم يتم العثور على بلاغات مرتبطة بالكود أو رقم البلاغ.'], 404);
    }

    $placeholders = implode(',', array_fill(0, count($idList), '?'));

    // ---- معاملة واحدة: بيان UPDATE واحد يفرض resolved على المفقود والمعثور معاً، ثم توثيق المطابقة ----
    $pdo->beginTransaction();
    try {
        // (1) التحديث المفروض لكلا الطرفين: يشتق الزوج من جدول المطابقات (match ID / pickup_code)
        //     AND id IN (...) يضمن أيضاً الطرف الموسّع من كود الاستلام المشترك.
        $sqlPair = "
            UPDATE reports
               SET status = 'resolved', resolved_at = NOW()
             WHERE id IN (
                       SELECT lost_report_id  FROM matches
                        WHERE pickup_code = ? OR lost_report_id = ? OR found_report_id = ?
                       UNION
                       SELECT found_report_id FROM matches
                        WHERE pickup_code = ? OR lost_report_id = ? OR found_report_id = ?
                   )
                OR id IN ($placeholders)
        ";
        $upd = $pdo->prepare($sqlPair);
        $upd->execute(array_merge(
            [$pickup_code, $report_id, $report_id, $pickup_code, $report_id, $report_id],
            $idList
        ));

        // (2) تحقق صريح: إن بقي أي طرف بحالة مختلفة نُرجِع المعاملة كاملة (لا نجاح جزئي)
        $chk = $pdo->prepare("SELECT COUNT(*) FROM reports WHERE id IN ($placeholders) AND status <> 'resolved'");
        $chk->execute($idList);
        if ((int) $chk->fetchColumn() > 0) {
            throw new RuntimeException('لم يتم تحديث كلا البلاغين (المفقود والمعثور) بشكل متزامن.');
        }

        // (3) توثيق كل سجلات المطابقة المرتبطة بأي من الطرفين
        $updM = $pdo->prepare("UPDATE matches SET status = 'verified', resolved_at = NOW() WHERE lost_report_id IN ($placeholders) OR found_report_id IN ($placeholders)");
        $updM->execute(array_merge($idList, $idList));

        // (4) جلب البلاغات المحدّثة وإشعار المالكين
        $stmtr = $pdo->prepare("SELECT id, title, user_id, type, status, resolved_at FROM reports WHERE id IN ($placeholders)");
        $stmtr->execute($idList);
        $repList = $stmtr->fetchAll(PDO::FETCH_ASSOC);

        foreach ($repList as $rep) {
            $stmtN = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, 'resolved')");
            $stmtN->execute([
                $rep['user_id'],
                'تم تسليم العنصر بنجاح وإغلاق البلاغ',
                'تم تسليم مفقوداتك "' . htmlspecialchars($rep['title'], ENT_QUOTES, 'UTF-8') . '" بنجاح عبر مكتب الأمن وإغلاق البلاغ رسمياً.'
            ]);
        }

        // (5) سجل النشاط
        $itemTitle = count($repList) > 0 ? $repList[0]['title'] : 'مقتنيات جامعية';
        logActivity(
            $pdo,
            'handover',
            $itemTitle,
            'تم تأكيد التسليم الفعلي والتحقق من كود الاستلام ' . ($pickup_code ?: ('البلاغ #' . $report_id)) . ' بواسطة ' . $officerName,
            $officerName
        );

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('Luqya AI handover failed: ' . $e->getMessage());
        jsonResponse(['success' => false, 'message' => 'فشل تأكيد التسليم: ' . $e->getMessage()], 500);
    }

    jsonResponse([
        'success'           => true,
        'message'           => 'تم تأكيد التسليم بنجاح! تم تحديث ' . count($repList) . ' بلاغ (المفقود والمعثور) إلى "تم الاستلام / مكتمل".',
        'updated_report_ids' => $idList,
        'reports'           => $repList
    ]);
}

// 3. إضافة معثور عليه جديد من لوحة الأمن
if ($action === 'add_found') {
    requireCsrf();
    if (!isSecurityOfficer()) {
        jsonResponse(['success' => false, 'message' => 'صلاحية ضابط الأمن مطلوبة لتنفيذ هذا الإجراء.'], 403);
    }

    $currentUser = getCurrentUser();
    $title       = sanitizeInput($_POST['title'] ?? '');
    $category    = sanitizeInput($_POST['category'] ?? 'مقتنيات شخصية');
    $description = sanitizeInput($_POST['description'] ?? '');
    $location    = sanitizeInput($_POST['location'] ?? 'مكتب الأمن الرئيسي - مبنى الإدارة');
    $date_event  = $_POST['date_event'] ?? date('Y-m-d');
    $ai_tags     = sanitizeInput($_POST['ai_tags'] ?? '');
    $ocr_data    = $_POST['ocr_data'] ?? null;
    $distinctive_features = sanitizeInput($_POST['distinctive_features'] ?? '');
    // image_url كان يُقبل من POST كما هو: أي مسار خارجي أو بصيغة بادئة خاطئة
    // كان يُخزَّن ويظهر <img src> معطوباً. نقبل المسارات النسبية المعروفة فقط.
    $postedImage = normalizeImagePath($_POST['image_url'] ?? '');
    $image_url   = $postedImage !== '' ? $postedImage : 'assets/images/sample_bag.svg';

    // حدود الأطوال تُفرض على مستوى الخادم بمطابقة دقيقة لأعمدة جدول reports
    // (انظر config/schema.php) — Previously أعرض من حدود الأعمدة فكانت تُقتطع بصمت.
    $lengthError = validateReportFieldLengths([
        'title'                => $title,
        'category'             => $category,
        'description'          => $description,
        'location'             => $location,
        'ai_tags'              => $ai_tags,
        'distinctive_features' => $distinctive_features,
        'image_url'            => $image_url,
    ]);
    if ($lengthError !== null) {
        jsonResponse(['success' => false, 'message' => $lengthError], 400);
    }

    // تطبيع الرقم الجامعي (المستخرج بالرؤية الذكية أو المُدخل يدوياً) وحفظه بأمان داخل بيانات OCR
    $ocrDataArr = [];
    if (is_string($ocr_data) && trim($ocr_data) !== '') {
        $decodedOcr = json_decode($ocr_data, true);
        if (is_array($decodedOcr)) {
            $ocrDataArr = $decodedOcr;
        }
    }
    // قصر البيانات المخزّنة على مفاتيح معروفة وقيم نصية فقط (حماية من الحقن والحقول غير المتوقعة)
    $ocrDataArr = array_intersect_key($ocrDataArr, array_flip(['student_id', 'name', 'college', 'department']));
    foreach ($ocrDataArr as $ocrKey => $ocrVal) {
        if (!is_scalar($ocrVal)) {
            unset($ocrDataArr[$ocrKey]);
            continue;
        }
        $ocrDataArr[$ocrKey] = sanitizeInput((string) $ocrVal);
    }
    $rawStudentId = trim((string) ($_POST['extracted_student_id'] ?? ''));
    if ($rawStudentId === '') {
        $rawStudentId = trim((string) ($ocrDataArr['student_id'] ?? ''));
    }
    $normalizedStudentId = '';
    if ($rawStudentId !== '') {
        if (preg_match('/S\d{9}/i', $rawStudentId, $sidMatch)) {
            $normalizedStudentId = strtoupper($sidMatch[0]);
        } elseif (preg_match('/\d{9}/', $rawStudentId, $sidMatch)) {
            $normalizedStudentId = 'S' . $sidMatch[0];
        }
    }
    if ($normalizedStudentId !== '' && validateUniversityID($normalizedStudentId)) {
        $ocrDataArr['student_id'] = $normalizedStudentId;
    } else {
        unset($ocrDataArr['student_id']);
    }
    $ocr_data = !empty($ocrDataArr) ? json_encode($ocrDataArr, JSON_UNESCAPED_UNICODE) : null;

    if (empty($title) || empty($description)) {
        jsonResponse(['success' => false, 'message' => 'يرجى إدخال عنوان ووصف المعثور عليه.'], 400);
    }

    // توليد كود استلام فريد لكل معثور عليه يُسجَّل جديداً في المخزون
    $pickupCode = generateRetrievalCode($pdo);

    // معالجة رفع الملف المرفق إن وجد (تُخزَّن الصور فعلياً في مجلد uploads/ عبر دالة آمنة موحّدة)
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $uploaded = handleImageUpload($_FILES['photo'], __DIR__ . '/../uploads/', 'sec');
        if ($uploaded !== '') {
            $image_url = $uploaded;
        }
    }

    // تعيين أيقونة افتراضية حسب التصنيف إذا لم تكن هناك صورة خاصة
    if ($image_url === 'assets/images/sample_bag.svg') {
        $catLower = mb_strtolower($category, 'UTF-8');
        $titleLower = mb_strtolower($title, 'UTF-8');
        if (str_contains($catLower, 'بطاقة') || str_contains($titleLower, 'بطاقة')) {
            $image_url = 'assets/images/sample_id_card.svg';
        } elseif (str_contains($catLower, 'إلكترونيات') || str_contains($titleLower, 'لابتوب')) {
            $image_url = 'assets/images/sample_laptop.svg';
        } elseif (str_contains($titleLower, 'سماعة') || str_contains($titleLower, 'ايربود')) {
            $image_url = 'assets/images/sample_airpods.svg';
        } elseif (str_contains($catLower, 'مقتنيات') || str_contains($titleLower, 'محفظة')) {
            $image_url = 'assets/images/sample_wallet.svg';
        }
    }

    $stmt = $pdo->prepare("
        INSERT INTO reports (user_id, type, title, category, description, location, date_event, image_url, ai_tags, ocr_data, distinctive_features, status, pickup_code)
        VALUES (?, 'found', ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?)
    ");
    $stmt->execute([
        $currentUser['id'],
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
    $newReportId = (int)$pdo->lastInsertId();

    // تشغيل محرك المطابقة الذكي
    $matchResult = runAIMatchingEngine($newReportId, $pdo);

    logActivity(
        $pdo,
        'add_found',
        $title,
        'قام ضابط الأمن بتسجيل معثور عليه جديد في المخزون: ' . $title,
        (string) $currentUser['full_name']
    );

    jsonResponse([
        'success'     => true,
        'message'     => 'تم تسجيل المعثور عليه في مخزون الأمن بنجاح!',
        'report_id'   => $newReportId,
        'pickup_code' => $matchResult['matched'] ? $matchResult['pickup_code'] : $pickupCode,
        'match_info'  => $matchResult
    ]);
}

// 4. تعديل بيانات غرض معثور عليه
if ($action === 'update_found') {
    requireCsrf();
    if (!isSecurityOfficer()) {
        jsonResponse(['success' => false, 'message' => 'صلاحية ضابط الأمن مطلوبة لتنفيذ هذا الإجراء.'], 403);
    }

    $report_id   = intval($_POST['id'] ?? 0);
    $title       = sanitizeInput($_POST['title'] ?? '');
    $category    = sanitizeInput($_POST['category'] ?? '');
    $description = sanitizeInput($_POST['description'] ?? '');
    $location    = sanitizeInput($_POST['location'] ?? '');
    $distinctive_features = sanitizeInput($_POST['distinctive_features'] ?? '');

    if ($report_id <= 0 || empty($title) || empty($description)) {
        jsonResponse(['success' => false, 'message' => 'بيانات التعديل غير مكتملة.'], 400);
    }

    // التحقق من وجود البلاغ وقراءة حالته الحالية: نحتاجها لمزامنة الطابع الزمني
    // ولتثبيت الحالة عند عدم إرسالها من النموذج.
    $check = $pdo->prepare("SELECT status, image_url FROM reports WHERE id = ? AND type = 'found'");
    $check->execute([$report_id]);
    $oldRow = $check->fetch(PDO::FETCH_ASSOC);
    if (!$oldRow) {
        jsonResponse(['success' => false, 'message' => 'البلاغ غير موجود.'], 404);
    }

    // ---- التحقق من الحالة مقابل قيم ENUM المعرَّفة في المخطط ----
    // كان يُمرَّر إلى العمود مباشرة بعد htmlspecialchars فقط، فكان ممكناً كتابة
    // أي قيمة من خارج النطاق (أو سبب خطأ 1265). نرفض الصريح الآن بدل إسقاطه
    // بصمت، حتى يعلم العميل أن الطلب مرفوض.
    //
    // عند غياب status في الطلب نحتفظ بالحالة الحالية بدل فرض 'active':
    // فرضها كان يمسح 'pending_pickup' ويحوّل بلاغاً بانتظار الاستلام إلى نشط.
    $rawStatus = is_string($_POST['status'] ?? null) ? trim($_POST['status']) : '';
    if ($rawStatus !== '' && !isValidReportStatus($rawStatus)) {
        jsonResponse([
            'success' => false,
            'message' => 'قيمة الحالة غير صالحة. القيم المسموح بها: ' . implode('، ', REPORT_STATUSES) . '.'
        ], 400);
    }
    $status = $rawStatus !== ''
        ? $rawStatus
        : (isValidReportStatus((string) $oldRow['status']) ? (string) $oldRow['status'] : REPORT_STATUS_DEFAULT);

    // حدود الأطوال تُفرض على مستوى الخادم بمطابقة دقيقة لأعمدة جدول reports
    $lengthError = validateReportFieldLengths([
        'title'                => $title,
        'category'             => $category,
        'description'          => $description,
        'location'             => $location,
        'distinctive_features' => $distinctive_features,
    ]);
    if ($lengthError !== null) {
        jsonResponse(['success' => false, 'message' => $lengthError], 400);
    }

    // رفع صورة جديدة إن تم إرفاقها (تُخزَّن فعلياً في مجلد uploads/ عبر دالة آمنة موحّدة)
    $newImagePath = '';
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $newImagePath = handleImageUpload($_FILES['photo'], __DIR__ . '/../uploads/', 'sec');
    }

    $setItems = ['title = ?', 'category = ?', 'description = ?', 'location = ?', 'status = ?', 'distinctive_features = ?'];
    $values = [$title, $category, $description, $location, $status, $distinctive_features !== '' ? $distinctive_features : null];

    if ($newImagePath !== '') {
        $setItems[] = 'image_url = ?';
        $values[] = normalizeImagePath($newImagePath) ?: $oldRow['image_url'];
    }

    // مزامنة الطابع الزمني الفعلي للتسليم: يُسجَّل عند التحويل إلى "تم الاستلام" ويُمسح عند العودة
    if ($status === 'resolved' && $oldRow['status'] !== 'resolved') {
        $setItems[] = 'resolved_at = NOW()';
    } elseif ($status !== 'resolved' && $oldRow['status'] === 'resolved') {
        $setItems[] = 'resolved_at = NULL';
    }

    $values[] = $report_id;
    $sql = "UPDATE reports SET " . implode(', ', $setItems) . " WHERE id = ? AND type = 'found'";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($values);

    // مزامنة الزوج المطابق عند التحويل يدوياً إلى "تم الاستلام" (المفقود + المعثور معاً)
    $syncedIds = [$report_id];
    if ($status === 'resolved') {
        $syncedIds = collectMatchedReportIds($pdo, [$report_id]);
        if (empty($syncedIds)) $syncedIds = [$report_id];
        $syncPh = implode(',', array_fill(0, count($syncedIds), '?'));
        $pdo->prepare("UPDATE reports SET status = 'resolved', resolved_at = NOW() WHERE id IN ($syncPh)")->execute($syncedIds);
        $pdo->prepare("UPDATE matches SET status = 'verified', resolved_at = NOW() WHERE lost_report_id IN ($syncPh) OR found_report_id IN ($syncPh)")
            ->execute(array_merge($syncedIds, $syncedIds));
    }

    logActivity(
        $pdo,
        'edit_found',
        $title,
        "عدّل ضابط الأمن بيانات المعثور عليه رقم {$report_id} إلى الحالة \"{$status}\""
            . ($newImagePath !== '' ? ' مع تحديث الصورة.' : '.'),
        ($_SESSION['user_name'] ?? 'ضابط الأمن')
    );

    jsonResponse([
        'success' => true,
        'message' => 'تم تحديث بيانات المعثور عليه بنجاح!',
        'updated_report_ids' => $syncedIds
    ]);
}

// 5. حذف بلاغ معثور عليه
if ($action === 'delete_found') {
    requireCsrf();
    if (!isSecurityOfficer()) {
        jsonResponse(['success' => false, 'message' => 'صلاحية ضابط الأمن مطلوبة لتنفيذ هذا الإجراء.'], 403);
    }

    $report_id = intval($_POST['id'] ?? 0);
    if ($report_id <= 0) {
        jsonResponse(['success' => false, 'message' => 'رقم البلاغ غير صالح.'], 400);
    }

    $stmt = $pdo->prepare("DELETE FROM reports WHERE id = ? AND type = 'found'");
    $stmt->execute([$report_id]);

    jsonResponse([
        'success' => true,
        'message' => 'تم حذف البلاغ من المخزون بنجاح.'
    ]);
}

// 6. قائمة المطابقات المقترحة للمراجعة (Human-in-the-Loop Smart Matching)
if ($action === 'list_pending_matches') {
    if (!isSecurityOfficer()) {
        jsonResponse(['success' => false, 'message' => 'صلاحية ضابط الأمن مطلوبة.'], 403);
    }

    // قائمة موحّدة: المعثورات المعلقة (بانتظار الاستلام/التوثيق) + مقترحات المطابقة النشطة
    //
    // المسار محلي بالكامل (useSemantic=false): هذه قائمة قراءة تُستدعى عند فتح
    // اللوحة وعلى كل ضغطة زر "تحديث". تشغيل Gemini هنا كان يضيف ~5.5 ثانية
    // متزامنة لكل بلاغ مفقود قبل ظهور أي عنصر، ثم تُعيد الواجهة استدعاء
    // action=ai_analyze_matches بعد ذلك مباشرة reanalyze النتيجة نفسها.
    // لذلك فُصل المرحلتان: القائمة تُرسم فوراً بالدرجة المحلية، وطبقة Gemini
    // تصل لاحقاً دون حجب الاستجابة.
    $items = buildPendingReviewItems($pdo, 15, 50, false);

    // إثراء مقترحات المطابقة بنتائج Gemini المخزّنة في الجلسة (إن وُجدت)
    // لا نمسح رأي محرك المطابقة إن لم يوجد تحليل يدوي مخزّن.
    foreach ($items as &$item) {
        if ($item['kind'] === 'suggestion' && !empty($item['lost']) && !empty($item['found'])) {
            $cachedAi = aiMatchCacheGet((int) $item['lost']['id'], (int) $item['found']['id']);
            if (!empty($cachedAi)) {
                $item['ai'] = $cachedAi;
            }
            $item['ai'] = $item['ai'] ?? null;
        }
    }
    unset($item);

    jsonResponse([
        'success'     => true,
        'count'       => count($items),
        'suggestions' => $items
    ]);
}

// 8. التحليل الدلالي لبلاغات المطابقة عبر Gemini API (AI Matching & Description)
if ($action === 'ai_analyze_matches') {
    requireCsrf();
    if (!isSecurityOfficer()) {
        jsonResponse(['success' => false, 'message' => 'صلاحية ضابط الأمن مطلوبة لتشغيل التحليل الذكي.'], 403);
    }

    $force  = (($_POST['force'] ?? '') === '1');
    $lostId  = intval($_POST['lost_id'] ?? 0);
    $foundId = intval($_POST['found_id'] ?? 0);

    $pairLocal = [];

    if ($lostId > 0 && $foundId > 0) {
        // وضع التعمق: تحليل زوج واحد محدد
        $q = $pdo->prepare("SELECT * FROM reports WHERE id IN (?, ?)");
        $q->execute([$lostId, $foundId]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        $lost = $found = null;
        foreach ($rows as $r) {
            if ($r['type'] === 'lost') $lost = $r;
            else $found = $r;
        }
        if (!$lost || !$found) {
            jsonResponse(['success' => false, 'message' => 'البلاغ المطلوب غير موجود.'], 404);
        }
        $local = computeMatchScore($lost, $found);
        $pairLocal[] = [
            'kind'             => 'suggestion',
            'lost'             => $lost,
            'found'            => $found,
            'confidence_score' => $local['score'],
            'breakdown'        => $local['breakdown'],
        ];
    } else {
        // الوضع العام: أفضل مقترحات المطابقة المحلية الحالية لتحليلها دلالياً بـ Gemini
        // (محلي فقط هنا: التحليل الدلالي هو الخطوة التالية مباشرة، فاستدعاؤه
        //  داخل البناء أيضاً كان يكرر الطلب نفسه إلى Gemini)
        $reviewItems = buildPendingReviewItems($pdo, 5, 50, false);
        foreach ($reviewItems as $it) {
            if ($it['kind'] === 'suggestion') {
                $pairLocal[] = $it;
            }
        }
    }

    if (empty($pairLocal)) {
        // لا توجد مقترحات جديدة؛ نُعيد عناصر المراجعة المعلقة (بانتظار الاستلام) كما هي
        $items = buildPendingReviewItems($pdo, 15, 50, false);
        foreach ($items as &$it) {
            if ($it['kind'] === 'suggestion' && !empty($it['lost']) && !empty($it['found'])) {
                $cachedAi = aiMatchCacheGet((int) $it['lost']['id'], (int) $it['found']['id']);
                if (!empty($cachedAi)) {
                    $it['ai'] = $cachedAi;
                }
                $it['ai'] = $it['ai'] ?? null;
            }
        }
        unset($it);
        jsonResponse([
            'success'      => true,
            'ai_available' => true,
            'analyzed'     => 0,
            'gemini_error' => null,
            'count'        => count($items),
            'suggestions'  => $items,
        ]);
    }

    // استبعاد الأزواج المخزّنة في الجلسة (ما لم يُطلب فرض التحليل من جديد)
    $toAnalyze = [];
    foreach ($pairLocal as $t) {
        $li = (int) $t['lost']['id'];
        $fi = (int) $t['found']['id'];
        if ($force) aiMatchCacheClear($li, $fi);
        if (aiMatchCacheGet($li, $fi) === null) $toAnalyze[] = $t;
    }

    $analyzedCount = 0;
    if (!empty($toAnalyze)) {
        $pairsForGemini = [];
        foreach ($toAnalyze as $t) {
            $pairsForGemini[] = ['lost' => $t['lost'], 'found' => $t['found']];
        }
        $aiMap = geminiBatchMatchAnalysis($pairsForGemini, 6);
        if (!empty($aiMap)) {
            foreach ($toAnalyze as $i => $t) {
                if (isset($aiMap[$i])) {
                    aiMatchCacheSet((int) $t['lost']['id'], (int) $t['found']['id'], $aiMap[$i]);
                    $analyzedCount++;
                }
            }
        }
    }

    $geminiError = getGeminiLastError();

    // إعادة القائمة الموحّدة الكاملة (المعلقة + المقترحات) مع إثرائها بنتائج Gemini المخزّنة
    $suggestions = buildPendingReviewItems($pdo, 15);
    foreach ($suggestions as &$it) {
        if ($it['kind'] === 'suggestion' && !empty($it['lost']) && !empty($it['found'])) {
            $cachedAi = aiMatchCacheGet((int) $it['lost']['id'], (int) $it['found']['id']);
            if (!empty($cachedAi)) {
                $it['ai'] = $cachedAi;
            }
            $it['ai'] = $it['ai'] ?? null;
        }
    }
    unset($it);

    jsonResponse([
        'success'      => true,
        'ai_available' => true,
        'analyzed'     => $analyzedCount,
        'gemini_error' => !empty($geminiError['message']) ? $geminiError['message'] : null,
        'count'        => count($suggestions),
        'suggestions'  => $suggestions,
    ]);
}

// التأكد من وجود البلاغين بالأنواع الصحيحة
// 7. الاعتماد اليدوي للمطابقة من قبل ضابط الأمن (Confirm Match)
if ($action === 'confirm_match') {
    requireCsrf();
    if (!isSecurityOfficer()) {
        jsonResponse(['success' => false, 'message' => 'صلاحية ضابط الأمن مطلوبة.'], 403);
    }

    $lost_id    = intval($_POST['lost_id'] ?? 0);
    $found_id   = intval($_POST['found_id'] ?? 0);
    $confidence = normalizeConfidenceScore($_POST['confidence'] ?? 90, 90);

    if ($lost_id <= 0 || $found_id <= 0) {
        jsonResponse(['success' => false, 'message' => 'معرفات البلاغات غير صالحة.'], 400);
    }

    $secUser = getCurrentUser();

    // القيد الفريد uk_matches_pair يمنع صفّين لنفس الزوج، فنحدّث القائم أو نُدرج جديداً.
    $existingStmt = $pdo->prepare(
        "SELECT id, status, pickup_code FROM matches
          WHERE lost_report_id = ? AND found_report_id = ? ORDER BY id DESC LIMIT 1"
    );
    $existingStmt->execute([$lost_id, $found_id]);
    $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);

    // أضف أو حدّث سجل المطابقة؛ المطابقة المؤكَّدة تُغلق البلاغين فوراً
    $pickupCode = normalizePickupCode($existing['pickup_code'] ?? '') ?: generateRetrievalCode($pdo);

    if ($existing) {
        $pdo->prepare(
            "UPDATE matches
                SET confidence_score = ?, pickup_code = ?, status = 'verified', resolved_at = NOW()
              WHERE id = ?"
        )->execute([$confidence, $pickupCode, (int) $existing['id']]);
    } else {
        $pdo->prepare(
            "INSERT INTO matches (lost_report_id, found_report_id, confidence_score, pickup_code, status, resolved_at)
             VALUES (?, ?, ?, ?, 'verified', NOW())"
        )->execute([$lost_id, $found_id, $confidence, $pickupCode]);
    }

    // اعتماد المطابقة يغلق البلاغين فوراً: تحديث حالة كلا البلاغين إلى "تم الاستلام" (resolved)
    $stmt = $pdo->prepare("UPDATE reports SET status = 'resolved', pickup_code = ?, resolved_at = NOW() WHERE id IN (?, ?)");
    $stmt->execute([$pickupCode, $lost_id, $found_id]);

    // جلب بيانات المالك لإشعاره
    $stmt = $pdo->prepare("SELECT r.title, r.user_id FROM reports r WHERE r.id = ?");
    $stmt->execute([$lost_id]);
    $lostInfo = $stmt->fetch(PDO::FETCH_ASSOC);

    // إشعار بنوع 'match_found' وهو ما تتوقّعه واجهة views/header.php لعرض
    // أيقونة المطابقة الذكية (Previously كانت كل النقاط تكتب 'resolved').
    if ($lostInfo) {
        $msg = "تم اعتماد مطابقة بلاغك '{$lostInfo['title']}' من قِبل ضابط الأمن وإغلاق البلاغ بحالة \"تم الاستلام\"! كود التوثيق: {$pickupCode}.";
        $stmtN = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, ?)");
        $stmtN->execute([$lostInfo['user_id'], 'تم تأكيد المطابقة وإغلاق البلاغ', $msg, 'match_found']);
    }

    logActivity(
        $pdo,
        'match_confirmed',
        $lostInfo['title'] ?? 'مقتنيات مطابقة',
        'قام ضابط الأمن ' . $secUser['full_name'] . ' بتأكيد المطابقة وإغلاق البلاغين بحالة "تم الاستلام" (resolved) وكود التوثيق ' . $pickupCode,
        (string) $secUser['full_name']
    );

    jsonResponse([
        'success'     => true,
        'message'     => 'تم تأكيد المطابقة بنجاح وغلق البلاغين بحالة "تم الاستلام". كود التوثيق: ' . $pickupCode,
        'pickup_code' => $pickupCode
    ]);
}

// 8. إلغاء / استبعاد مطابقة مقترحة (Human-in-the-Loop: رفض اقتراح Gemini/المحرك)
if ($action === 'reject_match') {
    requireCsrf();
    if (!isSecurityOfficer()) {
        jsonResponse(['success' => false, 'message' => 'صلاحية ضابط الأمن مطلوبة.'], 403);
    }

    $lost_id  = intval($_POST['lost_id'] ?? 0);
    $found_id = intval($_POST['found_id'] ?? 0);
    if ($lost_id <= 0 || $found_id <= 0) {
        jsonResponse(['success' => false, 'message' => 'معرفات البلاغات غير صالحة.'], 400);
    }

    // التأكد من وجود البلاغين بالأنواع الصحيحة
    $stmt = $pdo->prepare("SELECT id, type FROM reports WHERE id IN (?, ?)");
    $stmt->execute([$lost_id, $found_id]);
    $byId = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $byId[(int) $r['id']] = $r;
    }
    if (!isset($byId[$lost_id], $byId[$found_id])
        || $byId[$lost_id]['type'] !== 'lost'
        || $byId[$found_id]['type'] !== 'found') {
        jsonResponse(['success' => false, 'message' => 'البلاغات غير موجودة أو غير متوافقة.'], 404);
    }

    // منع إلغاء مطابقة سبق اعتمادها فعلياً
    $chk = $pdo->prepare("SELECT id, status FROM matches WHERE lost_report_id = ? AND found_report_id = ? ORDER BY id DESC LIMIT 1");
    $chk->execute([$lost_id, $found_id]);
    $existing = $chk->fetch(PDO::FETCH_ASSOC);
    if ($existing && in_array($existing['status'], ['pending', 'verified'], true)) {
        jsonResponse(['success' => false, 'message' => 'لا يمكن إلغاء مطابقة تم اعتمادها بالفعل.'], 409);
    }

    // تسجيل الرفض كصف مطابقة بحالة rejected — يستبعد الثنائي نهائياً من محرك المطابقة.
    // pickup_code = NULL وليس '' : القيد الفريد uk_matches_pickup_code يسمح بعدد
    // من القيم الفارغة (NULL) ولا يسمح بعدد من النصوص الفارغة ('').
    if (!$existing) {
        $stmtIns = $pdo->prepare("INSERT INTO matches (lost_report_id, found_report_id, confidence_score, pickup_code, status) VALUES (?, ?, 0, NULL, 'rejected')");
        $stmtIns->execute([$lost_id, $found_id]);
    }

    // إبطال ذاكرة التحليل الدلالي لهذا الثنائي حتى لا يُعاد عرضه
    aiMatchCacheClear($lost_id, $found_id);

    $secUser = getCurrentUser();
    logActivity(
        $pdo,
        'match_rejected',
        'إلغاء تطابق مقترح',
        'قام ضابط الأمن ' . $secUser['full_name'] . ' بإلغاء التطابق المقترح بين البلاغين #' . $lost_id . ' و #' . $found_id,
        (string) $secUser['full_name']
    );

    jsonResponse(['success' => true, 'message' => 'تم إلغاء التطابق المقترح واستبعاده من قائمة المراجعة.']);
}

jsonResponse(['success' => false, 'message' => 'إجراء أمني غير صالح.'], 400);
