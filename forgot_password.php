<?php
require_once __DIR__ . '/config/db.php';
if (isLoggedIn()) { redirectHome(); exit; }

// الخطوة الحالية: 'request' لطلب الرمز أو 'reset' لتعيين كلمة مرور جديدة
$step = (isset($_GET['step']) && $_GET['step'] === 'reset') ? 'reset' : 'request';
$initialToken = trim((string) ($_GET['token'] ?? ''));

require_once __DIR__ . '/views/header.php';
?>

<main class="main-layout" style="display:flex;align-items:center;justify-content:center;min-height:70vh;">
  <div style="background:#FFFFFF;border-radius:14px;padding:2.5rem;width:100%;max-width:440px;box-shadow:var(--shadow-md);border:1px solid var(--border-color);">
    <div style="text-align:center;margin-bottom:2rem;">
      <h2 style="font-size:1.5rem;color:var(--navy-primary);margin:0 0 6px 0;">إعادة ضبط كلمة المرور</h2>
      <?php if ($step === 'reset'): ?>
        <p style="font-size:0.88rem;color:var(--text-muted);">أدخل رمز إعادة التعيين وكلمة المرور الجديدة.</p>
      <?php else: ?>
        <p style="font-size:0.88rem;color:var(--text-muted);">أدخل الرقم الجامعي أو البريد الجامعي لبدء طلب إعادة الضبط.</p>
      <?php endif; ?>
    </div>

    <?php if ($step === 'reset'): ?>
      <form id="formResetPass" onsubmit="handleResetSubmit(event)">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
        <div class="form-group">
          <label class="form-label">رمز إعادة التعيين</label>
          <input type="text" class="form-control" name="token" id="resetToken"
                 value="<?= htmlspecialchars($initialToken, ENT_QUOTES, 'UTF-8'); ?>"
                 placeholder="الصق الرمز المستلم من الرسالة" required pattern="[A-Za-z0-9]{64}">
        </div>
        <div class="form-group">
          <label class="form-label">كلمة المرور الجديدة</label>
          <input type="password" class="form-control" name="password" id="resetPassword"
                 placeholder="6 أحرف أو أكثر" required minlength="6">
        </div>
        <div class="form-group">
          <label class="form-label">تأكيد كلمة المرور الجديدة</label>
          <input type="password" class="form-control" name="password_confirm" id="resetPasswordConfirm"
                 placeholder="أعد إدخال كلمة المرور" required minlength="6">
        </div>

        <button type="submit" class="btn btn-primary" style="width:100%;margin-top:1rem;">تحديث كلمة المرور</button>
      </form>

      <div style="text-align:center;margin-top:1.5rem;font-size:0.88rem;">
        <a href="forgot_password.php" style="color:var(--text-muted);">طلب رمز جديد</a>
      </div>
    <?php else: ?>
      <form id="formForgotPass" onsubmit="handleForgotSubmit(event)">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
        <div class="form-group">
          <label class="form-label">الرقم الجامعي أو البريد الجامعي</label>
          <input type="text" class="form-control" name="identifier"
                 placeholder="مثال: S000000001 أو s000000001@uoh.edu.sa" required>
        </div>

        <button type="submit" class="btn btn-primary" style="width:100%;margin-top:1rem;">إرسال تعليمات إعادة الضبط</button>
      </form>

      <?php if (isResetTokenExposedToLog()): ?>
      <div style="margin-top:1.25rem;padding:0.9rem;background:#FFF8E6;border:1px solid #E8C97A;border-radius:10px;font-size:0.8rem;color:#6B5410;line-height:1.7;">
        <strong style="display:block;margin-bottom:4px;">وضع التطوير — لا يوجد خادم بريد</strong>
        لا تُرسل هذه النسخة بريداً إلكترونياً. عند طلب الرمز يُكتب رابط إعادة التعيين في
        سجل أخطاء Apache على هذا المسار:
        <code style="direction:ltr;display:inline-block;">C:\xampp\apache\logs\error.log</code>
        ابحث فيه عن السطر الذي يبدأ بـ
        <code style="direction:ltr;display:inline-block;">Luqya AI: [DEV ONLY]</code>
        ثم انسخ قيمة <code style="direction:ltr;">token</code> من نهاية الرابط، والصقها في الخطوة الثانية.
        <br>الرمز صالح 30 دقيقة ويُستخدم مرة واحدة فقط.
        <br><small>ملاحظة: <code style="direction:ltr;display:inline-block;">error_log</code> في
        <code style="direction:ltr;display:inline-block;">php.ini</code> يشير إلى مجلد
        <code style="direction:ltr;display:inline-block;">C:\xampp\php\logs\</code> غير الموجود،
        لذلك يذهب سجل PHP إلى سجل Apache أعلاه.</small>
      </div>
      <?php endif; ?>
    <?php endif; ?>

    <div style="text-align:center;margin-top:1.5rem;font-size:0.88rem;">
      <a href="login.php" style="color:var(--text-muted);">العودة لتسجيل الدخول</a>
    </div>
  </div>
</main>

<script>
function handleForgotSubmit(event) {
  event.preventDefault();
  fetch('api/auth.php?action=forgot_password', { method:'POST', body: new FormData(event.target) })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        showToast(data.message, 'success', 6000);
        setTimeout(() => window.location.href = 'login.php', 2500);
      } else {
        showToast(data.message || 'حدث خطأ في إرسال الطلب', 'danger');
      }
    })
    .catch(() => showToast('خطأ في الاتصال بالشبكة', 'danger'));
}

function handleResetSubmit(event) {
  event.preventDefault();
  const formData = new FormData(event.target);
  const password = formData.get('password') || '';
  const confirmPassword = formData.get('password_confirm') || '';
  if (password !== confirmPassword) {
    showToast('كلمتا المرور غير متطابقتين.', 'danger');
    return;
  }
  fetch('api/auth.php?action=reset_password', { method:'POST', body: formData })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        showToast(data.message, 'success');
        setTimeout(() => window.location.href = 'login.php', 1200);
      } else {
        showToast(data.message || 'تعذر تحديث كلمة المرور.', 'danger');
      }
    })
    .catch(() => showToast('خطأ في الاتصال بالشبكة', 'danger'));
}
</script>

<?php require_once __DIR__ . '/views/footer.php'; ?>