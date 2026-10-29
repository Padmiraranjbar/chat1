(function () {
  'use strict';
  // Function keys must navigate to the dedicated page because module forms are no longer
  // displayed together on the dashboard.
  var shortcuts = {
    F2: { page: 'product', label: 'تعریف کالا' },
    F3: { page: 'purchase', label: 'ثبت خرید' },
    F4: { page: 'sale', label: 'ثبت فروش' }
  };
  document.addEventListener('keydown', function (event) {
    var shortcut = shortcuts[event.key];
    if (!shortcut || event.altKey || event.ctrlKey || event.metaKey || event.shiftKey) return;
    var target = event.target;
    if (target && (target.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(target.tagName))) return;
    event.preventDefault();
    window.location.href = 'index.php?page=' + encodeURIComponent(shortcut.page);
  });
})();