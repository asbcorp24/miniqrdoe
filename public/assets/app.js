(() => {
 const modal=document.getElementById('scanner-modal');
 const video=document.getElementById('scanner-video');
 const status=document.getElementById('scanner-status');
 let stream=null,active=false,detector=null,target='',last=0;
 function stop(){
  active=false;
  if(stream){stream.getTracks().forEach(t=>t.stop());stream=null;}
  video.srcObject=null;
  modal.hidden=true;
 }
 function found(code){
  const select=document.getElementById(target);
  const option=Array.from(select.options).find(o=>o.dataset.code===String(code).trim());
  if(!option){status.textContent='Код '+code+' отсутствует в справочнике';return;}
  select.value=option.value;
  select.dispatchEvent(new Event('change',{bubbles:true}));
  stop();
 }
 async function loop(){
  if(!active)return;
  if(video.readyState>=2&&Date.now()-last>200){
   last=Date.now();
   try{
    const results=await detector.detect(video);
    if(results.length)found(results[0].rawValue);
   }catch(e){status.textContent='Ошибка декодирования: '+e.message;}
  }
  if(active)requestAnimationFrame(loop);
 }
 async function start(which){
  target=which;modal.hidden=false;status.textContent='Подключаем камеру...';
  if(!navigator.mediaDevices?.getUserMedia){status.textContent='Для камеры откройте сайт по HTTPS (или localhost).';return;}
  if(!('BarcodeDetector'in window)){status.textContent='Браузер не поддерживает QR-сканер. Выберите станок или работника вручную.';return;}
  try{
   detector=new BarcodeDetector({formats:['qr_code']});
   stream=await navigator.mediaDevices.getUserMedia({audio:false,video:{facingMode:{ideal:'environment'}}});
   video.srcObject=stream;
   await video.play();
   active=true;status.textContent='Наведите камеру на QR-код';requestAnimationFrame(loop);
  }catch(e){
   if(stream)stream.getTracks().forEach(t=>t.stop());stream=null;
   status.textContent='Камера недоступна: '+e.message;
  }
 }
 document.querySelectorAll('[data-target]').forEach(b=>b.addEventListener('click',()=>start(b.dataset.target)));
 document.getElementById('close-scanner')?.addEventListener('click',stop);
 modal?.addEventListener('click',e=>{if(e.target===modal)stop();});
 document.addEventListener('keydown',e=>{if(e.key==='Escape'&&!modal.hidden)stop();});

 // USB HID scanner: receives a fast QR payload followed by Enter.
 let scanBuffer='', scanPrevious=0, scanStart=0, scanCount=0;
 let scanTimer;
 function clearScan(){scanBuffer='';scanCount=0;scanStart=0;scanPrevious=0;clearTimeout(scanTimer);}
 function usePayload(raw){
  const match=/^([123])([0-9]{11})$/.exec(raw.trim());
  const banner=document.getElementById('usb-scan-status');
  if(!match){if(banner)banner.textContent='Неверный QR: нужен цифровой код из 12 цифр (1/2/3 + 11 цифр). Получено: '+raw;return;}
  const kind=match[1],code=match[0];
  const select=document.getElementById(({'1':'machine','2':'worker','3':'group'})[kind]);
  if(!select){if(banner)banner.textContent='Откройте вкладку «Работа»';return;}
  const option=[...select.options].find(o=>o.dataset.code===code);
  if(!option){if(banner)banner.textContent='Код '+raw+' отсутствует среди доступных записей';return;}
  select.value=option.value;
  select.dispatchEvent(new Event('change',{bubbles:true}));
  if(banner)banner.textContent='✓ '+raw+' — '+option.textContent.trim();
 }
 document.addEventListener('keydown',event=>{
  if(!modal.hidden||event.ctrlKey||event.altKey||event.metaKey||event.isComposing){clearScan();return;}
  const node=event.target;
  if(node instanceof HTMLElement&&(node.matches('textarea,input[type="password"],input[type="text"],input[type="search"]')||node.isContentEditable)){clearScan();return;}
  const now=performance.now();
  if(now-scanPrevious>350)clearScan();
  if(event.key==='Enter'){
   if(scanCount>=5&&now-scanStart<=Math.max(1200,scanCount*250)){
    event.preventDefault();usePayload(scanBuffer);
   }
   clearScan();return;
  }
  if(event.key.length!==1||event.repeat)return;
  if(!scanCount)scanStart=now;
  scanBuffer+=event.key;scanCount++;scanPrevious=now;
  if(scanCount>150){clearScan();return;}
  clearTimeout(scanTimer);scanTimer=setTimeout(clearScan,450);
 },true);
})();