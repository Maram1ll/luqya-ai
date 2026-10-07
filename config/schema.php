<?php
/**
 * لُقيا AI — المصدر الوحيد للحقيقة: حدود أعمدة قاعدة البيانات وقيمها المسموح بها
 *
 * كل حدّ طول أو قائمة قيم معرَّفة هنا يجب أن يطابق تعريف العمود في
 * database/schema.sql بالضبط. هذا يمنع الخطأ الذي كان قائماً: مواءمة حدود
 * التحقق في الخادم (200/250/1000) مع أعمدة أضيق منها (150/150/255)، فكان
 * الخادم يقبل نصاً أطول مما تتسع له العمود.
 *
 * ملاحظة مهمة حول وضع SQL:
 * خادم MySQL/MariaDB في هذا المشروع قد لا يكون في الوضع STRICT (Strict Mode).
 * في الوضع غير الصارم لا يرفض MariaDB النص الأطول بخطأ، بل يقتطعه بصمت
 * (Data truncated) — وهو أسوأ من الخطأ الصريح لأنه يفسد البيانات دون إنذار.
 * لذلك التحقق في PHP هنا ليس تحسيناً اختيارياً بل خط الدفاع الوحيد.
 *
 * تُحمَّل هذا الملف تلقائياً عبر config/db.php فلا حاجة لـ require منفصلة.
 */

// ---------------------------------------------------------------------------
// 1) حدود أطوال أعمدة جدول reports (بالأحرف، مطابقة تماماً لعمود VARCHAR/TEXT)
// ---------------------------------------------------------------------------
define('REPORT_TITLE_MAX',         200);   // reports.title            VARCHAR(200)
define('REPORT_CATEGORY_MAX',      50);    // reports.category         VARCHAR(50)
define('REPORT_DESCRIPTION_MAX',   4000);  // reports.description      TEXT
define('REPORT_LOCATION_MAX',      250);   // reports.location         VARCHAR(250)
define('REPORT_AI_TAGS_MAX',       500);   // reports.ai_tags          VARCHAR(500)
define('REPORT_FEATURES_MAX',      2000);  // reports.distinctive_features TEXT
define('REPORT_IMAGE_URL_MAX',     255);   // reports.image_url        VARCHAR(255)

// ---------------------------------------------------------------------------
// 2) حدود أطوال الأعمدة في الجداول الأخرى (سجل النشاط والإشعارات والمستخدمين)
// ---------------------------------------------------------------------------
define('NOTIFICATION_TITLE_MAX',   150);   // notifications.title      VARCHAR(150)
define('NOTIFICATION_TYPE_MAX',    50);    // notifications.type       VARCHAR(50)
define('ACTIVITY_ITEM_TITLE_MAX',  200);   // activity_logs.item_title VARCHAR(200)
define('ACTIVITY_DETAILS_MAX',     2000);  // activity_logs.details    TEXT
define('USER_FULL_NAME_MAX',       100);   // users.full_name          VARCHAR(100)

// ---------------------------------------------------------------------------
// 3) القيم المسموح بها (مطابقة تعريفات ENUM في schema.sql)
// ---------------------------------------------------------------------------

/** قيم reports.status — ENUM('active','matched','pending_pickup','resolved','cancelled') */
const REPORT_STATUSES = ['active', 'matched', 'pending_pickup', 'resolved', 'cancelled'];

/** قيمة reports.status الافتراضية عند غياب قيمة صالحة */
const REPORT_STATUS_DEFAULT = 'active';

/** قيم reports.type — ENUM('lost','found') */
const REPORT_TYPES = ['lost', 'found'];

/** قيم matches.status — ENUM('pending','verified','rejected') */
const MATCH_STATUSES = ['pending', 'verified', 'rejected'];

/** قيمة matches.status الافتراضية */
const MATCH_STATUS_DEFAULT = 'pending';

/** قيم users.role — ENUM('student','security','admin') */
const USER_ROLES = ['student', 'security', 'admin'];

/**
 * قيم notifications.type (VARCHAR(50) وليس ENUM عمداً لمرونة الإشعارات).
 * 'match_found' هو ما تتوقّعه واجهة views/header.php لعرض أيقونة المطابقة الذكية،
 * لذلك يجب أن تكتبه نقطة نهاية المطابقة لا 'resolved'.
 */
const NOTIFICATION_TYPES = ['info', 'match_found', 'resolved'];

/**
 * لواحق ملفات الصور المقبولة في reports.image_url — انظر normalizeImagePath().
 */
const ALLOWED_IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif'];

// ---------------------------------------------------------------------------
// 4) التحقق من أطوال الحقول
// ---------------------------------------------------------------------------

/**
 * حدود الأطوال المسموح بها لكل حقل في جدول reports.
 *
 * @return array<string,int> اسم الحقل => أقصى عدد أحرف
 */
function reportFieldLimits(): array {
    return [
        'title'                => REPORT_TITLE_MAX,
        'category'             => REPORT_CATEGORY_MAX,
        'description'          => REPORT_DESCRIPTION_MAX,
        'location'             => REPORT_LOCATION_MAX,
        'ai_tags'              => REPORT_AI_TAGS_MAX,
        'distinctive_features' => REPORT_FEATURES_MAX,
        'image_url'            => REPORT_IMAGE_URL_MAX,
    ];
}

/**
 * أسماء الحقول العربية المعروضة للمستخدم عند تجاوز الطول.
 *
 * @return array<string,string>
 */
function reportFieldLabels(): array {
    return [
        'title'                => 'اسم العنصر',
        'category'             => 'التصنيف',
        'description'          => 'الوصف',
        'location'             => 'الموقع',
        'ai_tags'              => 'الوسوم الذكية',
        'distinctive_features' => 'العلامات المميزة',
        'image_url'            => 'رابط الصورة',
    ];
}

/**
 * التحقق من أطوال مجموعة حقول قبل أي INSERT/UPDATE على جدول reports.
 * يُرجع رسالة خطأ عربية جاهزة للعرض، أو null إذا كانت كل الحقول ضمن الحدود.
 *
 * ملاحظة: يُتخطى الحقل الفارغ (null / '') لأن القيم الاختيارية تُخزَّن NULL.
 *
 * @param array<string,mixed> $fields الحقول المُدخلة
 * @return string|null رسالة الخطأ أو null عند النجاح
 */
function validateReportFieldLengths(array $fields): ?string {
    $limits = reportFieldLimits();
    $labels = reportFieldLabels();

    foreach ($limits as $field => $max) {
        if (!array_key_exists($field, $fields)) {
            continue;
        }
        $value = $fields[$field];
        if ($value === null || is_array($value) || is_object($value)) {
            continue;
        }
        $len = mb_strlen((string) $value, 'UTF-8');
        if ($len > $max) {
            return sprintf(
                '%s تجاوز الحد المسموح (%d حرف). الطول الحالي %d حرف. يرجى اختصار النص.',
                $labels[$field] ?? $field,
                $max,
                $len
            );
        }
    }

    return null;
}

/**
 * التحقق من أطوال حقول سجل النشاط (activity_logs).
 * حقلا details و item_title كانا معرضين لتجاوز الطول.
 *
 * @return string|null رسالة الخطأ أو null عند النجاح
 */
function validateActivityLogLengths(string $itemTitle, string $details, string $userName): ?string {
    if (mb_strlen($itemTitle, 'UTF-8') > ACTIVITY_ITEM_TITLE_MAX) {
        return 'عنوان السجل تجاوز الحد المسموح (' . ACTIVITY_ITEM_TITLE_MAX . ' حرف).';
    }
    if (mb_strlen($details, 'UTF-8') > ACTIVITY_DETAILS_MAX) {
        return 'تفاصيل السجل تجاوزت الحد المسموح (' . ACTIVITY_DETAILS_MAX . ' حرف).';
    }
    if (mb_strlen($userName, 'UTF-8') > USER_FULL_NAME_MAX) {
        return 'اسم المستخدم في السجل تجاوز الحد المسموح (' . USER_FULL_NAME_MAX . ' حرف).';
    }
    return null;
}

// ---------------------------------------------------------------------------
// 5) التحقق من قيم ENUM (منع حقن قيم خارج النطاق المعرَّف في المخطط)
// ---------------------------------------------------------------------------

/**
 * هل القيمة صالحة ضمن قائمة معرَّفة؟
 *
 * @param array<int,string> $allowed
 */
function isAllowedValue(?string $value, array $allowed): bool {
    return is_string($value) && in_array($value, $allowed, true);
}

/**
 * هل القيمة صالحة كحالة لبلاغ (reports.status)؟
 */
function isValidReportStatus(?string $status): bool {
    return isAllowedValue($status, REPORT_STATUSES);
}

/**
 * هل القيمة صالحة كنوع بلاغ (reports.type)؟
 */
function isValidReportType(?string $type): bool {
    return isAllowedValue($type, REPORT_TYPES);
}

/**
 * هل القيمة صالحة كحالة مطابقة (matches.status)؟
 */
function isValidMatchStatus(?string $status): bool {
    return isAllowedValue($status, MATCH_STATUSES);
}

/**
 * تطبيع حالة البلاغ القادم من نموذج/طلب خارجي إلى قيمة ضمن الـ ENUM.
 * تُستخدم كخط دفاع عند ورود قيمة غير صالحة: تُسقطها إلى القيمة الافتراضية
 * بدل كتابتها في العمود (وهو ما يُسبب خطأ 1265 أو حالة فاسدة).
 *
 * @param mixed  $raw      القيمة الخام من $_POST
 * @param string $default  القيمة البديلة عند الرفض
 */
function normalizeReportStatus($raw, string $default = REPORT_STATUS_DEFAULT): string {
    $value = is_string($raw) ? trim($raw) : '';
    return isValidReportStatus($value) ? $value : $default;
}

/**
 * تطبيع نوع البلاغ إلى قيمة ضمن الـ ENUM.
 */
function normalizeReportType($raw, string $default = 'lost'): string {
    $value = is_string($raw) ? mb_strtolower(trim($raw), 'UTF-8') : '';
    return isValidReportType($value) ? $value : $default;
}

/**
 * تطبيع حالة المطابقة إلى قيمة ضمن الـ ENUM.
 */
function normalizeMatchStatus($raw, string $default = MATCH_STATUS_DEFAULT): string {
    $value = is_string($raw) ? trim($raw) : '';
    return isValidMatchStatus($value) ? $value : $default;
}

/**
 * تطبيع نوع الإشعار. أي قيمة خارج القائمة المعروفة تُسقط إلى 'info'
 * بدل كتابتها كما هي (عمود type من نوع VARCHAR(50) وليس ENUM عمداً لمرونة الإشعارات).
 */
function normalizeNotificationType(?string $type): string {
    return isAllowedValue($type, NOTIFICATION_TYPES) ? $type : 'info';
}

/**
 * هل حالة البلاغ تُعتبر نهائية (تُغلق مسار المراجعة)؟
 */
function isTerminalReportStatus(string $status): bool {
    return $status === 'resolved' || $status === 'cancelled';
}

// ---------------------------------------------------------------------------
// 6) تطبيع مسارات الصور المخزَّنة
// ---------------------------------------------------------------------------

/** المسارات النسبية المسموح تخزينها في reports.image_url */
const ALLOWED_IMAGE_PATH_PREFIXES = ['uploads/', 'assets/images/'];

/**
 * تطبيع مسار الصورة المخزَّن في reports.image_url.
 *
 * القواعد الثلاث مجتمعة: بادئة مسموحة + لاحقة صورة + طول ضمن الحد.
 *
 * لماذا البادئة: كان المخطط يتضمّن صفوفاً بصيغة 'assets/images/uploads/...' بينما
 * الرفع الفعلي يكتب 'uploads/...' (انظر handleImageUpload)، فوحّدنا الصيغتين.
 *
 * لماذا اللاحقة: بدونها كان أي مسار يبدأ بـ uploads/ يُقبل، ومنها 'uploads/evil.php'
 * و'uploads/x.html' — تُخزَّن في image_url وتُعاد للواجهة داخل <img src>.
 * لا ينفّذها <img>، لكنها تكسر ضمان أن العمود يشير لصورة وتكشف ملفات خادمة.
 *
 * كما يمنع تمرير مسارات خارجية (http://، //، javascript:) لأن image_url كان
 * يُقبل من POST مباشرة في شاشة تعديل المعثور عليه.
 *
 * @param mixed $path القيمة الخام
 * @return string المسار بعد التطبيع أو '' عند الرفض
 */
function normalizeImagePath($path): string {
    if (!is_string($path) || $path === '') {
        return '';
    }

    $path = trim($path);

    // رفض أي مخطط أو بروتوكول أو مسار شبكي — مسارات نسبية فقط
    if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*:#', $path) || str_starts_with($path, '//')) {
        return '';
    }

    // البادئ القديم يشير إلى مجلد uploads نفسه: نزيل 'assets/images/' فقط
    // ونُبقي 'uploads/' لأنها البادئة المجازة فعلياً.
    if (str_starts_with($path, 'assets/images/uploads/')) {
        $path = 'uploads/' . substr($path, strlen('assets/images/uploads/'));
    }

    foreach (ALLOWED_IMAGE_PATH_PREFIXES as $prefix) {
        if (!str_starts_with($path, $prefix)) {
            continue;
        }

        $candidate = ltrim($path, '/');
        if (str_contains($candidate, '..')) {
            return '';
        }
        if (mb_strlen($candidate, 'UTF-8') > REPORT_IMAGE_URL_MAX) {
            return '';
        }

        $ext = strtolower((string) pathinfo($candidate, PATHINFO_EXTENSION));
        if (!in_array($ext, ALLOWED_IMAGE_EXTENSIONS, true)) {
            return '';
        }

        // خدعة الامتداد المزدوج: 'uploads/x.php.jpg' تنتهي بـ jpg فتنجح،
        // لكن بعض إعدادات الخادم قد تنفّذها. handleImageUpload يولّد اسماً بلا
        // نقاط، لذا نرفض أي اسم أساسي فيه أكثر من نقطة.
        if (substr_count(basename($candidate), '.') > 1) {
            return '';
        }

        return $candidate;
    }

    return '';
}

/**
 * تطبيع معرّف ثقة المطابقة إلى مجال 0-100 (مطابق لـ CHECK في المخطط).
 */
function normalizeConfidenceScore($raw, int $default = 90): int {
    if (is_string($raw) && preg_match('/^-?\d+$/', trim($raw))) {
        $value = (int) trim($raw);
    } elseif (is_int($raw) || is_float($raw)) {
        $value = (int) $raw;
    } else {
        return $default;
    }
    return max(0, min(100, $value));
}

/**
 * تطبيع كود الاستلام RET-###### إلى الصيغة المعتمدة، أو '' عند الرفض.
 * يضمن أن كل الأكواد المتسعة في قاعدة البيانات تسير على نسق واحد.
 */
function normalizePickupCode($raw): string {
    if (!is_string($raw)) {
        return '';
    }
    $code = strtoupper(trim($raw));
    return preg_match('/^RET-\d{6}$/', $code) ? $code : '';
}
