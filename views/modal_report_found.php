<!-- نموذج الإبلاغ عن معثور عليه مع الماسح الضوئي -->
<div class="modal-overlay" id="modalReportFound">
  <div class="modal-container" style="max-width: 600px; border-radius: 16px;">
    <div class="modal-header">
      <h3 class="modal-title" style="display:flex;align-items:center;gap:8px;">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="var(--mint-success)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/>
        </svg>
        الإبلاغ عن معثور عليه (برفع ذكي واحد للعناصر والبطاقات)
      </h3>
      <button class="modal-close" onclick="closeModal('modalReportFound')">&times;</button>
    </div>
    <div class="modal-body">

      <form id="formReportFound" onsubmit="handleReportSubmit(event,'found')">
        <input type="hidden" name="type" value="found">
        <input type="hidden" name="ocr_data" id="ocrExtractedDataHidden">
        <input type="hidden" name="ai_tags" id="aiTagsHiddenFound">

        <div class="form-group">
          <label class="form-label">اسم العنصر / العنوان *</label>
          <input type="text" class="form-control" name="title" id="foundTitle"
                 required oninput="autoGenerateTagsFromInput('Found')">
        </div>

        <div class="form-group">
          <label class="form-label">التصنيف *</label>
          <select class="form-control" name="category" id="reportCategoryFound" required onchange="autoGenerateTagsFromInput('Found')">
            <option value="البطاقة الجامعية / الصراف" selected>البطاقة الجامعية / الصراف</option>
            <option value="مقتنيات شخصية">مقتنيات شخصية</option>
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
          <input type="text" class="form-control" name="extracted_student_id" id="ocrExtractedId" placeholder="S202600000" dir="ltr">
        </div>

        <div class="form-group">
          <label class="form-label">مكان العثور عليه بالتفصيل *</label>
          <input type="text" class="form-control" name="location" required>
        </div>

        <div class="form-group">
          <label class="form-label">تاريخ العثور *</label>
          <input type="date" class="form-control" name="date_event" value="<?= date('Y-m-d'); ?>" required>
        </div>

        <!-- حقل الوصف -->
        <div class="form-group">
          <label class="form-label">الوصف التفصيلي *</label>
          <textarea class="form-control" name="description" id="reportDescFound" rows="3"
                    placeholder="(يتم تحسين الوصف بالذكاء الاصطناعي لمطابقة أدق — يمكنك تعديله بحرية)"
                    required oninput="autoGenerateTagsFromInput('Found')"></textarea>
        </div>

        <!-- العلامات المميزة للغرض المعثور عليه -->
        <div class="form-group">
          <label class="form-label">العلامات المميزة</label>
          <input type="text" class="form-control" name="distinctive_features" id="distinctiveFeaturesFound"
                 placeholder="علامات فريدة"
                 oninput="autoGenerateTagsFromInput('Found')">
        </div>

        <!-- حقل رفع ذكي واحد: صورة عنصر عام أو بطاقة جامعية (يكشف النوع ويستخرج OCR تلقائيًا) -->
        <div class="form-group" id="photoGroupFound">
          <label class="form-label">صورة العنصر المعثور عليه — عنصر عام أو بطاقة جامعية *</label>
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
                <input type="file" name="photo" id="photoFileInputFound" accept="image/*"
                       style="display:none;" onchange="handleSmartUpload(this,'Found')">
              </label>
              <button type="button" class="btn-upload btn-upload-sm" id="reanalyzeBtnFound" style="display:none;margin-top:10px;" onclick="reanalyzeSmartUpload('Found')">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
                إعادة تحليل الصورة
              </button>
            </div>
            <div id="imagePreviewFound" style="margin-top:10px;"></div>
          </div>
        </div>

        <!-- الوسوم الذكية -->
        <div class="form-group" style="background:rgba(0,168,181,0.05);padding:12px;border-radius:10px;border:1px dashed var(--cyan-primary);">
          <strong style="font-size:0.85rem;color:var(--navy-primary);display:flex;align-items:center;gap:6px;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--cyan-primary)" stroke-width="2"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
            الوسوم المستخرجة بالرؤية الذكية
          </strong>
          <div id="aiTagsPreviewFound" class="tags-row" style="margin-top:6px;">
            <span class="ai-tag"> البطاقة الجامعية / الصراف</span>
          </div>
        </div>

        <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:1.5rem;">
          <button type="button" class="btn btn-secondary" onclick="closeModal('modalReportFound')">إلغاء</button>
          <button type="submit" class="btn btn-primary">إرسال البلاغ </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function handleReportSubmit(event, type) {
  event.preventDefault();

  // لا يُرسَل بلاغ ووصفه لا يزال "جاري التحليل..." — ينتظر اكتمال تحليل الصورة
  if (typeof isVisionAnalysisInFlight === 'function' &&
      isVisionAnalysisInFlight(type === 'lost' ? 'Lost' : 'Found')) {
    showToast('جارٍ تحليل الصورة بالذكاء الاصطناعي… انتظر اكتماله ثم أعد الإرسال.', 'danger', 5000);
    return false;
  }

  const form = event.target;
  const formData = new FormData(form);
  formData.append('_csrf', window.LUQYA_CSRF);

  const file1 = formData.get('photo');
  const hasPhoto = !!(file1 && file1.name && file1.size > 0);

  if (!hasPhoto) {
    showToast('يرجى رفع أو التقاط صورة واضحة للعنصر قبل إرسال البلاغ.', 'danger', 5000);
    const photoGroup = document.getElementById(type === 'lost' ? 'photoGroupLost' : 'photoGroupFound');
    if (photoGroup) {
      photoGroup.style.border = '1.5px solid #EF4444';
      photoGroup.style.padding = '10px';
      photoGroup.style.borderRadius = '10px';
    }
    return false;
  }

  fetch('api/reports.php?action=create', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        showToast(data.message, 'success');
        closeModal(type === 'lost' ? 'modalReportLost' : 'modalReportFound');
        if (data.match_info && data.match_info.matched) {
          showToast(' تم اكتشاف مطابقة ذكية بنسبة عالية! تم إرسال كود التوثيق لملفك الشخصي والإشعارات.', 'success', 8000);
        }
        setTimeout(() => window.location.reload(), 1500);
      } else {
        showToast(data.message || 'فشل إرسال البلاغ', 'danger');
      }
    })
    .catch(() => showToast('خطأ في الاتصال بالخادم.', 'danger'));
}
</script>
