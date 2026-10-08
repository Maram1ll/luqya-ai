<?php
// Config: Database Connection, Environment Loader & Security Helper Functions

// 0. Single source of truth for column widths and ENUM vocabularies.
//    Must load before anything validates a value against the schema.
require_once __DIR__ . '/schema.php';

// 1. Automatic .env file reader (real environment variables always take precedence)
if (is_readable(__DIR__ . '/../.env')) {
    $envLines = file(__DIR__ . '/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($envLines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        if (str_starts_with($line, 'export ')) {
            $line = trim(substr($line, 7));
        }
        if (!str_contains($line, '=')) continue;

        list($envKey, $envVal) = explode('=', $line, 2);
        $envKey = trim($envKey);
        if ($envKey === '') continue;

        $envVal = trim($envVal);
        $valLen = strlen($envVal);
        if ($valLen >= 2 && (($envVal[0] === '"' && $envVal[$valLen - 1] === '"')
            || ($envVal[0] === "'" && $envVal[$valLen - 1] === "'"))) {
            $envVal = substr($envVal, 1, -1);
        }

        // Never override a variable already supplied by the real environment
        if (getenv($envKey) !== false || isset($_ENV[$envKey])) continue;

        $_ENV[$envKey] = $envVal;
        putenv("{$envKey}={$envVal}");
    }
}

// 1b. Deployment secrets file: config/config.local.php
//     Most free hosting offers neither environment variables nor .env support,
//     so this file is the deployment path. It is git-ignored AND denied by
//     .htaccess, and it is only ever consulted for keys that are still
//     missing, so the real environment and .env always keep precedence.
//
//     Order of resolution: real environment > .env > config/config.local.php
if (!function_exists('luqyaLocalConfig')) {
    /**
     * يقرأ config/config.local.php مرة واحدة ويحفظه في الذاكرة.
     *
     * يُرجع مصفوفة كاملة عند تمرير $key فارغاً، أو قيمة مفتاح واحد.
     * الملف غير موجود أو تالف ⇒ مصفوفة فارغة، فتستمر بقية القيم الافتراضية.
     */
    function luqyaLocalConfig(string $key = '', $default = null) {
        static $cfg = null;
        if ($cfg === null) {
            $path = __DIR__ . '/config.local.php';
            if (is_file($path) && is_readable($path)) {
                // السطر التالي هو السطر الوحيد الذي يقرأ الأسرار، وهو من ملف
                // مستبعَد من Git ومست blocking من .htaccess.
                $loaded = require $path;
                $cfg = is_array($loaded) ? $loaded : [];
            } else {
                $cfg = [];
            }
        }
        if ($key === '') {
            return $cfg;
        }
        return array_key_exists($key, $cfg) ? $cfg[$key] : $default;
    }
}

// scalars (DB_USER, GEMINI_API_KEY, flags...) become environment values, but
// only where nothing has defined them yet. Arrays stay in luqyaLocalConfig().
foreach (luqyaLocalConfig() as $luqyaKey => $luqyaVal) {
    if (is_array($luqyaVal) || is_object($luqyaVal)) {
        continue;
    }
    if (getenv($luqyaKey) !== false || isset($_ENV[$luqyaKey])) {
        continue; // real environment / .env already won
    }
    $luqyaEnvVal = is_bool($luqyaVal) ? ($luqyaVal ? '1' : '0') : (string) $luqyaVal;
    $_ENV[$luqyaKey] = $luqyaEnvVal;
    putenv("{$luqyaKey}={$luqyaEnvVal}");
}

// 2. Database credentials come exclusively from the environment (no hardcoded secrets).
//    DB_HOST / DB_PORT are non-sensitive and keep safe defaults; DB_USER, DB_PASS and
//    DB_NAME must be provided via .env, config/config.local.php or the process environment.

// 2b. Railway (and similar PaaS) database variables. Railway's MySQL service
//     exposes either a single connection URL (MYSQL_URL, or DATABASE_URL) or
//     individual MYSQLHOST / MYSQLPORT / MYSQLUSER / MYSQLPASSWORD /
//     MYSQLDATABASE variables. These are used ONLY where the DB_* sources
//     above are absent, so any explicitly configured connection keeps winning.
$rvHost = false;
$rvPort = false;
$rvUser = false;
$rvPass = false;
$rvName = false;

$rvUrl = getenv('MYSQL_URL');
if ($rvUrl === false) {
    $rvUrl = getenv('DATABASE_URL');
}
if ($rvUrl !== false && $rvUrl !== '') {
    $parsedRv = parse_url($rvUrl);
    if (is_array($parsedRv) && isset($parsedRv['host'])) {
        $rvHost = $parsedRv['host'];
        $rvPort = isset($parsedRv['port']) ? (string) $parsedRv['port'] : '3306';
        $rvUser = $parsedRv['user'] ?? '';
        $rvPass = isset($parsedRv['pass']) ? urldecode($parsedRv['pass']) : '';
        $rvName = isset($parsedRv['path']) ? ltrim($parsedRv['path'], '/') : '';
    }
}

// Individual Railway variables override the parsed URL (in case both exist).
$rvHost = getenv('MYSQLHOST') !== false && getenv('MYSQLHOST') !== '' ? getenv('MYSQLHOST') : $rvHost;
$rvPort = getenv('MYSQLPORT') !== false && getenv('MYSQLPORT') !== '' ? getenv('MYSQLPORT') : $rvPort;
$rvUser = getenv('MYSQLUSER') !== false && getenv('MYSQLUSER') !== '' ? getenv('MYSQLUSER') : $rvUser;
$rvPass = getenv('MYSQLPASSWORD') !== false && getenv('MYSQLPASSWORD') !== '' ? getenv('MYSQLPASSWORD') : $rvPass;
$rvName = getenv('MYSQLDATABASE') !== false && getenv('MYSQLDATABASE') !== '' ? getenv('MYSQLDATABASE') : $rvName;

$dbHost = getenv('DB_HOST');
$dbPort = getenv('DB_PORT');
$dbUser = getenv('DB_USER');
$dbPass = getenv('DB_PASS');
if ($dbPass === false) {
    $dbPass = getenv('DB_PASSWORD');
}
$dbName = getenv('DB_NAME');

// Apply the Railway fallback only where the DB_* source returned nothing.
if (($dbHost === false || $dbHost === '') && $rvHost !== false && $rvHost !== '') { $dbHost = $rvHost; }
if (($dbPort === false || $dbPort === '') && $rvPort !== false && $rvPort !== '') { $dbPort = $rvPort; }
if (($dbUser === false || $dbUser === '') && $rvUser !== false && $rvUser !== '') { $dbUser = $rvUser; }
if (($dbPass === false || $dbPass === '') && $rvPass !== false && $rvPass !== '') { $dbPass = $rvPass; }
if (($dbName === false || $dbName === '') && $rvName !== false && $rvName !== '') { $dbName = $rvName; }

define('DB_HOST', $dbHost !== false && $dbHost !== '' ? $dbHost : 'localhost');
define('DB_PORT', $dbPort !== false && $dbPort !== '' ? $dbPort : '3306');
define('DB_USER', $dbUser !== false ? $dbUser : '');
define('DB_PASS', $dbPass !== false ? $dbPass : '');
define('DB_NAME', $dbName !== false ? $dbName : '');

// تفاصيل آخر فشل في الاتصال، بنفس نمط $gemini_last_error.
// تُخزَّن لتُعرض في setup_check.php / وضع التشخيص، لأن الاستثناء كان
// يُبتلع هنا فلا يرى أحد سبب الفشل الحقيقي. لا تحتوي كلمة المرور أبداً.
$GLOBALS['luqya_db_last_error'] = null;

function setDBLastError(string $message, string $kind = 'exception'): void {
    $GLOBALS['luqya_db_last_error'] = [
        'kind'      => $kind,
        'message'   => $message,
        'host'      => DB_HOST,
        'port'      => DB_PORT,
        'db'        => DB_NAME,
        'user'      => DB_USER,
        'php'       => PHP_VERSION,
        'timestamp' => date('c'),
    ];
}

function getDBLastError(): ?array {
    return $GLOBALS['luqya_db_last_error'];
}

function getDBConnection() {
    static $pdo = null;
    if ($pdo === null) {
        if (DB_USER === '' || DB_NAME === '') {
            $msg = 'DB_USER and DB_NAME are empty. No config source supplied them. '
                 . 'Check config/config.local.php, the .env file, and server environment variables.';
            error_log('Luqya AI: ' . $msg);
            setDBLastError($msg, 'not_configured');
            return null;
        }
        try {
            $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            // Explicitly pin the connection to utf8mb4 (DSN charset already does
            // this, this is belt-and-braces so Arabic text can never be stored
            // or read back through a latin1/other connection).
            $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
            $GLOBALS['luqya_db_last_error'] = null;
        } catch (PDOException $e) {
            // PDO sometimes embeds the host and user; it never embeds the password.
            // We strip any accidental password fragment before storing/logging it.
            $safe = preg_replace('/(using password: ).*/', '$1[redacted]', $e->getMessage());
            error_log('Luqya AI: database connection failed: ' . $safe);
            setDBLastError((string) $safe, 'exception');
            return null;
        } catch (Throwable $e) {
            // PHP 7+ would stop here; the explicit type keeps older engines honest.
            error_log('Luqya AI: database connection failed (non-PDO): ' . $e->getMessage());
            setDBLastError((string) $e->getMessage(), 'throwable');
            return null;
        }
    }
    return $pdo;
}

// 3. Session helper
if (session_status() === PHP_SESSION_NONE) {
    if (!headers_sent()) {
        $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    session_start();
}

function isLoggedIn(): bool {
    return isset($_SESSION['user_id']);
}

/**
 * قراءة راية بيئة من .env أو من بيئة العملية، بنفس نمط isSemanticMatchingEnabled().
 * تُرجع null إذا لم تُضبط الراية إطلاقاً (والقيمة الافتراضية يقرّرها المستدعي).
 */
function envFlag(string $key): ?string {
    $flag = getenv($key);
    if ($flag === false || trim((string) $flag) === '') {
        $flag = $_ENV[$key] ?? null;
    }
    if ($flag === null || trim((string) $flag) === '') {
        return null;
    }
    return trim((string) $flag);
}

/**
 * هل تُعرض بيانات الدخول التجريبية في صفحة تسجيل الدخول؟
 *
 * المفعّل الافتراضي هو 1 ليتمكن المُقيِّم من تجربة النظام فوراً دون إنشاء حساب،
 * ويمكن إطفاؤه من البيئة دون لمس الكود: LUQYA_DEMO_LOGIN=0
 *
 * ملاحظة أمنية جوهرية: هذه الدالة تتحكّم فقط في *عرض* الزر، ولا تُضيف أي مسار
 * دخول جديداً. الزر يملأ حقلي النموذج فقط، ثم يُرسَل الطلب إلى api/auth.php
 * بالمسار العادي: نفس CSRF ونفس حد المحاولات ونفس password_verify().
 * لا يوجد أي تجاوز للتحقق في هذه الميزة.
 */
function isDemoLoginEnabled(): bool {
    $flag = envFlag('LUQYA_DEMO_LOGIN');
    if ($flag === null) {
        return true;
    }
    return $flag !== '0';
}

/**
 * هل يُكتب رابط إعادة التعيين في سجل أخطاء PHP بدل إرساله بالبريد؟
 *
 * لا يوجد خادم بريد في هذه النسخة من المشروع، لذا تُسلَّم رموز إعادة التعيين
 * عبر سجل الأخطاء عند تفعيل هذه الراية. يجب إطفاؤها في أي بيئة حقيقية،
 * فالرمز الخام يمنح تغيير كلمة المرور كاملاً لمدة 30 دقيقة.
 */
function isResetTokenExposedToLog(): bool {
    return envFlag('LUQYA_EXPOSE_RESET_TOKEN') === '1';
}

/**
 * حسابات الدخول التجريبية المعروضة في صفحة تسجيل الدخول.
 *
 * الحسابات التجريبية المعروضة (حساب ضابط أمن + حساب طالب) مُتحقَّق منها فعلياً
 * مقابل جدول users في هذه النسخة عبر password_verify() على بصمة كلمة المرور:
 *
 * ⚠️ تُقرأ الحسابات بالترتيب التالي (أول مصدر يوفّر قيمة يفوز):
 *   1) config/config.local.php (ملف أسرار مستبعَد من Git ومحميّ بـ .htaccess)،
 *   2) متغيّر البيئة LUQYA_DEMO_ACCOUNTS بصيغة JSON،
 *   3) إعداد افتراضي مضمّن متطابق مع بيانات schema.sql (حسابات تجريبية عامة).
 * لتغيير كلمة مرور العرض التجريبي أو حذف اللوحة بالكامل لا تُعدّل شيفرة النشر:
 * عدّل config/config.local.php أو متغيّر البيئة، أو اخفِ اللوحة بـ LUQYA_DEMO_LOGIN=0.
 *
 * كل حساب هنا مُتحقَّق منه فعلياً مقابل جدول users عبر password_verify()
 * على بصمة كلمة المرور المخزَّنة، لأن الطلب يمرّ بمسار الدخول الطبيعي:
 *   api/auth.php?action=login ← CSRF + enforceRateLimit('login') + password_verify()
 *
 * تنبيه: حسابات الطلاب المذكورة في README (S000000001 .. S000000004) غير موجودة
 * في قاعدة البيانات. طلاب النسخة الحالية هم S202403400 و S202409876 و S202401234
 * إضافة إلى S202447940 الذي له كلمة مرور مختلفة ولا يُدرج هنا.
 * من أراد تجربة واجهة الطالب يسجّل حساباً من register.php.
 *
 * @return array<int, array{label:string, university_id:string, password:string}>
 */
function demoLoginAccounts(): array {
    if (!isDemoLoginEnabled()) {
        return [];
    }

    // 1) ملف الأسرار المحلي (الطريق الموصى به).
    $accounts = luqyaLocalConfig('LUQYA_DEMO_ACCOUNTS', []);

    // 2) بديل نصي لمن يفضّل ضبطها كمتغيّر بيئة (JSON) في لوحة الاستضافة.
    if (empty($accounts)) {
        $raw = envFlag('LUQYA_DEMO_ACCOUNTS');
        if ($raw !== null) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $accounts = $decoded;
            }
        }
    }

    // 3) الإعداد الافتراضي المضمّن: حسابات عامة متطابقة مع بيانات schema.sql
    //    (ضابط أمن SEC-USR-1 وطالب S202403400 — وكلمتاهما مُتحقَّقتان من الهاش
    //    المخزَّن في قاعدة البيانات). تتاح فقط عند غياب config.local.php ومتغيّر
    //    البيئة معاً، فتعمل لوحة «دخول تجريبي» سريع فوراً في بيئات النشر مثل
    //    Railway دون أي إعداد إضافي، مع بقاء التخصيص والإخفاء كما هما.
    //    ملاحظة: كلمات المرور هذه تجريبية وتُعرض على صفحة الدخول نفسها، لذا لا
    //    تشكّل سراً — وتُستبدل بإعداداتك قبل الإطلاق العام إن رغبت.
    if (empty($accounts)) {
        $accounts = [
            [
                'label'         => 'ضابط أمن — لوحة التحكم الكاملة',
                'university_id' => 'SEC-USR-1',
                'password'      => 'security123',
            ],
            [
                'label'         => 'طالب — الإبلاغ عن فقدان',
                'university_id' => 'S202403400',
                'password'      => 'password123',
            ],
        ];
    }

    $out = [];
    foreach ((array) $accounts as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id   = trim((string) ($row['university_id'] ?? ''));
        $pass = (string) ($row['password'] ?? '');
        if ($id === '' || $pass === '') {
            continue; // حساب ناقص لا يُعرض إطلاقاً
        }
        $out[] = [
            'label'         => trim((string) ($row['label'] ?? $id)),
            'university_id' => $id,
            'password'      => $pass,
        ];
    }
    return $out;
}

/**
 * يزيل أي رمز تعبيري (emoji) من بداية النص، ويُستخدم لعناوين الإشعارات
 * حتى لا تتكرر الأيقونات المتعارضة بجانب أيقونات واجهة المستخدم.
 */
function stripNotifEmojiPrefix($text): string {
    $text = (string) $text;
    return (string) preg_replace(
        '/^\s*[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2700}-\x{27BF}\x{FE0F}]+(\s*)/u',
        '$1',
        $text
    );
}

function getCurrentUser(): ?array {
    if (!isLoggedIn()) return null;
    return [
        'id'            => (int) $_SESSION['user_id'],
        'university_id' => $_SESSION['university_id'] ?? '',
        'full_name'     => $_SESSION['full_name'] ?? '',
        'email'         => $_SESSION['email'] ?? '',
        'phone'         => $_SESSION['phone'] ?? '',
        'college'       => $_SESSION['college'] ?? '',
        'major'         => $_SESSION['major'] ?? '',
        'role'          => $_SESSION['role'] ?? 'student'
    ];
}

function isSecurityOfficer(): bool {
    $user = getCurrentUser();
    return $user && ($user['role'] === 'security' || $user['role'] === 'admin');
}

function jsonResponse(array $data, int $statusCode = 200): void {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * رمز CSRF مرتبط بجلسة المستخدم: يُولَّد مرة واحدة ويُعاد استخدامه لبقية الجلسة.
 * يُدخَل في النماذج (حقل _csrf) أو يُرسَل عبر ترويسة X-CSRF-Token في طلبات fetch.
 */
function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * تحقق من صحة رمز CSRF المُرسل (حقل _csrf أو ترويسة X-CSRF-Token) عبر مقارنة آمنة.
 */
function verifyCsrfToken(?string $token): bool {
    if (!is_string($token) || $token === '') {
        return false;
    }
    $expected = $_SESSION['csrf_token'] ?? '';
    return $expected !== '' && hash_equals($expected, $token);
}

/**
 * فرض رمز CSRF على العمليات التي تغيّر الحالة: إن كان غير صالح يُقطع الطلب فوراً.
 * يجب استدعاؤها في أعلى أي نقطة نهاية تقبل POST/GET مُغيّراً للحالة.
 */
function requireCsrf(): void {
    $sent = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!verifyCsrfToken($sent)) {
        jsonResponse(['success' => false, 'message' => 'رمز الحماية (CSRF) غير صالح. يرجى تحديث الصفحة والمحاولة مرة أخرى.'], 403);
    }
}

/**
 * عنوان IP الفعلي للمستخدم مع تجاهل أي ترويسات يحاكيها العميل (خيار أولي آمن).
 */
function clientIp(): string {
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

/**
 * عدّاد معدل طلبات خفيف معتمد على الملفات (بدون تغيير مخطط قاعدة البيانات).
 * يمنع إساءة استخدام نقاط الدخول المكلفة (تسجيل الدخول، الذكاء الاصطناعي...).
 *
 * @param string $action       معرّف العملية (مثال: login-ip / ai-chat-ip)
 * @param int    $maxRequests  أقصى عدد طلبات مسموحة داخل النافذة
 * @param int    $windowSeconds مدة النافذة بالثواني
 * @return bool true إذا تجاوز المستخدم الحد (يجب رفض الطلب)
 */
function rateLimitExceeded(string $action, int $maxRequests, int $windowSeconds = 300): bool {
    if ($maxRequests <= 0 || $windowSeconds <= 0) {
        return false;
    }
    $ip   = clientIp();
    $dir  = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'luqya_ratelimit';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
        return false; // عند تعذر الكتابة نسمح بالمرور بدلاً من كسر الوظيفة
    }
    $file = $dir . DIRECTORY_SEPARATOR . md5($action . '|' . $ip) . '.json';

    $raw  = @file_get_contents($file);
    $data = is_string($raw) ? json_decode($raw, true) : [];
    if (!is_array($data) || !is_array($data['ts'] ?? null)) {
        $data = ['ts' => []];
    }

    $now = time();
    $data['ts'] = array_values(array_filter($data['ts'], static fn($t) => ($now - (int) $t) < $windowSeconds));

    if (count($data['ts']) >= $maxRequests) {
        return true;
    }

    $data['ts'][] = $now;
    @file_put_contents($file, json_encode($data), LOCK_EX);
    return false;
}

/**
 * فرض حد معدل الطلبات وإرجاع استجابة 429 عند تجاوزه.
 */
function enforceRateLimit(string $action, int $maxRequests, int $windowSeconds = 300): void {
    if (rateLimitExceeded($action, $maxRequests, $windowSeconds)) {
        jsonResponse(['success' => false, 'message' => 'طلبات كثيرة جداً. يرجى الانتظار قليلاً ثم إعادة المحاولة.'], 429);
    }
}

// 4. Security, Input Validation & Sanitization Helpers
function validateUniversityID(string $student_id): bool {
    // تنسيق موحد وصارم: حرف S كبير متبوعاً بـ 9 أرقام (مثال: S000000001)
    return (bool) preg_match('/^S\d{9}$/i', trim($student_id));
}

/**
 * التحقق من صيغة كود ضابط الأمن، مثال: SEC-USR-1 / SEC-USR-7 / SEC-USR-8 / SEC-USR-9
 */
function validateSecurityCode(string $code): bool {
    return (bool) preg_match('/^SEC-[A-Za-z0-9-]{1,16}$/i', trim($code));
}

/**
 * التحقق من معرّف تسجيل الدخول: إما رقم جامعي (S000000001) أو كود أمن (SEC-USR-1)
 */
function validateLoginIdentifier(string $identifier): bool {
    $identifier = trim($identifier);
    return validateUniversityID($identifier) || validateSecurityCode($identifier);
}

/**
 * مسار البداية المناسب لدور المستخدم: ضابط الأمن يذهب للوحة الأمن والطالب للموقع الرئيسي.
 */
function homePathForRole(?array $user): string {
    if ($user && ($user['role'] === 'security' || $user['role'] === 'admin')) {
        return 'security_dashboard.php';
    }
    return 'index.php';
}

function redirectHome(?array $user = null): void {
    $user = $user ?? getCurrentUser();
    header('Location: ' . homePathForRole($user));
    exit;
}

/**
 * ترحيل آمن: يضمن وجود عمودين للكلية والتخصص (college / major) في جدول users
 * لعرضهما في بطاقة الملف الشخصي بدلاً من البريد الجامعي.
 */
function ensureUserProfileColumns(?PDO $pdo): void {
    if (!$pdo) return;
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'college'");
        if ($stmt->fetchColumn() === false) {
            $pdo->exec("ALTER TABLE users ADD COLUMN college VARCHAR(100) DEFAULT NULL AFTER phone");
        }
    } catch (PDOException $e) {
        // نتجاهل الخطأ إذا كان العمود موجوداً بالفعل أو تعذر الوصول.
    }
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'major'");
        if ($stmt->fetchColumn() === false) {
            $pdo->exec("ALTER TABLE users ADD COLUMN major VARCHAR(100) DEFAULT NULL AFTER college");
        }
    } catch (PDOException $e) {
        // نتجاهل الخطأ إذا كان العمود موجوداً بالفعل أو تعذر الوصول.
    }
}

/**
 * ترحيل آمن: يضمن وجود عمود security_code في جدول users لحسابات ضباط الأمن (SEC-USR-1 إلخ).
 */
function ensureSecurityCodeColumn(?PDO $pdo): void {
    if (!$pdo) return;
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'security_code'");
        if ($stmt->fetchColumn() === false) {
            $pdo->exec("ALTER TABLE users ADD COLUMN security_code VARCHAR(20) DEFAULT NULL UNIQUE AFTER role");
        }
    } catch (PDOException $e) {
        // نتجاهل الخطأ إذا كان العمود موجوداً بالفعل أو تعذر الوصول.
    }
}

/**
 * ترحيل آمن: يضمن وجود عمود security_code_hash في جدول users لتخزين كود ضابط الأمن
 * كبصمة password_hash() فقط — لا نص مفتوح لأكواد SEC في القاعدة.
 */
function ensureSecurityCodeHashColumn(?PDO $pdo): void {
    if (!$pdo) return;
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'security_code_hash'");
        if ($stmt->fetchColumn() === false) {
            $pdo->exec("ALTER TABLE users ADD COLUMN security_code_hash VARCHAR(255) DEFAULT NULL AFTER security_code");
        }
    } catch (PDOException $e) {
        // نتجاهل الخطأ إذا كان العمود موجوداً مسبقاً أو تعذر الوصول.
    }
}

/**
 * تهيئة حسابات ضباط الأمن (SEC-USR-1, SEC-USR-7, SEC-USR-8, SEC-USR-9) بحيث يمكنهم
 * تسجيل الدخول بكود أمن بدلاً من رقم الطالب.
 *
 * التخزين الآمن: كود الأمن لا يُخزَّن كنص مفتوح أبداً — تُحفظ بصمته فقط
 * (password_hash) في security_code_hash، ويُستخدم password_verify للتحقق.
 * العملية مكرّرة بأمان (idempotent): ترحّل الحسابات القديمة التي كانت تخزّن
 * الكود نصاً مفتوحاً وتتجنّب التكرار.
 */
function ensureSecurityOfficers(?PDO $pdo): void {
    if (!$pdo) return;
    ensureSecurityCodeColumn($pdo);
    ensureSecurityCodeHashColumn($pdo);

    // أسماء حسابية تجريبية موحّدة مع بيانات schema.sql (مجرّد بيانات وهمية، لا أسماء حقيقية)
    $officers = [
        ['SEC-USR-1', 'عائشة الشمري-الأمن الجامعي'],
        ['SEC-USR-7', 'فاطمة الحربي - الأمن الجامعي'],
        ['SEC-USR-8', 'محمد العنزي - ضابط الأمن'],
        ['SEC-USR-9', 'سلطان الشمري - ضابط الأمن'],
    ];

    $officerRows = $pdo->query(
        "SELECT id, university_id, security_code, security_code_hash FROM users WHERE role = 'security' ORDER BY id"
    )->fetchAll(PDO::FETCH_ASSOC);

    // ترحيل أي حسابات قديمة كانت تخزّن كود الأمن كنص مفتوح (security_code أو university_id):
    // تُستبدل بصمة الكود القديمة ببصمة الكود الجديد (SEC-USR-<id>) ويتّخذ university_id
    // نفس قيمة الكود نفسه — فيضمن تطابق المعرّف المرئي مع كود الدخول الفعلي.
    foreach ($officerRows as $row) {
        if (!empty($row['security_code_hash'])) {
            continue;
        }
        $internalCode = 'SEC-USR-' . (int) $row['id'];
        $pdo->prepare(
            "UPDATE users SET university_id = ?, security_code = NULL, security_code_hash = ? WHERE id = ?"
        )->execute([
            $internalCode,
            password_hash($internalCode, PASSWORD_DEFAULT),
            $row['id']
        ]);
    }

    $hash = '$2y$10$YSTA1rz2GtZLSKkXWNZTS.viTBDWlxJH/GhBtyF4AmjvjQw4VUXWa'; // bcrypt digest of the demo officer password; no plaintext in the codebase

    // إدراج أكواد الأمن غير الموجودة قطعياً — والتحقق من الوجود عبر password_verify
    // على البصمات المخزنة أو مطابقة university_id مباشرةً (لا نص مفتوح للمقارنة).
    foreach ($officers as $officer) {
        list($code, $name) = $officer;
        $code = strtoupper($code);

        $exists = false;
        foreach ($officerRows as $row) {
            if (strtoupper((string) ($row['university_id'] ?? '')) === $code) {
                $exists = true;
                break;
            }
            if (!empty($row['security_code_hash']) && password_verify($code, $row['security_code_hash'])) {
                $exists = true;
                break;
            }
        }
        if ($exists) {
            continue;
        }

        try {
            $pdo->prepare(
                "INSERT INTO users (university_id, full_name, email, phone, password_hash, role, security_code_hash) VALUES (?, ?, ?, ?, ?, 'security', ?)"
            )->execute([
                $code,
                $name,
                strtolower($code) . '@uoh.edu.sa',
                '+966500000000',
                $hash,
                password_hash($code, PASSWORD_DEFAULT)
            ]);
        } catch (PDOException $e) {
            // تجاوز أي تعارض (مثال: حصرية university_id أو email) بلطف
        }
    }
}

function generateUniversityEmail(string $student_id): string {
    return strtolower(trim($student_id)) . '@uoh.edu.sa';
}

/**
 * تنظيف نص مُدخل من المستخدم لمنع هجمات XSS عبر ترميز HTML آمن.
 *
 * - يزيل المسافات الطرفية ومحارف التحكم غير المرئية.
 * - يحوّل الرموز الخاصة (< > " ' &) إلى كيانات HTML.
 *
 * تنبيه أمني: ترميز الإدخال طبقة دفاع واحدة فقط؛ الحماية الكاملة تكون بترميز
 * القيمة عند الإخراج حسب سياقها. استخدم escapeOutput() لهذا الغرض.
 */
function sanitizeInput($data): string {
    if (is_array($data) || is_object($data)) {
        return '';
    }
    $data = trim((string) $data);
    $stripped = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $data);
    if ($stripped !== null) {
        $data = $stripped;
    }
    return htmlspecialchars($data, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * التحقق من صحة قيمة مُدخلة وتنظيفها في خطوة واحدة — مناسب لحقول النماذج
 * ومربعات البحث وأي إدخال مستخدم.
 *
 * @param mixed  $value   القيمة الخام (عادةً من $_POST / $_GET).
 * @param string $type    نوع الحقل: text | search | name | email | password | phone | url |
 *                        int | float | id | date | enum | university_id | code
 * @param array  $options خيارات: required(bool), min(int), max(int), allowed(array), message(string)
 *
 * @return array{valid:bool, value:mixed, error:?string}
 *
 * مثال:
 *   $q = validateInput($_GET['query'] ?? '', 'search', ['required' => true, 'min' => 2, 'max' => 200]);
 *   if (!$q['valid']) { jsonResponse(['success' => false, 'message' => $q['error']], 400); }
 *   $query = $q['value'];
 */
function validateInput($value, string $type = 'text', array $options = []): array {
    $fail = static fn(string $msg): array => ['valid' => false, 'value' => null, 'error' => $msg];
    $ok   = static fn($clean): array   => ['valid' => true, 'value' => $clean, 'error' => null];

    // حقول النماذج المفردة لا تقبل المصفوفات/الكائنات
    if (is_array($value) || is_object($value)) {
        return $fail($options['message'] ?? 'قيمة الحقل غير صالحة.');
    }

    $raw = trim((string) ($value ?? ''));

    if ($raw === '') {
        return ($options['required'] ?? false)
            ? $fail($options['message'] ?? 'هذا الحقل مطلوب.')
            : $ok('');
    }

    $len = mb_strlen($raw, 'UTF-8');
    if (isset($options['min']) && $len < (int) $options['min']) {
        return $fail($options['message'] ?? "يجب ألا يقل عدد الأحرف عن {$options['min']}.");
    }
    if (isset($options['max']) && $len > (int) $options['max']) {
        return $fail($options['message'] ?? "يجب ألا يزيد عدد الأحرف عن {$options['max']}.");
    }

    switch ($type) {
        case 'int':
        case 'id':
            if (!preg_match('/^-?\d+$/', $raw)) {
                return $fail($options['message'] ?? 'يجب إدخال رقم صحيح.');
            }
            $intVal = (int) $raw;
            if ($type === 'id' && $intVal <= 0) {
                return $fail($options['message'] ?? 'المعرّف غير صالح.');
            }
            return $ok($intVal);

        case 'float':
            if (!is_numeric($raw)) {
                return $fail($options['message'] ?? 'يجب إدخال رقم.');
            }
            return $ok((float) $raw);

        case 'email':
            $email = mb_strtolower($raw, 'UTF-8');
            if (mb_strlen($email) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $fail($options['message'] ?? 'صيغة البريد الإلكتروني غير صحيحة.');
            }
            return $ok($email);

        case 'url':
            if (!preg_match('#^https?://#i', $raw) || !filter_var($raw, FILTER_VALIDATE_URL)) {
                return $fail($options['message'] ?? 'الرابط غير صالح.');
            }
            return $ok($raw);

        case 'phone':
            if (!preg_match('/^\+?[0-9\s\-]{7,20}$/', $raw)) {
                return $fail($options['message'] ?? 'صيغة رقم الجوال غير صحيحة.');
            }
            return $ok(preg_replace('/[^\d+]/', '', $raw));

        case 'date':
            $d = DateTime::createFromFormat('Y-m-d', $raw);
            if (!$d || $d->format('Y-m-d') !== $raw) {
                return $fail($options['message'] ?? 'صيغة التاريخ غير صحيحة (YYYY-MM-DD).');
            }
            return $ok($raw);

        case 'enum':
            if (!in_array($raw, $options['allowed'] ?? [], true)) {
                return $fail($options['message'] ?? 'القيمة المدخلة غير مسموح بها.');
            }
            return $ok($raw);

        case 'university_id':
            $id = strtoupper($raw);
            if (!validateUniversityID($id)) {
                return $fail($options['message'] ?? 'صيغة الرقم الجامعي غير صحيحة (مثال: S000000001).');
            }
            return $ok($id);

        case 'code':
            $code = strtoupper($raw);
            if (!validateSecurityCode($code)) {
                return $fail($options['message'] ?? 'كود الأمن غير صالح (مثال: SEC-USR-1).');
            }
            return $ok($code);

        case 'password':
            // كلمة المرور لا تُرمَّز: تبقى كما هي لتعمل مع password_hash / password_verify
            return $ok($raw);

        case 'name':
        case 'search':
        case 'text':
        default:
            // ترميز HTML لمنع XSS في أي سياق إخراج لاحق
            return $ok(sanitizeInput($raw));
    }
}

/**
 * ترميز آمن للقيم عند الإخراج حسب السياق — هذه هي الحماية الفعلية ضد XSS.
 *
 * @param mixed  $value   القيمة المطلوب إخراجها.
 * @param string $context السياق: html | attr | js | url
 */
function escapeOutput($value, string $context = 'html'): string {
    $str = (string) ($value ?? '');
    switch ($context) {
        case 'js':
            return json_encode($str, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        case 'url':
            return rawurlencode($str);
        case 'html':
        case 'attr':
        default:
            return htmlspecialchars($str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

/**
 * التحقق من أن الملف المرفوع صورة حقيقية عبر فحص المحتوى (وليس امتداد الاسم فقط)،
 * مع فرض حد أقصى للحجم لمنع رفع ملفات خبيثة ضخمة.
 *
 * @param array $file    عنصر $_FILES الخاص بالصورة
 * @param int   $maxBytes الحد الأقصى لحجم الملف (افتراضياً 8 ميجابايت)
 */
function isValidImageUpload(array $file, int $maxBytes = 8 * 1024 * 1024): bool {
    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return false;
    }
    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0 || $size > $maxBytes) {
        return false;
    }
    $allowed = ['image/jpeg', 'image/png', 'image/webp'];
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        return in_array($finfo->file($file['tmp_name']), $allowed, true);
    }
    $info = @getimagesize($file['tmp_name']);
    return $info !== false && in_array($info['mime'], $allowed, true);
}

/**
 * معالجة آمنة ومركزية لرفع صورة غرض:
 *   - تتجاهل أي ملف لم يُرفع فعلياً (is_uploaded_file).
 *   - تفرض حداً أقصى للحجم (8 ميجابايت).
 *   - تشتّق امتداد الملف من الصيغة الفعلية المكتشفة (finfo/getimagesize) وليس من اسم الملف المرسل،
 *     فيستحيل حفظ ملف تنفيذي (مثل .php) حتى لو غيّر المهاجم الاسم أو مضاعف التمديد.
 *   - تولّد اسماً عشوائياً غير قابل للتخمين.
 *
 * @param array  $file          عنصر $_FILES الخاص بالصورة
 * @param string $uploadDir     المسار المطلق لمجلد التخزين (يتضمن شرطة مائلة أأم لا)
 * @param string $prefix        بادئة اسم الملف
 * @return string المسار النسبي الجاهز للتخزين في قاعدة البيانات (uploads/xxx.jpg) أو '' عند الفشل
 */
function handleImageUpload(array $file, string $uploadDir, string $prefix = 'img'): string {
    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return '';
    }

    $size = (int) ($file['size'] ?? 0);
    $maxBytes = 8 * 1024 * 1024;
    if ($size <= 0 || $size > $maxBytes) {
        return '';
    }

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    $mime = '';
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($file['tmp_name']);
    } else {
        $info = @getimagesize($file['tmp_name']);
        $mime = is_array($info) ? ($info['mime'] ?? '') : '';
    }
    if ($mime === '' || !isset($allowed[$mime])) {
        return '';
    }

    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        return '';
    }

    $ext      = $allowed[$mime];
    $filename = $prefix . '_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $target   = rtrim($uploadDir, '/\\') . DIRECTORY_SEPARATOR . $filename;

    if (!move_uploaded_file($file['tmp_name'], $target)) {
        return '';
    }

    return 'uploads/' . $filename;
}

function generateRetrievalCode(?PDO $pdo = null): string {
    // كود فريد وعشوائي آمن (RET-XXXXXX) مع ضمان عدم تكراره في الجداول
    $code = '';
    $tries = 0;
    do {
        $code = 'RET-' . str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $tries++;
        if ($pdo === null || $tries > 20) {
            break;
        }
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM reports WHERE pickup_code = ?");
            $stmt->execute([$code]);
            $usedInReports = (int) $stmt->fetchColumn() > 0;
            $usedInMatches = 0;
            if (!$usedInReports) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM matches WHERE pickup_code = ?");
                $stmt->execute([$code]);
                $usedInMatches = (int) $stmt->fetchColumn();
            }
        } catch (PDOException $e) {
            break;
        }
    } while ($usedInReports || $usedInMatches > 0);
    return $code;
}

/**
 * ترحيل آمن للبيئات القائمة: يضمن وجود عمود resolved_at في جدول reports
 * (التحديث الفوري إلى "تم الاستلام" مع الطابع الزمني الفعلي دون إعادة تهيئة القاعدة).
 */
function ensureReportsResolvedAtColumn(?PDO $pdo): void {
    if (!$pdo) return;
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM reports LIKE 'resolved_at'");
        if ($stmt->fetchColumn() === false) {
            $pdo->exec("ALTER TABLE reports ADD COLUMN resolved_at DATETIME DEFAULT NULL AFTER pickup_code");
        }
    } catch (PDOException $e) {
        // في بعض النسخ تكون الأعمدة قد أُضيفت فعلاً؛ نتجاهل الخطأ.
    }
}

/**
 * ترحيل آمن: يضمن وجود عمود distinctive_features (العلامات المميزة) في جدول reports
 * لتغذية محرك المطابقة الذكية الموزون (وزن مرتفع لمطابقة النصوص والعلامات المميزة).
 * نوع العمود TEXT لأن الوصف الذي يولّده محرك الرؤية ذا نص حرّ غير محدود الطول.
 */
function ensureDistinctiveFeaturesColumn(?PDO $pdo): void {
    if (!$pdo) return;
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM reports LIKE 'distinctive_features'");
        if ($stmt->fetchColumn() === false) {
            $pdo->exec("ALTER TABLE reports ADD COLUMN distinctive_features TEXT DEFAULT NULL AFTER ocr_data");
        }
    } catch (PDOException $e) {
        // نتجاهل الخطأ إذا كان العمود موجوداً مسبقاً أو تعذر الوصول.
    }
}

/**
 * ترحيل آمن: يضمن وجود جدول password_resets الخاص بإعادة تعيين كلمة المرور.
 * يخزّن الجدول بصمة الرمز المؤقت فقط (SHA-256) ولا يخزّن الرمز نصاً مفتوحاً،
 * مع وقت انتهاء صلاحية وزمن استهلاك للاستخدام الفردي.
 */
function ensurePasswordResetsTable(?PDO $pdo): void {
    if (!$pdo) return;
    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `password_resets` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT NOT NULL,
                `token_hash` CHAR(64) NOT NULL UNIQUE,
                `expires_at` DATETIME NOT NULL,
                `used_at` DATETIME DEFAULT NULL,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
                INDEX `idx_resets_user` (`user_id`),
                INDEX `idx_resets_expiry` (`expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } catch (PDOException $e) {
        error_log('Luqya AI: تعذر تهيئة جدول إعادة تعيين كلمة المرور: ' . $e->getMessage());
    }
}

/**
 * كتابة سجل نشاط بنقطة واحدة عبر كل المسارات.
 *
 * يوحّد الاستدعاء (بدل خمسة استدعاءات INSERT متفرقة) ويمنع تجاوز
 * أعمدة activity_logs: يقتطع title/details بالحدود المعرَّفة في config/schema.php
 * بدل أن يترك MariaDB يقتطعها بصمت في الوضع غير الصارم.
 *
 * @param string $actionType نوع الإجراء (handover / add_found / match / ...)
 * @return bool true عند نجاح الكتابة
 */
function logActivity(PDO $pdo, string $actionType, string $itemTitle, string $details, string $userName): bool {
    $actionType = mb_substr(trim($actionType), 0, 50, 'UTF-8');
    $userName   = mb_substr(trim($userName !== '' ? $userName : 'النظام'), 0, USER_FULL_NAME_MAX, 'UTF-8');
    $itemTitle  = trim($itemTitle) !== '' ? trim($itemTitle) : 'مقتنيات جامعية';
    $itemTitle  = mb_substr($itemTitle, 0, ACTIVITY_ITEM_TITLE_MAX, 'UTF-8');
    $details    = trim($details) !== '' ? trim($details) : '—';
    $details    = mb_substr($details, 0, ACTIVITY_DETAILS_MAX, 'UTF-8');

    try {
        return $pdo->prepare(
            "INSERT INTO activity_logs (action_type, item_title, details, user_name) VALUES (?, ?, ?, ?)"
        )->execute([$actionType, $itemTitle, $details, $userName]);
    } catch (PDOException $e) {
        error_log('Luqya AI: failed to write activity log (' . $actionType . '): ' . $e->getMessage());
        return false;
    }
}

// ---------------------------------------------------------------------------
// 5. طبقة الترحيل الموحّدة للمخطط (Schema Migrations)
// ---------------------------------------------------------------------------
// كل الدوال أدناه idempotent: يمكن استدعاؤها في كل طلب دون أثر جانبي، وهي
// مسؤولة عن مطابقة قواعد البيانات القائمة مع ما يعرّفه database/schema.sql.

/** اسم الجدول كما هو في information_schema (بدون علامات تنسيق إضافية). */
function schemaTableName(string $table): string {
    return strtolower(trim($table, " \t\n\r\0\x0B`"));
}

/** هل يوجد الجدول في قاعدة البيانات الحالية؟ */
function schemaTableExists(PDO $pdo, string $table): bool {
    $st = $pdo->prepare(
        "SELECT 1 FROM information_schema.tables
          WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1"
    );
    $st->execute([schemaTableName($table)]);
    return (bool) $st->fetchColumn();
}

/** نوع العمود الفعلي (مثل 'varchar(200)' أو 'text') أو null إن لم يوجد. */
function schemaColumnType(PDO $pdo, string $table, string $column): ?string {
    $st = $pdo->prepare(
        "SELECT column_type FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1"
    );
    $st->execute([schemaTableName($table), strtolower(trim($column, " \t\n\r\0\x0B`"))]);
    $type = $st->fetchColumn();
    return $type === false ? null : strtolower((string) $type);
}

/** هل يوجد العمود؟ */
function schemaColumnExists(PDO $pdo, string $table, string $column): bool {
    return schemaColumnType($pdo, $table, $column) !== null;
}

/** هل يوجد الفهرس بالاسم المطلوب؟ */
function schemaIndexExists(PDO $pdo, string $table, string $index): bool {
    $st = $pdo->prepare(
        "SELECT 1 FROM information_schema.statistics
          WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1"
    );
    $st->execute([schemaTableName($table), strtolower(trim($index, " \t\n\r\0\x0B`"))]);
    return (bool) $st->fetchColumn();
}

/** هل يوجد القيد CHECK بالاسم المطلوب؟ */
function schemaCheckExists(PDO $pdo, string $table, string $constraint): bool {
    $st = $pdo->prepare(
        "SELECT 1 FROM information_schema.table_constraints
          WHERE constraint_schema = DATABASE() AND table_name = ?
            AND constraint_name = ? AND constraint_type = 'CHECK' LIMIT 1"
    );
    $st->execute([schemaTableName($table), strtolower(trim($constraint, " \t\n\r\0\x0B`"))]);
    return (bool) $st->fetchColumn();
}

/** ينفّذ DDL ويسجّل الفشل بدل كسر الطلب. */
function schemaExec(PDO $pdo, string $sql, string $context = ''): bool {
    try {
        $pdo->exec($sql);
        return true;
    } catch (PDOException $e) {
        error_log('Luqya AI: schema migration failed' . ($context ? " [$context]" : '') . ': ' . $e->getMessage());
        return false;
    }
}

/** يضيف عموداً إن لم يكن موجوداً. */
function ensureSchemaColumn(PDO $pdo, string $table, string $column, string $definition): bool {
    if (!schemaTableExists($pdo, $table) || schemaColumnExists($pdo, $table, $column)) {
        return false;
    }
    return schemaExec(
        $pdo,
        'ALTER TABLE `' . schemaTableName($table) . '` ADD COLUMN `' . trim($column, '`') . '` ' . $definition,
        "add {$table}.{$column}"
    );
}

/**
 * يوسّع عموداً إلى النوع المطلوب إن كان أضيق من ذلك حالياً.
 * لا يفعل شيئاً إن كان العمود مفقوداً (اترك ذلك لـ ensureSchemaColumn)
 * أو كان نوعه مختلفاً جوهرياً (TEXT مقابل varchar) — في تلك الحالة يُبلَّغ ولا يُعدَّل.
 */
function ensureSchemaColumnWidth(PDO $pdo, string $table, string $column, string $expectedType, string $definition): bool {
    $current = schemaColumnType($pdo, $table, $column);
    if ($current === null || $current === strtolower($expectedType)) {
        return false;
    }
    // لا نلمس الأعمدة التي تحوّلت إلى TEXT (أوسع من VARCHAR ولا تحتاج تعديلاً)
    if (str_contains($current, 'text')) {
        return false;
    }
    return schemaExec(
        $pdo,
        'ALTER TABLE `' . schemaTableName($table) . '` MODIFY COLUMN `' . trim($column, '`') . '` ' . $definition,
        "widen {$table}.{$column} {$current} -> {$expectedType}"
    );
}

/** هل العمود يقبل NULL؟ */
function schemaColumnNullable(PDO $pdo, string $table, string $column): ?bool {
    $st = $pdo->prepare(
        "SELECT is_nullable FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1"
    );
    $st->execute([schemaTableName($table), strtolower(trim($column, " \t\n\r\0\x0B`"))]);
    $v = $st->fetchColumn();
    return $v === false ? null : (strtoupper((string) $v) === 'YES');
}

/** يضيف فهرساً إن لم يكن موجوداً. */
function ensureSchemaIndex(PDO $pdo, string $table, string $index, string $columns, bool $unique = false): bool {
    if (!schemaTableExists($pdo, $table) || schemaIndexExists($pdo, $table, $index)) {
        return false;
    }
    $kind = $unique ? 'UNIQUE INDEX' : 'INDEX';
    return schemaExec(
        $pdo,
        'ALTER TABLE `' . schemaTableName($table) . '` ADD ' . $kind . ' `' . trim($index, '`') . '` (' . $columns . ')',
        "index {$table}.{$index}"
    );
}

/** يضيف قيد CHECK إن لم يكن موجوداً. */
function ensureSchemaCheck(PDO $pdo, string $table, string $name, string $expression): bool {
    if (!schemaTableExists($pdo, $table) || schemaCheckExists($pdo, $table, $name)) {
        return false;
    }
    return schemaExec(
        $pdo,
        'ALTER TABLE `' . schemaTableName($table) . '` ADD CONSTRAINT `' . trim($name, '`') . '` CHECK (' . $expression . ')',
        "check {$table}.{$name}"
    );
}

/**
 * الترحيل الشامل: ي bringing المخطط القائم إلى ما يعرّفه database/schema.sql.
 *
 * الإصلاحات التي يطبّقها:
 *   - توسيع الأعمدة الضيقة (title/location/ai_tags) ومحوّل distinctive_features و details إلى TEXT
 *   - إضافة updated_at تلقائي التحديث إلى users و reports و matches
 *   - جعل matches.pickup_code قابلاً لـ NULL(unique) مع تصفير الأكواد المزروفة على الرفض
 *   - فهارس الاستعلام الناقصة (reports.status/type، pickup_code، notifications، activity_logs، users.role)
 *   - قيد CHECK على نطاق confidence_score
 *   - تصحيح مسارات الصور التي بادئتها 'assets/images/uploads/'
 *
 * آمنة للاستدعاء المتكرر. لا ترمي استثناءً أبداً.
 */
function ensureSchemaUpgrades(?PDO $pdo): void {
    if (!$pdo) return;

    // 0) أعمدة reports التي كانت تُضاف بالترحيلات القديمة — نضمن وجودها أولاً
    //    لأن عمليات التوسيع التالية تتوقع وجودها.
    ensureSchemaColumn($pdo, 'reports', 'resolved_at', 'DATETIME DEFAULT NULL');
    ensureSchemaColumn($pdo, 'reports', 'distinctive_features', 'TEXT DEFAULT NULL');

    // 1) توسيع الأعمدة النصية -------------------------------------------------
    //    كانت حدود التحقق في الخادم (200/250/1000) أوسع من الأعمدة (150/150/255).
    ensureSchemaColumnWidth($pdo, 'reports', 'title',      'varchar(200)', "VARCHAR(200) NOT NULL");
    ensureSchemaColumnWidth($pdo, 'reports', 'location',   'varchar(250)', "VARCHAR(250) NOT NULL");
    ensureSchemaColumnWidth($pdo, 'reports', 'ai_tags',    'varchar(500)', "VARCHAR(500) DEFAULT NULL");
    ensureSchemaColumnWidth($pdo, 'reports', 'distinctive_features', 'text', 'TEXT DEFAULT NULL');
    ensureSchemaColumnWidth($pdo, 'activity_logs', 'details',    'text',         'TEXT NOT NULL');
    ensureSchemaColumnWidth($pdo, 'activity_logs', 'item_title', 'varchar(200)', "VARCHAR(200) NOT NULL");

    // 2) طوابع التحديث -------------------------------------------------------
    //    matches.created_at كان مفقوداً كلياً في المخطط القديم، فنضيفه قبل البقية.
    ensureSchemaColumn($pdo, 'matches', 'created_at', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP');
    $updatedAt = 'DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP';
    ensureSchemaColumn($pdo, 'users',   'updated_at', $updatedAt);
    ensureSchemaColumn($pdo, 'reports', 'updated_at', $updatedAt);
    ensureSchemaColumn($pdo, 'matches', 'updated_at', $updatedAt);

    // 2b) created_at يجب أن يكون NOT NULL كما يعرّفه schema.sql.
    //     نملأ أي قيمة فارغة أولاً حتى لا يفشل قيد NOT NULL.
    foreach (['users', 'reports', 'matches', 'notifications', 'activity_logs', 'password_resets'] as $t) {
        if (!schemaTableExists($pdo, $t) || schemaColumnNullable($pdo, $t, 'created_at') !== true) {
            continue;
        }
        schemaExec(
            $pdo,
            'UPDATE `' . $t . '` SET `created_at` = NOW() WHERE `created_at` IS NULL',
            "backfill {$t}.created_at"
        );
        schemaExec(
            $pdo,
            'ALTER TABLE `' . $t . '` MODIFY COLUMN `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            "tighten {$t}.created_at"
        );
    }

    // 3) نزاهة كود الاستلام في جدول المطابقات --------------------------------
    //    نُصفّر كود الرفض المؤقت أولاً حتى لا يتعارض مع قيد التفرّد الجديد،
    //    ثم نتيح NULL: القيد الفريد يسمح بعدد من القيم الفارغة (NULL)
    //    ولا يسمح بعدد من النصوص الفارغة ('').
    if (schemaTableExists($pdo, 'matches') && schemaColumnExists($pdo, 'matches', 'pickup_code')) {
        schemaExec(
            $pdo,
            "UPDATE matches SET pickup_code = NULL WHERE status = 'rejected' AND pickup_code = ''",
            'null rejected pickup codes'
        );
        if (schemaColumnNullable($pdo, 'matches', 'pickup_code') === false) {
            schemaExec(
                $pdo,
                'ALTER TABLE `matches` MODIFY COLUMN `pickup_code` VARCHAR(20) DEFAULT NULL',
                'make matches.pickup_code nullable'
            );
        }
    }

    // 4) فهارس الاستعلام ------------------------------------------------------
    ensureSchemaIndex($pdo, 'reports', 'idx_reports_status_type', '`status`, `type`');
    ensureSchemaIndex($pdo, 'reports', 'idx_reports_pickup', '`pickup_code`');
    ensureSchemaIndex($pdo, 'reports', 'idx_reports_user_status', '`user_id`, `status`');
    ensureSchemaIndex($pdo, 'notifications', 'idx_notifications_feed', '`user_id`, `is_read`, `created_at`');
    ensureSchemaIndex($pdo, 'users', 'idx_users_role', '`role`');
    ensureSchemaIndex($pdo, 'activity_logs', 'idx_activity_logs_created', '`created_at`');
    ensureSchemaIndex($pdo, 'activity_logs', 'idx_activity_logs_action', '`action_type`');

    // 5) مفاتيح التفرّد في جدول المطابقات ------------------------------------
    //    تُضاف فقط إن لم تكن هناك بيانات مكرّرة، وإلا نُسجّل ونُتخطّى.
    if (schemaTableExists($pdo, 'matches')) {
        $dupPair = $pdo->query(
            'SELECT lost_report_id, found_report_id, COUNT(*) c
               FROM matches GROUP BY lost_report_id, found_report_id HAVING c > 1 LIMIT 1'
        )->fetchColumn();
        if ($dupPair) {
            error_log('Luqya AI: schema migration skipped UNIQUE matches(lost,found) - duplicate pairs exist.');
        } else {
            ensureSchemaIndex($pdo, 'matches', 'uk_matches_pair', '`lost_report_id`, `found_report_id`', true);
        }

        $dupCode = $pdo->query(
            "SELECT pickup_code, COUNT(*) c FROM matches
              WHERE pickup_code IS NOT NULL AND pickup_code <> ''
              GROUP BY pickup_code HAVING c > 1 LIMIT 1"
        )->fetchColumn();
        if ($dupCode) {
            error_log('Luqya AI: schema migration skipped UNIQUE matches(pickup_code) - duplicate codes exist.');
        } else {
            ensureSchemaIndex($pdo, 'matches', 'uk_matches_pickup', '`pickup_code`', true);
        }
    }

    // 6) قيد نطاق نسبة الثقة --------------------------------------------------
    ensureSchemaCheck($pdo, 'matches', 'chk_matches_confidence', '`confidence_score` BETWEEN 0 AND 100');

    // 7) تصحيح بادئة مسارات الصور --------------------------------------------
    if (schemaTableExists($pdo, 'reports') && schemaColumnExists($pdo, 'reports', 'image_url')) {
        schemaExec(
            $pdo,
            "UPDATE reports
                SET image_url = SUBSTRING(image_url, LENGTH('assets/images/uploads/') + 1)
              WHERE image_url LIKE 'assets/images/uploads/%'",
            'fix image_url prefix'
        );
    }
}

