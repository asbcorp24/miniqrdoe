(() => {
  'use strict';
  const grid = document.getElementById('labels-grid');
  const types = document.getElementById('label-type');
  const sizes = document.getElementById('label-size');
  const message = document.getElementById('label-message');
  const labels = [...document.querySelectorAll('.qr-label')];
  const rows = [];
  const format=document.getElementById('label-format'),paper=document.getElementById('paper-mode');
  const pageStyle=document.createElement('style');document.head.append(pageStyle);
  function layout(){const w=Number(sizes.value),h=({40:20,50:30,58:40,60:40,70:50})[w];grid.dataset.format=format.value;grid.style.setProperty('--lw',w+'mm');grid.style.setProperty('--lh',h+'mm');grid.style.setProperty('--cols',paper.value==='thermal'?1:Math.floor(193/(w+3)));grid.classList.toggle('thermal',paper.value==='thermal');pageStyle.textContent=paper.value==='thermal'?'@page{size:'+w+'mm '+h+'mm;margin:0}':'@page{size:A4;margin:10mm}';}
  format.addEventListener('change',layout);paper.addEventListener('change',layout);sizes.addEventListener('change',layout);layout();
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
      if (!/^[1234][0-9]{11}$/.test(payload)) throw new Error('Некорректный код');
      node.querySelector('.barcode-graphic').append(Code128.render(payload));
      new QRCode(node.querySelector('.qr-graphic'), {
        text: payload, width: 128, height: 128,
        colorDark: '#000000', colorLight: '#ffffff',
        correctLevel: QRCode.CorrectLevel.M
      });
      rows.push({ node, check: node.querySelector('.choose-label') });
    } catch (e) {
      node.querySelector('.qr-graphic').textContent = 'Ошибка QR';
      node.querySelector('.choose-label').checked = false;
      node.querySelector('.choose-label').disabled = true;
      rows.push({ node, check: node.querySelector('.choose-label') });
    }
  }
  types.addEventListener('change', refresh);
  sizes.addEventListener('change', () => {
    grid.classList.toggle('wide-labels', sizes.value === '70');
  });
  document.getElementById('select-all-labels').addEventListener('click', () => {
    rows.forEach(row => { if (!row.node.hidden && !row.check.disabled) row.check.checked = true; });
  });
  document.getElementById('clear-all-labels').addEventListener('click', () => {
    rows.forEach(row => { if (!row.node.hidden) row.check.checked = false; });
  });
  document.getElementById('print-labels').addEventListener('click', () => {
    let count = 0;
    for (const row of rows) {
      const shouldPrint = !row.node.hidden && !row.check.disabled && row.check.checked;
      row.node.classList.toggle('print-item', shouldPrint);
      if (shouldPrint) count++;
    }
    if (!count) { message.textContent = 'Выберите хотя бы одну этикетку'; return; }
    if (typeof QRCode !== 'function' || typeof Code128 !== 'object') { message.textContent = 'Генератор QR не загружен'; return; }
    if(format.value==='both' && Number(sizes.value)<58){message.textContent='Для двух кодов выберите размер от 58 × 40 мм';return;}
    document.body.classList.add('printing-labels');
    // Let QRCode's image rendering finish before opening the print dialog.
    setTimeout(() => window.print(), 300);
  });
  window.addEventListener('afterprint', () => {
    document.body.classList.remove('printing-labels');
  });
  refresh();
})();
