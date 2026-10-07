<?php
/**
 * لُقيا AI — محرك المطابقة الذكية الموزون (PHP/MySQL)
 *
 * خوارزمية مطابقة كاملة تعمل على بيانات حقيقية في قاعدة البيانات بقيم موزونة:
 *   - تطابق التصنيف            : وزن مرتفع  (40 نقطة)
 *   - تطابق الموقع             : وزن متوسط  (25 نقطة)
 *   - تطابق النصوص والكلمات    : وزن مرتفع للمواد المميزة مثل (سماعات، شواحن، مفاتيح)
 *   - تطابق العلامات المميزة   : مكافأة إضافية عند تطابق حقل "العلامات المميزة"
 *   - تطابق بيانات OCR (بطاقة) : رافعة إضافية لبطاقات الهوية الجامعية
 *
 * تُستخدم هذه الدوال في:
 *   - المطابقة التلقائية عند تسجيل أي بلاغ جديد (مفقود أو معثور عليه)
 *   - مقترحات لوحة ضباط الأمن للمراجعة والاعتماد
 *   - قسم "المطابقات المحتملة" بلوحة الطالب
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/gemini.php'; // الرافعة الدلالية الاختيارية (لا تُعطِّل المطابقة إن تعذر الاتصال)

// ---- أوزان المطابقة القابلة للضبط ----
define('MATCH_WEIGHT_CATEGORY', 40);              // التصنيف: وزن مرتفع
define('MATCH_WEIGHT_LOCATION', 25);              // الموقع: وزن متوسط
define('MATCH_WEIGHT_TEXT_BASE', 40);             // النصوص المركزية (عنوان+وسوم+مميزات): وزن مرتفع (أساس)
define('MATCH_WEIGHT_TEXT_HIGH', 55);             // النصوص المركزية: وزن أعلى للمواد المميزة (سماعات/شواحن/مفاتيح)
define('MATCH_WEIGHT_DESC', 10);                  // الوصف التفصيلي: قناة مساندة صغيرة
define('MATCH_FEATURES_BONUS_BASE', 15);          // مكافأة العلامات المميزة (أساس)
define('MATCH_FEATURES_BONUS_HIGH', 25);          // مكافأة العلامات المميزة للمواد المميزة
define('MATCH_OCR_BONUS', 30);                    // رافعة مطابقة بطاقات الهوية عبر OCR
define('MATCH_AUTO_THRESHOLD', 75);               // حد اعتماد المطابقة التلقائية
define('MATCH_SUGGEST_THRESHOLD', 45);            // حد عرض الاقتراحات للمراجعة
define('MATCH_STUDENT_THRESHOLD', 75);            // حد عرض الاقتراحات للطالب (الثقة العالية فقط) — تُخفى النسب المنخفضة عن الطالب وتُترك للمراجعة الأمنية

// ---- إعدادات الرافعة الدلالية (Gemini) ----
/**
 * الرافعة الدلالية لا تُلغي المحرك الموزون، بل تعيد ترتيب المرشحين الذين
 * تجاوزوا مسبقاً عتبة المطابقة المحلية. القواعد التي التزمنا بها:
 *
 *  1) Gemini لا يخترع مرشحين: هو يسجّل رأياً على أزواج قائمة فقط.
 *  2) النتيجة النهائية مزيج: local*(1-W) + ai*W — فالنموذج يستطيع خفض
 *     نتيجة تطابق نصّي زائف (نفس الكلمات في وصفين مختلفين) أو رفعها،
 *     لكنه لا يستطيع وحده تجاوز عتبة الاعتماد.
 *  3) تكلفة محدودة: لا استدعاء لكل زوج. نقتصر على أفضل N مرشح محلياً
 *     في استدعاء واحد مجمّع (batch)، ولا نستدعي إطلاقاً تحت عتبة أرضية.
 *  4) عند غياب المفتاح أو فشل الشبكة نعيد النتيجة المحلية كما هي.
 */
define('MATCH_AI_BLEND_WEIGHT', 0.40);            // وزن رأي النموذج داخل المزيج النهائي (0..1)
define('MATCH_AI_MAX_PAIRS', 5);                 // أقصى عدد أزواج يُحلَّل دلالياً في طلب واحد
define('MATCH_AI_MIN_LOCAL', 45);                // أقل نتيجة محلية تستحق دفع كلفة استدعاء النموذج
define('MATCH_AI_VETO_DELTA', 25);               // فارق كافٍ بين النتيجة المحلية ودلالية يُعدّ تناقضاً واضحاً
define('MATCH_AI_MAX_CALLS_PER_REQUEST', 2);    // سقف صلب لعدد استدعاءات Gemini في الطلب الواحد

/**
 * هل الرافعة الدلالية مفعّلة في هذه البيئة؟
 * يمكن تعطيلها كلياً من البيئة دون لمس الكود.
 */
function isSemanticMatchingEnabled(): bool {
    $flag = getenv('AI_MATCHING_ENABLED');
    if ($flag === false || trim((string) $flag) === '') {
        $flag = $_ENV['AI_MATCHING_ENABLED'] ?? '1';
    }
    // isGeminiUnavailableOrLimited() يغطي حالتين معاً:
    //   1) لا يوجد مفتاح إطلاقاً.
    //   2) قاطع الحصة مفتوح بعد 429 ⇒ نتخطى الشبكة فوراً ونبقى على المحرك المحلي.
    // عندها تعمل الصفحة بنفس السرعة وبنفس النتيجة الجيدة، ولا يتعطّل أي عنصر.
    return (trim((string) $flag) !== '0') && isGeminiUnavailableOrLimited() === false;
}

/**
 * حجز موضع في ميزانية استدعاءات Gemini الخاصة بالطلب الحالي.
 *
 * سقف صارم على مستوى الطلب (لا على مستوى الدالة): مهما بلغ عدد
 * استدعاءات المحرك في المسار الواحد، لا تتجاوز الميزانية الحد المعرّف.
 * هذا يحمي من انفجار الكلفة وزمن الاستجابة في الصفحات التي تُصيّر
 * اقتراحات لعدة بلاغات دفعة واحدة (مثل لوحة الطالب).
 */
function claimSemanticAiBudget(int $cost = 1): bool {
    static $used = 0;
    $max = (int) MATCH_AI_MAX_CALLS_PER_REQUEST;
    if ($used + $cost > $max) {
        return false;
    }
    $used += $cost;
    return true;
}

/**
 * هل البلاغان لمواد يرفع لها وزن النصوص والمميزات؟
 * سماعات (أيربود) / شواحن / كابلات / مفاتيح — مواد صغيرة يسهل الخلط بينها.
 */
function isHighPriorityTextCategory(array $lost, array $found): bool {
    $haystack = mb_strtolower(
        ($lost['category'] ?? '') . ' ' . ($lost['title'] ?? '') . ' ' .
        ($found['category'] ?? '') . ' ' . ($found['title'] ?? ''),
        'UTF-8'
    );
    $hits = [
        'سماعة', 'سماعات', 'ايربود', 'أيربود', 'airpod', 'earphone', 'earbuds',
        'شاحن', 'شواحن', 'charger', 'charging', 'كابل', 'كابلات', 'usb',
        'مفاتيح', 'مفتاح', 'keys', 'ريموت'
    ];
    foreach ($hits as $h) {
        if (mb_strpos($haystack, mb_strtolower($h, 'UTF-8')) !== false) {
            return true;
        }
    }
    return false;
}

/**
 * توحيد النص العربي للبحث: توحيد همزات الألف والياء، وإزالة التشكيل والرموز.
 */
function normalizeArabicText(?string $text): string {
    $text = mb_strtolower(trim((string) $text), 'UTF-8');
    $text = str_replace(['أ', 'إ', 'آ', 'ٱ'], 'ا', $text);
    $text = str_replace('ى', 'ي', $text);
    $text = str_replace('ؤ', 'و', $text);
    $text = str_replace('ئ', 'ي', $text);
    $text = preg_replace('/[\x{064B}-\x{0652}]/u', '', $text);           // التشكيل
    $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);              // الرموز
    $text = preg_replace('/\s+/u', ' ', trim($text));
    return $text;
}

/**
 * تجزئة النص إلى كلمات مفتاحية مُطبَّعة (إزالة أدوات الربط وكلمات الأطر القياسية للرؤية الذكية).
 */
function tokenizeForMatching(?string $text): array {
    $stopwords = [
        // أدوات وأفعال عامة
        'في', 'من', 'على', 'عند', 'الى', 'او', 'الى', 'عن', 'قرب', 'بجانب', 'امام', 'خلف',
        'ثم', 'مع', 'هذا', 'هذه', 'التي', 'الذي', 'فيه', 'عليها', 'بها', 'له', 'لي',
        'قد', 'كان', 'كانت', 'يم', 'ان', 'الا', 'لقد', 'بعد', 'قبل', 'حول', 'داخل',
        'خارج', 'مثال', 'غرض', 'غرضي', 'يوجد', 'موجود', 'حيث', 'علي', 'معه', 'بله', 'به',
        'اللون', 'باللون', 'بلون', 'الوان', 'ألوان', 'لون', 'احمر', 'الاحمر', 'كل',
        // كلمات الأطر القياسية الناتجة عن محرك الرؤية الذكية (لا قيمة لها في المطابقة)
        'العنصر', 'عنصر', 'ظاهر', 'الظاهر', 'الظاهرة', 'ظاهرة', 'المرئي', 'مرئي',
        'الحالة', 'الحاله', 'حالة', 'لا', 'توجد', 'اضرار', 'أضرار', 'ضرر', 'ضرر', 'جزء',
        'الجزء', 'سمات', 'السمات', 'مميزة', 'المميزة', 'لاعلاقة', 'ظاهرة', 'بروز',
        'العلامة', 'علامة', 'مقروء', 'مقروءة', 'مطبوعة', 'بالجزء', 'السفلي', 'العلوي', 'العلوية',
        'بحامي', 'الحلزوني', 'حلزوني', 'مغلف', 'وملفوف', 'لدبابيس', 'دبابيس', 'القابس', 'قابس',
        'صغير', 'الصغير', 'الكبير', 'واضح', 'الواضح', 'مين', 'لكل', 'هو', 'هي', 'هنا', 'الا'
    ];
    $tokens = preg_split('/\s+/u', normalizeArabicText($text));
    $out = [];
    foreach ($tokens as $t) {
        $t = trim($t);
        if ($t === '') continue;
        if (mb_strlen($t, 'UTF-8') < 2) continue;

        $variants = [$t];
        // فكّ حروف الجر البادئة لتحسين البصمة: "بالأسود" => "بالأسود" + "اسود"
        if (mb_strpos($t, 'بال') === 0 && mb_strlen($t, 'UTF-8') > 4) {
            $variants[] = mb_substr($t, 2, null, 'UTF-8');
        } elseif (mb_strpos($t, 'ب') === 0 && mb_strlen($t, 'UTF-8') > 4) {
            $variants[] = mb_substr($t, 1, null, 'UTF-8');
        }
        // إزالة ألف التعريف من الجذر
        if (mb_strpos($t, 'ال') === 0 && mb_strlen($t, 'UTF-8') > 3) {
            $variants[] = mb_substr($t, 2, null, 'UTF-8');
        }

        foreach ($variants as $v) {
            if (mb_strlen($v, 'UTF-8') < 2) continue;
            if (in_array($v, $stopwords, true)) continue;
            if (in_array($v, $out, true)) continue;
            $out[] = $v;
        }
    }
    return $out;
}

/**
 * نسبة تطابق الكلمات بين مجموعتي كلمات (مع احتواء Substring).
 */
function tokenOverlapRatio(array $tokensA, array $tokensB): float {
    if (empty($tokensA) || empty($tokensB)) return 0.0;
    $small = count($tokensA) <= count($tokensB) ? $tokensA : $tokensB;
    $large = count($tokensA) <= count($tokensB) ? $tokensB : $tokensA;
    $largeText = implode(' ', $large);
    $hits = 0;
    foreach ($small as $tok) {
        if (in_array($tok, $large, true)) {
            $hits++;
        } elseif (mb_strlen($tok, 'UTF-8') > 2 && mb_strpos($largeText, $tok) !== false) {
            $hits++;
        }
    }
    return $hits / max(1, count($small));
}

/**
 * استخراج الرقم الجامعي SXXXXXXXXX من بيانات OCR إذا وُجد.
 */
function extractStudentIdFromOcr(?string $ocrData): string {
    if (empty($ocrData)) return '';
    preg_match('/S\d{9}/i', $ocrData, $matches);
    return strtoupper($matches[0] ?? '');
}

/**
 * حساب ناتج المطابقة الموزون بين بلاغ مفقود وبلاغ معثور عليه.
 *
 * @return array{score:int, breakdown:array, high_text:bool}
 */
function computeMatchScore(array $lost, array $found): array {
    $breakdown = [
        'category'             => 0,
        'location'             => 0,
        'keywords'             => 0,
        'distinctive_features' => 0,
        'ocr'                  => 0,
    ];

    $highText = isHighPriorityTextCategory($lost, $found);

    // 1) التصنيف — وزن مرتفع
    $catA = mb_strtolower($lost['category'] ?? '', 'UTF-8');
    $catB = mb_strtolower($found['category'] ?? '', 'UTF-8');
    if ($catA !== '' && $catA !== 'أخرى' && $catA === $catB) {
        $breakdown['category'] = MATCH_WEIGHT_CATEGORY;
    }

    // 2) الموقع — وزن متوسط (احتواء متبادل أو تشابه Levenshtein)
    $locA = normalizeArabicText($lost['location'] ?? '');
    $locB = normalizeArabicText($found['location'] ?? '');
    if ($locA !== '' && $locB !== '') {
        if (str_contains($locA, $locB) || str_contains($locB, $locA)) {
            $breakdown['location'] = MATCH_WEIGHT_LOCATION;
        } else {
            $len = max(mb_strlen($locA, 'UTF-8'), mb_strlen($locB, 'UTF-8'));
            if ($len > 4) {
                $dist = levenshtein($locA, $locB);
                if ($dist <= max(2, (int) floor($len * 0.3))) {
                    $breakdown['location'] = MATCH_WEIGHT_LOCATION;
                }
            }
        }
    }

    // 3) النصوص الموزونة:
    //    قناة مركزية عالية الأهمية = العنوان + الوسوم الذكية + العلامات المميزة
    //    قناة مساندة صغيرة = الوصف التفصيلي (تُفلتر الكلمات القياسية للمحافظة على النقاء)
    $textMax = $highText ? MATCH_WEIGHT_TEXT_HIGH : MATCH_WEIGHT_TEXT_BASE;

    $coreA = tokenizeForMatching(
        ($lost['title'] ?? '') . ' ' . ($lost['ai_tags'] ?? '') . ' ' . ($lost['distinctive_features'] ?? '')
    );
    $coreB = tokenizeForMatching(
        ($found['title'] ?? '') . ' ' . ($found['ai_tags'] ?? '') . ' ' . ($found['distinctive_features'] ?? '')
    );
    if (!empty($coreA) && !empty($coreB)) {
        $breakdown['keywords'] = (int) round(tokenOverlapRatio($coreA, $coreB) * $textMax);
    }

    $descA = tokenizeForMatching($lost['description'] ?? '');
    $descB = tokenizeForMatching($found['description'] ?? '');
    if (!empty($descA) && !empty($descB)) {
        $breakdown['keywords'] += (int) round(tokenOverlapRatio($descA, $descB) * MATCH_WEIGHT_DESC);
    }

    // 4) العلامات المميزة — مكافأة إضافية عند تطابق حقل "العلامات المميزة" تحديداً
    $featA = trim((string) ($lost['distinctive_features'] ?? ''));
    $featB = trim((string) ($found['distinctive_features'] ?? ''));
    if ($featA !== '' && $featB !== '') {
        $ftA = tokenizeForMatching($featA);
        $ftB = tokenizeForMatching($featB);
        if (!empty($ftA) && !empty($ftB)) {
            $ratioF = tokenOverlapRatio($ftA, $ftB);
            if ($ratioF > 0) {
                $breakdown['distinctive_features'] = (int) round(
                    $ratioF * ($highText ? MATCH_FEATURES_BONUS_HIGH : MATCH_FEATURES_BONUS_BASE)
                );
            }
        }
    }

    // 5) بطاقات الهوية — رافعة OCR للرقم الجامعي
    $ocrA = extractStudentIdFromOcr($lost['ocr_data'] ?? '');
    $ocrB = extractStudentIdFromOcr($found['ocr_data'] ?? '');
    if ($ocrA !== '' && $ocrA === $ocrB) {
        $breakdown['ocr'] = MATCH_OCR_BONUS;
    }

    $score = $breakdown['category'] + $breakdown['location'] + $breakdown['keywords']
           + $breakdown['distinctive_features'] + $breakdown['ocr'];
    $score = max(0, min(99, $score));

    return ['score' => $score, 'breakdown' => $breakdown, 'high_text' => $highText];
}

/**
 * جلب المرشحين المقابلين (معثور/مفقود) لبلاغ معيّن مع ناتج مطابقة محسوب حقيقياً من قاعدة البيانات.
 *
 * @return array<int, array{opposite:array, score:int, breakdown:array, high_text:bool}>
 */
function findMatchingCandidates(int $reportId, $pdo, int $minScore = MATCH_SUGGEST_THRESHOLD, int $limit = 12): array {
    if (!$pdo) return [];

    $stmt = $pdo->prepare("SELECT * FROM reports WHERE id = ?");
    $stmt->execute([$reportId]);
    $report = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$report) return [];

    $oppositeType = ($report['type'] === 'lost') ? 'found' : 'lost';

    $stmt = $pdo->prepare(
        "SELECT * FROM reports
         WHERE type = ? AND status = 'active' AND id != ?
           AND NOT EXISTS (
               SELECT 1 FROM matches m
               WHERE (m.lost_report_id = ? AND m.found_report_id = reports.id)
                  OR (m.lost_report_id = reports.id AND m.found_report_id = ?)
           )
         ORDER BY created_at DESC
         LIMIT 200"
    );
    $stmt->execute([$oppositeType, $reportId, $reportId, $reportId]);
    $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $results = [];
    foreach ($candidates as $candidate) {
        if ($report['type'] === 'lost') {
            $match = computeMatchScore($report, $candidate);
        } else {
            $match = computeMatchScore($candidate, $report);
        }
        if ($match['score'] >= $minScore) {
            $results[] = [
                'opposite'   => $candidate,
                'score'      => $match['score'],
                'breakdown'  => $match['breakdown'],
                'high_text'  => $match['high_text'],
            ];
        }
    }

    usort($results, fn($a, $b) => $b['score'] <=> $a['score']);
    return array_slice($results, 0, $limit);
}

/**
 * الرافعة الدلالية: إعادة ترتيب المرشحين بمزج النتيجة المحلية مع رأي Gemini.
 *
 * تحترم ثلاثة ضوابط صارمة:
 *   - لا استدعاء لـ Gemini إطلاقاً بدون مفتاح، أو إذا عُطّلت من البيئة.
 *   - استدعاء واحد فقط: زوج مفرد → geminiSemanticVerdict() (مربّع أغنى
 *     يطلب الإشارات)، وعدة أزواج → geminiBatchMatchAnalysis() مجمّعاً.
 *   - ما دون عتبة MATCH_AI_MIN_LOCAL محلياً لا يُدفع ثمنه إطلاقاً؛ يبقى
 *     على نتيجته المحلية دون رأي ذكي.
 *
 * المزيج = round(local × (1 − W) + ai × W) مع W = MATCH_AI_BLEND_WEIGHT.
 * النتيجة النهائية هي 'score' (وهي التي تُقارن بالعتبات)، بينما نحتفظ
 * بـ local_score و ai.score و disagreement للشفافية أمام الضابط.
 *
 * @param array $anchor     صف البلاغ المِرسي كما جاء من قاعدة البيانات
 * @param array $candidates مخرجات findMatchingCandidates مرتبة تنازلياً
 * @return array القائمة نفسها بعد المزج، مرتبة تنازلياً على 'score'
 */
function semanticRerankCandidates(array $anchor, array $candidates): array {
    if (empty($candidates)) {
        return $candidates;
    }
    if (!isSemanticMatchingEnabled()) {
        foreach ($candidates as $i => $c) {
            $candidates[$i]['local_score'] = (int) $c['score'];
            $candidates[$i]['ai']           = null;
        }
        return $candidates;
    }

    $anchorType = (string) ($anchor['type'] ?? 'lost');
    if ($anchorType !== 'lost' && $anchorType !== 'found') {
        return $candidates;
    }

    // نختار أفضل MATCH_AI_MAX_PAIRS مرشحاً تجاوز العتبة الأرضية فقط.
    $eligible = [];
    foreach ($candidates as $idx => $c) {
        if ((int) $c['score'] >= MATCH_AI_MIN_LOCAL) {
            $eligible[$idx] = $c;
        }
    }
    if (empty($eligible)) {
        return $candidates;
    }
    $eligible = array_slice($eligible, 0, MATCH_AI_MAX_PAIRS, true);

    // بناء أزواج (مفقود، معثور) بشكل صحيح مهما كان نوع البلاغ المِرسي.
    $pairs = [];
    foreach ($eligible as $idx => $c) {
        $opposite = $c['opposite'] ?? null;
        if (!is_array($opposite)) continue;
        if ($anchorType === 'lost') {
            $pairs[$idx] = ['lost' => $anchor, 'found' => $opposite];
        } else {
            $pairs[$idx] = ['lost' => $opposite, 'found' => $anchor];
        }
    }
    if (empty($pairs)) {
        return $candidates;
    }

    // استدعاء واحد: مفرد → مسار الزوج الواحد، متعدد → مسار الدفعة.
    // claimSemanticAiBudget يضمن ألّا تتجاوز الصفحة سقف الكلفة مهما تعدّدت.
    $analysis = [];
    if (count($pairs) === 1) {
        $onlyKey = (int) array_key_first($pairs);
        $only    = $pairs[$onlyKey];
        if (!claimSemanticAiBudget()) {
            return $candidates;
        }

        // geminiSemanticVerdict() هي الاستدعاء الفعلي، وهي التي تحمل ذاكرة
        // على مستوى الطلب. geminiSemanticScore() تُقرأ بعده فتُصيب الذاكرة
        // ولا تُكلّف استدعاءً ثانياً — لذلك لا تُفصل النداءتان في الكود.
        $verdict = geminiSemanticVerdict($only['lost'], $only['found']);
        $score   = geminiSemanticScore($only['lost'], $only['found']);

        if (!empty($verdict['available']) && $score > 0) {
            $analysis[$onlyKey] = [
                'score'         => $score,
                'verdict'       => $score >= 75 ? 'high' : ($score >= 45 ? 'medium' : 'low'),
                'verdict_label' => $score >= 75 ? 'احتمال مطابقة مرتفع'
                                 : ($score >= 45 ? 'احتمال مطابقة متوسط' : 'احتمال مطابقة منخفض'),
                'reason'        => (string) $verdict['reason'],
                'flags'         => array_values($verdict['mismatches']),
                'signals'       => array_values($verdict['signals']),
            ];
        }
    } else {
        if (!claimSemanticAiBudget()) {
            return $candidates;
        }
        $list  = array_values($pairs);
        $batch = geminiBatchMatchAnalysis($list, MATCH_AI_MAX_PAIRS);
        $keys  = array_keys($pairs);
        foreach ($batch as $i => $entry) {
            if (is_array($entry) && isset($keys[$i])) {
                $analysis[$keys[$i]] = $entry;
            }
        }
    }

    // المزج وإعادة الترتيب.
    $w = (float) MATCH_AI_BLEND_WEIGHT;
    $updated = $candidates;
    foreach ($candidates as $idx => $c) {
        $localScore = (int) $c['score'];
        $updated[$idx]['local_score'] = $localScore;

        $entry = $analysis[$idx] ?? null;
        if (!is_array($entry)) {
            $updated[$idx]['ai'] = null;
            continue;
        }

        $aiScore = max(0, min(100, (int) ($entry['score'] ?? 0)));
        $blended = (int) round($localScore * (1 - $w) + $aiScore * $w);
        $delta   = $localScore - $aiScore;

        $updated[$idx]['ai'] = array_merge($entry, [
            'score'           => $aiScore,
            'local_score'     => $localScore,
            'blended_score'   => $blended,
            'disagreement'    => $delta,
            'strong_disagreement' => abs($delta) >= MATCH_AI_VETO_DELTA,
        ]);
        $updated[$idx]['score'] = $blended;
    }

    usort($updated, fn($a, $b) => $b['score'] <=> $a['score']);
    return $updated;
}

/**
 * بناء قائمة موحّدة لعناصر المراجعة/المعلقة التي تحتاج إجراءً من ضابط الأمن:
 *   (أ) أغراض معثور عليها بانتظار الاستلام (pending_pickup) أو التوثيق (matched) — جاهزة للتسليم/التحقق.
 *   (ب) مقترحات مطابقة جديدة بين البلاغات النشطة — تحتاج اعتماداً أو رفضاً.
 *
 * تُستخدم هذه الدالة نفسها في عدّاد الشارة الجانبية وفي قائمة المراجعة، لضمان
 * تطابق العدد المعروض في الشارة مع العدد الفعلي القابل للتنفيذ في اللوحة.
 *
 * @return array<int,array> عناصر موحّدة، كل عنصر يحمل المفتاح kind ('pending_pickup' أو 'suggestion').
 */
/**
 * يبني قائمة المراجعة الموحّدة: المعثورات المعلقة + مقترحات المطابقة النشطة.
 *
 * $useSemantic تتحكّم في تشغيل الطبقة الدلالية (Gemini) داخل هذا المسار:
 *   - true  (الافتراضي): يُعاد الترتيب بالدرجة المدمجة local×(1-W) + ai×W.
 *   - false: محلي بالكامل. تُملأ local_score و ai=null بنفس الشكل تماماً،
 *            من دون أي استدعاء شبكي. يُستخدم في مسارات القراءة التي يجب أن
 *            تكون فورية (قائمة المراجعة وعدّادها)، لأن الطبقة الدلالية تُطبَّق
 *            بعدها عبر action=ai_analyze_matches بشكل غير متزامن.
 *
 * ملاحظة: التمرير بـ false لا يغيّر عدد النتائج، لأن إعادة الترتيب لا تغيّر عدد
 * المقترحات التي يمرّها array_slice.
 */
function buildPendingReviewItems($pdo, int $suggestionLimit = 15, int $pendingLimit = 50, bool $useSemantic = true): array {
    if (!$pdo) return [];

    $items = [];
    $seenFoundIds = [];

    // (أ) المعثورات المعلقة: بانتظار الاستلام أو بانتظار التوثيق
    $stmtPending = $pdo->prepare(
        "SELECT r.*, u.full_name AS recorder_name
           FROM reports r
           LEFT JOIN users u ON r.user_id = u.id
          WHERE r.type = 'found' AND r.status IN ('pending_pickup','matched')
          ORDER BY (r.status = 'pending_pickup') DESC, r.created_at DESC
          LIMIT ?"
    );
    $stmtPending->bindValue(1, $pendingLimit, PDO::PARAM_INT);
    $stmtPending->execute();
    foreach ($stmtPending->fetchAll(PDO::FETCH_ASSOC) as $found) {
        $fid = (int) $found['id'];
        if (isset($seenFoundIds[$fid])) continue;
        $seenFoundIds[$fid] = true;

        // الربط بالبلاغ المفقود عبر جدول المطابقات أولاً
        $lost = null;
        $confidence = null;

        $mStmt = $pdo->prepare("SELECT * FROM matches WHERE found_report_id = ? ORDER BY id DESC LIMIT 1");
        $mStmt->execute([$fid]);
        $match = $mStmt->fetch(PDO::FETCH_ASSOC);
        if ($match) {
            $confidence = (int) $match['confidence_score'];
            $lStmt = $pdo->prepare("SELECT r.*, u.full_name AS full_name FROM reports r LEFT JOIN users u ON r.user_id = u.id WHERE r.id = ?");
            $lStmt->execute([(int) $match['lost_report_id']]);
            $lost = $lStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        // احتياطياً: الربط عبر كود الاستلام المشترك
        if (!$lost && !empty($found['pickup_code'])) {
            $lStmt = $pdo->prepare("SELECT r.*, u.full_name AS full_name FROM reports r LEFT JOIN users u ON r.user_id = u.id WHERE r.type = 'lost' AND r.pickup_code = ? LIMIT 1");
            $lStmt->execute([$found['pickup_code']]);
            $lost = $lStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        $items[] = [
            'kind'             => 'pending_pickup',
            'lost'             => $lost,
            'found'            => $found,
            'confidence_score' => $confidence,
            'breakdown'        => null,
            'ai'               => null,
        ];
    }

    // (ب) مقترحات مطابقة جديدة بين البلاغات النشطة
    $semanticOn = $useSemantic && isSemanticMatchingEnabled();
    $stmtLost = $pdo->query("SELECT * FROM reports r JOIN users u ON r.user_id = u.id WHERE r.type = 'lost' AND r.status = 'active' ORDER BY r.created_at DESC");
    $suggestions = [];
    foreach ($stmtLost->fetchAll(PDO::FETCH_ASSOC) as $lost) {
        $candidates = findMatchingCandidates((int) $lost['id'], $pdo, MATCH_SUGGEST_THRESHOLD, 5);

        if ($semanticOn && !empty($candidates)) {
            $candidates = semanticRerankCandidates($lost, $candidates);
        } elseif (!empty($candidates)) {
            // مسار القراءة السريع: نفس شكل ناتج semanticRerankCandidates في
            // فرع "الطبقة الدلالية معطّلة" — درجة محلية فقط ودون استدعاء شبكة.
            foreach ($candidates as $i => $c) {
                $candidates[$i]['local_score'] = (int) $c['score'];
                $candidates[$i]['ai']          = null;
            }
        }

        foreach ($candidates as $c) {
            $found = $c['opposite'];
            $fid = (int) $found['id'];
            if (isset($seenFoundIds[$fid])) continue;
            $seenFoundIds[$fid] = true;
            $suggestions[] = [
                'kind'             => 'suggestion',
                'lost'             => $lost,
                'found'            => $found,
                'confidence_score' => (int) $c['score'],
                'local_score'      => (int) ($c['local_score'] ?? $c['score']),
                'breakdown'        => $c['breakdown'],
                'ai'               => $c['ai'] ?? null,
            ];
        }
    }

    usort($suggestions, fn($a, $b) => $b['confidence_score'] <=> $a['confidence_score']);
    $suggestions = array_slice($suggestions, 0, $suggestionLimit);

    return array_merge($items, $suggestions);
}

/**
 * المطابقة التلقائية عند تسجيل بلاغ جديد:
 *  - يحسب الناتج الموزون ضد كل البلاغات النشطة المقابلة
 *  - عند تجاوز عتبة الاعتماد: ينشئ سجل مطابقة بحالة 'pending'، ويصدر كود استلام،
 *    ويحوّل المعثور إلى 'pending_pickup' والمفقود إلى 'matched'، ويسجّل إشعاراً
 *    لصاحب المفقود بنوع 'match_found'، وسجل نشاط في قاعدة البيانات.
 *
 * ملاحظة: المطابقة التلقائية لا تُغلق البلاغ. إغلاقه يدوي عبر confirm_match
 * أو handover من لوحة ضابط الأمن. كان الكود يضع status='resolved' مباشرة،
 * فتبقى قيمتا 'matched' و'pending_pickup' بلا مسار يكتبهما، فلا تُرجع
 * buildPendingReviewItems أي عنصر للمراجعة.
 */
function runAutomaticMatching(int $newReportId, $pdo): array {
    if (!$pdo) return ['matched' => false];

    ensureDistinctiveFeaturesColumn($pdo);

    $stmt = $pdo->prepare("SELECT * FROM reports WHERE id = ?");
    $stmt->execute([$newReportId]);
    $newReport = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$newReport) return ['matched' => false];

    // مع الرافعة الدلالية نبني قائمة مختصرة من عتبة أرضية أدنى
    // (MATCH_AI_MIN_LOCAL) بدل عتبة الاعتماد مباشرة، حتى يسمح المزج برفع زوج
    // زوج أخطأ الموزون تقديره إلى ما فوق عتبة الاعتماد. القرار يبقى على النتيجة المدمجة.
    $semanticOn   = isSemanticMatchingEnabled();
    $shortlistMin = $semanticOn ? MATCH_AI_MIN_LOCAL : MATCH_AUTO_THRESHOLD;
    $candidates   = findMatchingCandidates($newReportId, $pdo, $shortlistMin, 5);

    if ($semanticOn && !empty($candidates)) {
        $candidates = semanticRerankCandidates($newReport, $candidates);
        // نُسقط ما لم يبلغ عتبة الاعتماد بعد المزج.
        $candidates = array_values(array_filter(
            $candidates,
            fn($c) => (int) ($c['score'] ?? 0) >= MATCH_AUTO_THRESHOLD
        ));
    }

    if (empty($candidates)) {
        return ['matched' => false];
    }

    $best         = $candidates[0];
    $bestScore    = (int) $best['score'];
    $bestOpposite = $best['opposite'];

    $lostId   = ($newReport['type'] === 'lost') ? $newReport['id'] : $bestOpposite['id'];
    $foundId  = ($newReport['type'] === 'found') ? $newReport['id'] : $bestOpposite['id'];
    $lostTitle  = ($newReport['type'] === 'lost') ? $newReport['title'] : $bestOpposite['title'];
    $foundTitle = ($newReport['type'] === 'found') ? $newReport['title'] : $bestOpposite['title'];

    // منع التكرار: إذا وُجدت مطابقة سابقة بين نفس الزوج نتوقف.
    $stmt = $pdo->prepare("SELECT id FROM matches WHERE lost_report_id = ? AND found_report_id = ? LIMIT 1");
    $stmt->execute([$lostId, $foundId]);
    if ($stmt->fetchColumn()) {
        return ['matched' => false, 'reason' => 'duplicate_match'];
    }

    $pickupCode = generateRetrievalCode($pdo);

    $stmt = $pdo->prepare(
        "INSERT INTO matches (lost_report_id, found_report_id, confidence_score, pickup_code, status, resolved_at)
         VALUES (?, ?, ?, ?, 'pending', NULL)"
    );
    $stmt->execute([$lostId, $foundId, $bestScore, $pickupCode]);

    // المعثور ينتظر الاستلام من مكتب الأمن، والمفقود ينتظر التوثيق.
    // كلاهما خارج 'active' فلا يعيد محرك المطابقة اقتراحهما على بلاغات أخرى.
    $stmt = $pdo->prepare(
        "UPDATE reports SET pickup_code = ?,
                status = CASE WHEN type = 'found' THEN 'pending_pickup' ELSE 'matched' END
          WHERE id IN (?, ?)"
    );
    $stmt->execute([$pickupCode, $lostId, $foundId]);

    // إشعار حقيقي لصاحب المفقود
    $lostStmt = $pdo->prepare("SELECT user_id, title FROM reports WHERE id = ?");
    $lostStmt->execute([$lostId]);
    $lostInfo = $lostStmt->fetch(PDO::FETCH_ASSOC);

    if ($lostInfo) {
        $msg = "وجدنا مطابقة ذكية باحتمال {$bestScore}% بين غرضك المفقود \"{$lostInfo['title']}\" والمعثور عليه \"{$foundTitle}\". "
             . 'يرجى إبراز كود التوثيق والاستلام الآمن لمكتب الأمن لاستلام مفقوداتك: ' . $pickupCode . '.';
        $stmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, ?)");
        $stmt->execute([$lostInfo['user_id'], 'تم العثور على مطابقة ذكية!', $msg, 'match_found']);
    }

    // سجل النشاط
    logActivity(
        $pdo,
        'match',
        $foundTitle,
        "طابق محرك المطابقة الموزون البلاغات بنسبة {$bestScore}% وأدرجها بانتظار توثيق ضابط الأمن. كود التوثيق: {$pickupCode}",
        'Luqya AI Matching Engine'
    );

    $bestAi      = is_array($best['ai'] ?? null) ? $best['ai'] : null;
    $bestLocal   = (int) ($best['local_score'] ?? $bestScore);

    return [
        'matched'      => true,
        'confidence'   => $bestScore,
        'pickup_code'  => $pickupCode,
        'local_score'  => $bestLocal,
        'ai_score'     => $bestAi !== null ? (int) $bestAi['score'] : null,
        'ai_verified'  => $bestAi !== null,
        'ai'           => $bestAi,
    ];
}

/**
 * مقترحات لوحة الطالب: أفضل المعثورات المحتملة المطابقة لبلاغات الطالب المفقودة النشطة.
 *
 * الرافعة الدلالية مفعّلة هنا أيضاً، لكن ضمن ميزانية الطلب: لا يتجاوز
 * عدد استدعاءات Gemini في هذه الصفحة MATCH_AI_MAX_CALLS_PER_REQUEST مهما
 * بلغ عدد بلاغات الطالب، حتى تبقى الصفحة سريعة ورخيصة.
 *
 * @return array<int, array{lost:array, found:array, score:int, breakdown:array, ai:?array}>
 */
function getPotentialMatchesForUser(int $userId, $pdo, int $minScore = MATCH_SUGGEST_THRESHOLD, int $limit = 8): array {
    if (!$pdo) return [];

    ensureDistinctiveFeaturesColumn($pdo);

    $stmt = $pdo->prepare("SELECT * FROM reports WHERE user_id = ? AND type = 'lost' AND status = 'active' ORDER BY created_at DESC");
    $stmt->execute([$userId]);
    $lostReports = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($lostReports)) return [];

    $semanticOn = isSemanticMatchingEnabled();
    $seenFoundIds = [];
    $results = [];

    foreach ($lostReports as $lost) {
        $candidates = findMatchingCandidates($lost['id'], $pdo, $minScore, 6);

        if ($semanticOn && !empty($candidates)) {
            $candidates = semanticRerankCandidates($lost, $candidates);
        }

        foreach ($candidates as $c) {
            $found = $c['opposite'];
            if (isset($seenFoundIds[$found['id']])) continue; // كل غرض مُعرَض مرة واحدة بأعلى نسبة
            $seenFoundIds[$found['id']] = true;
            $results[] = [
                'lost'        => $lost,
                'found'       => $found,
                'score'       => (int) $c['score'],
                'local_score' => (int) ($c['local_score'] ?? $c['score']),
                'breakdown'   => $c['breakdown'],
                'ai'          => $c['ai'] ?? null,
            ];
            if (count($results) >= $limit) break 2;
        }
    }

    usort($results, fn($a, $b) => $b['score'] <=> $a['score']);
    return array_slice($results, 0, $limit);
}

/**
 * توسيع مجموعة بلاغات إلى "الزوج الكامل" (المفقود + المعثور عليه) عبر مصدرَي الربط:
 *   1) كود الاستلام المشترك المخزَّن في جدول reports (pickup_code).
 *   2) جدول المطابقات matches (lost_report_id / found_report_id) — يغطي الحالات التي
 *      يختلف فيها الكود أو يحمله طرفٌ واحد فقط.
 *
 * تُستخدم عند التسليم/الإغلاق لضمان تحديث الطرفين معاً وعدم بقاء بلاغ مقابل معلّقاً.
 *
 * @param PDO   $pdo
 * @param int[] $seedIds معرفات ابتدائية (بلاغ واحد يكفي لاستدعاء الزوج كاملاً)
 * @return int[] قائمة معرفات فريدة تشمل البلاغ وقرينه المطابق
 */
function collectMatchedReportIds($pdo, array $seedIds): array {
    if (!$pdo) return [];

    $set = [];
    foreach ($seedIds as $sid) {
        $sid = (int) $sid;
        if ($sid > 0) $set[$sid] = true;
    }
    if (empty($set)) return [];

    // (1) أضف كل البلاغات التي تتشارك أي كود استلام موجود ضمن المجموعة الحالية
    $ids = array_keys($set);
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT DISTINCT pickup_code FROM reports WHERE id IN ($ph) AND pickup_code IS NOT NULL AND pickup_code <> ''");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $code) {
        $s = $pdo->prepare("SELECT id FROM reports WHERE pickup_code = ?");
        $s->execute([$code]);
        foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $rid) {
            $set[(int) $rid] = true;
        }
    }

    // (2) أضف الطرفين من كل سجل مطابقة يخص أي بلاغ ضمن المجموعة
    $ids = array_keys($set);
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT lost_report_id, found_report_id FROM matches WHERE lost_report_id IN ($ph) OR found_report_id IN ($ph)");
    $stmt->execute(array_merge($ids, $ids));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $lost  = (int) $row['lost_report_id'];
        $found = (int) $row['found_report_id'];
        if ($lost > 0)  $set[$lost]  = true;
        if ($found > 0) $set[$found] = true;
    }

    return array_keys($set);
}

/**
 * توحيد حالة الزوج المطابق عند العرض: إذا كان أحد طرفي المطابقة "resolved"
 * (أو كانت المطابقة موثّقة 'verified') تُعاد حالة الطرفين كـ "resolved" معاً،
 * حتى لا تظهر بطاقة "تم الاستلام" وأخرى "بانتظار الاستلام" لنفس الزوج في القوائم.
 *
 * لا تعدّل هذه الدالة قاعدة البيانات؛ إنها تعمل على المصفوفة المعروضة فقط.
 *
 * @param PDO   $pdo
 * @param array $rows صفوف تحمل المفتاحين 'id' و 'status'
 * @return array الصفوف بعد توحيد الحالة
 */
function normalizeMatchedStatuses($pdo, array $rows): array {
    if (!$pdo || empty($rows)) return $rows;

    $index = [];
    foreach ($rows as $i => $r) {
        $id = (int) ($r['id'] ?? 0);
        if ($id > 0) $index[$id] = $i;
    }
    if (empty($index)) return $rows;

    $ids = array_keys($index);
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT lost_report_id, found_report_id, status FROM matches WHERE lost_report_id IN ($ph) OR found_report_id IN ($ph)");
    $stmt->execute(array_merge($ids, $ids));
    $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($matches)) return $rows;

    // حالة الأطراف غير الموجودة في الصفحة الحالية (مثل بلاغ يخص مستخدماً آخر)
    $missing = [];
    foreach ($matches as $m) {
        foreach ([(int) $m['lost_report_id'], (int) $m['found_report_id']] as $pid) {
            if ($pid > 0 && !isset($index[$pid])) $missing[$pid] = true;
        }
    }
    $peerStatus = [];
    if (!empty($missing)) {
        $mids = array_keys($missing);
        $mph  = implode(',', array_fill(0, count($mids), '?'));
        $ps = $pdo->prepare("SELECT id, status FROM reports WHERE id IN ($mph)");
        $ps->execute($mids);
        foreach ($ps->fetchAll(PDO::FETCH_ASSOC) as $pr) {
            $peerStatus[(int) $pr['id']] = $pr['status'];
        }
    }

    $statusOf = function (int $id) use ($index, $rows, $peerStatus) {
        if (isset($index[$id])) return $rows[$index[$id]]['status'] ?? null;
        return $peerStatus[$id] ?? null;
    };

    foreach ($matches as $m) {
        $l = (int) $m['lost_report_id'];
        $f = (int) $m['found_report_id'];
        // 'resolved' ليست قيمة صالحة في matches.status (المعرَّفة: pending/verified/rejected)،
        // فكان هذا الشرط يتضمن قيمة مستحيلة. 'verified' وحدها تعني أن المطابقة موثّقة.
        $matchResolved  = $m['status'] === 'verified';
        $eitherResolved = ($statusOf($l) === 'resolved') || ($statusOf($f) === 'resolved');
        if ($matchResolved || $eitherResolved) {
            if (isset($index[$l])) $rows[$index[$l]]['status'] = 'resolved';
            if (isset($index[$f])) $rows[$index[$f]]['status'] = 'resolved';
        }
    }

    return $rows;
}
