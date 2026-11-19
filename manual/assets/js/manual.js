// Teknisoft Contab - Manual: menu movel, pesquisa no indice e lightbox dos prints.
(function () {
  var body = document.body;
  var btn = document.querySelector('.menu-btn');
  if (btn) btn.addEventListener('click', function (e) { e.stopPropagation(); body.classList.toggle('nav-open'); });
  document.addEventListener('click', function (e) {
    if (body.classList.contains('nav-open') && !e.target.closest('.side')) body.classList.remove('nav-open');
  });

  var printBtn = document.querySelector('.print-btn');
  if (printBtn) printBtn.addEventListener('click', function () { window.print(); });

  // Pesquisa: filtra entradas do menu pelo titulo e palavras-chave.
  var input = document.querySelector('.side .search input');
  if (input) input.addEventListener('input', function () {
    var q = input.value.trim().toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
    document.querySelectorAll('.side nav a').forEach(function (a) {
      var hay = (a.textContent + ' ' + (a.getAttribute('data-k') || '')).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
      a.classList.toggle('hidden', q !== '' && hay.indexOf(q) === -1);
    });
  });

  // Lightbox
  var lb = document.createElement('div');
  lb.className = 'lb';
  lb.innerHTML = '<img alt=""><div class="cap"></div>';
  document.body.appendChild(lb);
  var lbImg = lb.querySelector('img'), lbCap = lb.querySelector('.cap');
  document.querySelectorAll('figure.shot img').forEach(function (img) {
    img.addEventListener('click', function () {
      lbImg.src = img.src;
      var cap = img.closest('figure').querySelector('figcaption');
      lbCap.textContent = cap ? cap.textContent : '';
      lb.classList.add('open');
    });
  });
  lb.addEventListener('click', function () { lb.classList.remove('open'); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') lb.classList.remove('open'); });
})();
