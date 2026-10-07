<!-- نافذة مساعد لُقيا AI الذكي التفاعلية (AI Chat Assistant Modal) -->
<div class="modal-overlay" id="modalAIChat" style="z-index: 1050;">
  <div class="modal-container ai-chat-container" style="border-radius: 16px;">
    
    <!-- ترويسة نافذة المحادثة -->
    <div class="modal-header ai-chat-header" style="background:linear-gradient(135deg,var(--navy-primary) 0%,var(--navy-dark) 100%);color:#FFFFFF;border-radius:16px 16px 0 0;padding:1.25rem 1.5rem;">
      <div style="display: flex; align-items: center; gap: 12px;">
        <div class="ai-avatar-pulse" style="width:44px;height:44px;background:#FFFFFF;border:2px solid var(--cyan-primary);border-radius:12px;display:flex;align-items:center;justify-content:center;position:relative;">
          <img src="assets/images/luqya-magnifier.svg" alt="" class="ai-chat-avatar-logo" width="30" height="32">
        </div>
        <div>
          <h3 class="modal-title" style="font-size: 1.15rem; display: flex; align-items: center; gap: 6px; color:#FFFFFF; margin:0;">
            مساعد <span translate="no" class="notranslate brand-wordmark" dir="rtl" style="display: inline-block; unicode-bidi: isolate; color: #00A8B5; font-weight:800;">لُقيا AI</span> الذكي
          </h3>
          <div style="display: flex; align-items: center; gap: 6px; font-size: 0.75rem; color: #CBD5E1; margin-top: 2px;">
            <span class="status-dot-live"></span>
            <span>متصل بقاعدة بيانات البلاغات الحية</span>
          </div>
        </div>
      </div>
      
      <div style="display: flex; align-items: center; gap: 10px;">
        <button type="button" class="btn-chat-action" onclick="resetAIChatHistory()" title="بدء محادثة جديدة" style="background:rgba(255,255,255,0.1);border:1px solid rgba(255,255,255,0.2);color:#fff;border-radius:8px;padding:6px;cursor:pointer;display:flex;align-items:center;justify-content:center;">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/>
          </svg>
        </button>
        <button type="button" class="btn-chat-action" onclick="closeModal('modalAIChat')" title="إغلاق" style="background:rgba(255,255,255,0.1);border:1px solid rgba(255,255,255,0.2);color:#fff;border-radius:8px;padding:6px;cursor:pointer;display:flex;align-items:center;justify-content:center;">&times;</button>
      </div>
    </div>

    <!-- منطقة الرسائل والمحادثة -->
    <div class="modal-body ai-chat-body" id="aiChatBody">
      
      <div class="chat-messages-wrap" id="aiChatMessages">
        <!-- رسالة الترحيب الأولى من المساعد الذكي -->
        <div class="chat-msg bot-msg">
          <div class="chat-msg-bubble">
            <p style="margin: 0 0 8px 0; font-weight: 700; color: var(--navy-primary);">
              أهلاً بك! أنا مساعد <span translate="no" class="notranslate brand-wordmark" dir="rtl" style="display: inline-block; unicode-bidi: isolate;">لُقيا AI</span> الذكي للمفقودات والمعثورات الجامعية.
            </p>
            <p style="margin: 0 0 8px 0; font-size: 0.88rem; line-height: 1.6; color: var(--text-primary);">
              أنا متصل مباشرةً بقاعدة بلاغات المفقودات والمعثورات الحالية بالجامعة. يمكنك أن تسألني بأي صياغة طبيعية؛ سأبحث لك فوراً في البلاغات أو أرشدك لأقصر طريق لاستعادة أغراضك.
            </p>
            <div class="chat-suggestions-title" style="font-weight:700;margin:10px 0 6px;color:var(--navy-primary);font-size:0.85rem;"> استفسارات مقترحة يمكنك تجربتها فوراً:</div>
            <div class="chat-chips-row">
              <button type="button" class="chat-chip" onclick="quickSendAIChat('فقدت محفظة سوداء في كلية الهندسة، هل هي مسجلة؟')">
                هل توجد محفظة سوداء بكلية الهندسة؟
              </button>
              <button type="button" class="chat-chip" onclick="quickSendAIChat('عثرت على بطاقة جامعية في الجامعة، ماذا أفعل؟')">
                عثرت على بطاقة جامعية في الجامعة، ماذا أفعل؟
              </button>
              <button type="button" class="chat-chip" onclick="quickSendAIChat('هل تم العثور على سماعات إيربودز بيضاء في الكافتيريا؟')">
                هل تم العثور على سماعات إيربودز؟
              </button>
              <button type="button" class="chat-chip" onclick="quickSendAIChat('أين يقع مكتب الأمن وما هو كود التوثيق لاستلام مفقوداتي؟')">
                أين يقع مكتب الأمن وكيف أستلم؟
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- مؤشر الكتابة التفاعلي (Typing Indicator) -->
      <div class="chat-typing-indicator" id="aiChatTyping" style="display: none;">
        <div class="typing-bubble">
          <span class="typing-dot"></span>
          <span class="typing-dot"></span>
          <span class="typing-dot"></span>
          <small style="margin-inline-start: 8px; font-size: 0.75rem; color: #64748B;">المساعد يحلل البلاغات ويكتب الرد...</small>
        </div>
      </div>

    </div>

    <!-- شريط إدخال الرسالة والإرسال -->
    <div class="ai-chat-footer">
      <form id="aiChatForm" onsubmit="handleAIChatSubmit(event)" style="display: flex; gap: 8px; align-items: center; width: 100%;">
        <input type="text"
               id="aiChatInput"
               class="ai-chat-input"
               placeholder="اكتب استفسارك هنا..."
               autocomplete="off"
               required>
        <button type="submit" class="btn btn-primary btn-chat-send" id="aiChatSendBtn" title="إرسال" style="display:flex;align-items:center;gap:6px;">
          <span>إرسال</span>
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="transform:scaleX(-1);">
            <line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>
          </svg>
        </button>
      </form>
      <div class="ai-chat-disclaimer">
         مدعوم بنموذج <span translate="no" class="notranslate" style="direction: ltr; display: inline-block; unicode-bidi: isolate;">Gemini AI</span>
      </div>
    </div>

  </div>
</div>
