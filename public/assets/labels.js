(() => {
  'use strict';
  const grid = document.getElementById('labels-grid');
  const types = document.getElementById('label-type');
  const sizes = document.getElementById('label-size');
  const message = document.getElementById('label-message');
  const labels = [...document.querySelectorAll('.qr-label')];
  const rows = [];
  function refresh() {
    const selectedType = types.value;
    let visible = 0;
    for (const row of rows) {
      const matches = selectedType === 'all' || row.node.dataset.type === selectedType;
      row.node.hidden = !matches;
      if (matches) visible++;
    }
    message.textContent = 'Показано этикеток: ' + visible;
  }
  for (const node of labels) {
    const payload = node.dataset.payload || '';
    try {
      if (!/^[123][0-9]{11}$/.test(payload)) throw new Error('Некорректный код');
      new QRCode(node.querySelector('.qr-graphic'), {
        text: payload, width: 128, height: 128,
        colorDark: '#000000', colorLight: '#ffffff',
        correctLevel: QRCode.CorrectLevel.M
      });
      rows.push({ node, check: node.querySelector('.choose-label') });
    } catch (e) {
      node.querySelector('.qr-graphic').textContent = 'Ошибка QR';
      node.querySelector('.choose-label').checked = false;
      rows.push({ node, check: node.querySelector('.choose-label') });
    }
  }
  types.addEventListener('change', refresh);
  sizes.addEventListener('change', () => {
    grid.classList.toggle('wide-labels', sizes.value === '70');
  });
  document.getElementById('select-all-labels').addEventListener('click', () => {
    rows.forEach(row => { if (!row.node.hidden) row.check.checked = true; });
  });
  document.getElementById('clear-all-labels').addEventListener('click', () => {
    rows.forEach(row => { if (!row.node.hidden) row.check.checked = false; });
  });
  document.getElementById('print-labels').addEventListener('click', () => {
    let count = 0;
    for (const row of rows) {
      const shouldPrint = !row.node.hidden && row.check.checked;
      row.node.classList.toggle('print-item', shouldPrint);
      if (shouldPrint) count++;
    }
    if (!count) { message.textContent = 'Выберите хотя бы одну этикетку'; return; }
    if (typeof QRCode !== 'function') { message.textContent = 'Генератор QR не загружен'; return; }
    document.body.classList.add('printing-labels');
    // Let QRCode's image rendering finish before opening the print dialog.
    setTimeout(() => window.print(), 300);
  });
  window.addEventListener('afterprint', () => {
    document.body.classList.remove('printing-labels');
  });
  refresh();
})();
