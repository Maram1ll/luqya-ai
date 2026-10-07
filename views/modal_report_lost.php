<?php
// RBAC: منع ضباط الأمن من الوصول لنموذج الإبلاغ عن المفقودات
$lostFormRestricted = function_exists('isSecurityOfficer') && isSecurityOfficer();
?>
<!-- نموذج الإبلاغ عن مفقود -->
<div class="modal-overlay" id="modalReportLost">
  <div class="modal-container" style="max-width: 600px; border-radius: 16px;">
    <div class="modal-header">
      <h3 class="modal-title" style="display:flex;align-items:center;gap:8px;">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="var(--cyan-primary)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>
        </svg>
        <?php if ($lostFormRestricted): ?>
          تنبيه
        <?php else: ?>
          الإبلاغ عن عنصر مفقود
        <?php endif; ?>
      </h3>
      <button class="modal-close" onclick="closeModal('modalReportLost')">&times;</button>
    </div>
    <div class="modal-body">
      <?php if ($lostFormRestricted): ?>
        <p style="text-align:center;color:var(--navy-primary);padding:20px;line-height:1.6;">
          عذراً، صلاحية تقديم بلاغات المفقودات مخصصة لطلاب الجامعة فقط.<br>
          يمكنك تسجيل المعثورات عبر لوحة تحكم الأمن.
        </p>
      <?php else: ?>
      <form id="formReportLost" onsubmit="handleReportSubmit(event,'lost')">
        <input type="hidden" name="type" value="lost">
        <input type="hidden" name="ai_tags" id="aiTagsHiddenLost">
        <!-- بيانات OCR المستخرجة من صورة هذا البلاغ فقط (كانت تُكتب في حقول نموذج المعثور) -->
        <input type="hidden" name="ocr_data" id="ocrExtractedDataLost">

        <div class="form-group">
          <label class="form-label">اسم العنصر / العنوان *</label>
          <input type="text" class="form-control" name="title" id="reportTitleLost"
                 required oninput="autoGenerateTagsFromInput('Lost')">
        </div>

        <div class="form-group">
          <label class="form-label">التصنيف *</label>
          <select class="form-control" name="category" id="reportCategoryLost" required onchange="autoGenerateTagsFromInput('Lost')">
            <option value="مقتنيات شخصية">مقتنيات شخصية</option>
            <option value="البطاقة الجامعية / الصراف">البطاقة الجامعية / الصراف</option>
            <option value="إلكترونيات">إلكترونيات</option>
            <option value="وثائق ومستندات">وثائق ومستندات</option>
            <option value="مفاتيح">مفاتيح</option>
            <option value="حقائب وحقائب ظهر">حقائب وحقائب ظهر</option>
            <option value="ملابس وإكسسوارات">ملابس وإكسسوارات</option>
            <option value="أخرى">أخرى</option>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label">الرقم الجامعي المستخرج (تلقائيًا من الصورة أو يدويًا)</label>
          <input type="text" class="form-control" name="extracted_student_id" id="ocrExtractedIdLost" placeholder="S202600000" dir="ltr">
        </div>

        <div class="form-group">
          <label class="form-label">مكان الفقدان المتوقع *</label>
          <input type="text" class="form-control" name="location" required>
        </div>

        <div class="form-group">
          <label class="form-label">تاريخ الفقدان *</label>
          <input type="date" class="form-control" name="date_event" value="<?= date('Y-m-d'); ?>" required>
        </div>

        <!-- حقل الوصف -->
        <div class="form-group">
          <label class="form-label">الوصف التفصيلي *</label>
          <textarea class="form-control" name="description" id="reportDescLost" rows="3"
                    placeholder="(يتم تحسين الوصف بالذكاء الاصطناعي لمطابقة أدق — يمكنك تعديله بحرية)"
                    required oninput="autoGenerateTagsFromInput('Lost')"></textarea>
        </div>

        <!-- العلامات المميزة للغرض المفقود -->
        <div class="form-group">
          <label class="form-label">العلامات المميزة</label>
          <input type="text" class="form-control" name="distinctive_features" id="distinctiveFeaturesLost"
                 placeholder="علامات فريدة"
                 oninput="autoGenerateTagsFromInput('Lost')">
        </div>

        <!-- حقل رفع ذكي واحد: صورة عنصر عام أو بطاقة جامعية (يكشف النوع ويستخرج OCR تلقائيًا) -->
        <div class="form-group" id="photoGroupLost">
          <label class="form-label">صورة العنصر المفقود — عنصر عام أو بطاقة جامعية *</label>
          <div class="ocr-box" style="border-radius:12px;position:relative;overflow:hidden;background:linear-gradient(135deg,var(--navy-primary) 0%,var(--navy-dark) 100%);color:#fff;padding:1.25rem;text-align:center;margin-bottom:0;">
            <div>
              <div style="width:48px;height:48px;background:rgba(0,168,181,0.2);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 8px;">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="var(--cyan-primary)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/>
                </svg>
              </div>
              <p style="margin:0 0 12px;font-size:0.85rem;color:rgba(255,255,255,0.75);line-height:1.6;">رفع ذكي موحد: ارفع صورة العنصر أو البطاقة — وسيتم استخراج البيانات تلقائيًا بالرؤية الذكية.</p>
              <label class="btn-upload">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/><polyline points="16 11 12 15 8 11"/></svg>
                التقاط بالكاميرا أو رفع صورة العنصر / البطاقة
                <input type="file" name="photo" id="photoFileInputLost" accept="image/*"
                       style="display:none;" onchange="handleSmartUpload(this,'Lost')">
              </label>
              <button type="button" class="btn-upload btn-upload-sm" id="reanalyzeBtnLost" style="display:none;margin-top:10px;" onclick="reanalyzeSmartUpload('Lost')">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
                إعادة تحليل الصورة
              </button>
            </div>
            <div id="imagePreviewLost" style="margin-top:10px;"></div>
          </div>
        </div>

        <!-- الوسوم الذكية -->
        <div class="form-group" style="background:rgba(0,168,181,0.05);padding:12px;border-radius:10px;border:1px dashed var(--cyan-primary);">
          <strong style="font-size:0.85rem;color:var(--navy-primary);display:flex;align-items:center;gap:6px;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--cyan-primary)" stroke-width="2"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
            الوسوم المستخرجة بالرؤية الذكية
          </strong>
          <div id="aiTagsPreviewLost" class="tags-row" style="margin-top:6px;">
            <span class="ai-tag"> مقتنيات شخصية</span>
          </div>
        </div>

        <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:1.5rem;">
          <button type="button" class="btn btn-secondary" onclick="closeModal('modalReportLost')">إلغاء</button>
          <button type="submit" class="btn btn-primary">إرسال البلاغ </button>
        </div>
      </form>
      <?php endif; ?>
    </div>
  </div>
</div>
