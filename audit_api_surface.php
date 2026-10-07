<?php
/**
 * لُقيا AI — تدقيق سطح الاستجابة العام (API surface audit)
 * ---------------------------------------------------------------
 * أداة مراجعة مستقلة تُشغَّل من سطر الأوامر، بلا أي اعتمادية ولا بنية اختبار.
 * الغرض: إثبات — لا مجرد ادّعاء — أن الاستجابات العامة لا تحوي مفاتيح داخلية
 * ولا بيانات خاصة ولا تفاصيل تشخيص.
 *
 * التشغيل:
 *   php audit_api_surface.php          # من جذر المشروع
 *
 * المخرجات: رمز خروج 0 إذا كان السطح نظيفاً، و1 إذا وُجد خرق.
 *
 * ملاحظة: الأداة تحليل ساكن (static analysis) للملفات المصدرية، وليست اختباراً
 * تشغيلياً؛ فهي لا تحل محل مراجعة بشرية قبل النشر.
 *
 * حدود التحليل الساكن (مهمّة):
 *   - الأداة تفحص أسماء المفاتيح، لا قيمها. فـ 'user' => $row يمرّ لكنها ليست
 *     خرقاً إن كان $row قائمة سماح مثل getCurrentUser(). لذلك رُفعت قاعدة
 *     'raw row dump' إلى مستوى تنبيه لا خرق، والقرار يُترك للإنسان.
 *   - لا تفحص مخرجات HTML ولا مخرجات JavaScript المضمّنة في الصفحات.
 *   - تلتقط استدعاءات jsonResponse() فقط.
 */

declare(strict_types=1);

$root = __DIR__;
$scanDirs = ['api', 'config', 'views'];
$rootFiles = [
    'index.php', 'login.php', 'register.php', 'profile.php',
    'forgot_password.php', 'security_dashboard.php', 'setup_db.php',
];

/** @return array<int,string> */
function collectPhpFiles(string $root, array $dirs, array $rootFiles): array {
    $files = [];
    foreach ($dirs as $dir) {
        $path = $root . DIRECTORY_SEPARATOR . $dir;
        if (!is_dir($path)) continue;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && strtolower($f->getExtension()) === 'php') $files[] = $f->getPathname();
        }
    }
    foreach ($rootFiles as $f) {
        if (is_file($root . DIRECTORY_SEPARATOR . $f)) $files[] = $root . DIRECTORY_SEPARATOR . $f;
    }
    sort($files);
    return $files;
}

/**
 * يستخرج نص كل استدعاء jsonResponse() للتحليل.
 */
function extractResponseBodies(string $code): array {
    $bodies = [];
    // نص الردّ بين [ بعد jsonResponse( و ] يغلقها — نمسح على الأقواس المعتدلة.
    if (!preg_match_all('/jsonResponse\s*\(/', $code, $m, PREG_OFFSET_CAPTURE)) return $bodies;
    foreach ($m[0] as $hit) {
        $start = $hit[1] + strlen($hit[0]);
        $depth = 1; $i = $start; $len = strlen($code);
        while ($i < $len && $depth > 0) {
            $c = $code[$i];
            if ($c === '[' || $c === '(') $depth++;
            elseif ($c === ']' || $c === ')') $depth--;
            $i++;
        }
        $bodies[] = substr($code, $start, max(0, $i - $start));
    }
    return $bodies;
}

$files = collectPhpFiles($root, $scanDirs, $rootFiles);
if (empty($files)) {
    fwrite(STDERR, "لم يُعثر على أي ملفات PHP للفحص. شغّل الأداة من جذر المشروع.\n");
    exit(1);
}

/**
 * قواعد الفحص. كل قاعدة: المفتاح => [نمط, مستوى, شرح]
 * المستوى 'hard' = خرق مؤكد. 'warn' = يستدعي مراجعة بشرية.
 */
$keyRules = [
    'internal_*'        => ["/'internal_[A-Za-z0-9_]*'\s*=>/", 'hard', 'مفتاح داخلي متسرّب إلى الردّ'],
    'debug'             => ["/'(debug|debug_info|is_debug|trace|stack_?trace|backtrace)'\s*=>/i", 'hard', 'أثر تصحيح أخطاء في الردّ'],
    'dump'              => ["/'(dump|raw|raw_?response|var_?export|print_?r)'\s*=>/i", 'hard', 'محتوى خام متسرّب إلى الردّ'],
    'sql'               => ["/'(sql|sql_?query|query_?text|last_?query|statement)'\s*=>/i", 'hard', 'نص SQL في الردّ'],
    'fs path'           => ["/'(file_?path|filepath|dir|directory|__file__|cwd|absolute_?path)'\s*=>/i", 'warn', 'مسار نظام ملفات في الردّ'],
    'secret'            => ["/'(password|password_?hash|passwd|secret|api_?key|private_?key|auth_?token|access_?token|refresh_?token|raw_?token|token_?hash|security_?code_?hash|security_?code|session_?id|cookie)'/i", 'hard', 'بيانات اعتماد أو سرّ في الردّ'],
    'raw row dump'      => ["/'(user|user_?row|account|owner|recorder|reporter)'\s*=>\s*/i", 'warn', 'ردّ قد يحمل صفّ مستخدم كاملاً — تحقّق أن المصدر قائمة سماح لا صفّ قاعدة بيانات'],
    'php state'         => ["/\\\$_(SESSION|SERVER|COOKIE|ENV|GET|POST|REQUEST|FILES)\b/", 'hard', ' dumping لمتغير superglobal في الردّ'],
    'env'               => ["/(getenv\s*\(|putenv\s*\(|\\\$_ENV\s*\[)/", 'warn', 'قراءة بيئة التشغيل قريبة من الردّ'],
];

$violations = [];
$warnings   = [];
$responses  = 0;

foreach ($files as $file) {
    $code = file_get_contents($file);
    $rel  = str_replace($root . DIRECTORY_SEPARATOR, '', $file);

    foreach (extractResponseBodies($code) as $body) {
        $responses++;
        foreach ($keyRules as $label => [$pattern, $level, $why]) {
            if (preg_match($pattern, $body, $mm)) {
                $entry = sprintf('%s: %s — %s', $rel, $why, trim($mm[0]));
                if ($level === 'hard') $violations[] = $entry;
                else                    $warnings[]   = $entry;
            }
        }
    }

    // أي استخدام لأدوات التصحيح مباشرة في مخرجات الـ HTTP.
    if (preg_match('/\b(print_r|var_dump|var_export)\s*\(/', $code, $mm)) {
        $line = substr_count(substr($code, 0, strpos($code, $mm[0])), "\n") + 1;
        // نتجاهل الاستخدامات داخل error_log أو التعليقات إن وُجدت سياق واضح
        $violations[] = sprintf('%s:%d: %s() في كود التطبيق', $rel, $line, $mm[1]);
    }
}

echo "═══════════════════════════════════════════════════════════\n";
echo " لُقيا AI — تدقيق سطح الاستجابة\n";
echo "═══════════════════════════════════════════════════════════\n";
printf(" الملفات المفحوصة : %d\n", count($files));
printf(" استدعاءات الردّ   : %d\n", $responses);
printf(" خروقات           : %d\n", count($violations));
printf(" تنبيهات للمراجعة : %d\n", count($warnings));
echo "───────────────────────────────────────────────────────────\n";

if ($violations) {
    echo " خروقات:\n";
    foreach ($violations as $v) echo "   ✗ $v\n";
}
if ($warnings) {
    echo " تنبيهات (قد تكون مشروعة — راجعها):\n";
    foreach ($warnings as $w) echo "   ! $w\n";
}
if (!$violations) {
    echo " ✓ لا مفاتيح داخلية ولا بيانات خاصة ولا تفاصيل تشخيص في الردود.\n";
}
echo "═══════════════════════════════════════════════════════════\n";

exit($violations ? 1 : 0);
