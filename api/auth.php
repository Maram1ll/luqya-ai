<?php
// نقطة النهاية للتوثيق والتحقق من الهوية الجامعية
require_once __DIR__ . '/../config/db.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'register') {
    requireCsrf();
    enforceRateLimit('register', 5, 3600);

    $university_id = strtoupper(trim($_POST['university_id'] ?? ''));
    $full_name = trim($_POST['full_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';

    // الكلية والتخصص (اختياريان): تُعرض في بطاقة الملف الشخصي بجانب الرقم الجامعي.
    $collegeCheck = validateInput($_POST['college'] ?? '', 'text', ['max' => 100]);
    $college = $collegeCheck['valid'] ? $collegeCheck['value'] : '';
    $majorCheck = validateInput($_POST['major'] ?? '', 'text', ['max' => 100]);
    $major = $majorCheck['valid'] ? $majorCheck['value'] : '';

    // يحق للطلاب فقط إنشاء حساب بأنفسهم؛ حسابات ضباط الأمن تُجهَّز عبر أكواد SEC-USR-* إلخ.
    $role = 'student';

    // التحقق الصارم من صيغة الرقم الجامعي S متبوعاً بـ 9 أرقام
    if (!validateUniversityID($university_id)) {
        jsonResponse([
            'success' => false,
            'message' => 'صيغة الرقم الجامعي غير صحيحة. يجب أن يبدأ بحرف S متبوعاً بـ 9 أرقام (مثال: S000000001).'
        ], 400);
    }

    // الاسم الكامل: نص مُنظّف ومنسّق (ترميز HTML ضد XSS) بأقصى 100 حرف
    $nameCheck = validateInput($full_name, 'name', ['required' => true, 'max' => 100]);
    if (!$nameCheck['valid']) {
        jsonResponse(['success' => false, 'message' => $nameCheck['error'] ?? 'الاسم الكامل غير صالح.'], 400);
    }
    $full_name = $nameCheck['value'];

    // رقم الجوال: يُحفظ نصاً قياسياً مُتحققاً منه (أرقام فقط) لإمكانية التواصل لاحقاً
    $phoneCheck = validateInput($phone, 'phone', ['max' => 20]);
    $phone = $phoneCheck['valid'] ? $phoneCheck['value'] : '';

    // كلمة المرور: تُفحص صرًة على مستوى الخادم ثم تُحفظ كبصمة password_hash فقط
    if (empty($password)) {
        jsonResponse(['success' => false, 'message' => 'يرجى إدخال كلمة المرور.'], 400);
    }
    if (strlen($password) < 6) {
        jsonResponse(['success' => false, 'message' => 'كلمة المرور يجب ألا تقل عن 6 أحرف.'], 400);
    }

    $email = generateUniversityEmail($university_id);
    $pdo = getDBConnection();

    if (!$pdo) {
        jsonResponse(['success' => false, 'message' => 'فشل الاتصال بقاعدة البيانات.'], 500);
    }

    $stmt = $pdo->prepare("SELECT id FROM users WHERE university_id = ? OR email = ?");
    $stmt->execute([$university_id, $email]);
    if ($stmt->fetch()) {
        jsonResponse(['success' => false, 'message' => 'يوجد حساب مسجل مسبقاً بهذا الرقم الجامعي.'], 400);
    }

    $password_hash = password_hash($password, PASSWORD_DEFAULT);
    ensureUserProfileColumns($pdo);
    $stmt = $pdo->prepare("INSERT INTO users (university_id, full_name, email, phone, college, major, password_hash, role) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$university_id, $full_name, $email, $phone, $college, $major, $password_hash, $role]);

    $new_user_id = $pdo->lastInsertId();

    // تسجيل الدخول التلقائي (تدوير معرّف الجلسة لمنع تثبيت الجلسة)
    session_regenerate_id(true);
    $_SESSION['user_id'] = $new_user_id;
    $_SESSION['university_id'] = $university_id;
    $_SESSION['full_name'] = $full_name;
    $_SESSION['email'] = $email;
    $_SESSION['college'] = $college;
    $_SESSION['major'] = $major;
    $_SESSION['role'] = $role;

    jsonResponse([
        'success' => true,
        'message' => 'تم إنشاء الحساب الجامعي بنجاح!',
        'redirect' => 'index.php'
    ]);
}

if ($action === 'login') {
    requireCsrf();
    // يمنع هجمات التفخيخ (Brute Force) بتقييد المحاولات لكل عنوان IP
    enforceRateLimit('login', 10, 300);

    $identifier = strtoupper(trim($_POST['university_id'] ?? ''));
    $password = $_POST['password'] ?? '';

    // قبول إما الرقم الجامعي (S000000001) أو كود ضابط الأمن (SEC-USR-1)
    if (!validateLoginIdentifier($identifier)) {
        jsonResponse(['success' => false, 'message' => 'معرّف الدخول غير صالح! أدخل رقمك الجامعي (S000000001) أو كود ضابط الأمن (مثل SEC-USR-1).'], 400);
    }

    $pdo = getDBConnection();
    if (!$pdo) {
        jsonResponse(['success' => false, 'message' => 'خطأ في الاتصال بقاعدة البيانات.'], 500);
    }

    // ضمان وجود عمود/حسابات أكواد الأمن (تهيئة ذاتية آمنة عند الحاجة)
    ensureSecurityCodeColumn($pdo);
    ensureSecurityOfficers($pdo);
    ensureUserProfileColumns($pdo);

    // البحث عن الحساب حسب نوع المعرّف:
    // - الطلاب (S...): الرقم الجامعي معرّف فريد يُخزَّن نصاً قياسياً للبحث والتواصل.
    // - ضباط الأمن (SEC...): الكود لا يُخزَّن نصاً مفتوحاً بل كبصمة password_hash في
    //   security_code_hash، لذا لا يمكن البحث عنه مباشرة في SQL؛ نمسح حسابات الأمن
    //   ونتحقق من الكود عبر password_verify ثم نتأكد من كلمة المرور كالمعتاد.
    if (validateUniversityID($identifier)) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE university_id = ?");
        $stmt->execute([$identifier]);
        $user = $stmt->fetch();
    } else {
        $user = null;
        $stmt = $pdo->prepare("SELECT * FROM users WHERE role IN ('admin', 'security')");
        $stmt->execute();
        foreach ($stmt->fetchAll() as $candidate) {
            $storedCodeHash = $candidate['security_code_hash'] ?? '';
            if ($storedCodeHash !== '' && password_verify($identifier, $storedCodeHash)) {
                $user = $candidate;
                break;
            }
        }
    }

    // توثيق قياسي: تحقق من كلمة المرور مقابل الهاش المخزّن فقط (بلا كلمات مرور مفتوحة/خلفية)
    if ($user && password_verify($password, $user['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        // نعرض في الجلسة المعرّف القابل للقراءة الذي أدخله المستخدم (رقم الطالب أو كود الأمن)
        $_SESSION['university_id'] = $identifier;
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['college'] = $user['college'] ?? '';
        $_SESSION['major'] = $user['major'] ?? '';
        $_SESSION['role'] = $user['role'];

        // ترقية الهاش تلقائياً إذا تغيّرت الخوارزمية الافتراضية (يبقى متوافقاً مع الهاشات القديمة)
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $rehashStmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
            $rehashStmt->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
        }

        jsonResponse([
            'success' => true,
            'message' => 'تم تسجيل الدخول بنجاح!',
            'redirect' => homePathForRole($user)
        ]);
    } else {
        jsonResponse(['success' => false, 'message' => 'المعرّف أو كلمة المرور غير صحيحة.'], 401);
    }
}

if ($action === 'forgot_password') {
    requireCsrf();
    enforceRateLimit('forgot_password', 5, 300);

    $identifier = trim($_POST['identifier'] ?? '');

    if (empty($identifier)) {
        jsonResponse(['success' => false, 'message' => 'يرجى إدخال الرقم الجامعي أو البريد الجامعي.'], 400);
    }

    // رسالة عامة موحّدة لكل الحالات: لا تُكشف بيانات المستخدمين أو تُستخدم لفرز الحسابات
    $genericMessage = 'تم إرسال تعليمات إعادة تعيين كلمة المرور إلى بريدك الجامعي.';

    $pdo = getDBConnection();
    if (!$pdo) {
        jsonResponse(['success' => false, 'message' => 'خطأ في الاتصال بقاعدة البيانات.'], 500);
    }
    ensurePasswordResetsTable($pdo);

    // تحديد الحساب: قبول الرقم الجامعي (S000000001) أو البريد الجامعي (@uoh.edu.sa)
    $userId = null;
    if (validateUniversityID($identifier)) {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE university_id = ?");
        $stmt->execute([strtoupper($identifier)]);
        $row = $stmt->fetch();
        $userId = $row ? (int) $row['id'] : null;
    } else {
        $emailCheck = validateInput($identifier, 'email', ['required' => true]);
        if ($emailCheck['valid']) {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$emailCheck['value']]);
            $row = $stmt->fetch();
            $userId = $row ? (int) $row['id'] : null;
        }
    }

    if ($userId !== null) {
        // إلغاء أي رموز سابقة غير مستهلكة لنفس الحساب (استخدام فردي صارم)
        $pdo->prepare("DELETE FROM password_resets WHERE user_id = ? AND used_at IS NULL")
            ->execute([$userId]);

        // رمز مؤقت آمن عمره 30 دقيقة؛ تُخزَّن بصمته (SHA-256) فقط في قاعدة البيانات
        $rawToken  = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $expiresAt = date('Y-m-d H:i:s', time() + 1800);
        $pdo->prepare("INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, ?)")
            ->execute([$userId, $tokenHash, $expiresAt]);

        // الرمز الخام لا يُخزَّن ولا يُطبع إلا في بيئة التطوير.
        // في الإنتاج يُرسَل إلى البريد الجامعي ولا يظهر في أي سجل.
        if (isResetTokenExposedToLog()) {
            error_log(
                'Luqya AI: [DEV ONLY] password reset link for user #' . $userId . ' - ' .
                'forgot_password.php?step=reset&token=' . $rawToken
            );
        }
        error_log(sprintf(
            'Luqya AI: password reset token issued for user #%d (expires %s, token_sha256_prefix=%s)',
            $userId,
            $expiresAt,
            substr($tokenHash, 0, 12)
        ));
    }

    // تنظيف الرموز المنتهية القديمة من الجدول
    $pdo->prepare("DELETE FROM password_resets WHERE expires_at < ?")
        ->execute([date('Y-m-d H:i:s', time())]);

    jsonResponse([
        'success' => true,
        'message' => $genericMessage
    ]);
}

if ($action === 'reset_password') {
    requireCsrf();
    enforceRateLimit('reset_password', 5, 900);

    $token = trim($_POST['token'] ?? '');
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';

    // الرمز يجب أن يكون سداسياً عشرياً بطول 64 محرفاً كاملاً
    if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/i', $token)) {
        jsonResponse(['success' => false, 'message' => 'رمز إعادة التعيين غير صالح.'], 400);
    }

    if ($password === '') {
        jsonResponse(['success' => false, 'message' => 'يرجى إدخال كلمة المرور الجديدة.'], 400);
    }
    if (strlen($password) < 6) {
        jsonResponse(['success' => false, 'message' => 'كلمة المرور يجب ألا تقل عن 6 أحرف.'], 400);
    }
    if ($password_confirm !== $password) {
        jsonResponse(['success' => false, 'message' => 'كلمتا المرور غير متطابقتين.'], 400);
    }

    $pdo = getDBConnection();
    if (!$pdo) {
        jsonResponse(['success' => false, 'message' => 'خطأ في الاتصال بقاعدة البيانات.'], 500);
    }
    ensurePasswordResetsTable($pdo);

    // البحث عن الرمز ببصمته فقط، بشرط أن يكون غير مستهلك
    $stmt = $pdo->prepare("SELECT id, user_id, expires_at FROM password_resets WHERE token_hash = ? AND used_at IS NULL LIMIT 1");
    $stmt->execute([hash('sha256', $token)]);
    $reset = $stmt->fetch();

    if (!$reset) {
        jsonResponse(['success' => false, 'message' => 'رمز إعادة التعيين غير صالح أو منتهي الصلاحية.'], 400);
    }

    // التحقق من انتهاء الصلاحية (30 دقيقة من وقت الإصدار)
    if (strtotime((string) $reset['expires_at']) < time()) {
        $pdo->prepare("DELETE FROM password_resets WHERE id = ?")->execute([$reset['id']]);
        jsonResponse(['success' => false, 'message' => 'انتهت صلاحية رمز إعادة التعيين. يرجى طلب رمز جديد.'], 400);
    }

    // التأكد من استمرار وجود الحساب المرتبط بالرمز
    $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ?");
    $stmt->execute([$reset['user_id']]);
    if (!$stmt->fetch()) {
        jsonResponse(['success' => false, 'message' => 'الحساب المرتبط بالرمز لم يعد موجوداً.'], 400);
    }

    // تحديث كلمة المرور كبصمة password_hash فقط واستهلاك الرمز في معاملة واحدة
    $newHash = password_hash($password, PASSWORD_DEFAULT);
    $now = date('Y-m-d H:i:s');

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        $stmt->execute([$newHash, $reset['user_id']]);

        $stmt = $pdo->prepare("UPDATE password_resets SET used_at = ? WHERE id = ?");
        $stmt->execute([$now, $reset['id']]);

        // إلغاء أي رموز أخرى متبقية للحساب لضمان استخدام واحد فقط
        $pdo->prepare("DELETE FROM password_resets WHERE user_id = ? AND id <> ?")
            ->execute([$reset['user_id'], $reset['id']]);

        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log('Luqya AI: فشل تحديث كلمة المرور: ' . $e->getMessage());
        jsonResponse(['success' => false, 'message' => 'تعذر تحديث كلمة المرور. يرجى المحاولة لاحقاً.'], 500);
    }

    jsonResponse([
        'success' => true,
        'message' => 'تم تحديث كلمة المرور بنجاح. يمكنك الآن تسجيل الدخول.'
    ]);
}

if ($action === 'logout') {
    // تسجيل الخروج عبر GET يُعد هجوماً محتملاً (CSRF)؛ يجب أن يكون POST مع رمز حماية
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(['success' => false, 'message' => 'طريقة التسجيل غير مدعومة.'], 405);
    }
    requireCsrf();

    session_destroy();
    header('Location: ../login.php');
    exit;
}

jsonResponse(['success' => false, 'message' => 'طلب غير صالح.'], 400);
