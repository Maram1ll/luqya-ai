<?php
/**
 * لُقيا AI — Vision AI & OCR Endpoint
 * تحليل بصري منظم للعناصر المفقودة/المعثور عليها باستخدام Gemini.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/gemini.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'طريقة الطلب غير صالحة. يجب استخدام POST.'], 405);
}

$rawInput = file_get_contents('php://input');
$jsonData = json_decode($rawInput, true);
if (!is_array($jsonData)) {
    $jsonData = [];
}

$reportType = sanitizeInput($_POST['report_type'] ?? $_POST['type'] ?? ($jsonData['report_type'] ?? ($jsonData['type'] ?? 'lost')));
$mode       = sanitizeInput($_POST['mode'] ?? ($jsonData['mode'] ?? 'general_item'));

if ($reportType === 'lost' && function_exists('isSecurityOfficer') && isSecurityOfficer()) {
    jsonResponse([
        'success' => false,
        'message' => 'عذراً، صلاحية تحليل صور المفقودات مخصصة لطلاب الجامعة فقط.'
    ], 403);
}

$uploadDir = __DIR__ . '/../uploads/';
if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
    jsonResponse(['success' => false, 'message' => 'تعذر إنشاء مجلد رفع الصور.'], 500);
}

$imageBase64     = '';
$mimeType        = 'image/jpeg';
$savedImagePath  = '';
$allowedTypes    = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp'
];

// 1) Multipart upload
if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
    $file = $_FILES['image'];

    if ($file['size'] > 8 * 1024 * 1024) {
        jsonResponse(['success' => false, 'message' => 'حجم الصورة كبير جداً. الحد الأقصى 8 ميجابايت.'], 400);
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $detectedMime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!isset($allowedTypes[$detectedMime])) {
        jsonResponse(['success' => false, 'message' => 'صيغة الصورة غير مدعومة. الصيغ المسموحة: JPG, PNG, WEBP.'], 400);
    }

    $mimeType  = $detectedMime;
    $ext       = $allowedTypes[$mimeType];
    $filename  = 'item_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $targetPath = $uploadDir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        jsonResponse(['success' => false, 'message' => 'فشل حفظ الصورة على الخادم.'], 500);
    }

    $savedImagePath = 'uploads/' . $filename;
    $imageBase64    = base64_encode(file_get_contents($targetPath));
}
// 2) Base64 upload
else {
    $base64Input = $_POST['image_base64'] ?? ($jsonData['image_base64'] ?? '');

    if (!empty($base64Input)) {
        if (preg_match('/^data:(image\/(?:jpeg|png|webp|jpg));base64,(.*)$/s', $base64Input, $matches)) {
            $mimeType    = ($matches[1] === 'image/jpg') ? 'image/jpeg' : $matches[1];
            $imageBase64 = preg_replace('/\s+/', '', $matches[2]);
        } else {
            $imageBase64 = preg_replace('/\s+/', '', $base64Input);
        }

        $decoded = base64_decode($imageBase64, true);
        if ($decoded === false || $decoded === '') {
            jsonResponse(['success' => false, 'message' => 'بيانات الصورة Base64 غير صالحة.'], 400);
        }

        // مهم: لا نفترض JPEG إذا وصل Base64 خام. نكتشف الـ MIME من البايتات نفسها.
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detectedMime = finfo_buffer($finfo, $decoded);
        finfo_close($finfo);

        if (!isset($allowedTypes[$detectedMime])) {
            jsonResponse(['success' => false, 'message' => 'نوع الصورة المرسلة بصيغة Base64 غير مدعوم.'], 400);
        }

        $mimeType = $detectedMime;
        $ext      = $allowedTypes[$mimeType];
        $filename = 'item_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $targetPath = $uploadDir . $filename;

        if (file_put_contents($targetPath, $decoded) === false) {
            jsonResponse(['success' => false, 'message' => 'فشل حفظ الصورة على الخادم.'], 500);
        }

        $savedImagePath = 'uploads/' . $filename;
        $imageBase64    = base64_encode($decoded);
    }
}

if (empty($imageBase64)) {
    jsonResponse(['success' => false, 'message' => 'لم يتم إرسال أي صورة للمعالجة.'], 400);
}

$apiKey = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : getenv('GEMINI_API_KEY');
if (empty($apiKey)) {
    jsonResponse([
        'success'    => false,
        'ai_powered' => false,
        'error_type' => 'missing_api_key',
        'message'    => 'مفتاح Gemini API غير مُعدّ. يرجى ضبط المتغير البيئي GEMINI_API_KEY على الخادم.'
    ], 500);
}

// إذا كان GEMINI_MODEL معرفاً في config/gemini.php فسوف يتغلب على القيمة الافتراضية هنا.
$model = defined('GEMINI_MODEL') && GEMINI_MODEL ? GEMINI_MODEL : 'gemini-3-flash-preview';

$categories = [
    'مقتنيات شخصية',
    'البطاقة الجامعية / الصراف',
    'إلكترونيات',
    'وثائق ومستندات',
    'مفاتيح',
    'حقائب وحقائب ظهر',
    'ملابس وإكسسوارات',
    'أخرى'
];

$reportWord = ($reportType === 'lost') ? 'فقدان' : 'عثور';

$prompt = <<<PROMPT
أنت نظام فحص بصري لمقتنيات مفقودة أو معثور عليها. مهمتك استخراج حقائق بصرية فقط من الصورة، وليس كتابة وصف تسويقي.
نوع البلاغ: {$reportWord}.

قواعد إلزامية:
1) حدّد العنصر المادي الرئيسي الظاهر في الصورة فقط.
2) لا تستنتج الخامة من اللون أو اللمعان. مثال: اللون الفضي/الرمادي لا يعني أن الخامة معدن. اكتب "غير واضح" للخامة ما لم تكن الخامة مرئية بشكل لا يحتمل اللبس.
3) لا تستنتج أن العنصر "نظيف" أو "بحالة جيدة" أو "خالٍ من العيوب" لمجرد عدم ظهور تلف. إذا لم يظهر تلف واضح، استخدم: "لا توجد أضرار ظاهرة في الجزء المرئي".
4) لا تذكر علامة تجارية أو موديل إلا إذا كان الاسم/الشعار مقروءاً بوضوح.
5) تجاهل الخلفية، الأيدي، الطاولات، الأرضيات، واجهة الهاتف، وأي نصوص ليست جزءاً من العنصر نفسه.
6) إذا كانت الصورة لقطة شاشة أو كان العنصر صغيراً/مموهاً/محجوباً، لا تخمن. استخدم "غير واضح" في الحقول غير المؤكدة.
7) اللون هو اللون المرئي فقط. لا تحول اللون إلى مادة. مثال: "فضي" لون، أما "معدني" فخامة ولا تُذكر إلا بيقين.
8) السمات المميزة يجب أن تكون أشياء مرئية قابلة للمطابقة لاحقاً، مثل: حلقة دائرية، سحاب أسود، نقش حرفي، غطاء شفاف، كسر في زاوية محددة.
9) لا تُدخل كلمات "مفقود" أو "معثور عليه" أو "مقتنيات" ضمن الوسوم أو نوع العنصر.
10) إن لم تستطع تحديد نوع العنصر بثقة بصرية معقولة، اجعل object_type = "غير واضح".

أعد البيانات وفق مخطط JSON المطلوب فقط.
PROMPT;

if ($mode === 'id_card') {
    $prompt .= <<<PROMPT

تعليمات خاصة بالبطاقة (الأهم):
- حدد الرقم الجامعي المطبوع على البطاقة وضعه في حقل "student_id" فقط.
- الرقم عادة أرقام فقط مكونة من 9 أرقام مثل 202402461، وقد يسبقه حرف S مثل S202402400. انسخ الأرقام كما هي.
- استخرج الاسم المطبوع على البطاقة وضعه في حقل "name".
- انسخ الأرقام بدقة دون مسافات أو واصلات أو علامات ترقيم.
- لا تكمل أي حرف أو رقم ناقص بالتخمين.
- إذا كان الاسم أو الرقم غير مكتمل/غير واضح فاستخدم سلسلة فارغة "".
PROMPT;
}

$schema = [
    'type' => 'OBJECT',
    'properties' => [
        'object_type' => [
            'type' => 'STRING',
            'description' => 'الاسم المرئي المحدد للعنصر الرئيسي، أو غير واضح.'
        ],
        'category' => [
            'type' => 'STRING',
            'enum' => $categories
        ],
        'primary_color' => [
            'type' => 'STRING',
            'description' => 'اللون الأساسي المرئي، أو غير واضح.'
        ],
        'secondary_colors' => [
            'type' => 'ARRAY',
            'items' => ['type' => 'STRING'],
            'maxItems' => 3
        ],
        'material' => [
            'type' => 'STRING',
            'description' => 'الخامة فقط إذا كانت مؤكدة بصرياً؛ وإلا غير واضح.'
        ],
        'brand' => [
            'type' => 'STRING',
            'description' => 'العلامة التجارية المقروءة بوضوح؛ وإلا سلسلة فارغة.'
        ],
        'condition' => [
            'type' => 'STRING',
            'description' => 'التلف المرئي فقط. عند عدم ظهور تلف استخدم: لا توجد أضرار ظاهرة في الجزء المرئي.'
        ],
        'distinctive_features' => [
            'type' => 'ARRAY',
            'items' => ['type' => 'STRING'],
            'minItems' => 0,
            'maxItems' => 5
        ],
        'student_id' => [
            'type' => 'STRING',
            'description' => 'الرقم الجامعي المطبوع على البطاقة (أرقام فقط من 9 أرقام مثل 202402461، أو بحرف S مثل S202402400). إن لم يكن مقروءاً بوضوح فاستخدم سلسلة فارغة.'
        ],
        'name' => [
            'type' => 'STRING',
            'description' => 'اسم الطالب المطبوع على البطاقة. إن لم يكن مقروءاً بوضوح فاستخدم سلسلة فارغة.'
        ],
        'visual_confidence' => [
            'type' => 'INTEGER',
            'minimum' => 0,
            'maximum' => 100,
            'description' => 'تقدير تقريبي لجودة رؤية العنصر في الصورة، وليس ضماناً لصحة الوصف.'
        ]
    ],
    'required' => [
        'object_type', 'category', 'primary_color', 'secondary_colors',
        'material', 'brand', 'condition', 'distinctive_features',
        'student_id', 'name', 'visual_confidence'
    ]
];

$apiResult = callGeminiVisionAPI($imageBase64, $mimeType, $prompt, [
    'maxOutputTokens' => 1200,
    'responseSchema'  => $schema,
]);

if (!$apiResult['success']) {
    jsonResponse([
        'success'    => false,
        'ai_powered' => false,
        'error_type' => $apiResult['error_type'],
        'message'    => $apiResult['message'],
        'image_path' => $savedImagePath
    ], $apiResult['http_code']);
}

$vision      = $apiResult['data'];
$usedModel   = $apiResult['model'] ?? $model;

$objectType   = normalizeVisualValue($vision['object_type'] ?? 'غير واضح');
$primaryColor = normalizeVisualValue($vision['primary_color'] ?? 'غير واضح');
$material     = normalizeVisualValue($vision['material'] ?? 'غير واضح');
$brand        = trim((string)($vision['brand'] ?? ''));
$condition    = normalizeVisualValue($vision['condition'] ?? 'لا توجد أضرار ظاهرة في الجزء المرئي');
$confidence   = max(0, min(100, (int)($vision['visual_confidence'] ?? 0)));

$category = sanitizeInput($vision['category'] ?? 'أخرى');
if (!in_array($category, $categories, true)) {
    $category = 'أخرى';
}

$secondaryColors = cleanVisualList($vision['secondary_colors'] ?? [], 3);
$features        = cleanVisualList($vision['distinctive_features'] ?? [], 5);

$studentId = normalizeUniversityId($vision['student_id'] ?? '');

$titleParts = [];
if (!isUnknownVisual($objectType)) {
    $titleParts[] = $objectType;
}
if (!isUnknownVisual($primaryColor)) {
    $titleParts[] = 'باللون ' . $primaryColor;
}
if (!isUnknownVisual($material)) {
    $titleParts[] = 'من ' . $material;
}
$title = !empty($titleParts) ? implode(' ', $titleParts) : 'عنصر غير واضح';

$descriptionParts = [];
$descriptionParts[] = !isUnknownVisual($objectType)
    ? 'العنصر الظاهر: ' . $objectType
    : 'نوع العنصر غير واضح من الصورة';

if (!isUnknownVisual($primaryColor)) {
    $colors = array_merge([$primaryColor], $secondaryColors);
    $colors = array_values(array_unique(array_filter($colors, fn($v) => !isUnknownVisual($v))));
    if (!empty($colors)) {
        $descriptionParts[] = 'الألوان الظاهرة: ' . implode('، ', $colors);
    }
}

if (!isUnknownVisual($material)) {
    $descriptionParts[] = 'الخامة الظاهرة: ' . $material;
}

if ($brand !== '' && !isUnknownVisual($brand)) {
    $descriptionParts[] = 'العلامة المقروءة: ' . $brand;
}

if (!isUnknownVisual($condition)) {
    $descriptionParts[] = 'الحالة الظاهرة: ' . $condition;
}

if (!empty($features)) {
    $descriptionParts[] = 'سمات مميزة: ' . implode('، ', $features);
}

$description = implode('. ', $descriptionParts) . '.';

// الوسوم تُبنى من نفس الحقائق، ولا نطلب من النموذج إنشاء قائمة مستقلة قد تتعارض مع الوصف.
$tagCandidates = array_merge(
    [$objectType, $primaryColor],
    $secondaryColors,
    [$material, $brand],
    $features
);

$genericTags = [
    'مقتنيات شخصية', 'مفقود', 'معثور عليه', 'عنصر', 'شيء',
    'أخرى', 'غير واضح', '(غير واضح)'
];

$tags = [];
foreach ($tagCandidates as $tag) {
    $tag = sanitizeInput((string)$tag);
    if ($tag === '' || in_array($tag, $genericTags, true) || isUnknownVisual($tag)) {
        continue;
    }
    if (!in_array($tag, $tags, true)) {
        $tags[] = $tag;
    }
    if (count($tags) >= 5) {
        break;
    }
}

jsonResponse([
    'success'    => true,
    'ai_powered' => true,
    'data'       => [
        'mode'              => $mode,
        'report_type'       => $reportType,
        'category'          => $category,
        'title'             => sanitizeInput($title),
        'tags'              => $tags,
        'description'       => sanitizeInput($description),
        'student_id'        => $studentId,
        'name'              => sanitizeInput((string)($vision['name'] ?? '')),
        'visual_confidence' => $confidence,
        'image_path'        => $savedImagePath,
        'model'             => $usedModel
    ]
]);

function isUnknownVisual($value) {
    $v = trim((string)$value);
    return $v === '' || $v === 'غير واضح' || $v === '(غير واضح)' || $v === 'غير مؤكد';
}

function normalizeVisualValue($value) {
    $v = trim((string)$value);
    if ($v === '' || in_array($v, ['(غير واضح)', 'غير مؤكد', 'unknown', 'Unknown', 'N/A', 'null'], true)) {
        return 'غير واضح';
    }
    return $v;
}

function cleanVisualList($value, $maxItems = 5) {
    if (!is_array($value)) {
        return [];
    }

    $out = [];
    foreach ($value as $item) {
        $item = normalizeVisualValue($item);
        if (isUnknownVisual($item)) {
            continue;
        }
        $item = sanitizeInput($item);
        if ($item !== '' && !in_array($item, $out, true)) {
            $out[] = $item;
        }
        if (count($out) >= $maxItems) {
            break;
        }
    }
    return $out;
}

/**
 * تطبيع الرقم الجامعي المستخرج من صورة البطاقة.
 * يدعم صيغتين:
 *   - حرف S متبوعاً بـ 9 أرقام (مثال: S000000001)
 *   - 9 أرقام فقط كما تظهر مطبوعة على البطاقة (مثال: 202402461)
 * لا تُحذف القيمة المستخرجة أبداً؛ ننسخ أكبر تسلسل رقمي صالح فقط.
 */
function normalizeUniversityId($value) {
    $v = trim((string)$value);
    if ($v === '' || $v === 'null' || $v === 'N/A' || $v === 'غير واضح') {
        return '';
    }

    $compact = preg_replace('/[\s\-–—]/u', '', $v);
    if (preg_match('/S\d{9}/i', $compact, $m)) {
        return strtoupper($m[0]);
    }

    if (preg_match('/\d{9}/', $compact, $m)) {
        return $m[0];
    }

    return sanitizeInput($v);
}

