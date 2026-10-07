<?php
require_once __DIR__ . '/config/db.php';
if (isLoggedIn()) { redirectHome(); exit; }
require_once __DIR__ . '/views/header.php';
?>

<main class="main-layout" style="display:flex;align-items:center;justify-content:center;min-height:70vh;">
  <div style="background:#FFFFFF;border-radius:14px;padding:2.5rem;width:100%;max-width:440px;box-shadow:var(--shadow-md);border:1px solid var(--border-color);">
    <div style="text-align:center;margin-bottom:2rem;">
      <h2 style="font-size:1.6rem;color:var(--navy-primary);margin:0;">تسجيل الدخول</h2>
    </div>

    <form id="formLogin" onsubmit="handleLoginSubmit(event)">
      <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
      <div class="form-group">
        <label class="form-label">الرقم الجامعي أو كود الأمن</label>
        <input type="text" class="form-control" name="university_id" id="loginStudentId"
               placeholder="الرقم الجامعي أو كود الأمن" required pattern="^(S\d{9}|SEC-[A-Za-z0-9-]{1,16})$">
      </div>

      <div class="form-group">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
          <label class="form-label" style="margin:0;">كلمة المرور</label>
          <a href="forgot_password.php" style="font-size:0.8rem;color:var(--cyan-primary);">نسيت كلمة المرور؟</a>
        </div>
        <div class="password-field">
          <input type="password" class="form-control" name="password" id="loginPassword" placeholder="••••••••" required>
          <button type="button" class="password-toggle" aria-label="إظهار كلمة المرور" onclick="togglePassword(this, 'loginPassword')">
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

      <button type="submit" class="btn btn-primary" style="width:100%;margin-top:1rem;">تسجيل الدخول</button>
    </form>

    <?php
    // لوحة الدخول التجريبي: تظهر فقط عند تفعيل LUQYA_DEMO_LOGIN (المفعّل افتراضياً).
    // زرها يملأ الحقلين فقط ثم يُرسَل النموذج عبر المسار العادي — لا يوجد أي تجاوز للتحقق.
    $demoAccounts = demoLoginAccounts();
    if ($demoAccounts):
    ?>
    <div style="margin-top:1.5rem;padding:1rem;background:#F4F9FB;border:1px dashed var(--border-color);border-radius:10px;">
      <div style="font-size:0.82rem;font-weight:700;color:var(--navy-primary);margin-bottom:2px;">دخول تجريبي سريع</div>
      <div style="font-size:0.76rem;color:var(--text-muted);margin-bottom:0.75rem;">
        للتجربة الفورية دون إنشاء حساب. اضغط الزر لتعبئة الحقول، ثم «تسجيل الدخول».
      </div>
      <?php foreach ($demoAccounts as $acc): ?>
        <button type="button"
                class="demo-login-btn"
                data-demo-id="<?= htmlspecialchars($acc['university_id'], ENT_QUOTES, 'UTF-8'); ?>"
                data-demo-pass="<?= htmlspecialchars($acc['password'], ENT_QUOTES, 'UTF-8'); ?>"
                style="display:block;width:100%;text-align:right;padding:0.6rem 0.8rem;margin-bottom:0.5rem;background:#FFFFFF;border:1px solid var(--border-color);border-radius:8px;cursor:pointer;font-size:0.84rem;color:var(--navy-primary);font-family:inherit;">
          <strong style="display:block;"><?= htmlspecialchars($acc['label'], ENT_QUOTES, 'UTF-8'); ?></strong>
          <span style="font-family:monospace;font-size:0.78rem;color:var(--text-muted);direction:ltr;display:block;">
            <?= htmlspecialchars($acc['university_id'], ENT_QUOTES, 'UTF-8'); ?> / <?= htmlspecialchars($acc['password'], ENT_QUOTES, 'UTF-8'); ?>
          </span>
        </button>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div style="text-align:center;margin-top:1.5rem;font-size:0.88rem;color:var(--text-muted);">
      ليس لديك حساب؟ <a href="register.php" style="color:var(--cyan-primary);font-weight:600;">إنشاء حساب جديد</a>
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
function handleLoginSubmit(event) {
  event.preventDefault();
  fetch('api/auth.php?action=login', { method:'POST', body: new FormData(event.target) })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        showToast(data.message, 'success');
        setTimeout(() => window.location.href = data.redirect || 'index.php', 1000);
      } else {
        showToast(data.message || 'فشل تسجيل الدخول', 'danger');
      }
    })
    .catch(() => showToast('خطأ في الاتصال بالشبكة', 'danger'));
}

// الدخول التجريبي: تعبئة الحقول فقط. لا يرسل شيئاً ولا يتجاوز أي تحقق؛
// الطلب يمرّ بعدها من api/auth.php كما لو كتب المستخدم البيانات بنفسه.
document.querySelectorAll('.demo-login-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    const idField = document.getElementById('loginStudentId');
    const passField = document.getElementById('loginPassword');
    if (!idField || !passField) return;
    idField.value = btn.dataset.demoId || '';
    passField.value = btn.dataset.demoPass || '';
    idField.dispatchEvent(new Event('input', { bubbles: true }));
    passField.dispatchEvent(new Event('input', { bubbles: true }));
    showToast('تم تعبئة البيانات التجريبية — اضغط «تسجيل الدخول».', 'success', 3000);
    document.getElementById('formLogin').querySelector('button[type=submit]').focus();
  });
});
</script>

<?php require_once __DIR__ . '/views/footer.php'; ?>
