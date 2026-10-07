<?php
/**
 * لُقيا AI — نقطة نهاية البحث الذكي بالنص الحر
 * POST /api/ai_search.php
 * Body: { "query": "محفظة جلدية سوداء ضاعت عند المكتبة" } أو $_POST['query']
 * Response: { "success": true, "ids": [1,5], "reason": "...", "reports": [...] }
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/gemini.php';

// البحث الذكي يستدعي Gemini (مكلف) لذا يُقتصر على POST مع تقييد معدل الطلبات لكل عنوان IP
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'الطريقة غير مدعومة. يُرجى استخدام POST.'], 405);
}
enforceRateLimit('ai_search', 20, 300);

$input = json_decode(file_get_contents('php://input'), true);
$query = trim($input['query'] ?? $_POST['query'] ?? '');

if (empty($query)) {
    jsonResponse(['success' => false, 'message' => 'يرجى إدخال نص أو وصف ما تبحث عنه في خانة البحث.'], 400);
}

if (mb_strlen($query, 'UTF-8') > 500) {
    jsonResponse(['success' => false, 'message' => 'الاستفسار طويل جداً، يرجى اختصاره.'], 400);
}

$pdo = getDBConnection();
if (!$pdo) {
    jsonResponse(['success' => false, 'message' => 'فشل الاتصال بقاعدة البيانات.'], 500);
}

$stmt = $pdo->prepare(
    "SELECT id, type, title, category, description, location, image_url, status, ai_tags
     FROM reports
     WHERE status IN ('active', 'matched', 'pending_pickup')
     ORDER BY created_at DESC
     LIMIT 50"
);
$stmt->execute();
$activeReports = $stmt->fetchAll();

if (empty($activeReports)) {
    jsonResponse([
        'success' => true,
        'ids'     => [],
        'reason'  => 'لا توجد بلاغات نشطة حالياً في النظام.',
        'reports' => [],
        'count'   => 0
    ]);
}

// 1. محاولة استدعاء Gemini للبحث الدلالي الذكي
$searchResult = geminiSmartSearch($query, $activeReports);

// 2. جلب تفاصيل البلاغات المُوصى بها من Gemini
$matchedReports = [];
if (!empty($searchResult['ids'])) {
    $cleanIds = array_map('intval', (array) $searchResult['ids']);
    if (!empty($cleanIds)) {
        $placeholders = implode(',', array_fill(0, count($cleanIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT r.id, r.type, r.title, r.category, r.description, r.location,
                    r.image_url, r.status, r.ai_tags, r.date_event,
                    u.full_name, u.university_id
             FROM reports r
             JOIN users u ON r.user_id = u.id
             WHERE r.id IN ({$placeholders})
             ORDER BY FIELD(r.id, {$placeholders})"
        );
        $stmt->execute(array_merge($cleanIds, $cleanIds));
        $matchedReports = $stmt->fetchAll();
    }
}

// 3. إذا لم يجد Gemini نتائج أو تعذر الاتصال بالـ API، تنفيذ محرك البحث الدلالي الذكي المحلي
if (empty($matchedReports)) {
    $stopWords = ['في', 'من', 'إلى', 'على', 'عن', 'مع', 'أو', 'ثم', 'هذا', 'هذه', 'كل', 'عند', 'قرب', 'بجانب', 'مكان', 'شيء', 'التي', 'الذي', 'ضاعت', 'فقدت', 'لقيت', 'وجدت'];
    $rawWords = preg_split('/[\s,،.!-]+/u', mb_strtolower($query, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY);
    $searchWords = array_values(array_filter($rawWords, fn($w) => mb_strlen($w, 'UTF-8') >= 2 && !in_array($w, $stopWords)));

    if (empty($searchWords)) {
        $searchWords = $rawWords;
    }

    $scoredReports = [];
    foreach ($activeReports as $r) {
        $score = 0;
        $rText = mb_strtolower($r['title'] . ' ' . $r['description'] . ' ' . $r['location'] . ' ' . ($r['ai_tags'] ?? '') . ' ' . $r['category'], 'UTF-8');

        foreach ($searchWords as $w) {
            if (mb_strpos($rText, $w) !== false) {
                $score += 10;
                // مطابقة في العنوان أو الوسوم تعطي وزناً أعلى
                if (mb_strpos(mb_strtolower($r['title'], 'UTF-8'), $w) !== false) $score += 15;
                if (mb_strpos(mb_strtolower($r['ai_tags'] ?? '', 'UTF-8'), $w) !== false) $score += 10;
            }
        }

        if ($score > 0) {
            $r['score'] = $score;
            $scoredReports[] = $r;
        }
    }

    usort($scoredReports, fn($a, $b) => $b['score'] <=> $a['score']);
    $topReports = array_slice($scoredReports, 0, 10);

    if (!empty($topReports)) {
        $matchedIds = array_map('intval', array_column($topReports, 'id'));
        $searchResult['ids'] = $matchedIds;
        $searchResult['reason'] = 'تم العثور على ' . count($topReports) . ' بلاغ يطابق الكلمات والوصف المدخل عبر محرك المطابقة الذكي.';

        $placeholders = implode(',', array_fill(0, count($matchedIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT r.id, r.type, r.title, r.category, r.description, r.location,
                    r.image_url, r.status, r.ai_tags, r.date_event,
                    u.full_name, u.university_id
             FROM reports r
             JOIN users u ON r.user_id = u.id
             WHERE r.id IN ({$placeholders})
             ORDER BY FIELD(r.id, {$placeholders})"
        );
        $stmt->execute(array_merge($matchedIds, $matchedIds));
        $matchedReports = $stmt->fetchAll();
    }
}

jsonResponse([
    'success' => true,
    'query'   => $query,
    'ids'     => array_map('intval', (array) ($searchResult['ids'] ?? [])),
    'reason'  => $searchResult['reason'] ?? '',
    'reports' => $matchedReports,
    'count'   => count($matchedReports),
]);
