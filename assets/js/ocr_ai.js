// لُقيا AI - محرك التعرف البصري والماسح الضوئي الذكي (Multimodal Vision OCR & Heuristics)
// يربط واجهات الرفع والكاميرا بنقطة النهاية api/vision_ai.php المدعومة بـ Gemini Vision
// مع محرك احتياطي محلي سلس في المتصفح.

let mobileNetModelInstance = null;

// تحميل مسبق لنموذج الرؤية الحاسوبية في الذاكرة كخطة احتياطية
if (typeof mobilenet !== 'undefined') {
  mobilenet.load({ version: 2, alpha: 1.0 }).then(model => {
    mobileNetModelInstance = model;
  }).catch(() => {});
}

/**
 * استدعاء محرك الرؤية الحاسوبية السحابي (Gemini Vision) عبر api/vision_ai.php
 * @param {File} file - ملف الصورة المرفوع
 * @param {string} mode - 'id_card' أو 'general_item'
 * @param {string} reportType - 'lost' أو 'found'
 * @returns {Promise<Object>}
 */
async function callCloudVisionAPI(file, mode = 'general_item', reportType = 'lost') {
  const formData = new FormData();
  formData.append('image', file);
  formData.append('mode', mode);
  formData.append('report_type', reportType);
  formData.append('type', reportType);

  const response = await fetch('api/vision_ai.php', {
    method: 'POST',
    body: formData
  });

  if (!response.ok) {
    throw new Error(`Vision API error status: ${response.status}`);
  }

  const result = await response.json();
  if (result.success && result.data) {
    return result.data;
  }
  throw new Error(result.message || 'فشل تحليل الصورة بالذكاء الاصطناعي.');
}

/**
 * دالة التحليل البصري الشاملة للنموذجين (مفقود / معثور عليه)
 * @param {HTMLInputElement} inputElement - حقل رفع الملف أو الكاميرا
 * @param {string} modalType - 'Lost' أو 'Found'
 */
async function analyzeImageWithAI(inputElement, modalType) {
  const isLost = modalType === 'Lost';
  const reportType = isLost ? 'lost' : 'found';

  const previewDiv = document.getElementById(isLost ? 'imagePreviewLost' : 'imagePreviewFound');
  const descTextarea = document.getElementById(isLost ? 'reportDescLost' : 'reportDescFound');
  const titleInput = document.getElementById(isLost ? 'reportTitleLost' : 'foundTitle');
  const categorySelect = document.getElementById(isLost ? 'reportCategoryLost' : 'reportCategoryFound');
  const tagsContainer = document.getElementById(isLost ? 'aiTagsPreviewLost' : 'aiTagsPreviewFound');
  const hiddenTagsInput = document.getElementById(isLost ? 'aiTagsHiddenLost' : 'aiTagsHiddenFound');

  if (!inputElement || !inputElement.files || !inputElement.files[0]) return;

  const file = inputElement.files[0];
  const fileName = file.name.toLowerCase();

  // 0. عرض معاينة الصورة فوراً قبل التحليل
  if (previewDiv) {
    const previewReader = new FileReader();
    previewReader.onload = function(e) {
      const previewImg = document.createElement('img');
      previewImg.src = e.target.result;
      previewImg.style.cssText = 'max-width:200px;max-height:200px;border-radius:8px;margin-top:8px;border:2px solid var(--cyan-primary);';
      previewImg.alt = 'معاينة';

      const statusDiv = document.createElement('div');
      statusDiv.style.cssText = 'background:rgba(0,168,181,0.08);border:1.5px solid var(--cyan-primary);padding:12px 14px;border-radius:10px;margin-top:8px;display:flex;align-items:center;gap:12px;';
      statusDiv.innerHTML = '<span style="width:16px;height:16px;border-radius:50%;background:var(--cyan-primary);display:inline-block;animation:corePulse 0.8s infinite alternate;"></span><span style="color:var(--cyan-primary);font-size:0.88rem;font-weight:700;">جاري تحليل تفاصيل ومواصفات الصورة عبر Gemini Vision واستخراج العنوان والوصف...</span>';

      previewDiv.innerHTML = '';
      previewDiv.appendChild(previewImg);
      previewDiv.appendChild(statusDiv);
    };
    previewReader.readAsDataURL(file);
  }

  // تحديد وضع التحليل: بطاقة أم عنصر عام
  const selectedCategory = categorySelect?.value ?? '';
  const mode = (selectedCategory === 'البطاقة الجامعية / الصراف' || fileName.includes('id') || fileName.includes('card') || fileName.includes('بطاق'))
               ? 'id_card' : 'general_item';

  // حالة التحميل في الحقول
  if (titleInput) {
    titleInput.value = ' جاري التحليل...';
    titleInput.disabled = true;
  }
  if (descTextarea) {
    descTextarea.value = ' جاري تحليل الصورة واستخراج البيانات...';
    descTextarea.disabled = true;
  }
  if (tagsContainer) {
    tagsContainer.innerHTML = '<span class="ai-tag" style="opacity:0.6;"> جاري الاستخراج...</span>';
  }

  try {
    // استدعاء محرك Gemini Vision
    const aiData = await callCloudVisionAPI(file, mode, reportType);

    // إعادة تفعيل الحقول
    if (titleInput) titleInput.disabled = false;
    if (descTextarea) descTextarea.disabled = false;

    // تطبيق العنوان المستخرج
    if (titleInput && aiData.title) {
      titleInput.value = aiData.title;
    } else if (titleInput) {
      titleInput.value = '';
    }

    // تطبيق الوصف التفصيلي
    if (descTextarea && aiData.description) {
      descTextarea.value = aiData.description;
    } else if (descTextarea) {
      descTextarea.value = '';
    }

    // مطابقة وتحديث التصنيف
    if (categorySelect && aiData.category) {
      for (let opt of categorySelect.options) {
        if (opt.value === aiData.category || opt.text === aiData.category || opt.value.includes(aiData.category) || aiData.category.includes(opt.value)) {
          categorySelect.value = opt.value;
          break;
        }
      }
    }

    // الوسوم الذكية
    if (aiData.tags && Array.isArray(aiData.tags) && aiData.tags.length > 0) {
      if (tagsContainer) {
        tagsContainer.innerHTML = aiData.tags.map(t => `<span class="ai-tag"> ${t}</span>`).join(' ');
      }
      if (hiddenTagsInput) {
        hiddenTagsInput.value = aiData.tags.join('، ');
      }
    } else if (tagsContainer) {
      tagsContainer.innerHTML = '';
    }

    // بيانات البطاقة الجامعية
    if (aiData.student_id && modalType === 'Found') {
      const ocrField = document.getElementById('ocrExtractedId');
      if (ocrField) ocrField.value = aiData.student_id;
      const ocrHidden = document.getElementById('ocrExtractedDataHidden');
      if (ocrHidden) ocrHidden.value = JSON.stringify({ student_id: aiData.student_id, name: aiData.name || '' });
    }

    // عرض نتيجة التحليل مع معاينة الصورة
    if (previewDiv) {
      const previewReader2 = new FileReader();
      previewReader2.onload = function(e) {
        const resultDiv = document.createElement('div');
        resultDiv.style.cssText = 'background:rgba(76,217,161,0.12);border:1.5px solid var(--mint-success);padding:10px 14px;border-radius:10px;margin-top:8px;font-size:0.86rem;color:var(--navy-primary);';
        resultDiv.innerHTML = `
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px;">
            <strong style="color:var(--mint-success);font-size:0.92rem;display:flex;align-items:center;gap:6px;">
               تم تحليل الصورة بالذكاء الاصطناعي بنجاح
            </strong>
            <small style="color:var(--text-muted);"> ${fileName}</small>
          </div>
          <div><strong>العنصر المكتشف:</strong> ${aiData.title || 'غير محدد'}</div>`;

        const previewImg = document.createElement('img');
        previewImg.src = e.target.result;
        previewImg.style.cssText = 'max-width:200px;max-height:200px;border-radius:8px;margin-top:8px;border:2px solid var(--cyan-primary);';
        previewImg.alt = 'معاينة';

        previewDiv.innerHTML = '';
        previewDiv.appendChild(previewImg);
        previewDiv.appendChild(resultDiv);
      };
      previewReader2.readAsDataURL(file);
    }

    if (typeof showToast === 'function') {
      showToast(' تم تحليل الصورة وتعبئة العنوان والوصف والتصنيف بالذكاء الاصطناعي بنجاح!', 'success');
    }

  } catch (err) {
    console.error('[Luqya AI Vision] Gemini Vision API failed:', err);

    // إعادة تفعيل الحقول
    if (titleInput) titleInput.disabled = false;
    if (descTextarea) descTextarea.disabled = false;

    // لا نعرض نتيجة محلية عشوائية عند فشل Gemini؛ نُظهر الخطأ ليتم إصلاح الاتصال فعلياً.
    if (titleInput) titleInput.value = '';
    if (descTextarea) descTextarea.value = '';
    if (tagsContainer) tagsContainer.innerHTML = '';
    if (previewDiv) {
      previewDiv.innerHTML = `
        <div class="ocr-result-card" style="border-color:#EF4444;">
          <div style="font-weight:700;color:#EF4444;margin-bottom:6px;"> تعذر الاتصال بـ Gemini Vision</div>
          <div style="font-size:0.85rem;color:var(--text-muted);">تحقق من إعداد GEMINI_API_KEY واتصال الخادم ثم أعد المحاولة.</div>
        </div>`;
    }
    if (typeof showToast === 'function') {
      showToast('تعذر الاتصال بـ Gemini Vision. لم يتم استخدام تحليل محلي بديل.', 'danger');
    }
  }
}

/**
 * الخطة الاحتياطية المحلية بالمتصفح
 */
function runLocalVisualAnalysis(file, fileName, previewDiv, descTextarea, titleInput, categorySelect, tagsContainer, hiddenTagsInput, modalType, reportType) {
  const img = document.createElement('img');
  img.src = URL.createObjectURL(file);

  img.onload = function() {
    const visualProps = analyzeImageVisualPixels(img);

    const executeAnalysis = (model) => {
      if (model && typeof model.classify === 'function') {
        model.classify(img, 5).then(predictions => {
          const result = mapVisualPredictionsToDetails(predictions || [], fileName, visualProps, reportType);
          applyAnalysisResults(result, previewDiv, descTextarea, titleInput, categorySelect, tagsContainer, hiddenTagsInput, fileName, modalType);
        }).catch(() => {
          const fallbackResult = mapVisualPredictionsToDetails([], fileName, visualProps, reportType);
          applyAnalysisResults(fallbackResult, previewDiv, descTextarea, titleInput, categorySelect, tagsContainer, hiddenTagsInput, fileName, modalType);
        });
      } else {
        const fallbackResult = mapVisualPredictionsToDetails([], fileName, visualProps, reportType);
        applyAnalysisResults(fallbackResult, previewDiv, descTextarea, titleInput, categorySelect, tagsContainer, hiddenTagsInput, fileName, modalType);
      }
    };

    if (mobileNetModelInstance) {
      executeAnalysis(mobileNetModelInstance);
    } else {
      setTimeout(() => executeAnalysis(null), 400);
    }
  };

  img.onerror = function() {
    if (previewDiv) {
      previewDiv.innerHTML = `
        <div style="background:rgba(239,68,68,0.1);border:1px solid #EF4444;padding:8px 12px;border-radius:8px;margin-top:6px;color:#EF4444;font-size:0.83rem;">
           تم إرفاق الصورة، يرجى كتابة تفاصيل العنصر.
        </div>`;
    }
  };
}

/**
 * تحليل بيكسلات الصورة واستخراج الألوان السائدة والخامة البصرية عبر Canvas
 */
function analyzeImageVisualPixels(imgElement) {
  try {
    const canvas = document.createElement('canvas');
    const ctx = canvas.getContext('2d');
    const size = 64;
    canvas.width = size;
    canvas.height = size;
    ctx.drawImage(imgElement, 0, 0, size, size);

    const imgData = ctx.getImageData(0, 0, size, size).data;
    let rTotal = 0, gTotal = 0, bTotal = 0, count = 0;
    let brightnessTotal = 0;

    for (let i = 0; i < imgData.length; i += 16) {
      const r = imgData[i];
      const g = imgData[i + 1];
      const b = imgData[i + 2];
      rTotal += r;
      gTotal += g;
      bTotal += b;
      brightnessTotal += (r * 0.299 + g * 0.587 + b * 0.114);
      count++;
    }

    const rAvg = rTotal / count;
    const gAvg = gTotal / count;
    const bAvg = bTotal / count;
    const avgBrightness = brightnessTotal / count;

    let dominantColor = 'محايد / متعدد الألوان';
    let colorTag = 'ألوان متعددة';
    let materialTag = 'خامة متينة';
    let textureTone = 'متوسط الإضاءة والوضوح';

    if (avgBrightness < 55) {
      dominantColor = 'أسود داكن';
      colorTag = 'لون أسود';
      materialTag = 'سطح داكن';
      textureTone = 'مظهر أسود داكن كلاسيكي';
    } else if (avgBrightness > 205 && Math.abs(rAvg - gAvg) < 15 && Math.abs(gAvg - bAvg) < 15) {
      dominantColor = 'أبيض ناصع';
      colorTag = 'لون أبيض';
      materialTag = 'سطح أملس ناصع';
      textureTone = 'لون أبيض ناصع ونظيف';
    } else if (Math.abs(rAvg - gAvg) < 20 && Math.abs(gAvg - bAvg) < 20) {
      if (avgBrightness > 140) {
        dominantColor = 'فضي معدني';
        colorTag = 'لون فضي';
        materialTag = 'هيكل معدني مصقول';
        textureTone = 'سطح فضي معدني أنيق';
      } else {
        dominantColor = 'رمادي مطفي';
        colorTag = 'لون رمادي';
        materialTag = 'سطح رمادي متين';
        textureTone = 'مظهر رمادي متناسق';
      }
    } else if (rAvg > gAvg + 25 && rAvg > bAvg + 25) {
      if (gAvg > 65 && bAvg < 65) {
        dominantColor = 'بني جلدي';
        colorTag = 'لون بني';
        materialTag = 'خامة جلدية طبيعية';
        textureTone = 'مظهر جلدي كلاسيكي بدرجات بنية دافئة';
      } else {
        dominantColor = 'أحمر قاني';
        colorTag = 'لون أحمر';
        materialTag = 'لون ملفت وواضح';
        textureTone = 'درجة حمراء زاهية واضحة';
      }
    } else if (bAvg > rAvg + 20 && bAvg > gAvg + 15) {
      if (avgBrightness < 95) {
        dominantColor = 'أزرق كحلي داكن';
        colorTag = 'لون كحلي';
        materialTag = 'خامة قماشية متينة';
        textureTone = 'مظهر كحلي أنيق ومتماسك';
      } else {
        dominantColor = 'أزرق ملكي / سماوي';
        colorTag = 'لون أزرق';
        materialTag = 'سطح أزرق ساطع';
        textureTone = 'لون أزرق بارز ونقي';
      }
    } else if (gAvg > rAvg + 15 && gAvg > bAvg + 15) {
      dominantColor = 'أخضر زيتي';
      colorTag = 'لون أخضر';
      materialTag = 'خامة قماشية/بلاستيكية';
      textureTone = 'درجة خضراء زيتية متناسقة';
    } else if (rAvg > 155 && gAvg > 125 && bAvg < 100) {
      dominantColor = 'ذهبي / بيج';
      colorTag = 'لون ذهبي';
      materialTag = 'مظهر معدني مميز';
      textureTone = 'درجة ذهبية دافئة وواضحة';
    }

    const naturalWidth = imgElement.naturalWidth || 400;
    const naturalHeight = imgElement.naturalHeight || 300;
    const ratio = naturalWidth / naturalHeight;
    let shapeDesc = 'هيكل متناسق الأبعاد';
    if (ratio > 1.35) shapeDesc = 'تصميم أفقي عريض';
    else if (ratio < 0.75) shapeDesc = 'تصميم رأسي مدمج';

    return { dominantColor, colorTag, materialTag, textureTone, shapeDesc };
  } catch (err) {
    return {
      dominantColor: 'ألوان طبيعية واضحة',
      colorTag: 'ألوان طبيعية',
      materialTag: 'خامة متينة',
      textureTone: 'حالة ممتازة ونظيفة',
      shapeDesc: 'هيكل متناسق'
    };
  }
}

/**
 * تعيين توقعات الذكاء الاصطناعي المحلية للمواصفات (خطة احتياطية فقط)
 */
function mapVisualPredictionsToDetails(predictions, fileName, visualProps, reportType = 'lost') {
  const isLost = reportType === 'lost';
  const topText = (predictions.map(p => p.className).join(' ') + ' ' + fileName).toLowerCase();
  const topConfidence = predictions.length > 0 ? Math.round(predictions[0].probability * 100) : 50;

  let categoryValue = 'مقتنيات شخصية';
  let itemTitle = isLost ? 'عنصر شخصي مفقود' : 'مقتنى شخصي';
  let detailedDescription = '';
  let smartTags = [];

  if (topText.includes('laptop') || topText.includes('notebook') || topText.includes('computer') || topText.includes('لابتوب') || topText.includes('كمبيوتر')) {
    categoryValue = 'إلكترونيات';
    itemTitle = 'جهاز كمبيوتر محمول';
    detailedDescription = `جهاز كمبيوتر محمول باللون ${visualProps.dominantColor}.`;
    smartTags = ['كمبيوتر محمول', visualProps.colorTag, 'إلكترونيات'];
  } else if (topText.includes('phone') || topText.includes('mobile') || topText.includes('جوال') || topText.includes('هاتف')) {
    categoryValue = 'إلكترونيات';
    itemTitle = 'هاتف ذكي';
    detailedDescription = `هاتف ذكي باللون ${visualProps.dominantColor}.`;
    smartTags = ['هاتف ذكي', visualProps.colorTag, 'إلكترونيات'];
  } else if (topText.includes('wallet') || topText.includes('محفظة')) {
    categoryValue = 'مقتنيات شخصية';
    itemTitle = 'محفظة';
    detailedDescription = `محفظة باللون ${visualProps.dominantColor}.`;
    smartTags = ['محفظة', visualProps.colorTag, 'مقتنيات شخصية'];
  } else if (topText.includes('card') || topText.includes('id') || topText.includes('بطاقة') || topText.includes('صراف')) {
    categoryValue = 'البطاقة الجامعية / الصراف';
    itemTitle = 'بطاقة جامعية';
    detailedDescription = `بطاقة جامعية باللون ${visualProps.dominantColor}.`;
    smartTags = ['بطاقة جامعية', visualProps.colorTag];
  } else if (topText.includes('bag') || topText.includes('backpack') || topText.includes('حقيبة') || topText.includes('شنطة')) {
    categoryValue = 'حقائب وحقائب ظهر';
    itemTitle = 'حقيبة ظهر';
    detailedDescription = `حقيبة ظهر باللون ${visualProps.dominantColor}.`;
    smartTags = ['حقيبة ظهر', visualProps.colorTag, 'حقائب وحقائب ظهر'];
  } else if (topText.includes('key') || topText.includes('مفتاح') || topText.includes('مفاتيح')) {
    categoryValue = 'مفاتيح';
    itemTitle = 'مفاتيح';
    detailedDescription = `مفاتيح باللون ${visualProps.dominantColor}.`;
    smartTags = ['مفاتيح', visualProps.colorTag];
  } else if (topText.includes('watch') || topText.includes('ساعة')) {
    categoryValue = 'إلكترونيات';
    itemTitle = 'ساعة يد';
    detailedDescription = `ساعة يد باللون ${visualProps.dominantColor}.`;
    smartTags = ['ساعة يد', visualProps.colorTag];
  } else {
    categoryValue = 'مقتنيات شخصية';
    itemTitle = isLost ? `عنصر مفقود (${visualProps.colorTag})` : `غرض شخصي (${visualProps.colorTag})`;
    detailedDescription = `مقتنى شخصي باللون ${visualProps.dominantColor}.`;
    smartTags = ['مقتنيات شخصية', visualProps.colorTag];
  }

  smartTags.push(categoryValue);
  smartTags = [...new Set(smartTags)].filter(t => t && t.trim().length > 0);

  return { categoryValue, itemTitle, detailedDescription, smartTags, topConfidence };
}

function applyAnalysisResults(result, previewDiv, descTextarea, titleInput, categorySelect, tagsContainer, hiddenTagsInput, fileName, modalType) {
  if (categorySelect && result.categoryValue) categorySelect.value = result.categoryValue;
  if (titleInput && result.itemTitle) titleInput.value = result.itemTitle;
  if (descTextarea && result.detailedDescription) descTextarea.value = result.detailedDescription;

  if (previewDiv) {
    previewDiv.innerHTML = `
      <div style="background:rgba(76,217,161,0.12);border:1.5px solid var(--mint-success);padding:10px 14px;border-radius:10px;margin-top:8px;font-size:0.86rem;color:var(--navy-primary);">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px;">
          <strong style="color:var(--mint-success);font-size:0.92rem;"> التحليل المحلي الاحتياطي (${result.topConfidence}%)</strong>
          <small style="color:var(--text-muted);"> ${fileName}</small>
        </div>
        <div><strong>العنصر المكتشف:</strong> ${result.itemTitle}</div>
      </div>`;
  }

  if (tagsContainer && result.smartTags && result.smartTags.length > 0) {
    tagsContainer.innerHTML = result.smartTags.map(tag => `<span class="ai-tag"> ${tag}</span>`).join(' ');
  }
  if (hiddenTagsInput && result.smartTags) {
    hiddenTagsInput.value = result.smartTags.join('، ');
  }
}

/**
 * ماسح البطاقات الجامعية بالرؤية الذكية (Gemini Vision OCR)
 */
async function simulateOCRCardScan(inputElement) {
  const ocrBeam   = document.getElementById('ocrScanBeam');
  const ocrOutput = document.getElementById('ocrScanOutput');
  const idInput   = document.getElementById('ocrExtractedId');

  if (ocrBeam) ocrBeam.classList.add('scanning');
  if (ocrOutput) ocrOutput.innerHTML = `<p style="color:var(--cyan-primary);font-weight:700;"> جاري استدعاء محرك Gemini Vision OCR لمسح بيانات البطاقة بدقة...</p>`;

  const file = inputElement?.files?.[0] ?? null;
  if (!file) {
    if (ocrBeam) ocrBeam.classList.remove('scanning');
    return;
  }

  try {
    const aiData = await callCloudVisionAPI(file, 'id_card', 'found');
    if (ocrBeam) ocrBeam.classList.remove('scanning');

    const extractedId = aiData.student_id || '';
    const cardholderName = aiData.name || '';

    if (ocrOutput) {
      if (extractedId) {
        ocrOutput.innerHTML = `
          <div class="ocr-result-card" style="background:rgba(76,217,161,0.12);border:1.5px solid var(--mint-success);border-radius:10px;padding:12px;margin-top:8px;">
            <div style="font-weight:800;color:var(--mint-success);margin-bottom:6px;"> تم استخراج بيانات البطاقة عبر Gemini Vision OCR</div>
            <div><strong>الرقم الجامعي المستخرج:</strong> <code style="color:var(--cyan-primary);font-size:1.05rem;font-weight:800;">${extractedId}</code></div>
            ${cardholderName ? `<div><strong>اسم الطالب / الصاحب:</strong> ${cardholderName}</div>` : ''}
            <div style="font-size:0.85rem;color:var(--text-muted);margin-top:4px;">${aiData.description || 'بطاقة جامعية.'}</div>
          </div>`;
      } else {
        ocrOutput.innerHTML = `
          <div class="ocr-result-card" style="background:rgba(239,68,68,0.08);border:1.5px solid #EF4444;border-radius:10px;padding:12px;margin-top:8px;">
            <div style="font-weight:700;color:#EF4444;margin-bottom:6px;"> تعذر قراءة الرقم الجامعي من الصورة</div>
            <div style="font-size:0.85rem;color:var(--text-muted);">يرجى التأكد من وضوح الصورة وإعادة المحاولة، أو إدخال الرقم يدوياً.</div>
          </div>`;
      }
    }

    if (idInput) idInput.value = extractedId;
    const ocrHidden = document.getElementById('ocrExtractedDataHidden');
    if (ocrHidden) ocrHidden.value = JSON.stringify({ student_id: extractedId, name: cardholderName });

    const titleInput = document.getElementById('foundTitle');
    if (titleInput) titleInput.value = aiData.title || (cardholderName ? `بطاقة جامعية للطالب ${cardholderName}` : 'بطاقة جامعية');

    const descField = document.getElementById('reportDescFound');
    if (descField && aiData.description) descField.value = aiData.description;

    autoGenerateTagsFromInput('Found');
    if (typeof showToast === 'function' && extractedId) {
      showToast('تم مسح واستخراج الرقم الجامعي: ' + extractedId, 'success');
    }

  } catch (err) {
    console.error('[Luqya AI OCR] Gemini Vision OCR failed:', err);
    if (ocrBeam) ocrBeam.classList.remove('scanning');
    if (idInput) idInput.value = '';
    if (ocrOutput) {
      ocrOutput.innerHTML = `
        <div class="ocr-result-card" style="background:rgba(239,68,68,0.08);border:1.5px solid #EF4444;border-radius:10px;padding:12px;margin-top:8px;">
          <div style="font-weight:700;color:#EF4444;margin-bottom:6px;"> تعذر الاتصال بـ Gemini Vision OCR</div>
          <div style="font-size:0.85rem;color:var(--text-muted);">تحقق من إعداد GEMINI_API_KEY واتصال الخادم ثم أعد المحاولة.</div>
        </div>`;
    }
    if (typeof showToast === 'function') {
      showToast('تعذر الاتصال بـ Gemini Vision OCR. لم يتم استخدام تحليل محلي بديل.', 'danger');
    }
  }
}

function fallbackToTesseractOCR(file, ocrBeam, ocrOutput, idInput, inputElement) {
  if (typeof Tesseract !== 'undefined') {
    const reader = new FileReader();
    reader.onload = (e) => {
      Tesseract.recognize(e.target.result, 'eng', { logger: () => {} })
        .then(({ data: { text } }) => {
          if (ocrBeam) ocrBeam.classList.remove('scanning');
          const idMatch   = text.match(/[Ss]\d{9}/) || text.match(/\d{9}/);
          const extracted = idMatch ? (idMatch[0].toUpperCase().startsWith('S') ? idMatch[0].toUpperCase() : 'S' + idMatch[0]) : '';
          renderOCRResult(extracted, text.substring(0, 70), ocrOutput, idInput);
          if (extracted) analyzeImageWithAI(inputElement, 'Found');
        })
        .catch(() => fallbackOCRScanner('', ocrBeam, ocrOutput, idInput, inputElement));
    };
    reader.readAsDataURL(file);
  } else {
    fallbackOCRScanner('', ocrBeam, ocrOutput, idInput, inputElement);
  }
}

function renderOCRResult(extractedId, textSnippet, ocrOutput, idInput) {
  if (ocrOutput) {
    if (extractedId) {
      ocrOutput.innerHTML = `
        <div class="ocr-result-card">
          <div style="font-weight:700;color:var(--mint-success);margin-bottom:6px;"> تم استخراج الرقم الجامعي عبر الماسح الضوئي</div>
          <div><strong>الرقم الجامعي المستخرج:</strong> <code style="color:var(--cyan-primary);font-size:1rem;font-weight:700;">${extractedId}</code></div>
        </div>`;
    } else {
      ocrOutput.innerHTML = `
        <div class="ocr-result-card" style="border-color:#EF4444;">
          <div style="font-weight:700;color:#EF4444;margin-bottom:6px;"> تعذر قراءة الرقم الجامعي</div>
          <div style="font-size:0.85rem;color:var(--text-muted);">يرجى إدخال الرقم يدوياً.</div>
        </div>`;
    }
  }
  if (idInput) idInput.value = extractedId;
  const titleInput = document.getElementById('foundTitle');
  if (titleInput && !titleInput.value && extractedId) titleInput.value = `بطاقة جامعية (${extractedId})`;
  autoGenerateTagsFromInput('Found');
}

function fallbackOCRScanner(extractedId, ocrBeam, ocrOutput, idInput, inputElement) {
  if (ocrBeam) ocrBeam.classList.remove('scanning');
  renderOCRResult(extractedId, 'بطاقة جامعية للطالب', ocrOutput, idInput);
  if (inputElement) analyzeImageWithAI(inputElement, 'Found');
}

/**
 * منشئ الوسوم الذكية التفاعلي
 */
function autoGenerateTagsFromInput(modalType) {
  const isLost     = modalType === 'Lost';
  const isSecFound = modalType === 'SecFound';
  const categoryEl   = document.getElementById(isSecFound ? 'secItemCategory' : (isLost ? 'reportCategoryLost' : 'reportCategoryFound'));
  const titleEl      = document.getElementById(isSecFound ? 'secItemTitle' : (isLost ? 'reportTitleLost' : 'foundTitle'));
  const descEl       = document.getElementById(isSecFound ? 'secItemDesc' : (isLost ? 'reportDescLost' : 'reportDescFound'));
  const tagContainer = document.getElementById(isSecFound ? 'secItemTagsPreview' : (isLost ? 'aiTagsPreviewLost' : 'aiTagsPreviewFound'));
  const hiddenInput  = document.getElementById(isSecFound ? 'secItemTags' : (isLost ? 'aiTagsHiddenLost' : 'aiTagsHiddenFound'));
  if (!tagContainer) return;

  const category = categoryEl?.value ?? '';
  const combined  = ((titleEl?.value ?? '') + ' ' + (descEl?.value ?? '')).toLowerCase();
  let tags = [];

  if (category) tags.push(category);
  if (combined.includes('wallet') || combined.includes('محفظة')) tags.push('محفظة', 'حافظة بطاقات');
  if (combined.includes('id') || combined.includes('بطاقة') || category.includes('بطاقة')) tags.push('بطاقة جامعية', 'إثبات هوية');
  if (combined.includes('airpods') || combined.includes('سماعة') || combined.includes('سماعات')) tags.push('سماعات لاسلكية');
  if (combined.includes('laptop') || combined.includes('كمبيوتر') || combined.includes('لابتوب')) tags.push('كمبيوتر محمول', 'إلكترونيات');
  if (combined.includes('phone') || combined.includes('جوال') || combined.includes('هاتف')) tags.push('هاتف ذكي');
  if (combined.includes('key') || combined.includes('مفتاح')) tags.push('مفاتيح');
  if (combined.includes('bag') || combined.includes('حقيبة')) tags.push('حقيبة ظهر');

  if (tags.length === 0) tags = ['مقتنيات شخصية'];
  tags = [...new Set(tags)];

  tagContainer.innerHTML = tags.map(t => `<span class="ai-tag"> ${t}</span>`).join(' ');
  if (hiddenInput) hiddenInput.value = tags.join('، ');
}
