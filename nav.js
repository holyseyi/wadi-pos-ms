document.addEventListener('DOMContentLoaded', function () {
  var toggles = document.querySelectorAll('.menu-toggle');
  toggles.forEach(function (toggle) {
    toggle.addEventListener('click', function () {
      var menu = toggle.parentElement.querySelector('.header-actions');
      if (!menu) return;
      var open = menu.classList.toggle('open');
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  });

  document.querySelectorAll('.header-actions a').forEach(function (link) {
    link.addEventListener('click', function () {
      var menu = link.closest('.header-actions');
      if (!menu) return;
      menu.classList.remove('open');
      var toggle = menu.parentElement.querySelector('.menu-toggle');
      if (toggle) toggle.setAttribute('aria-expanded', 'false');
    });
  });
});
