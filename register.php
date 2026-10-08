<?php
require_once __DIR__ . '/config/db.php';
if (isLoggedIn()) { redirectHome(); exit; }
require_once __DIR__ . '/views/header.php';
?>

<main class="main-layout" style="display:flex;align-items:center;justify-content:center;min-height:75vh;">
  <div style="background:#FFFFFF;border-radius:14px;padding:2.5rem;width:100%;max-width:480px;box-shadow:var(--shadow-md);border:1px solid var(--border-color);">
    <div style="text-align:center;margin-bottom:2rem;">
      <h2 style="font-size:1.6rem;color:var(--navy-primary);margin:0;">إنشاء حساب جديد</h2>
    </div>

    <form id="formRegister" onsubmit="handleRegisterSubmit(event)">
      <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
      <div class="form-group">
        <label class="form-label">الرقم الجامعي</label>
        <input type="text" class="form-control" name="university_id" id="regStudentId"
               placeholder="S000000001" required pattern="^[Ss]\d{9}$" oninput="updateEmailPreview(this.value)">
      </div>

      <div class="form-group">
        <label class="form-label">البريد الجامعي</label>
        <input type="email" class="form-control" id="regEmailPreview" value="sXXXXXXXXX@uoh.edu.sa"
               readonly style="background-color:#F8FAFC;color:var(--text-muted);">
      </div>

      <div class="form-group">
        <label class="form-label">الاسم الكامل</label>
        <input type="text" class="form-control" name="full_name" required>
      </div>

      <div class="form-group">
        <label class="form-label">الكلية</label>
        <input type="text" class="form-control" name="college" placeholder="مثال: كلية علوم وهندسة الحاسب" maxlength="100">
      </div>

      <div class="form-group">
        <label class="form-label">التخصص</label>
        <input type="text" class="form-control" name="major" placeholder="مثال: هندسة البرمجيات" maxlength="100">
      </div>

      <div class="form-group">
        <label class="form-label">رقم الجوال</label>
        <input type="tel" class="form-control" name="phone" placeholder="+96651234567" required>
      </div>

      <div class="form-group">
        <label class="form-label">كلمة المرور</label>
        <div class="password-field">
          <input type="password" class="form-control" name="password" id="regPassword" placeholder="••••••••" required minlength="6" autocomplete="new-password">
          <button type="button" class="password-toggle" aria-label="إظهار كلمة المرور" onclick="togglePassword(this, 'regPassword')">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <g class="eye-open">
                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                <circle cx="12" cy="12" r="3"/>
              </g>
              <g class="eye-closed" style="display:none;">
                <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/>
                <line x1="1" y1="1" x2="23" y2="23"/>
              </g>
            </svg>
          </button>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">الرمز الأمني للاسترجاع <span style="color:var(--text-muted);font-weight:400;font-size:0.8rem;">(اختياري لكن موصى به)</span></label>
        <input type="password" class="form-control" name="security_code" id="regSecurityCode"
               placeholder="6 أحرف أو أكثر — سيُطلب عند إعادة تعيين كلمة المرور" minlength="6" maxlength="64" autocomplete="new-password">
      </div>

      <button type="submit" class="btn btn-primary" style="width:100%;margin-top:1rem;">إنشاء الحساب</button>
    </form>

    <div style="text-align:center;margin-top:1.5rem;font-size:0.88rem;color:var(--text-muted);">
      لديك حساب بالفعل؟ <a href="login.php" style="color:var(--cyan-primary);font-weight:600;">تسجيل الدخول</a>
    </div>
  </div>
</main>

<script>
function togglePassword(btn, inputId) {
  const input = document.getElementById(inputId);
  const isMasked = input.type === 'password';
  const openG = btn.querySelector('.eye-open');
  const closedG = btn.querySelector('.eye-closed');
  if (isMasked) {
    input.type = 'text';
    if (openG) openG.style.display = 'none';
    if (closedG) closedG.style.display = 'inline';
    btn.setAttribute('aria-label', 'إخفاء كلمة المرور');
  } else {
    input.type = 'password';
    if (openG) openG.style.display = 'inline';
    if (closedG) closedG.style.display = 'none';
    btn.setAttribute('aria-label', 'إظهار كلمة المرور');
  }
}
function updateEmailPreview(val) {
  const preview = document.getElementById('regEmailPreview');
  val = val.trim();
  preview.value = val ? val.toLowerCase() + '@uoh.edu.sa' : 'sXXXXXXXXX@uoh.edu.sa';
}
function handleRegisterSubmit(event) {
  event.preventDefault();
  fetch('api/auth.php?action=register', { method:'POST', body: new FormData(event.target) })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        showToast(data.message, 'success');
        setTimeout(() => window.location.href = data.redirect || 'index.php', 1200);
      } else {
        showToast(data.message || 'فشل إنشاء الحساب', 'danger');
      }
    })
    .catch(() => showToast('خطأ في الاتصال بالشبكة', 'danger'));
}
</script>

<?php require_once __DIR__ . '/views/footer.php'; ?>
