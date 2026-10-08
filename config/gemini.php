<?php
/**
 * لُقيا AI — طبقة تكامل Gemini API المركزية
 * تُوفّر دوال PHP لاستدعاء Gemini عبر cURL:
 *   - callGeminiAPI()                 : الاستدعاء النصي للـ API
 *   - analyzeImageWithGeminiVision()  : التحليل البصري واستخراج النصوص (Multimodal Vision OCR)
 *   - enhanceDescriptionWithGemini()   : تحسين الوصف عند إنشاء البلاغ
 *   - geminiSemanticScore()           : تقييم التشابه الدلالي بين بلاغين
 *   - geminiSmartSearch()             : البحث الذكي بالنص الحر
 */

require_once __DIR__ . '/db.php';

// ---- إعداد المفتاح والنموذج ----
// المفتاح يُقرأ من بيئة التشغيل (.env أو النظام) فقط ولا يُكتب داخل المشروع.
$geminiEnvKey = getenv('GEMINI_API_KEY');
if ($geminiEnvKey === false || trim((string) $geminiEnvKey) === '') {
    $geminiEnvKey = $_ENV['GEMINI_API_KEY'] ?? '';
}
define('GEMINI_API_KEY', trim((string) $geminiEnvKey));

$geminiEnvModel = getenv('GEMINI_MODEL');
if ($geminiEnvModel === false || trim((string) $geminiEnvModel) === '') {
    $geminiEnvModel = $_ENV['GEMINI_MODEL'] ?? '';
}
$geminiEnvModel = trim((string) $geminiEnvModel);
// إذا لم يُحدد الموديل أو تم تحديد الموديل الملغى gemini-1.5-flash، نستخدم gemini-3-flash-preview
if ($geminiEnvModel === '' || $geminiEnvModel === 'gemini-1.5-flash') {
    $geminiEnvModel = 'gemini-3-flash-preview';
}
define('GEMINI_MODEL', $geminiEnvModel);

// قائمة النماذج المدعومة للتبديل التلقائي (Fallback) عند نفاذ الحصة 429 أو خطأ 404/503
define('GEMINI_FALLBACK_MODELS', [
    'gemini-3-flash-preview',
    'gemini-3.6-flash',
    'gemini-flash-latest',
    'gemini-3.5-flash',
    'gemini-3.1-flash-lite'
]);

function getGeminiEndpoint(string $model = GEMINI_MODEL): string {
    return 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent';
}

define('GEMINI_ENDPOINT', getGeminiEndpoint(GEMINI_MODEL));
define('GEMINI_TIMEOUT',  25); // ثوانٍ قبل انتهاء مهلة الاتصال النصي
define('GEMINI_VISION_TIMEOUT', 60); // استدلال الصور يحتاج وقتاً أطول من النص

// متغير لتخزين تفاصيل آخر خطأ للتشخيص بدقة
$GLOBALS['gemini_last_error'] = null;

/**
 * هل مفتاح Gemini متاح في بيئة التشغيل؟
 *
 * فحص رخيص جداً (بدون أي طلب شبكي) تستخدمه كل الطبقات العليا لتخطّي
 * المسار الذكي بدل دفع كلفة استدعاء فاشل متكرر.
 */
function isGeminiAvailable(): bool {
    return defined('GEMINI_API_KEY') && trim((string) GEMINI_API_KEY) !== '';
}

/**
 * استخراج نص أول مرشّح من رد Gemini، مع تسميات خطأ واضحة عند الفشل.
 *
 * @return array{ok:bool, text:string, http_code:int, error:string}
 */
function extractGeminiText($response, int $httpCode = 200, string $curlErr = ''): array {
    if ($curlErr !== '' || $httpCode !== 200 || empty($response)) {
        return ['ok' => false, 'text' => '', 'http_code' => $httpCode, 'error' => 'transport'];
    }
    $data = json_decode((string) $response, true);
    $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
    if (trim((string) $text) === '') {
        // النموذج قد يرد بسبب حاجز (promptFeedback / safety) بدل نص.
        $blocked = $data['promptFeedback']['blockReason'] ?? ($data['candidates'][0]['finishReason'] ?? '');
        if ($blocked !== '') {
            return ['ok' => false, 'text' => '', 'http_code' => $httpCode, 'error' => 'blocked:' . $blocked];
        }
        return ['ok' => false, 'text' => '', 'http_code' => $httpCode, 'error' => 'empty_text'];
    }
    return ['ok' => true, 'text' => (string) $text, 'http_code' => $httpCode, 'error' => ''];
}

/**
 * فكّ كائن JSON من نص قد يكون ملفوفاً Markdown أو محاطاً بشرح.
 * يعيد null عند الفشل بدل رمي استثناء.
 */
function decodeGeminiJson(string $text): ?array {
    $text = trim($text);
    if ($text === '') return null;
    $candidates = [];
    if (preg_match('/\{.*\}/s', $text, $m)) $candidates[] = $m[0];
    if (preg_match('/\[.*\]/s', $text, $m)) $candidates[] = $m[0];
    $candidates[] = $text;
    foreach ($candidates as $c) {
        $decoded = json_decode($c, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }
    }
    return null;
}

function getGeminiLastError() {
    return $GLOBALS['gemini_last_error'];
}

// ============================================================
//  Circuit breaker for quota exhaustion
// ============================================================
//  A 429 from the Gemini free tier is NOT a per-model fault: the quota
//  metric (generate_content_free_tier_requests) belongs to the API key /
//  project, so every model in the chain is equally unavailable. Treating
//  429 like 404 used to burn one extra request per fallback model, which
//  could waste a large slice of a small daily budget and turned a single
//  feature into a ~20 second stall.
//
//  Instead we now classify the failure once, open a breaker for a while, and
//  every later call returns instantly with no network traffic at all. That
//  is what makes the app degrade fast instead of degrading slowly.
//
//  State is per-process only. Shared hosting runs each request in its own
//  process, so a static holds for exactly the requests of one page load,
//  which is the scope where the expensive loops live.

define('GEMINI_BREAKER_QUOTA_TTL',  900); // 15 min: conservative for a daily cap
define('GEMINI_BREAKER_TRANSIENT_TTL', 90); // 90s: 429 that looked like throttling

$GLOBALS['gemini_breaker'] = ['until' => 0, 'reason' => '', 'retry_after' => 0];

/**
 * هل المفتاح غير مُعدّ، أو أن قاعدتنا صباحت، أو أن مزوّد الخدمة متوقف؟
 * تستدعيها الطبقات العليا فتخطّي الشبكة بالكامل وتستخدم المحرك المحلي.
 */
function isGeminiRateLimited(): bool {
    return ($GLOBALS['gemini_breaker']['until'] ?? 0) > time();
}

/**
 * نفس isGeminiRateLimited() لكن تتضمّن حالة "لا يوجد مفتاح" أيضاً.
 * الدوال التي لا تُنفّذ أصلاً بدون مفتاح تستخدم هذه.
 */
function isGeminiUnavailableOrLimited(): bool {
    return !isGeminiAvailable() || isGeminiRateLimited();
}

/**
 * يسجّل حالة الفشل ويفتح القاطع عند الحاجة.
 *
 * @param int    $httpCode     رمز HTTP الذي أعادته الواجهة
 * @param string $apiMessage   نص رسالة Gemini
 */
function registerGeminiFailure(int $httpCode, string $apiMessage = ''): void {
    if ($httpCode !== 429) {
        // 503 / 500 ونحوها ضغط مؤقت على مزوّد الخدمة: لا داعي لتجميد التطبيق.
        return;
    }

    // The free tier prints "Please retry in Ns" even for a fully spent daily
    // budget, so we deliberately do NOT trust that hint to pick a short TTL.
    $isDailyCap = stripos($apiMessage, 'free_tier_requests') !== false
        || stripos($apiMessage, 'quota exceeded for metric') !== false;

    $ttl = $isDailyCap ? GEMINI_BREAKER_QUOTA_TTL : GEMINI_BREAKER_TRANSIENT_TTL;

    $GLOBALS['gemini_breaker'] = [
        'until'       => time() + $ttl,
        'reason'      => $isDailyCap ? 'quota_exhausted' : 'rate_limited',
        'retry_after' => $ttl,
    ];

    error_log('[Luqya AI Gemini] quota breaker OPEN for ' . $ttl . 's ('
        . ($isDailyCap ? 'daily/plan quota exhausted' : 'rate limited')
        . '). AI features will use the local engine until then.');
}

/**
 * Allows a caller to describe quota state to the UI without leaking the key.
 *
 * @return array{limited:bool, reason:string, retry_after:int}
 */
function getGeminiBreakerStatus(): array {
    $b = $GLOBALS['gemini_breaker'] ?? ['until' => 0, 'reason' => '', 'retry_after' => 0];
    $remaining = max(0, (int) ($b['until'] ?? 0) - time());
    return [
        'limited'     => $remaining > 0,
        'reason'      => $remaining > 0 ? (string) ($b['reason'] ?? 'rate_limited') : '',
        'retry_after' => $remaining,
    ];
}

/**
 * تسجيل خطأ محلي (فحص مسبق قبل أي طلب شبكي) دون تلفيقه كخطأ cURL.
 *
 * يستخدمه مسار الرؤية عند غياب المفتاح أو غياب الصورة، حتى تعرض الواجهة
 * سبباً صريحاً "المفتاح غير مُهيأ" بدل "خطأ اتصال" مضلّل.
 */
function setGeminiLocalError(string $message, string $attemptedModel = ''): array {
    $errorData = [
        'curl_error'      => '',
        'curl_errno'      => 0,
        'http_code'       => 0,
        'response'        => '',
        'api_message'     => $message,
        'attempted_model' => $attemptedModel,
        'endpoint'        => '',
        'message'         => $message,
    ];
    $GLOBALS['gemini_last_error'] = $errorData;
    return $errorData;
}

/**
 * تسجيل وتنسيق تفاصيل الخطأ بدقة متناهية (cURL + HTTP + API Body)
 */
function setGeminiLastError(?string $curlErr, int $curlErrNo, int $httpCode, $response, string $attemptedModel, string $endpoint): array {
    $rawResponse = is_string($response) ? $response : json_encode($response, JSON_UNESCAPED_UNICODE);
    if (empty($rawResponse)) {
        $rawResponse = 'No Response Body';
    }

    $apiMessage = '';
    $decoded = json_decode($rawResponse, true);
    if (is_array($decoded) && isset($decoded['error']['message'])) {
        $apiMessage = $decoded['error']['message'];
    }

    $errorParts = [];
    if (!empty($curlErr) || $curlErrNo > 0) {
        $errorParts[] = "خطأ cURL [{$curlErrNo}]: {$curlErr}";
    }
    if ($httpCode !== 200) {
        $errorParts[] = "HTTP {$httpCode}";
    }
    if (!empty($apiMessage)) {
        $errorParts[] = "Gemini API: {$apiMessage}";
    } elseif ($rawResponse !== 'No Response Body') {
        $cleanResp = preg_replace('/\s+/', ' ', strip_tags($rawResponse));
        $errorParts[] = "رد الخادم: " . mb_substr($cleanResp, 0, 200, 'UTF-8');
    }

    $formattedMessage = !empty($errorParts)
        ? implode(' | ', $errorParts)
        : 'فشل غير معروف في الاتصال بـ Gemini API';

    $errorData = [
        'curl_error'       => $curlErr ?: '',
        'curl_errno'       => $curlErrNo,
        'http_code'        => $httpCode,
        'response'         => $rawResponse,
        'api_message'      => $apiMessage,
        'attempted_model'  => $attemptedModel,
        'endpoint'         => $endpoint,
        'message'          => $formattedMessage,
    ];

    $GLOBALS['gemini_last_error'] = $errorData;
    error_log("[Luqya AI Gemini Error] Model: {$attemptedModel} | " . $formattedMessage);

    return $errorData;
}

/**
 * استدعاء Gemini API النصي عبر cURL مع تجاوز SSL ودعم السقوط التلقائي للنماذج المدعومة
 *
 * @param  string $prompt      النص الكامل المُرسَل للنموذج
 * @param  int    $maxTokens   الحد الأقصى للرموز في الردّ (default: 512)
 * @return string|null         النص المُولَّد، أو null عند الفشل
 */
function callGeminiAPI(string $prompt, int $maxTokens = 512): ?string {
    if (empty(GEMINI_API_KEY)) {
        $GLOBALS['gemini_last_error'] = [
            'curl_error'  => '',
            'curl_errno'  => 0,
            'http_code'   => 500,
            'response'    => '',
            'api_message' => 'لم يتم ضبط مفتاح GEMINI_API_KEY في النظام أو ملف .env',
            'message'     => 'لم يتم ضبط مفتاح GEMINI_API_KEY في النظام أو ملف .env'
        ];
        error_log('[Luqya AI Gemini] تحذير: لم يتم ضبط GEMINI_API_KEY. تخطي استدعاء الـ API.');
        return null;
    }

    // قاطع الحصة مفتوح: نخرج فوراً دون أي طلب شبكي (تراجع رشيق وسريع).
    if (isGeminiRateLimited()) {
        $status = getGeminiBreakerStatus();
        $GLOBALS['gemini_last_error'] = [
            'curl_error' => '', 'curl_errno' => 0, 'http_code' => 429, 'response' => '',
            'api_message' => 'Gemini quota breaker is open (' . $status['reason'] . ').',
            'message'     => 'Gemini quota exhausted; using the local engine for ' . $status['retry_after'] . 's.',
        ];
        return null;
    }

    $payload = json_encode([
        'contents' => [
            [
                'parts' => [
                    ['text' => $prompt]
                ]
            ]
        ],
        'generationConfig' => [
            'maxOutputTokens' => $maxTokens,
            'temperature'     => 0.3,
            'topP'            => 0.85,
        ],
        'safetySettings' => [
            ['category' => 'HARM_CATEGORY_HARASSMENT',        'threshold' => 'BLOCK_NONE'],
            ['category' => 'HARM_CATEGORY_HATE_SPEECH',       'threshold' => 'BLOCK_NONE'],
            ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_NONE'],
            ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_NONE'],
        ],
    ], JSON_UNESCAPED_UNICODE);

    // قائمة النماذج للتجربة بدءاً من النموذج المحدد ثم النماذج البديلة المدعومة.
    // نكتفي بأول 3 نماذج فقط: التبديل لجسّ 404/503 المفيد، دون تحويل فشل النموذج
    // الأساسي إلى سلسلة انتظار طويلة (كل نموذج له مهلة مستقلة).
    $modelsToTry = array_values(array_unique(array_merge([GEMINI_MODEL], GEMINI_FALLBACK_MODELS)));
    $modelsToTry = array_slice($modelsToTry, 0, 3);
    $lastErrorData = null;

    foreach ($modelsToTry as $model) {
        $endpoint = getGeminiEndpoint($model);

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-goog-api-key: ' . GEMINI_API_KEY
            ],
            CURLOPT_TIMEOUT        => GEMINI_TIMEOUT,
            // تحقق كامل من شهادة SSL/TLS (يستعمل curl.cainfo المُهيأ في php.ini)
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // إجبار IPv4 لحل مشاكل الاتصال في بيئة ويندوز / XAMPP
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
        ]);

        $response   = curl_exec($ch);
        $httpCode   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr    = curl_error($ch);
        $curlErrNo  = curl_errno($ch);
        curl_close($ch);

        if ($curlErr || $httpCode !== 200) {
            $lastErrorData = setGeminiLastError($curlErr, $curlErrNo, $httpCode, $response, $model, $endpoint);

            // 429 = الحصة نفدت على مستوى المفتاح/المشروع، لا على مستوى النموذج.
            // كل النماذج الأخرى محرومة بالقدر نفسه، فتجربة البقية تستهلك طلبات
            // بلا فائدة وتُطيل الانتظار. نسجّل القاطع ونخرج فوراً.
            if ($httpCode === 429) {
                registerGeminiFailure(429, (string) ($lastErrorData['api_message'] ?? ''));
                return null;
            }

            // 404 = النموذج غير متاح، 503 = ضغط مؤقت: هنا فقط التبديل للنموذج
            // البديل مفيد فعلاً، لأنه خاصية ذلك النموذج نفسه.
            if (in_array($httpCode, [404, 503], true) && $model !== end($modelsToTry)) {
                error_log("[Luqya AI Gemini] النموذج {$model} أعاد رمز {$httpCode}. المحاولة بالنموذج البديل...");
                continue;
            }

            // في حال وجود خطأ شبكة cURL حقيقي، نتوقف فوراً دون انتظار
            return null;
        }

        $data = json_decode($response, true);
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if ($text !== null) {
            $GLOBALS['gemini_last_error'] = null; // مسح الأخطاء السابقة عند النجاح
            return $text;
        }
    }

    return null;
}

/**
 * توحيد صيغة الرقم الجامعي المستخرج من الرؤية.
 *
 * مثال على سبب الحاجة: قد يُرجع النموذج "s 123456789" أو أرقاماً هندية
 * "١٢٣٤٥٦٧٨٩" أو بدون حرف S. محرك المطابقة يقارن النمط S\d{9} حرفياً،
 * فلو لم نوحّد هنا لفات التطابق في محرك المطابقة رغم وجوده فعلاً.
 */
function normalizeGeminiStudentId($value): string {
    $raw = trim((string) $value);
    if ($raw === '') return '';

    // تحويل الأرقام الهندية/العربية إلى ASCII
    $arabicIndic = ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
                    '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
                    '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
                    '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9'];
    $raw = strtr($raw, $arabicIndic);

    // حذف كل ما ليس حرفاً أو رقماً ثم التحويل لأحرف كبيرة
    $raw = preg_replace('/[^A-Za-z0-9]/', '', $raw);
    $raw = strtoupper((string) $raw);
    if ($raw === '') return '';

    if (preg_match('/^S?\d{9}$/', $raw)) {
        return 'S' . substr($raw, -9);
    }
    if (preg_match('/\d{9}/', $raw, $m)) {
        return 'S' . $m[0];
    }
    // صيغة غير موثوقة: لا نخترع قيمة من خمّن النموذج.
    return '';
}

/**
 * استدعاء Gemini برؤية الصورة — الطبقة البرمجية الوحيدة والنقطة المشتركة.
 *
 * تُستخدم من مسارين:
 *   - analyzeImageWithGeminiVision()  : تحليل عام/بطاقة معياري
 *   - api/vision_ai.php               : تحليل الواجهة بمخطط استجابة صارم
 *
 * لذلك توحّدنا النقل هنا: مهلة واحدة، وسلسلة نماذج بديلة واحدة، وتصنيف
 * أخطاء واحد، ودالة استخراج JSON واحدة. كان هذا الكود مكرراً داخل
 * vision_ai.php، ما كان يسمح للمسارين بالتباعد عن بعضهما.
 *
 * @param string $imageBase64      بيانات الصورة Base64 بلا بادئة data:
 * @param string $mimeType         نوع الصورة
 * @param string $prompt           التعليمات المرسلة مع الصورة
 * @param array  $generationConfig إعداد التوليد (يُدمج مع الافتراضي)
 * @return array{success:bool, data?:array, http_code:int, model?:string, error_type?:string, message?:string}
 */
function callGeminiVisionAPI(string $imageBase64, string $mimeType, string $prompt, array $generationConfig = []): array {
    if (!isGeminiAvailable()) {
        setGeminiLocalError('مفتاح GEMINI_API_KEY غير مُهيأ في بيئة التشغيل.', GEMINI_MODEL);
        return [
            'success'    => false,
            'error_type' => 'missing_api_key',
            'message'    => 'مفتاح Gemini API غير مُعدّ. يرجى ضبط GEMINI_API_KEY في بيئة التشغيل.',
            'http_code'  => 500,
        ];
    }
    if (isGeminiRateLimited()) {
        $status = getGeminiBreakerStatus();
        setGeminiLocalError(
            'Gemini quota breaker is open (' . $status['reason'] . '); using the local engine for ' . $status['retry_after'] . 's.',
            GEMINI_MODEL
        );
        return [
            'success'    => false,
            'error_type' => 'quota_exhausted',
            'message'    => 'نفدت حصة Gemini حالياً؛ المحرك المحلي مستخدم بدلاً منها.',
            'http_code'  => 429,
        ];
    }
    if ($imageBase64 === '') {
        setGeminiLocalError('لا توجد بيانات صورة لإرسالها إلى Gemini.', GEMINI_MODEL);
        return [
            'success'    => false,
            'error_type' => 'empty_image',
            'message'    => 'لم تصل أي بيانات صورة إلى محرك الرؤية.',
            'http_code'  => 400,
        ];
    }

    $generationConfig = array_merge([
        'maxOutputTokens' => 900,
        'temperature'     => 0.1,
        'topP'            => 0.8,
        'responseMimeType' => 'application/json',
    ], $generationConfig);

    $body = json_encode([
        'contents' => [[
            'parts' => [
                ['text' => $prompt],
                ['inlineData' => ['mimeType' => $mimeType, 'data' => $imageBase64]],
            ]
        ]],
        'generationConfig' => $generationConfig,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($body === false) {
        setGeminiLocalError('فشل إنشاء طلب Gemini: ' . json_last_error_msg(), GEMINI_MODEL);
        return [
            'success'    => false,
            'error_type' => 'json_encode_error',
            'message'    => 'فشل إنشاء طلب Gemini: ' . json_last_error_msg(),
            'http_code'  => 500,
        ];
    }

    $modelsToTry = array_values(array_unique(array_merge([GEMINI_MODEL], GEMINI_FALLBACK_MODELS)));
    $modelsToTry = array_slice($modelsToTry, 0, 3);
    $lastResult  = null;

    foreach ($modelsToTry as $currentModel) {
        $endpoint = getGeminiEndpoint($currentModel);
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-goog-api-key: ' . GEMINI_API_KEY,
            ],
            // Vision needs a longer window than text: image tokens are heavy.
            CURLOPT_TIMEOUT        => GEMINI_VISION_TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
        ]);

        $response  = curl_exec($ch);
        $httpCode  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrNo = curl_errno($ch);
        curl_close($ch);

        if ($curlError) {
            setGeminiLastError($curlError, $curlErrNo, $httpCode, null, $currentModel, $endpoint);
            return [
                'success'    => false,
                'error_type' => 'connection_error',
                'message'    => "فشل اتصال cURL [{$curlErrNo}]: " . $curlError,
                'http_code'  => 502,
            ];
        }

        if ($httpCode !== 200) {
            setGeminiLastError('', 0, $httpCode, $response, $currentModel, $endpoint);
            $errBody = json_decode((string) $response, true);
            $errMsg  = $errBody['error']['message'] ?? 'خطأ غير معروف من Gemini API.';

            // 429 = الحصة نفدت على مستوى المفتاح، لا على مستوى النموذج، فالتبديل
            // هنا يستهلك طلبات مجانية بلا نتيجة. نسجّل القاطع ونخرج مباشرة.
            if ($httpCode === 429) {
                registerGeminiFailure(429, (string) $errMsg);
                return [
                    'success'    => false,
                    'error_type' => 'quota_exhausted',
                    'message'    => 'نفدت حصة Gemini حالياً (' . $currentModel . '). الرجاء المحاولة لاحقاً.',
                    'http_code'  => 429,
                ];
            }

            $lastResult = [
                'success'    => false,
                'error_type' => 'api_error',
                'message'    => "خطأ من Gemini API (HTTP {$httpCode} - النموذج: {$currentModel}): {$errMsg}",
                'http_code'  => $httpCode,
            ];

            if (in_array($httpCode, [404, 503], true) && $currentModel !== end($modelsToTry)) {
                error_log("[Luqya AI Vision] النموذج {$currentModel} أعاد {$httpCode}. تجربة النموذج البديل...");
                continue;
            }
            return $lastResult;
        }

        if (empty($response)) {
            $lastResult = [
                'success'    => false,
                'error_type' => 'empty_response',
                'message'    => 'استجابة فارغة من Gemini API.',
                'http_code'  => 502,
            ];
            continue;
        }

        $GLOBALS['gemini_last_error'] = null;

        $text = extractGeminiText($response, 200);
        if (!$text['ok']) {
            return [
                'success'    => false,
                'error_type' => $text['error'] === 'empty_text' ? 'no_content' : 'bad_response',
                'message'    => $text['error'] === 'empty_text'
                    ? 'لم يُرجع Gemini محتوى نصياً (سبب محتمل: حاجز أمان أو طول استجابة).'
                    : 'تعذّر قراءة ردّ Gemini.',
                'http_code'  => 502,
            ];
        }

        $parsed = decodeGeminiJson($text['text']);
        if ($parsed === null) {
            return [
                'success'    => false,
                'error_type' => 'parse_error',
                'message'    => 'فشل تحليل JSON من Gemini. النص المُرجع: ' . mb_substr($text['text'], 0, 300, 'UTF-8'),
                'http_code'  => 502,
            ];
        }

        return [
            'success'   => true,
            'data'      => $parsed,
            'http_code' => 200,
            'model'     => $currentModel,
        ];
    }

    return $lastResult ?: [
        'success'    => false,
        'error_type' => 'connection_failed',
        'message'    => 'تعذر الاتصال بكافة نماذج Gemini المتاحة.',
        'http_code'  => 502,
    ];
}

/**
 * التحليل البصري المتعدد الوسائط للصور (Gemini Multimodal Vision API)
 *
 * @param  string $imageBase64  بيانات الصورة مرمزة Base64 (بدون بادئة data:image/...)
 * @param  string $mimeType     نوع الصورة (e.g. image/jpeg, image/png)
 * @param  string $mode         'id_card' أو 'general_item'
 * @param  string $reportType   'lost' أو 'found'
 * @return array|null           مصفوفة النتائج المستخرجة (title, category, student_id, name, tags, description)
 */
function analyzeImageWithGeminiVision(string $imageBase64, string $mimeType = 'image/jpeg', string $mode = 'general_item', string $reportType = 'lost'): ?array {
    // لا نستدعي Gemini إطلاقاً بدون مفتاح، لكن نسجّل السبب ليعرضه التطبيق بدل صمت غامض.
    if (!isGeminiAvailable()) {
        setGeminiLocalError('مفتاح GEMINI_API_KEY غير مُهيأ في بيئة التشغيل.', GEMINI_MODEL);
        return null;
    }
    if (isGeminiRateLimited()) {
        $status = getGeminiBreakerStatus();
        setGeminiLocalError('نفدت حصة Gemini مؤقتاً؛ تم الاكتفاء بالمحرك المحلي.', GEMINI_MODEL);
        return null;
    }
    if ($imageBase64 === '') {
        setGeminiLocalError('لم تصل أي بيانات صورة إلى محرك الرؤية.', GEMINI_MODEL);
        return null;
    }

    // نسمح بأنواع الصور فقط حتى لا نرسل نوعاً غير متوقع إلى الواجهة البرمجية.
    $allowedMimes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/heic', 'image/heif'];
    $mimeType = strtolower(trim($mimeType));
    if (!in_array($mimeType, $allowedMimes, true)) {
        $mimeType = 'image/jpeg';
    }

    // توحيد نوع البلاغ حتى لا يقع أي مسار غير معروف في Prompt مختلف بالخطأ.
    $reportType = ($reportType === 'found') ? 'found' : 'lost';

    // ذاكرة على مستوى الطلب: نفس الصورة لا تُحمَّل من Gemini مرتين.
    $memoKey = 'v:' . substr(hash('sha256', $imageBase64), 0, 32) . ':' . $mode . ':' . $reportType;
    static $memo = [];
    if (isset($memo[$memoKey])) {
        return $memo[$memoKey];
    }

    if ($mode === 'id_card') {
        $prompt = <<<PROMPT
أنت محرك رؤية وOCR دقيق لنظام المفقودات والمعثورات الجامعية "لُقيا AI".
حلل فقط ما هو ظاهر ومقروء فعلياً في صورة البطاقة، ولا تخمّن أي معلومة غير واضحة.

أرجع JSON فقط بهذه الحقول:
{
  "title":"",
  "category":"البطاقة الجامعية / الصراف",
  "student_id":"",
  "name":"",
  "tags":[],
  "description":""
}

قواعد إلزامية:
- استخرج الاسم والرقم الجامعي والجهة فقط إذا كانت مقروءة بوضوح.
- إذا تعذر قراءة أي معلومة، اترك الحقل فارغاً "" بدلاً من التخمين.
- لا تصف البطاقة بأنها سليمة أو ممتازة أو تحتوي شريحة ذكية إلا إذا كان ذلك ظاهراً ومؤكداً.
- الوصف يجب أن يذكر فقط اللون والنصوص والسمات المرئية المؤكدة.
- ممنوع إضافة أي معلومة من المعرفة العامة أو التوقع.
PROMPT;
    } else {
        $reportLabel = ($reportType === 'found') ? 'المعثور عليه' : 'المفقود';

        $prompt = <<<PROMPT
أنت محرك رؤية حاسوبية دقيق لنظام المفقودات والمعثورات الجامعية "لُقيا AI".
حلل صورة العنصر {$reportLabel} اعتماداً على البكسلات الظاهرة فقط، وليس على التخمين أو المعرفة العامة.

مهم جداً: لا تكتب وصفاً أدبياً ولا تحاول إكمال التفاصيل الناقصة. مهمتك استخراج خصائص بصرية مؤكدة فقط.

أرجع JSON فقط بالشكل التالي:
{
  "object_type":"",
  "primary_color":"",
  "secondary_colors":[],
  "material":"",
  "brand":"",
  "visible_text":"",
  "visible_features":[],
  "visible_damage":[],
  "category":"أخرى",
  "student_id":"",
  "name":""
}

قواعد إلزامية للدقة:
- "object_type": اكتب الاسم العام للشيء الظاهر فقط مثل: ساعة يد، محفظة، هاتف، حقيبة، مفتاح. إذا لم يتضح النوع اكتب "غرض غير محدد".
- "primary_color" و"secondary_colors": اذكر الألوان المرئية فقط.
- "material": اتركه فارغاً "" ما لم تكن الخامة مؤكدة بصرياً بدرجة عالية. اللون الفضي/الرمادي أو اللمعان لا يعني معدن.
- "brand": اتركه فارغاً "" إلا إذا ظهر اسم أو شعار العلامة بشكل واضح ومقروء. لا تستنتج الماركة من التصميم وحده.
- "visible_text": انسخ فقط النص المقروء فعلياً، وإلا اتركه فارغاً.
- "visible_features": سمات مرئية قابلة للمطابقة فقط مثل إطار دائري، حزام أسود، سحابان، ثلاثة أزرار، نقش معين. لا تضف وظائف أو محتويات غير ظاهرة.
- "visible_damage": اذكر فقط الخدوش/الكسور/التمزقات الظاهرة بوضوح. إذا لم تكن هناك أضرار مؤكدة اترك المصفوفة فارغة؛ لا تكتب أن الحالة ممتازة أو خالية من العيوب.
- "category": اختر فقط من: ["مقتنيات شخصية", "إلكترونيات", "وثائق ومستندات", "مفاتيح", "حقائب وحقائب ظهر", "ملابس وإكسسوارات", "البطاقة الجامعية / الصراف", "أخرى"].
- "student_id" و"name": استخدمهما فقط إذا كانت الصورة بطاقة وبها بيانات مقروءة، وإلا اتركهما فارغين.
- إذا كانت خاصية غير مؤكدة: استخدم "" أو [] ولا تخمّن.
- ممنوع استخدام كلمات مثل: غالباً، يبدو أنه مصنوع من، على الأرجح، بحالة ممتازة، نظيف، خالٍ من العيوب.
- ردّ بـ JSON صالح فقط، دون Markdown أو شرح إضافي.
PROMPT;
    }

    // النقل والمصادقة يتشاركان callGeminiVisionAPI مع api/vision_ai.php
    $apiResult = callGeminiVisionAPI($imageBase64, $mimeType, $prompt, [
        'maxOutputTokens' => 700,
        'temperature'     => 0.1,
        'topP'            => 0.8,
    ]);

    if (!$apiResult['success']) {
        setGeminiLocalError(
            'فشل التحليل البصري: ' . ($apiResult['message'] ?? 'سبب غير معروف.'),
            $apiResult['model'] ?? GEMINI_MODEL
        );
        return null;
    }

    $parsed = $apiResult['data'];

    // بطاقات الهوية تحتفظ بالصيغة القديمة المتوقعة من الواجهة.
    if ($mode === 'id_card') {
        $parsed['title']       = trim((string)($parsed['title'] ?? 'بطاقة'));
        $parsed['category']    = 'البطاقة الجامعية / الصراف';
        $parsed['student_id']  = normalizeGeminiStudentId($parsed['student_id'] ?? '');
        $parsed['name']        = trim((string)($parsed['name'] ?? ''));
        $parsed['description'] = trim((string)($parsed['description'] ?? ''));
        $parsed['tags']        = array_values(array_unique(array_filter((array)($parsed['tags'] ?? []), fn($v) => trim((string)$v) !== '')));
        $memo[$memoKey] = $parsed;
        return $parsed;
    }

    // من هنا PHP هو الذي يبني العنوان والوصف والوسوم من الحقائق المستخرجة.
    // هذا يمنع Gemini من "تجميل" الوصف أو إضافة خامة/حالة غير مؤكدة.
    $objectType      = trim((string)($parsed['object_type'] ?? ''));
    $primaryColor    = trim((string)($parsed['primary_color'] ?? ''));
    $secondaryColors = array_values(array_filter(array_map('trim', (array)($parsed['secondary_colors'] ?? []))));
    $material        = trim((string)($parsed['material'] ?? ''));
    $brand           = trim((string)($parsed['brand'] ?? ''));
    $visibleText     = trim((string)($parsed['visible_text'] ?? ''));
    $features        = array_values(array_filter(array_map('trim', (array)($parsed['visible_features'] ?? []))));
    $damages         = array_values(array_filter(array_map('trim', (array)($parsed['visible_damage'] ?? []))));
    $studentId       = normalizeGeminiStudentId($parsed['student_id'] ?? '');
    $name            = trim((string)($parsed['name'] ?? ''));

    if ($objectType === '') {
        $objectType = 'غرض غير محدد';
    }

    $allowedCategories = [
        'مقتنيات شخصية', 'إلكترونيات', 'وثائق ومستندات', 'مفاتيح',
        'حقائب وحقائب ظهر', 'ملابس وإكسسوارات', 'البطاقة الجامعية / الصراف', 'أخرى'
    ];
    $category = trim((string)($parsed['category'] ?? 'أخرى'));
    if (!in_array($category, $allowedCategories, true)) {
        $category = 'أخرى';
    }

    // العنوان: نوع + لون + ماركة فقط. لا نضع الخامة في العنوان مهما كانت.
    $titleParts = [$objectType];
    if ($primaryColor !== '') $titleParts[] = $primaryColor;
    if ($brand !== '') $titleParts[] = $brand;
    $title = implode(' ', array_values(array_unique($titleParts)));

    // الوصف يبنى من معلومات مؤكدة فقط، بدون أي استدعاء ذكاء اصطناعي ثانٍ.
    $sentences = [];
    $sentences[] = 'العنصر الظاهر: ' . $objectType . '.';

    $allColors = array_values(array_unique(array_filter(array_merge([$primaryColor], $secondaryColors))));
    if (!empty($allColors)) {
        $sentences[] = 'الألوان الظاهرة: ' . implode('، ', $allColors) . '.';
    }
    if ($brand !== '') {
        $sentences[] = 'العلامة الظاهرة: ' . $brand . '.';
    }
    if ($material !== '') {
        $sentences[] = 'الخامة الظاهرة بوضوح: ' . $material . '.';
    }
    if (!empty($features)) {
        $sentences[] = 'السمات المميزة: ' . implode('، ', array_slice($features, 0, 5)) . '.';
    }
    if ($visibleText !== '') {
        $sentences[] = 'النص الظاهر: ' . $visibleText . '.';
    }
    if (!empty($damages)) {
        $sentences[] = 'علامات ظاهرة: ' . implode('، ', array_slice($damages, 0, 3)) . '.';
    }
    $description = implode(' ', $sentences);

    // الوسوم من نفس الحقائق فقط؛ لا نستخدم الحالة ولا أي كلمات عامة.
    $tagCandidates = array_merge([$objectType, $primaryColor], $secondaryColors, [$brand], array_slice($features, 0, 4));
    $tags = [];
    foreach ($tagCandidates as $tag) {
        $tag = trim((string)$tag);
        if ($tag === '' || $tag === 'غرض غير محدد' || in_array($tag, ['غير واضح', 'غير محدد', 'أخرى'], true)) {
            continue;
        }
        if (!in_array($tag, $tags, true)) {
            $tags[] = $tag;
        }
        if (count($tags) >= 8) break;
    }

    $result = [
        'title'       => $title,
        'category'    => $category,
        'tags'        => $tags,
        'description' => $description,
        'student_id'  => $studentId,
        'name'        => $name,
    ];
    $memo[$memoKey] = $result;
    return $result;
}

/**
 * تحسين وصف البلاغ بالذكاء الاصطناعي
 */
function enhanceDescriptionWithGemini(string $title, string $category, string $description): string {
    // مهم: لا نعيد إرسال وصف الصور إلى Gemini مرة ثانية.
    // الاستدعاء الثاني كان قد يضيف تفاصيل غير موجودة في الصورة ويغيّر النتيجة الصحيحة.
    $description = preg_replace('/\s+/u', ' ', trim($description));
    return $description ?? '';
}

/**
 * تنظيف حقل نصي قبل إرساله داخل مربّع Prompt.
 *
 * يزيل أسطر جديدة (لمنع انتحال أقسام جديدة داخل المربّع) ويحدّ الطول
 * حتى لا يزيح المربّع حجم النافذة المسموح به.
 */
function sanitizePromptField($value, int $max = 160): string {
    $text = preg_replace('/\s+/u', ' ', trim((string) $value));
    $text = str_replace(["\r", "\n", "\t"], ' ', $text);
    if (mb_strlen($text, 'UTF-8') > $max) {
        $text = mb_substr($text, 0, $max, 'UTF-8') . '…';
    }
    return $text;
}

/**
 * ترتيب الزوج إلى (مفقود، معثور عليه) بشكل حتمي.
 *
 * إن لم يكن أحد الطرفين مفقوداً والآخر معثوراً، نحترم الترتيب الوارد
 * حتى لا نخسر البُعد الدلالي المقصود من ترتيب الاستدعاء.
 *
 * @return array{0:array, 1:array, swapped:bool}
 */
function normalizeSemanticPair(array $a, array $b): array {
    $ta = (string) ($a['type'] ?? '');
    $tb = (string) ($b['type'] ?? '');
    if ($ta === 'found' && $tb === 'lost') {
        return [$b, $a, true];
    }
    return [$a, $b, false];
}

/**
 * تقييم دلالي مفصّل لزوج بلاغات عبر Gemini.
 *
 * يعيد مصفوفة نتائج ثابتة الشكل بدل int عارية، لأن محرك المطابقة يحتاج
 * التفسير والاختلافات الجوهرية وليس الرقم وحده:
 *
 * @return array{
 *   available:bool, score:int, reason:string, mismatches:array,
 *   signals:array, error:string, cached:bool
 * }
 */
function geminiSemanticVerdict(array $reportA, array $reportB): array {
    $empty = [
        'available'  => false,
        'score'      => 0,
        'reason'     => '',
        'mismatches' => [],
        'signals'    => [],
        'error'      => 'not_attempted',
        'cached'     => false,
    ];

    if (!isGeminiAvailable()) {
        $empty['error'] = 'missing_api_key';
        return $empty;
    }
    // قاطع الحصة مفتوح: لا نُرسل بلاغات أصلاً، ونعيد نتيجة "غير متاح" فوراً
    // فتبقى حالة الاقتراحات مرئية كما هي، مع الاكتفاء بالمحرك المحلي.
    if (isGeminiRateLimited()) {
        $status = getGeminiBreakerStatus();
        $empty['error'] = $status['reason']; // 'quota_exhausted' أو 'rate_limited'
        return $empty;
    }

    [$lost, $found, $swapped] = normalizeSemanticPair($reportA, $reportB);
    $lostId  = (int) ($lost['id']  ?? 0);
    $foundId = (int) ($found['id'] ?? 0);

    // ذاكرة على مستوى الطلب: نفس الزوج لا يُدفع ثمنه مرتين.
    $memoKey = min($lostId, $foundId) . ':' . max($lostId, $foundId);
    static $memo = [];
    if (isset($memo[$memoKey])) {
        $hit = $memo[$memoKey];
        $hit['cached'] = true;
        return $hit;
    }

    $prompt = "أنت محرك مطابقة دلالي لنظام المفقودات والمعثورات الجامعية \"لُقيا AI\".\n"
        . "قيّم احتمال أن يكون العنصرَان التاليان هو نفس الغرض المادي بالضبط.\n\n"
        . "قواعد التقييم (مهمّة جداً):\n"
        . "- العنصر المعنون يختلف عن وصفه أحياناً؛Race العنوان وحده لا يكفي للحكم.\n"
        . "- الاختلاف في اللون أو الماركة أو الموقع الجغرافي المحدد يقلّل النسبة بشدة.\n"
        . "- تطابق الرقم الجامعي من بيانات البطاقة دليل قوي جداً.\n"
        . "- قارن فقط بالبيانات المكتوبة أدناه؛ لا تستدل من معرفة عامة بالعالم.\n"
        . "- لا تذكر أسماء الطلاب ولا تستنتج ما لم يُذكر.\n\n"
        . "البلاغ المفقود:\n" . geminiReportLine($lost) . "\n\n"
        . "البلاغ المعثور عليه:\n" . geminiReportLine($found) . "\n\n"
        . "أرجع JSON فقط بالشكل التالي ودون أي نص إضافي أو Markdown:\n"
        . '{"score": 0, "reason": "جملة عربية قصيرة تذكر أدلة التوافق",'
        . ' "mismatches": ["اختلاف مذكور فقط"], "signals": ["تطابق مذكور فقط"]}' . "\n"
        . "score: عدد صحيح من 0 إلى 100. reason: من 2 إلى 30 كلمة. "
        . "mismatches و signals: مصفوفتان قد تكونان فارغتين [].";

    $result = callGeminiAPI($prompt, 512);
    if ($result === null || trim($result) === '') {
        $err = getGeminiLastError();
        $empty['error'] = is_array($err) && !empty($err['message'])
            ? (string) $err['message']
            : 'no_response';
        $memo[$memoKey] = $empty;
        return $empty;
    }

    $parsed = decodeGeminiJson($result);
    if (!is_array($parsed)) {
        $empty['error'] = 'unparsable_response';
        $memo[$memoKey] = $empty;
        return $empty;
    }

    $score = max(0, min(100, (int) ($parsed['score'] ?? 0)));
    $reason = sanitizePromptField($parsed['reason'] ?? '', 280);

    $cleanList = static function ($v): array {
        $out = [];
        foreach ((array) $v as $item) {
            $s = sanitizePromptField($item, 80);
            if ($s !== '' && !in_array($s, $out, true)) $out[] = $s;
            if (count($out) >= 5) break;
        }
        return $out;
    };

    $verdict = [
        'available'  => true,
        'score'      => $score,
        'reason'     => $reason,
        'mismatches' => $cleanList($parsed['mismatches'] ?? $parsed['flags'] ?? []),
        'signals'    => $cleanList($parsed['signals'] ?? []),
        'error'      => '',
        'cached'     => false,
    ];

    $memo[$memoKey] = $verdict;
    return $verdict;
}

/**
 * تقييم التشابه الدلالي بين بلاغَين عبر Gemini.
 *
 * غلاف مُبسّط حول geminiSemanticVerdict() يعيد النتيجة رقماً (0..100)
 * لتوافق التوقيع التاريخي. الترتيب غير مهم: يُرتّب الزوج داخلياً
 * حسب نوع البلاغ (مفقود/معثور) قبل الإرسال.
 */
function geminiSemanticScore(array $report1, array $report2): int {
    $verdict = geminiSemanticVerdict($report1, $report2);
    return (int) $verdict['score'];
}

/**
 * تنسيق سطر موجز لبلاغ واحد يُرسَل إلى Gemini (بدون اعتماد على config/matching.php).
 */
function geminiReportLine(array $report): string {
    $line = 'العنوان: ' . trim((string)($report['title'] ?? ''));
    $line .= ' | التصنيف: ' . trim((string)($report['category'] ?? ''));
    $loc = trim((string)($report['location'] ?? ''));
    if ($loc !== '') $line .= ' | الموقع: ' . $loc;
    $features = trim((string)($report['distinctive_features'] ?? ''));
    if ($features !== '') $line .= ' | المميزات: ' . $features;
    $ocr = trim((string)($report['ocr_data'] ?? ''));
    if ($ocr !== '') {
        preg_match('/S\d{9}/i', $ocr, $ocrMatches);
        if (!empty($ocrMatches[0])) {
            $line .= ' | الرقم الجامعي: ' . strtoupper($ocrMatches[0]);
        }
    }
    $desc = preg_replace('/\s+/u', ' ', trim((string)($report['description'] ?? '')));
    if (mb_strlen($desc, 'UTF-8') > 180) {
        $desc = mb_substr($desc, 0, 180, 'UTF-8') . '…';
    }
    if ($desc !== '') $line .= ' | الوصف: ' . $desc;
    return $line;
}

/**
 * التحليل الدلالي لعدة أزواج (مفقود ↔ معثور عليه) عبر Gemini في استدعاء واحد.
 *
 * @param array<int, array{lost:array, found:array}> $pairs
 * @param int $maxPairs   الحد الأقصى للأزواج في الاستدعاء الواحد لتقليل زمن الاستجابة
 * @return array<int, array{score:int, verdict:string, verdict_label:string, reason:string, flags:array}>
 *         مصفوفة مفتاحية بنفس ترتيب الأزواج، وفارغة عند فشل الاتصال أو تعذّر التحليل.
 */
function geminiBatchMatchAnalysis(array $pairs, int $maxPairs = 6): array {
    if (empty($pairs)) return [];
    $pairs = array_slice(array_values($pairs), 0, $maxPairs);

    $lines = [];
    foreach ($pairs as $i => $pair) {
        $lost  = $pair['lost']  ?? [];
        $found = $pair['found'] ?? [];
        $lines[] = "### الزوج " . ($i + 1) . " (مفقود ↔ معثور عليه)\n"
                 . "- مفقود: " . geminiReportLine($lost)
                 . "\n- معثور عليه: " . geminiReportLine($found);
    }

    $prompt = "أنت محرك تحليل ومطابقة ذكي لنظام \"لُقيا AI\" لتتبع المفقودات والمعثورات الجامعية.\n\n"
        . "أمامك " . count($pairs) . " أزواج من البلاغات؛ كل زوج يصف غرضاً مفقوداً وغرضاً معثوراً عليه. قيّم احتمال أن يكون العنصر المعثور عليه هو نفس العنصر المفقود، اعتماداً على البيانات المقدمة فقط (العنوان، التصنيف، الموقع، الوصف، العلامات المميزة، بيانات البطاقة الجامعية).\n\n"
        . implode("\n\n", $lines) . "\n\n"
        . "أرجع JSON فقط كالتالي (مصفوفة بنفس ترتيب الأزواج):\n"
        . '[{"score": 82, "reason": "سبب عربي موجز يسرد أدلة التوافق والاختلاف", "mismatches": ["اختلاف اللون: ..."]}]' . "\n\n"
        . "قواعد إلزامية:\n"
        . "- score: عدد صحيح من 0 إلى 100 يمثل نسبة احتمال تطابق العنصرين.\n"
        . "- reason: جملة أو جملتان بالعربية (2-30 كلمة) تذكر أدلة التوافق: التشابه في النوع/اللون/الماركة/المميزات/بيانات البطاقة، وأهم الاختلافات.\n"
        . "- mismatches: قائمة بالاختلافات الملحوظة فقط مثل (لون مختلف، ماركة مختلفة، موقع مختلف، مادة مختلفة)، وفارغة [] إن لم توجد.\n"
        . "- لا تستدل من المعرفة العامة؛ اعتمد فقط على النصوص المقدمة. لا تذكر أسماء الطلاب.\n"
        . "- ردّ بتنسيق JSON صالح فقط دون أي نص أو Markdown إضافي.";

    $result = callGeminiAPI($prompt, 1024);
    if ($result === null) return [];

    // استخراج المصفوفة أو الكائن المحتوي عليها (نتائج Gemini أحياناً تلفّها في نص Markdown)
    $items = [];
    if (preg_match('/\[.*\]/s', $result, $arrMatch)) {
        $decoded = json_decode($arrMatch[0], true);
        if (is_array($decoded)) $items = $decoded;
    }
    if (empty($items) && preg_match('/\{.*\}/s', $result, $objMatch)) {
        $decoded = json_decode($objMatch[0], true);
        if (is_array($decoded)) {
            if (isset($decoded['results']) && is_array($decoded['results'])) {
                $items = $decoded['results'];
            } elseif (isset($decoded['matches']) && is_array($decoded['matches'])) {
                $items = $decoded['matches'];
            }
        }
    }

    $out = [];
    foreach ($items as $idx => $item) {
        if (!is_array($item)) continue;
        $score = (int) ($item['score'] ?? 0);
        $score = max(0, min(100, $score));
        $reason = trim((string) ($item['reason'] ?? ''));
        if (mb_strlen($reason, 'UTF-8') > 280) {
            $reason = mb_substr($reason, 0, 280, 'UTF-8') . '…';
        }
        $flags = (array) ($item['mismatches'] ?? $item['flags'] ?? []);
        $flags = array_values(array_filter(array_map('trim', $flags), fn($v) => $v !== ''));
        $flags = array_slice(array_values(array_unique($flags)), 0, 5);

        $verdict = $score >= 75 ? 'high' : ($score >= 45 ? 'medium' : 'low');
        $labels  = [
            'high'   => 'احتمال مطابقة مرتفع',
            'medium' => 'احتمال مطابقة متوسط',
            'low'    => 'احتمال مطابقة منخفض',
        ];

        $out[$idx] = [
            'score'         => $score,
            'verdict'       => $verdict,
            'verdict_label' => $labels[$verdict],
            'reason'        => $reason,
            'flags'         => $flags,
        ];
    }

    return $out;
}

/**
 * تحليل دلالي لزوج واحد عبر Gemini (غلاف فوق الاستدعاء الجماعي).
 *
 * @return array{score:int, verdict:string, verdict_label:string, reason:string, flags:array}|[]
 */
function geminiMatchAnalysisOfPair(array $lost, array $found): array {
    $map = geminiBatchMatchAnalysis([['lost' => $lost, 'found' => $found]], 1);
    return $map[0] ?? [];
}

/**
 * البحث الذكي بالنص الحر
 */
function geminiSmartSearch(string $userQuery, array $reports): array {
    if (empty($reports)) {
        return ['ids' => [], 'reason' => 'لا توجد بلاغات نشطة حالياً.'];
    }

    $summaries = [];
    foreach (array_slice($reports, 0, 30) as $r) {
        $summaries[] = "ID:{$r['id']} | النوع:" . ($r['type'] === 'lost' ? 'مفقود' : 'معثور') . " | العنوان:{$r['title']} | التصنيف:{$r['category']} | الموقع:{$r['location']} | الوصف:" . mb_substr($r['description'], 0, 80, 'UTF-8');
    }
    $summaryText = implode("\n", $summaries);

    $prompt = <<<PROMPT
أنت محرك بحث ذكي لنظام "لُقيا AI" لتتبع المفقودات الجامعية.

استفسار المستخدم: "{$userQuery}"
قائمة البلاغات:
{$summaryText}

رد فقط بصيغة JSON:
{"ids":[1,2],"reason":"سبب الاختيار بجملة عربية موجزة"}
PROMPT;

    $result = callGeminiAPI($prompt, 200);
    if ($result === null) {
        return ['ids' => [], 'reason' => 'تعذّر الاتصال بمحرك البحث الذكي حالياً.'];
    }

    preg_match('/\{.*\}/s', trim($result), $jsonMatches);
    if (!empty($jsonMatches[0])) {
        $parsed = json_decode($jsonMatches[0], true);
        if (json_last_error() === JSON_ERROR_NONE && isset($parsed['ids'])) {
            return [
                'ids'    => array_map('intval', (array) $parsed['ids']),
                'reason' => $parsed['reason'] ?? '',
            ];
        }
    }

    return ['ids' => [], 'reason' => 'تعذّر تحليل استفسارك بدقة.'];
}
