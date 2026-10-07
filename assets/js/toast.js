// لُقيا AI - نظام الإشعارات المنبثقة (Toast)

let toastContainer = null;

function showToast(message, type = 'success', duration = 4000) {
  if (!toastContainer) {
    toastContainer = document.createElement('div');
    toastContainer.id = 'toast-container';
    document.body.appendChild(toastContainer);
  }

  const toast = document.createElement('div');
  toast.className = `toast toast-${type}`;

  const icon = type === 'success' ? '' : type === 'danger' ? '' : '';
  const iconSpan = document.createElement('span');
  iconSpan.style.fontSize = '1.2rem';
  iconSpan.textContent = icon;
  toast.appendChild(iconSpan);
  toast.appendChild(document.createTextNode(' ' + (message === null || message === undefined ? '' : String(message))));

  toastContainer.appendChild(toast);

  setTimeout(() => {
    toast.style.opacity = '0';
    toast.style.transform = 'translateY(20px)';
    toast.style.transition = 'all 0.3s ease';
    setTimeout(() => toast.remove(), 300);
  }, duration);
}
