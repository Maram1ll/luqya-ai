<?php
// إعداد وتهيئة قاعدة بيانات لُقيا AI
require_once __DIR__ . '/config/db.php';

header('Content-Type: text/html; charset=utf-8');

/**
 * فصل ملف SQL إلى عبارات قابلة للتنفيذ، مع تجاهل:
 *   - تعليقات السطر المسبوقة بـ -- أو #
 *   - التعليقات الكتلية /* ... *\/
 *   - الفاصلة المنقوطة الواقعة داخل نص بين علامتَي اقتباس ' أو " أو `
 *   - الشرطة المائلة العكسية \ المستخدمة للهروب داخل النصوص
 *
 * السبب: database/schema.sql يحتوي قيود CHECK وتعليقات، والتقسيم البسيط على ';'
 * كان سيفشل أو ينفّذ عبارات ناقصة.
 *
 * @return array<int,string>
 */
function splitSqlStatements(string $sql): array {
    $statements = [];
    $current    = '';
    $len        = strlen($sql);
    $inSingle   = false;
    $inDouble   = false;
    $inBacktick = false;

    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];
        $next = $i + 1 < $len ? $sql[$i + 1] : '';

        if ($inSingle || $inDouble || $inBacktick) {
            $current .= $ch;
            if ($ch === '\\' && $next !== '') {
                $current .= $next;
                $i++;
                continue;
            }
            if ($inSingle  && $ch === "'")  { $inSingle   = false; continue; }
            if ($inDouble  && $ch === '"')  { $inDouble   = false; continue; }
            if ($inBacktick && $ch === '`') { $inBacktick = false; continue; }
            continue;
        }

        // تعليقات السطر
        if (($ch === '-' && $next === '-') || $ch === '#') {
            while ($i < $len && $sql[$i] !== "\n") { $i++; }
            $current .= "\n";
            continue;
        }

        // التعليقات الكتلية
        if ($ch === '/' && $next === '*') {
            $end = strpos($sql, '*/', $i + 2);
            $i = ($end === false) ? $len : $end + 1;
            $current .= ' ';
            continue;
        }

        if ($ch === "'")  { $inSingle   = true; $current .= $ch; continue; }
        if ($ch === '"')  { $inDouble   = true; $current .= $ch; continue; }
        if ($ch === '`')  { $inBacktick = true; $current .= $ch; continue; }

        if ($ch === ';') {
            $trimmed = trim($current);
            if ($trimmed !== '') { $statements[] = $trimmed; }
            $current = '';
            continue;
        }

        $current .= $ch;
    }

    $trimmed = trim($current);
    if ($trimmed !== '') { $statements[] = $trimmed; }

    return $statements;
}

// حماية: منع إعادة تهيئة (وهي عملية تُفرِّغ الجداول) عبر المتصفح بعد أن تصبح قاعدة البيانات مهيأة.
// التهيئة الأولى (قاعدة/جداول غير موجودة) تبقى مسموحة. يمكن تجاوز الحماية من الطرفية أو بمتغير ALLOW_DB_RESET=1.
$isCli = (PHP_SAPI === 'cli');
$allowReset = getenv('ALLOW_DB_RESET') === '1';
if (!$isCli && !$allowReset) {
    try {
        $probe = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);
        $existingUsers = (int) $probe->query("SELECT COUNT(*) FROM users")->fetchColumn();
        if ($existingUsers > 0) {
            http_response_code(403);
            echo "<div style='color:#B45309;background:#FEF3C7;padding:15px;border-radius:8px;font-family:sans-serif;direction:rtl;'>";
            echo "<strong>قاعدة البيانات مهيأة مسبقاً.</strong> تم إيقاف إعادة التهيئة من المتصفح لحماية البيانات. ";
            echo "لإعادة التهيئة شغّل الملف من الطرفية (CLI) أو اضبط المتغير <code>ALLOW_DB_RESET=1</code>.";
            echo "</div>";
            exit;
        }
    } catch (Exception $e) {
        // قاعدة البيانات أو جدول المستخدمين غير موجود بعد → متابعة التهيئة الأولى.
    }
}

echo "<h2>تهيئة قاعدة بيانات <span translate='no' class='notranslate brand-wordmark' dir='rtl'>لُقيا AI</span></h2>";

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";charset=utf8mb4", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    
    echo "<p style='color:#00A8B5;'>تم الاتصال بخادم قاعدة البيانات بنجاح.</p>";

    $sqlFile = __DIR__ . '/database/schema.sql';
    if (!file_exists($sqlFile)) {
        throw new Exception("لم يتم العثور على ملف schema.sql في " . $sqlFile);
    }
    
    $sqlContent = file_get_contents($sqlFile);

    // المخطط يحتوي تعليقات وقيود CHECK، والتقسيم على ';' فقط كان يكسر التنفيذ.
    $queries = splitSqlStatements($sqlContent);

    foreach ($queries as $query) {
        if ($query !== '') {
            $pdo->exec($query);
        }
    }
    
    echo "<div style='color: #4CD9A1; background: #1B365D; padding: 15px; border-radius: 8px; font-weight: bold;'>";
    echo "تمت تهيئة قاعدة البيانات `luqya_ai` وتعبئة البيانات التجريبية باللغة العربية بنجاح!";
    echo "</div>";
    echo "<p><a href='index.php' style='background: #00A8B5; color: white; padding: 10px 20px; text-decoration: none; border-radius: 6px; display: inline-block;'>الانتقال إلى الرئيسية &larr;</a></p>";

} catch (Exception $e) {
    echo "<div style='color: #ff4d4d; background: #fff0f0; padding: 15px; border-radius: 8px;'>";
    echo "<strong>خطأ أثناء تهيئة قاعدة البيانات:</strong> " . htmlspecialchars($e->getMessage());
    echo "</div>";
}
