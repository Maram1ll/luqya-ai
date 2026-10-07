// لُقيا AI - التفاعل الرئيسي للواجهة ومساعد الذكاء الاصطناعي التفاعلي

document.addEventListener('DOMContentLoaded', () => {

  // ---- زر وقائمة الإشعارات ----
  const bellBtn   = document.getElementById('notifBellBtn');
  const notifDrop = document.getElementById('notifDropdown');
  if (bellBtn && notifDrop) {
    bellBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      notifDrop.classList.toggle('show');
    });
    document.addEventListener('click', () => notifDrop.classList.remove('show'));
  }

  // ---- أكورديون الأسئلة الشائعة ----
  document.querySelectorAll('.accordion-header').forEach(header => {
    header.addEventListener('click', () => {
      const item = header.parentElement;
      item.classList.toggle('open');
    });
  });

  // ---- ربط أزرار فتح مساعد لُقيا AI الذكي ----
  const aiChatBtns = [
    document.getElementById('aiChatOpenBtn'),
    document.getElementById('aiSearchBtn'),
    document.getElementById('aiSearchToggleBtn')
  ].filter(Boolean);

  aiChatBtns.forEach(btn => {
    btn.addEventListener('click', (e) => {
      e.preventDefault();
      openAIChatModal();
    });
  });

  // ---- الفرز والتصفية اللحظية في شبكة المفقودات والمعثورات ----
  const searchInput    = document.getElementById('searchInput');
  const categoryFilter = document.getElementById('categoryFilter');
  const statusFilter   = document.getElementById('statusFilter');
  const typeFilter     = document.getElementById('typeFilter');

  function applyLocalFilters() {
    const q        = (searchInput?.value ?? '').trim().toLowerCase();
    const cat      = (categoryFilter?.value ?? '').toLowerCase();
    const stat     = (statusFilter?.value ?? '').toLowerCase();
    const typ      = (typeFilter?.value ?? '').toLowerCase();
    const cards    = document.querySelectorAll('.item-card');
    const noResult = document.getElementById('noResultsMessage');

    const words = q.split(/\s+/).filter(w => w.length >= 2);
    let visible = 0;

    cards.forEach(card => {
      const cTitle = card.dataset.title || '';
      const cLoc   = card.dataset.location || '';
      const cTags  = card.dataset.tags || '';
      const cText  = `${cTitle} ${cLoc} ${cTags}`.toLowerCase();

      // مطابقة مرنة للكلمات
      const matchQ = words.length === 0 || words.every(w => cText.includes(w));
      const matchCat  = !cat  || (card.dataset.category || '').toLowerCase() === cat;
      const matchStat = !stat || card.dataset.status === stat;
      const matchType = !typ  || card.dataset.type === typ;

      if (matchQ && matchCat && matchStat && matchType) {
        card.style.display = 'flex';
        visible++;
      } else {
        card.style.display = 'none';
      }
    });

    if (noResult) noResult.style.display = visible === 0 ? 'block' : 'none';
  }

  [searchInput, categoryFilter, statusFilter, typeFilter].forEach(el => {
    if (el) {
      el.addEventListener('input', applyLocalFilters);
      el.addEventListener('change', applyLocalFilters);
    }
  });

  // ضغط Enter في حقل البحث يمكنه إما التصفية أو فتح المحادثة إذا احتوى نصاً طويلاً
  if (searchInput) {
    searchInput.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        const val = searchInput.value.trim();
        // إذا كتب المستخدم استفساراً وصفياً طويلاً، افتح المساعد الذكي له تلقائياً
        if (val.length > 15) {
          e.preventDefault();
          openAIChatModal(val);
        }
      }
    });
  }
});

// ---- إدارة النوافذ المنبثقة العامة (Modals) ----

// ربط كل نافذة بنطاق تحليلها: إغلاق النافذة يُلغي تحليلاتها الجارية حتى
// لا تعود نتيجة متأخرة لتعبئة نموذج أُعيد فتحه/تصديره لاحقاً.
const VISION_MODAL_SCOPES = {
  modalReportLost: 'Lost',
  modalReportFound: 'Found',
  modalAddFound: 'SecFound'
};

function invalidateVisionScopeForModal(modalId) {
  const scope = VISION_MODAL_SCOPES[modalId];
  if (scope) invalidateVisionScope(scope);
}

function openModal(id) {
  const modal = document.getElementById(id);
  if (modal) modal.classList.add('show');
  if (id === 'modalReportFound') resetOCRScanner();
  document.body.style.overflow = 'hidden';
}

function closeModal(id) {
  const modal = document.getElementById(id);
  if (modal) modal.classList.remove('show');
  invalidateVisionScopeForModal(id);
  document.body.style.overflow = '';
}

// إغلاق النافذة عند النقر خارج المحتوى
document.addEventListener('click', (e) => {
  if (e.target.classList.contains('modal-overlay')) {
    if (e.target.id) {
      closeModal(e.target.id);
      return;
    }
    e.target.classList.remove('show');
    document.body.style.overflow = '';
  }
});

// التحقق من تسجيل الدخول قبل فتح النماذج
function checkAuthAndOpenModal(modalId) {
  // RBAC: ضباط الأمن لا يستخدمون نماذج الطلاب إطلاقاً — إدارة المعثورات تتم حصرياً عبر لوحة التحكم
  if (window.IS_SECURITY && (modalId === 'modalReportLost' || modalId === 'modalReportFound')) {
    showToast('لوحة تحكم ضابط الأمن مخصصة لإدارة المعثورات فقط. استخدم "تسجيل معثور عليه في المخزون" من لوحة التحكم.', 'danger', 6000);
    return;
  }
  if (window.IS_LOGGED_IN) {
    openModal(modalId);
  } else {
    openModal('modalLoginRequired');
  }
}

// إعادة ضبط فلاتر البحث في الصفحة الرئيسية
function resetSearchFilters() {
  ['searchInput', 'categoryFilter', 'statusFilter', 'typeFilter'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.value = '';
  });
  document.querySelectorAll('.item-card').forEach(card => {
    card.style.display = 'flex';
  });
  const noResult = document.getElementById('noResultsMessage');
  if (noResult) noResult.style.display = 'none';
}


// ==========================================================================
// مساعد لُقيا AI الذكي التفاعلي (Interactive AI Chat Assistant)
// ==========================================================================

let aiChatHistory = [];

/**
 * فتح نافذة محادثة المساعد الذكي
 * @param {string|null} prefillMsg نص استفسار مبدئي (اختياري)
 */
function openAIChatModal(prefillMsg = null) {
  openModal('modalAIChat');

  const chatInput = document.getElementById('aiChatInput');
  const mainSearch = document.getElementById('searchInput');

  // إذا لم يتم تمرير استفسار، نفحص إذا كان المستخدم قد كتب شيئاً في خانة البحث الرئيسية
  const query = prefillMsg || (mainSearch?.value || '').trim();

  if (chatInput) {
    if (query && query.length > 0) {
      chatInput.value = query;
      // إرسال الاستفسار مباشرة لإعطاء تجربة سلسة وفورية
      setTimeout(() => {
        sendAIChatMessage(query);
      }, 350);
    } else {
      setTimeout(() => chatInput.focus(), 200);
    }
  }

  // التمرير لأسفل الرسائل
  scrollChatToBottom();
}

/**
 * إرسال رسالة من الأسئلة السريعة المقترحة (Chips)
 */
function quickSendAIChat(text) {
  const chatInput = document.getElementById('aiChatInput');
  if (chatInput) chatInput.value = text;
  sendAIChatMessage(text);
}

/**
 * معالجة إرسال النموذج عند الضغط على Enter أو زر الإرسال
 */
function handleAIChatSubmit(event) {
  event.preventDefault();
  const input = document.getElementById('aiChatInput');
  const msg = input?.value?.trim();
  if (msg) {
    sendAIChatMessage(msg);
    input.value = '';
  }
}

/**
 * إرسال رسالة المستخدم إلى api/ai_chat.php وعرض الرد مع مؤشر الكتابة
 */
async function sendAIChatMessage(messageText) {
  const text = (messageText || '').trim();
  if (!text) return;

  const chatMessages = document.getElementById('aiChatMessages');
  const chatInput = document.getElementById('aiChatInput');
  const sendBtn = document.getElementById('aiChatSendBtn');
  const typingIndicator = document.getElementById('aiChatTyping');

  // 1. إضافة رسالة المستخدم إلى الواجهة
  appendChatMessage('user', text);
  if (chatInput) chatInput.value = '';

  // 2. تفعيل مؤشر الكتابة التفاعلي وتعطيل زر الإرسال مؤقتاً
  if (typingIndicator) typingIndicator.style.display = 'flex';
  if (sendBtn) sendBtn.disabled = true;
  scrollChatToBottom();

  // تسجيل الرسالة في السجل
  aiChatHistory.push({ role: 'user', content: text });

  try {
    const response = await fetch('api/ai_chat.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json'
      },
      body: JSON.stringify({
        message: text,
        history: aiChatHistory.slice(-6) // إرسال آخر 6 رسائل للسياق
      })
    });

    const rawText = await response.text();
    let data;
    try {
      data = JSON.parse(rawText);
    } catch (parseError) {
      console.error('[Luqya AI Chat JSON Parse Error]', parseError, rawText);
      if (typingIndicator) typingIndicator.style.display = 'none';
      const cleanSnippet = (rawText || '').replace(/<[^>]*>?/gm, ' ').trim().substring(0, 250);
      appendChatMessage('bot', 'خطأ في استجابة الخادم: ' + (cleanSnippet || parseError.message));
      return;
    }

    // إخفاء مؤشر الكتابة
    if (typingIndicator) typingIndicator.style.display = 'none';

    if (data.success && data.reply) {
      // 3. إضافة رد الذكاء الاصطناعي للواجهة
      appendChatMessage('bot', data.reply);
      aiChatHistory.push({ role: 'assistant', content: data.reply });
    } else {
      appendChatMessage('bot', data.message || 'عذراً، حدث خطأ أثناء معالجة الرد، يرجى المحاولة مرة أخرى.');
    }

  } catch (error) {
    console.error('[Luqya AI Chat Error]', error);
    if (typingIndicator) typingIndicator.style.display = 'none';
    appendChatMessage('bot', 'خطأ في الاتصال: ' + (error.message || 'تعذّر الوصول إلى خادم الذكاء الاصطناعي'));
  } finally {
    if (sendBtn) sendBtn.disabled = false;
    if (chatInput) chatInput.focus();
    scrollChatToBottom();
  }
}

/**
 * إضافة فقاعة رسالة جديدة إلى شاشة المحادثة
 * @param {'user'|'bot'} sender 
 * @param {string} rawText 
 */
function appendChatMessage(sender, rawText) {
  const chatMessages = document.getElementById('aiChatMessages');
  if (!chatMessages) return;

  const msgDiv = document.createElement('div');
  msgDiv.className = `chat-msg ${sender}-msg`;

  const avatarDiv = document.createElement('div');
  avatarDiv.className = 'chat-msg-avatar';
  avatarDiv.innerHTML = sender === 'user' ? '' : '';

  const bubbleDiv = document.createElement('div');
  bubbleDiv.className = 'chat-msg-bubble';

  if (sender === 'bot') {
    bubbleDiv.innerHTML = formatMarkdownToHTML(rawText);
  } else {
    bubbleDiv.textContent = rawText;
  }

  if (sender === 'user') {
    msgDiv.appendChild(avatarDiv);
  }
  msgDiv.appendChild(bubbleDiv);
  chatMessages.appendChild(msgDiv);

  scrollChatToBottom();
}

/**
 * تهريب نص المستخدم/المساعد قبل إدراجه في HTML لمنع حقن الوسوم
 */
function escapeHtml(value) {
  return String(value === null || value === undefined ? '' : value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

/**
 * تحويل نصوص المساعد (Markdown خفيف) إلى HTML منسق
 */
function formatMarkdownToHTML(text) {
  if (!text) return '';

  let html = escapeHtml(text)
    // إبراز اسم العلامة لُقيا AI دون ترجمته
    .replace(/luqya-?ai|لُ?قْ?يا\s*AI/gi, '<span translate="no" class="notranslate brand-wordmark" dir="rtl" style="direction:rtl;display:inline-block;unicode-bidi:isolate;font-weight:700;color:#00A8B5;">لُقيا AI</span>')
    // تحويل **النص العريض**
    .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
    // تحويل *النص المائل*
    .replace(/\*(.*?)\*/g, '<em>$1</em>')
    // تحويل القوائم النقطية
    .replace(/^[•\-\*]\s+(.*)$/gm, '<li style="margin-bottom:4px;">$1</li>')
    // تحويل الأسطر الجديدة
    .replace(/\n\n/g, '<br><br>')
    .replace(/\n/g, '<br>');

  // تغليف عناصر القوائم بـ ul
  html = html.replace(/(<li style="margin-bottom:4px;">.*<\/li>)/gs, '<ul style="padding-inline-start:20px;margin:8px 0;">$1</ul>');

  return html;
}

/**
 * التمرير التلقائي لأسفل نافذة المحادثة
 */
function scrollChatToBottom() {
  const body = document.getElementById('aiChatBody');
  if (body) {
    setTimeout(() => {
      body.scrollTop = body.scrollHeight;
    }, 50);
  }
}

/**
 * إعادة تعيين المحادثة وبدء جلسة جديدة
 */
function resetAIChatHistory() {
  aiChatHistory = [];
  const chatMessages = document.getElementById('aiChatMessages');
  if (chatMessages) {
    // الإبقاء على أول رسالة ترحيبية فقط وحذف الباقي
    const firstMsg = chatMessages.firstElementChild;
    chatMessages.innerHTML = '';
    if (firstMsg) {
      chatMessages.appendChild(firstMsg);
    }
  }
  const chatInput = document.getElementById('aiChatInput');
  if (chatInput) {
    chatInput.value = '';
    chatInput.focus();
  }
  showToast('تم بدء جلسة محادثة جديدة مع مساعد لُقيا AI.', 'success', 3000);
}


// ==========================================================================
// الرؤية الذكية واستخراج الرقم الجامعي (Gemini Vision OCR)
// ==========================================================================
// يضمن إرسال الصورة بصيغة مدعومة وحجم مناسب، وتعبئة الرقم الجامعي المستخرج
// تلقائياً في حقل .#ocrExtractedId عند مسح/تحليل صورة بطاقة الطالب.

const VISION_API_URL = 'api/vision_ai.php';

// ==========================================================================
// صورة نشطة واحدة لكل نموذج (تخصيص نتيجة التحليل لنموذجها)
// ==========================================================================
// رفع صورتين متتاليتين (أو مسح بطاقة أثناء تحليل صورة) كان يُطلق تحليلين في
// نفس الوقت، وتأخر أي استجابة كان يعيد كتابة العنوان والوصف والوسوم وبيانات
// OCR وبطاقة المعاينة من الصورة السابقة فوق الصورة المعروضة حاليًا.
// الحل: رمز تسلسلي واحد لكل نموذج — أي بدء تحليل جديد يُلغي السابق فورًا،
// ولا تُسمح نتيجة ملغاة (متأخرة) بمسّ أي عنصر في النموذج أو المعاينة.
const visionScopes = { Lost: 0, Found: 0, SecFound: 0 };
const visionScopeBusy = { Lost: false, Found: false, SecFound: false };

// الماسح الضوئي للبطاقة ينتمي لنفس نموذج "معثور" لأنه يكتب في الحقول نفسها
// (العنوان/الوصف/التصنيف/ocr_data) — فلا يعمل تحليلان في نموذج واحد معًا.
const CARD_SCAN_SCOPE = 'Found';

// تصنيف البطاقة الجامعية الموحّد في كل النماذج الثلاثة
const CARD_CATEGORY = 'البطاقة الجامعية / الصراف';

function beginVisionScope(scopeKey) {
  visionScopes[scopeKey] = (visionScopes[scopeKey] || 0) + 1;
  visionScopeBusy[scopeKey] = true;
  return visionScopes[scopeKey];
}

function isVisionScopeCurrent(scopeKey, token) {
  return visionScopes[scopeKey] === token;
}

function endVisionScope(scopeKey, token) {
  if (visionScopes[scopeKey] === token) visionScopeBusy[scopeKey] = false;
}

function invalidateVisionScope(scopeKey) {
  visionScopes[scopeKey] = (visionScopes[scopeKey] || 0) + 1;
  visionScopeBusy[scopeKey] = false;
}

function isVisionAnalysisInFlight(scopeKey) {
  if (scopeKey) return !!visionScopeBusy[scopeKey];
  return !!(visionScopeBusy.Lost || visionScopeBusy.Found || visionScopeBusy.SecFound);
}

// رسائل الحالة أثناء التحليل (يتم التعرف عليها لتحريرها لاحقًا بأمان)
const VISION_LOADING_TITLE = ' جاري التحليل...';
const VISION_LOADING_DESC = ' جاري تحليل الصورة واستخراج البيانات...';
const VISION_LOADING_TAGS = '<span class="ai-tag" style="opacity:0.6;"> جاري الاستخراج...</span>';

// حقل الرقم الجامعي: رسالة مهذبة عند تحليل صورة عنصر عام لا يتطلب رقمًا جامعيًا
const OCR_ID_PLACEHOLDER = 'S202600000';
const OCR_NO_REQUIRED_HINT = 'لا يتطلب رقم جامعي';

function isSupportedImageType(type) {
  return type === 'image/jpeg' || type === 'image/png' || type === 'image/webp' || type === 'image/jpg';
}

/**
 * تجهيز الصورة قبل الإرسال: HEIC/الصور الضخمة تُحوَّل إلى JPEG مضغوط
 * لضمان قبولها من خادم Gemini Vision ومعالجتها بنجاح.
 */
function prepareImageForVision(file) {
  if (isSupportedImageType(file.type) && file.size <= 2.5 * 1024 * 1024) {
    return Promise.resolve(file);
  }

  return new Promise((resolve, reject) => {
    const url = URL.createObjectURL(file);
    const img = new Image();
    img.onload = () => {
      const scale = Math.min(1, 1800 / Math.max(img.naturalWidth, img.naturalHeight));
      const canvas = document.createElement('canvas');
      canvas.width = Math.max(1, Math.round(img.naturalWidth * scale));
      canvas.height = Math.max(1, Math.round(img.naturalHeight * scale));
      const ctx = canvas.getContext('2d');
      ctx.fillStyle = '#ffffff';
      ctx.fillRect(0, 0, canvas.width, canvas.height);
      ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
      URL.revokeObjectURL(url);
      canvas.toBlob((blob) => {
        if (!blob) { reject(new Error('تعذر تحويل الصورة إلى صيغة JPEG.')); return; }
        const name = file.name.replace(/\.[^.]+$/, '') + '_optimized.jpg';
        resolve(new File([blob], name, { type: 'image/jpeg', lastModified: Date.now() }));
      }, 'image/jpeg', 0.92);
    };
    img.onerror = () => {
      URL.revokeObjectURL(url);
      reject(new Error('تعذر قراءة الصورة المرفوعة.'));
    };
    img.src = url;
  });
}

/**
 * تطبيع الرقم الجامعي مهما كانت صيغة عودته من النموذج:
 * - بحرف S: S000000001  -> S000000001
 * - أرقام فقط: 202402461 -> 202402461
 */
function normalizeUniversityId(raw) {
  if (raw === null || raw === undefined) return '';
  const value = String(raw).replace(/[\s\-–—]/g, '').trim();
  if (!value) return '';
  const withS = value.match(/S\d{9}/i);
  if (withS) return withS[0].toUpperCase();
  const digits = value.match(/\d{9}/);
  if (digits) return digits[0];
  return value;
}

/**
 * إرسال الصورة إلى api/vision_ai.php ومعالجة الاستجابة بأمان
 * (تتفادى أخطاء تحليل JSON القادمة من الخادم بقراءة نصية أولاً).
 */
async function sendImageToVision(file, mode, reportType) {
  const optimized = await prepareImageForVision(file);
  const formData = new FormData();
  formData.append('image', optimized);
  formData.append('mode', mode);
  formData.append('report_type', reportType);
  formData.append('type', reportType);

  const response = await fetch(VISION_API_URL, { method: 'POST', body: formData });
  const rawText = await response.text();
  let payload;
  try {
    payload = JSON.parse(rawText);
  } catch (parseError) {
    throw new Error('استجابة غير صالحة من الخادم: ' + rawText.replace(/<[^>]*>?/g, ' ').trim().substring(0, 200));
  }
  if (!response.ok || !payload.success || !payload.data) {
    throw new Error(payload.message || ('فشل تحليل الصورة (HTTP ' + response.status + ').'));
  }
  return payload.data;
}

/**
 * تحديد معرفات عناصر النموذج المستهدفة حسب نوع النموذج (طالب / أمن).
 * يسمح باستخدام نفس منطق التحليل والتعبئة في نموذج الطالب ونافذة الأمن.
 */
function resolveVisionTargets(modalType) {
  if (modalType === 'SecFound') {
    return {
      reportType: 'found',
      title: 'secItemTitle',
      desc: 'secItemDesc',
      category: 'secItemCategory',
      tags: 'secItemTagsPreview',
      hiddenTags: 'secItemTags',
      preview: 'secImagePreviewBox',
      ocrId: 'secExtractedStudentId',
      ocrHidden: 'secItemOcrData',
      savedImage: 'secItemSavedImagePath'
    };
  }
  const isLost = modalType === 'Lost';
  return {
    reportType: isLost ? 'lost' : 'found',
    title: isLost ? 'reportTitleLost' : 'foundTitle',
    desc: isLost ? 'reportDescLost' : 'reportDescFound',
    category: isLost ? 'reportCategoryLost' : 'reportCategoryFound',
    tags: isLost ? 'aiTagsPreviewLost' : 'aiTagsPreviewFound',
    hiddenTags: isLost ? 'aiTagsHiddenLost' : 'aiTagsHiddenFound',
    preview: isLost ? 'imagePreviewLost' : 'imagePreviewFound',
    // حقل الرقم الجامعي المرئي موجود في النموذجين (مفقود ومعثور) مع حقله
    // المخفي الخاص بكل نموذج — لا تتم كتابة بيانات OCR نموذج لآخر.
    ocrId: isLost ? 'ocrExtractedIdLost' : 'ocrExtractedId',
    ocrHidden: isLost ? 'ocrExtractedDataLost' : 'ocrExtractedDataHidden',
    savedImage: null
  };
}

/**
 * تعبئة النموذج بنتائج التحليل البصري وإبراز الرقم الجامعي في حقل الإدخال
 */
function applyVisionResultToForm(aiData, modalType) {
  const targets = resolveVisionTargets(modalType);
  const titleEl = document.getElementById(targets.title);
  const descEl  = document.getElementById(targets.desc);
  const catEl   = document.getElementById(targets.category);
  const tagsEl  = document.getElementById(targets.tags);
  const hiddenTagsEl = document.getElementById(targets.hiddenTags);

  if (titleEl) titleEl.value = aiData.title || 'بطاقة جامعية';
  if (descEl) descEl.value = aiData.description || '';

  if (catEl && aiData.category) {
    const option = Array.from(catEl.options).find(o =>
      o.value === aiData.category ||
      o.value.includes(aiData.category) ||
      aiData.category.includes(o.value)
    );
    if (option) catEl.value = option.value;
  }

  if (tagsEl && Array.isArray(aiData.tags) && aiData.tags.length) {
    tagsEl.innerHTML = aiData.tags.map(tag => `<span class="ai-tag"> ${tag}</span>`).join(' ');
  }
  if (hiddenTagsEl) {
    hiddenTagsEl.value = (Array.isArray(aiData.tags) ? aiData.tags : []).join('، ');
  }

  // عرض الرقم الجامعي المستخرج تلقائياً في حقل الإدخال
  // (visionSrc = 'photo' يعني أن البيانات أنتجها تحليل صورة العنصر، فيُمسح
  //  تلقائيًا عند اختيار صورة جديدة حتى لا ترث صورة جديدة بيانات صورة قديمة)
  const studentId = normalizeUniversityId(aiData.student_id);
  if (studentId && targets.ocrId) {
    const idInput = document.getElementById(targets.ocrId);
    if (idInput) {
      idInput.value = studentId;
      idInput.dataset.visionSrc = 'photo';
    }
  }
  if (studentId && targets.ocrHidden) {
    const idHidden = document.getElementById(targets.ocrHidden);
    if (idHidden) {
      idHidden.value = JSON.stringify({ student_id: studentId, name: aiData.name || '' });
      idHidden.dataset.visionSrc = 'photo';
    }
  }

  // مسار الأمن: إرفاق مسار الصورة المحفوظة على الخادم تلقائياً
  // (visionSrc='photo' حتى لا يرث مسار صورة قديمة من اختيار صورة أحدث فشل تحليلها)
  if (targets.savedImage && aiData.image_path) {
    const savedEl = document.getElementById(targets.savedImage);
    if (savedEl) {
      savedEl.value = aiData.image_path;
      savedEl.dataset.visionSrc = 'photo';
    }
  }

  // اكتشاف بطاقة جامعية تلقائيًا في كل النماذج: استخراج رقم جامعي ناجح من الصورة
  // يعني أن الصورة بطاقة، فيُثبَّت تصنيف البطاقة دون تدخل المستخدم.
  if (studentId && catEl) {
    catEl.value = CARD_CATEGORY;
  }

  return studentId;
}

/**
 * دمج الرقم الجامعي من حقل مرئي في حقل ocr_data المخفي (JSON) دون فقدان
 * باقي المفاتيح (مثل اسم صاحب البطاقة المستخرج من الماسح).
 */
function syncStudentIdToOcrHidden(idInput, hidden) {
  if (!idInput || !hidden) return;

  let data = {};
  try {
    data = hidden.value ? JSON.parse(hidden.value) : {};
  } catch (e) {
    data = {};
  }
  if (!data || typeof data !== 'object' || Array.isArray(data)) data = {};

  const sid = normalizeUniversityId(idInput.value);
  if (sid) {
    data.student_id = sid;
  } else {
    delete data.student_id;
  }
  hidden.value = Object.keys(data).length ? JSON.stringify(data) : '';
}

/**
 * مزامنة حقل الرقم الجامعي المرئي في نافذة الأمن مع بيانات OCR المخزَّنة في الحقل المخفي،
 * لضمان حفظه في قاعدة البيانات سواء أُدخل يدوياً أو استُخرج تلقائياً بالرؤية الذكية.
 */
function syncSecOcrData() {
  const idInput = document.getElementById('secExtractedStudentId');
  const hidden  = document.getElementById('secItemOcrData');
  if (!idInput || !hidden) return;
  syncStudentIdToOcrHidden(idInput, hidden);
}

/**
 * عرض معاينة فورية للصورة المرفوعة/المُلتقطة داخل الحاوية مع شريط حالة حي.
 * يُرجع مرجع شريط الحالة لتحديثه أثناء التحليل وبعده، أو null إذا أُلغي العرض
 * (options.isActive يرجع false = صورة أحدث/إغلاق النافذة تسبق هذا العرض،
 *  وعندها لا تُلمس الحاوية حتى لا تحل المعاينة القديمة محل المعاينة النشطة).
 * @param {File} file الملف المرفوع
 * @param {HTMLElement|null} container الحاوية المستهدفة
 * @param {string} loadingText نص الحالة أثناء التحليل
 * @param {{beam?: boolean, isActive?: () => boolean}} [options] beam:true تُضاف موجة المسح فوق الصورة
 */
function renderImagePreview(file, container, loadingText, options = {}) {
  return new Promise((resolve, reject) => {
    if (!container) { reject(new Error('حاوية المعاينة غير متوفرة.')); return; }
    if (typeof options.isActive === 'function' && !options.isActive()) { resolve(null); return; }

    const reader = new FileReader();
    reader.onload = (e) => {
      if (typeof options.isActive === 'function' && !options.isActive()) { resolve(null); return; }
      const wrap = document.createElement('div');
      wrap.className = 'ocr-preview vision-preview';
      wrap.style.cssText = 'background:rgba(255,255,255,0.06);border:1px solid rgba(0,168,181,0.45);border-radius:12px;padding:10px;max-width:250px;margin:10px auto 0;text-align:center;color:#FFFFFF;box-shadow:0 4px 16px rgba(0,0,0,0.35);';

      const img = document.createElement('img');
      img.src = e.target.result;
      img.style.cssText = 'max-width:100%;max-height:220px;border-radius:8px;display:block;margin:0 auto;';
      img.alt = 'معاينة الصورة المرفوعة';

      const status = document.createElement('div');
      status.style.cssText = 'margin-top:8px;font-size:0.83rem;font-weight:700;color:var(--cyan-primary);display:flex;align-items:center;justify-content:center;gap:6px;';

      const dot = document.createElement('span');
      dot.style.cssText = 'width:12px;height:12px;border-radius:50%;background:var(--cyan-primary);display:inline-block;animation:corePulse 0.8s infinite alternate;';

      const text = document.createElement('span');
      text.textContent = loadingText;

      status.appendChild(dot);
      status.appendChild(text);
      wrap.appendChild(img);
      wrap.appendChild(status);

      let beam = null;
      if (options.beam) {
        beam = document.createElement('div');
        beam.className = 'ocr-beam scanning';
        wrap.appendChild(beam);
      }

      container.innerHTML = '';
      container.appendChild(wrap);
      resolve({ text, dot, img, beam });
    };
    reader.onerror = () => reject(new Error('تعذر قراءة الصورة المرفوعة.'));
    reader.readAsDataURL(file);
  });
}

/**
 * تحديث شريط حالة المعاينة بعد اكتمال التحليل أو فشله.
 * تُزال موجة المسح الضوئي فور اكتمال التحليل.
 * ok: true = نجاح، false = خطأ، null = محايدة (تحليل مُلغى/مستبدل).
 */
function setPreviewStatus(statusRef, text, ok) {
  if (!statusRef) return;
  if (statusRef.beam) { statusRef.beam.remove(); statusRef.beam = null; }
  if (!statusRef.text || !statusRef.dot) return;
  const color = (ok === null || ok === undefined) ? 'var(--text-muted)' : (ok ? 'var(--mint-success)' : '#EF4444');
  statusRef.text.textContent = text;
  statusRef.dot.style.animation = 'none';
  statusRef.dot.style.background = color;
  statusRef.text.style.color = color;
}

/** ترميز آمن لقيم الرؤية قبل إدراجها في HTML (لا يعتمد على دوال الصفحات). */
function visionEscapeHtml(value) {
  return String(value === null || value === undefined ? '' : value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

/**
 * بطاقة نتيجة التحليل داخل حاوية المعاينة الموحّدة:
 * - صورة بطاقة جامعية (رقم جامعي مستخرج) → بطاقة OCR خضراء بالرقم والاسم.
 * - صورة عنصر عام → بطاقة نجاح بسيطة.
 */
function appendVisionResultCard(container, studentId, holderName, description) {
  if (!container) return;
  if (studentId) {
    container.insertAdjacentHTML('beforeend', `
      <div class="ocr-result-card" style="background:rgba(76,217,161,0.12);border:1.5px solid var(--mint-success);border-radius:10px;padding:12px;margin-top:8px;">
        <div style="font-weight:800;color:var(--mint-success);margin-bottom:6px;">تم استخراج بيانات البطاقة تلقائيًا عبر Gemini Vision OCR</div>
        <div><strong>الرقم الجامعي المستخرج:</strong> <code style="color:var(--cyan-primary);font-size:1.05rem;font-weight:800;">${visionEscapeHtml(studentId)}</code></div>
        ${holderName ? `<div><strong>اسم صاحب البطاقة:</strong> ${visionEscapeHtml(holderName)}</div>` : ''}
        ${description ? `<div style="font-size:0.85rem;color:var(--text-muted);margin-top:4px;">${visionEscapeHtml(description)}</div>` : ''}
      </div>`);
    return;
  }
  container.insertAdjacentHTML('beforeend', `
    <div style="background:rgba(76,217,161,0.12);border:1.5px solid var(--mint-success);padding:10px 14px;border-radius:10px;margin-top:8px;font-size:0.86rem;color:#FFFFFF;">
      <strong style="color:var(--mint-success);"> تم تحليل الصورة بنجاح</strong>
    </div>`);
}

// ---- أدوات إدارة حالة التحميل والـ OCR داخل النموذج ----

function visionFieldElements(targets) {
  return {
    title: document.getElementById(targets.title),
    desc: document.getElementById(targets.desc),
    tags: document.getElementById(targets.tags)
  };
}

/**
 * وضع حقول النموذج في حالة "جارٍ التحليل" مع توقيعها برمز التحليل الحالي،
 * حتى لا يحرّر تحليل ملغٍّ (متأخر) حقولًا تديرها عملية أحدث.
 */
function setVisionFieldsLoading(targets, token) {
  const els = visionFieldElements(targets);
  if (els.title) { els.title.value = VISION_LOADING_TITLE; els.title.disabled = true; els.title.dataset.visionToken = String(token); }
  if (els.desc)  { els.desc.value = VISION_LOADING_DESC;  els.desc.disabled = true;  els.desc.dataset.visionToken = String(token); }
  if (els.tags)  { els.tags.innerHTML = VISION_LOADING_TAGS; els.tags.dataset.visionToken = String(token); }
}

/** إزالة توقيعات حالة التحميل من الحقول التي يديرها التحليل الحالي (بعد النجاح). */
function clearVisionLoadingMarkers(targets, token) {
  const els = visionFieldElements(targets);
  Object.values(els).forEach((el) => {
    if (el && el.dataset.visionToken === String(token)) delete el.dataset.visionToken;
  });
}

/**
 * تحرير الحقول المشغولة بتحليل مُلغى/فاشل: تُستعاد قابلية التعديل وتُفرَّغ
 * الحقول فقط إذا كانت ما تزال تحمل توقيع هذا التحليل (إن تولّتها عملية
 * أحدث فلا نلمسها إطلاقاً).
 */
function releaseVisionLoadingFields(targets, token) {
  const els = visionFieldElements(targets);
  Object.values(els).forEach((el) => {
    if (!el || el.dataset.visionToken !== String(token)) return;
    delete el.dataset.visionToken;
    if (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA') {
      el.disabled = false;
      el.value = '';
    } else {
      el.innerHTML = '';
    }
  });
}

/** استلام حقل للكتابة المباشرة (الماسح) بإزالة أي توقيع تحميل معلّق عليه. */
function claimVisionFieldForDirectWrite(el) {
  if (!el) return;
  delete el.dataset.visionToken;
  el.disabled = false;
}

/**
 * لا ترث صورة جديدة بيانات OCR أنتجها تحليل صورة سابقة (visionSrc === 'photo'),
 * بينما تبقى بيانات الماسح الضوئي للبطاقة (visionSrc === 'card') والمُدخلة يدوياً
 * (بلا توقيع) كما هي — فالبطاقة والصورة مصدران مستقلان لنموذج المعثور.
 */
function resetPhotoDerivedOcr(targets) {
  [targets.ocrId, targets.ocrHidden, targets.savedImage].forEach((id) => {
    if (!id) return;
    const el = document.getElementById(id);
    if (el && el.dataset.visionSrc === 'photo') {
      el.value = '';
      delete el.dataset.visionSrc;
    }
  });
}

/**
 * كشف مبدئي لكون الصورة بطاقة جامعية قبل إرسالها:
 * تصنيف البطاقة المختار أو تسمية ملف تحمل مفاتيح بطاقة/هوية/رقم جامعي.
 * (الكشف النهائي يأتي بعد الاستجابة: أي استخراج ناجح لرقم جامعي يُعامَل كبطاقة.)
 */
function isLikelyCardImage(file, catEl) {
  if (catEl && catEl.value === CARD_CATEGORY) return true;
  const name = String(file?.name || '');
  return /(id|card|بطاق|student|passport|bank|atm)/i.test(name) || /S\d{9}/i.test(name);
}

/** زر "إعادة تحليل الصورة" المصاحب لحقل الرفع الموحّد لكل نموذج. */
function getReanalyzeButton(modalType) {
  return document.getElementById(modalType === 'Lost' ? 'reanalyzeBtnLost' : 'reanalyzeBtnFound');
}

/**
 * نقطة الدخول الموحّدة لحقل الرفع في نموذجي المفقود والمعثور:
 * حقل واحد يتعامل مع الصورتين — إن كانت بطاقة جامعية يُفعَّل تحليل OCR تلقائيًا
 * (كشف مبدئي بالتصنيف/اسم الملف، ثم التأكد بعد الاستجابة برقم جامعي مستخرج).
 * @param {HTMLInputElement} inputElement حقل الملف/الكاميرا الموحّد
 * @param {'Lost'|'Found'} modalType
 */
function handleSmartUpload(inputElement, modalType) {
  const file = inputElement?.files?.[0];
  if (!file) return;

  const reanalyzeBtn = getReanalyzeButton(modalType);
  if (reanalyzeBtn) reanalyzeBtn.style.display = '';

  const targets = resolveVisionTargets(modalType);
  const catEl = document.getElementById(targets.category);
  return analyzeImageWithAI(inputElement, modalType, { cardHint: isLikelyCardImage(file, catEl) });
}

/**
 * إعادة تحليل الصورة المحفوظة فعلًا في حقل الرفع نفسه (بعد فشل التحليل أو
 * لتغيير التصنيف) — لا حاجة لإعادة اختيار الملف، فيبقى البلاغ مرفقًا بصورته.
 * @param {'Lost'|'Found'} modalType
 */
function reanalyzeSmartUpload(modalType) {
  const inputId = modalType === 'Lost' ? 'photoFileInputLost' : 'photoFileInputFound';
  const inputElement = document.getElementById(inputId);
  if (!inputElement || !inputElement.files || !inputElement.files[0]) {
    if (typeof showToast === 'function') showToast('لا توجد صورة مرفقة لإعادة تحليلها.', 'warning');
    return;
  }
  return handleSmartUpload(inputElement, modalType);
}

/**
 * تحليل صورة عنصر/بطاقة بالرؤية الذكية وتعبئة الحقول تلقائيًا.
 * صورة واحدة نشطة فقط لكل نموذج: أي اختيار جديد يُلغي التحليل السابق،
 * ولا تكتب النتيجة الملغاة في النموذج مهما تأخرت.
 * @param {HTMLInputElement} inputElement حقل الملف/الكاميرا
 * @param {'Lost'|'Found'|'SecFound'} modalType
 * @param {{cardHint?: boolean}} [options] cardHint: كشف مبدئي بأن الصورة بطاقة
 */
async function analyzeImageWithAI(inputElement, modalType, options = {}) {
  const file = inputElement?.files?.[0];
  if (!file) return;

  const targets = resolveVisionTargets(modalType);
  const reportType = targets.reportType;
  const catEl   = document.getElementById(targets.category);
  const previewEl = document.getElementById(targets.preview);

  // إلغاء أي تحليل سابق لهذا النموذج قبل بدء الجديد
  const token = beginVisionScope(modalType);
  const isCurrent = () => isVisionScopeCurrent(modalType, token);

  let previewStatus = null;
  try {
    // إزالة بيانات OCR التي أنتجتها صورة سابقة (لا ترثها الصورة الجديدة)
    resetPhotoDerivedOcr(targets);

    setVisionFieldsLoading(targets, token);

    const cardHint = (typeof options.cardHint === 'boolean')
      ? options.cardHint
      : isLikelyCardImage(file, catEl);
    const mode = cardHint ? 'id_card' : 'general_item';

    // معاينة فورية للصورة المرفوعة مع شريط حالة واضح
    if (previewEl) {
      const loadingText = cardHint
        ? ' جاري تحليل البطاقة واستخراج البيانات بالرؤية الذكية...'
        : ' جاري فحص الصورة بالرؤية الذكية — يُحدَّد النوع تلقائيًا (Gemini Vision)...';
      previewStatus = await renderImagePreview(file, previewEl, loadingText, {
        isActive: () => isVisionScopeCurrent(modalType, token),
        beam: true
      });
      if (!previewStatus) {
        // الصورة استُبدلت قبل عرض معاينتها — تُحرر الحقول إن كانت ما تزال موقّعة بهذا التحليل
        releaseVisionLoadingFields(targets, token);
        return;
      }
    }

    const aiData = await sendImageToVision(file, mode, reportType);

    if (!isCurrent()) {
      // نتيجة تحليل مُلغى (صورة أحدث أو إغلاق النافذة): لا نلمس الحقول النشطة
      setPreviewStatus(previewStatus, ' تم إلغاء التحليل', null);
      releaseVisionLoadingFields(targets, token);
      return;
    }

    const titleEl = document.getElementById(targets.title);
    const descEl  = document.getElementById(targets.desc);
    if (titleEl) titleEl.disabled = false;
    if (descEl)  descEl.disabled = false;
    clearVisionLoadingMarkers(targets, token);

    const studentId = applyVisionResultToForm(aiData, modalType);

    // عنصر عام (لا بطاقة) → رسالة موحدة في حقل الرقم الجامعي بدل ترك فجوة
    if (modalType === 'Lost' || modalType === 'Found') {
      const ocrIdInput = document.getElementById(targets.ocrId);
      if (ocrIdInput) {
        ocrIdInput.placeholder = studentId ? OCR_ID_PLACEHOLDER : OCR_NO_REQUIRED_HINT;
      }
    }

    if (previewEl) {
      setPreviewStatus(previewStatus, studentId ? ` جاهز — بطاقة: الرقم الجامعي ${studentId}` : ' تم تحليل الصورة بنجاح', true);
      appendVisionResultCard(previewEl, studentId, aiData.name, aiData.description);
    }

    if (typeof autoGenerateTagsFromInput === 'function') autoGenerateTagsFromInput(modalType);
    if (typeof showToast === 'function') {
      showToast(studentId ? `تم استخراج الرقم الجامعي: ${studentId}` : 'تم تحليل الصورة وتعبئة البيانات بالذكاء الاصطناعي.', 'success');
    }
  } catch (err) {
    if (!isCurrent()) {
      // فشل تحليل مُلغٍّ أصلاً — لا يمسّ حقول النموذج النشطة
      releaseVisionLoadingFields(targets, token);
      return;
    }
    console.error('[Luqya AI Vision]', err);
    releaseVisionLoadingFields(targets, token);
    if (previewEl && previewStatus) {
      setPreviewStatus(previewStatus, ` تعذر التحليل: ${err.message}`, false);
    }
    if (typeof showToast === 'function') showToast('تعذر تحليل الصورة: ' + err.message, 'danger');
  } finally {
    endVisionScope(modalType, token);
  }
}

/**
 * ماسح البطاقات الجامعية بالرؤية الذكية (مسار مخصّص مستقل عن حقل الرفع الموحّد):
 * 1. يُخفي أزرار الرفع ويعرض صورة البطاقة فقط مع موجة المسح الضوئي
 * 2. يستخرج الرقم الجامعي ويضعه في #ocrExtractedId
 * 3. يُظهر صف إعادة المسح بعد الانتهاء أو عند الخطأ
 * مسح واحد نشط فقط في نموذج المعثور: يُلغي أي تحليل صورة جارٍ في نفس النموذج
 * (الحقول مشتركة)، ولا تكتب نتيجة ملغاة في الحقول مهما تأخرت.
 * ملاحظة: نماذج الطلبات تستخدم الآن handleSmartUpload الموحّد؛ هذه الدالة محفوظة
 * لتوافق أي كود خارجي يستدعيها، وتعمل بحاوية المعاينة الموحدة إن غاب #ocrScanOutput.
 */
async function simulateOCRCardScan(inputElement) {
  const file = inputElement?.files?.[0];
  if (!file) return;

  const ocrOutput   = document.getElementById('ocrScanOutput') || document.getElementById('imagePreviewFound');
  const idInput     = document.getElementById('ocrExtractedId');
  const idHidden    = document.getElementById('ocrExtractedDataHidden');
  const promptEl    = document.getElementById('ocrScanPrompt');
  const rescanRowEl = document.getElementById('ocrRescanRow');

  // إعادة ضبط حقل الإدخال حتى لو اختار المستخدم نفس الملف مرة أخرى —
  // ما لم يكن حقل البلاغ نفسه (يجب أن يحتفظ بملفه ليُرسل مع النموذج)
  if (!inputElement.name) inputElement.value = '';

  const token = beginVisionScope(CARD_SCAN_SCOPE);
  const isCurrent = () => isVisionScopeCurrent(CARD_SCAN_SCOPE, token);

  // إخفاء واجهة البداية فوراً وإظهار منطقة المعاينة فقط
  if (promptEl)    promptEl.style.display = 'none';
  if (rescanRowEl) rescanRowEl.style.display = 'none';

  let previewStatus = null;
  try {
    previewStatus = await renderImagePreview(
      file, ocrOutput,
      ' تم إرفاق البطاقة — جاري مسح بياناتها بالرؤية الذكية (Gemini Vision OCR)...',
      { beam: true, isActive: () => isVisionScopeCurrent(CARD_SCAN_SCOPE, token) }
    );
    if (!previewStatus) return;

    const aiData   = await sendImageToVision(file, 'id_card', 'found');

    if (!isCurrent()) {
      setPreviewStatus(previewStatus, ' تم إلغاء المسح', null);
      return;
    }

    const studentId = normalizeUniversityId(aiData.student_id);
    const holderName = aiData.name || '';

    // استلام حقول العنوان/الوصف من أي تحليل صورة سابق (يزيل توقيع تحميله المعلّق)
    const titleEl = document.getElementById('foundTitle');
    const descEl = document.getElementById('reportDescFound');
    claimVisionFieldForDirectWrite(titleEl);
    claimVisionFieldForDirectWrite(descEl);

    if (idInput) {
      idInput.value = studentId;
      idInput.dataset.visionSrc = 'card';
    }
    if (idHidden) {
      idHidden.value = JSON.stringify({ student_id: studentId, name: holderName });
      idHidden.dataset.visionSrc = 'card';
    }

    if (titleEl) titleEl.value = aiData.title || (holderName ? `بطاقة جامعية للطالب ${holderName}` : 'بطاقة جامعية');
    if (descEl && aiData.description) descEl.value = aiData.description;
    const catEl = document.getElementById('reportCategoryFound');
    if (catEl) catEl.value = 'البطاقة الجامعية / الصراف';

    if (ocrOutput) {
      if (studentId) {
        setPreviewStatus(previewStatus, ` جاهز — الرقم الجامعي: ${studentId}`, true);
        ocrOutput.insertAdjacentHTML('beforeend', `
          <div class="ocr-result-card" style="background:rgba(76,217,161,0.12);border:1.5px solid var(--mint-success);border-radius:10px;padding:12px;margin-top:8px;">
            <div style="font-weight:800;color:var(--mint-success);margin-bottom:6px;"> تم استخراج بيانات البطاقة عبر Gemini Vision OCR</div>
            <div><strong>الرقم الجامعي المستخرج:</strong> <code style="color:var(--cyan-primary);font-size:1.05rem;font-weight:800;">${studentId}</code></div>
            ${holderName ? `<div><strong>اسم الطالب / الصاحب:</strong> ${holderName}</div>` : ''}
            ${aiData.description ? `<div style="font-size:0.85rem;color:var(--text-muted);margin-top:4px;">${aiData.description}</div>` : ''}
          </div>`);
      } else {
        setPreviewStatus(previewStatus, ' تعذر قراءة الرقم الجامعي بوضوح', false);
        ocrOutput.insertAdjacentHTML('beforeend', `
          <div class="ocr-result-card" style="background:rgba(239,68,68,0.08);border:1.5px solid #EF4444;border-radius:10px;padding:12px;margin-top:8px;">
            <div style="font-weight:700;color:#EF4444;margin-bottom:6px;"> تعذر قراءة الرقم الجامعي من الصورة</div>
            <div style="font-size:0.85rem;color:var(--text-muted);">يرجى التأكد من وضوح الصورة وإعادة المحاولة، أو إدخال الرقم يدوياً.</div>
          </div>`);
      }
    }

    if (typeof autoGenerateTagsFromInput === 'function') autoGenerateTagsFromInput('Found');
    if (typeof showToast === 'function' && studentId) showToast('تم مسح واستخراج الرقم الجامعي: ' + studentId, 'success');
  } catch (err) {
    if (!isCurrent()) {
      // فشل مسح مُلغٍّ أصلاً — لا يُضاف بطاقة خطأ في معاينة النموذج النشطة
      return;
    }
    console.error('[Luqya AI OCR]', err);
    if (previewStatus) {
      setPreviewStatus(previewStatus, ` خطأ: ${err.message}`, false);
    }
    if (ocrOutput) {
      ocrOutput.insertAdjacentHTML('beforeend', `
        <div class="ocr-result-card" style="background:rgba(239,68,68,0.08);border:1.5px solid #EF4444;border-radius:10px;padding:12px;margin-top:8px;">
          <div style="font-weight:700;color:#EF4444;margin-bottom:6px;"> تعذر الاتصال بـ Gemini Vision OCR</div>
          <div style="font-size:0.85rem;color:var(--text-muted);">${err.message || 'تحقق من إعداد GEMINI_API_KEY واتصال الخادم ثم أعد المحاولة.'}</div>
        </div>`);
    }
    if (typeof showToast === 'function') showToast('تعذر مسح البطاقة: ' + err.message, 'danger');
  } finally {
    endVisionScope(CARD_SCAN_SCOPE, token);
    // إظهار صف إعادة المسح بعد اكتمال/فشل المسح
    if (rescanRowEl) rescanRowEl.style.display = '';
  }
}

/**
 * إعادة ضبط واجهة الماسح الضوئي للبطاقة عند فتح النموذج من جديد:
 * يُلغى أي مسح/تحليل سابق جارٍ حتى لا تعود نتيجته المتأخرة لتعبئة نموذج
 * تم تصفيره للتو، وتُمسح علامات ملكية بيانات OCR المأخوذة من الصور.
 */
function resetOCRScanner() {
  const promptEl    = document.getElementById('ocrScanPrompt');
  const rescanRowEl = document.getElementById('ocrRescanRow');
  const outputEl    = document.getElementById('ocrScanOutput');
  const beamEl      = document.getElementById('ocrScanBeam');
  const idInput     = document.getElementById('ocrExtractedId');
  const idHidden    = document.getElementById('ocrExtractedDataHidden');

  ['ocrCardFileInput', 'ocrCardRescanInput'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.value = '';
  });
  if (promptEl)    promptEl.style.display = '';
  if (rescanRowEl) rescanRowEl.style.display = 'none';
  if (outputEl)    outputEl.innerHTML = '';
  if (beamEl)      beamEl.classList.remove('scanning');
  if (idInput)     { idInput.value = ''; delete idInput.dataset.visionSrc; }
  if (idHidden)    { idHidden.value = ''; delete idHidden.dataset.visionSrc; }

  invalidateVisionScope(CARD_SCAN_SCOPE);
}

/**
 * تفريغ ملكية بيانات OCR اليدوية: عند تعديل الرقم الجامعي يدوياً تتوقف الحقول
 * عن كونها ناتج تحليل صورة، فلا تُمسح عند اختيار صورة تالية.
 * كما تُزامن قيمة الحقل المرئي مع حقل ocr_data المخفي (نموذج المعثور يفتقد
 * معالج oninput الموجود في نافذة الأمن، فكانت البيانات اليدوية لا تُحفظ).
 */
function bindStudentIdFieldOwnership() {
  [
    { id: 'ocrExtractedId', hidden: 'ocrExtractedDataHidden' },
    { id: 'ocrExtractedIdLost', hidden: 'ocrExtractedDataLost' },
    { id: 'secExtractedStudentId', hidden: 'secItemOcrData' }
  ].forEach(({ id, hidden }) => {
    const el = document.getElementById(id);
    const hiddenEl = document.getElementById(hidden);
    if (!el || el.dataset.visionBound) return;
    el.dataset.visionBound = '1';
    el.addEventListener('input', () => {
      syncStudentIdToOcrHidden(el, hiddenEl);
      delete el.dataset.visionSrc;
      if (hiddenEl) delete hiddenEl.dataset.visionSrc;
    });
  });
}
bindStudentIdFieldOwnership();

// إتاحة الدوال عالمياً لاستخدامها من النماذج والصفحات
window.analyzeImageWithAI = analyzeImageWithAI;
window.simulateOCRCardScan = simulateOCRCardScan;
window.isVisionAnalysisInFlight = isVisionAnalysisInFlight;
window.handleSmartUpload = handleSmartUpload;
window.reanalyzeSmartUpload = reanalyzeSmartUpload;
