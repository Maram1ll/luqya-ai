<?php
/**
 * لُقيا AI — محرك المطابقة الذكية
 *
 * طبقة توافق رقيقة تُفوِّض إلى محرك المطابقة في config/matching.php
 * بنفس التوقيع السابق (runAIMatchingEngine) حتى لا تتغير نقاط الاستدعاء.
 */

require_once __DIR__ . '/../config/matching.php';

/**
 * تشغيل محرك المطابقة الذكية على بلاغ جديد (ينشئ / يُحدّث المطابقات والتنبيهات)
 *
 * @param  int    $new_report_id  مُعرَّف البلاغ الجديد
 * @param  object $pdo            اتصال PDO بقاعدة البيانات
 * @return array                  نتيجة المطابقة
 */
function runAIMatchingEngine(int $new_report_id, $pdo): array {
    return runAutomaticMatching($new_report_id, $pdo);
}