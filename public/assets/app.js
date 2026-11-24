(() => {
 const modal=document.getElementById('scanner-modal');
 const video=document.getElementById('scanner-video');
 const status=document.getElementById('scanner-status');
 const finishModal=document.getElementById('finish-modal');
 const finishStatus=document.getElementById('finish-status');
 const finishCode=document.getElementById('finish-worker-code');
 const finishConfirm=document.getElementById('confirm-finish');
 let expectedFinishCode='';
 function finishMessage(message){
  if(finishStatus)finishStatus.textContent=message;
  if(!modal.hidden&&target==='finish-worker')status.textContent=message;
 }
 function verifyFinishWorker(raw){
  if(!finishModal||finishModal.hidden)return;
  const code=String(raw).trim();
  finishCode.value='';
  finishConfirm.disabled=true;
  if(!/^2[0-9]{11}$/.test(code)){
   finishMessage('Нужен цифровой код работника (12 цифр, начинается с 2).');
   return;
  }
  if(code!==expectedFinishCode){
   finishMessage('Это другой работник. Отсканируйте код того, кто начал работу.');
   return;
  }
  finishCode.value=code;
  finishConfirm.disabled=false;
  finishMessage('✓ Работник подтверждён. Нажмите «Завершить».');
  if(!modal.hidden&&target==='finish-worker')stop();
 }
 function closeFinish(){
  if(!finishModal)return;
  finishModal.hidden=true;
  expectedFinishCode='';
  finishCode.value='';
  finishConfirm.disabled=true;
  if(!modal.hidden&&target==='finish-worker')stop();
  clearScan();
 }
 function openFinish(button){
  document.getElementById('finish-form').reset();
  document.querySelector('[name=quality_reason]').required=false;
  expectedFinishCode=button.dataset.finishWorkerCode||'';
  document.getElementById('finish-entry-id').value=button.dataset.finishEntry||'';
  document.getElementById('finish-worker-name').textContent=button.dataset.finishWorkerName||'';
  finishCode.value='';
  finishConfirm.disabled=true;
  finishStatus.textContent='Ожидаю код работника через USB-сканер или камеру.';
  finishModal.hidden=false;
  finishModal.querySelector('.modal-body')?.focus();
  clearScan();
 }
 let stream=null,active=false,detector=null,target='',last=0;
 function stop(){
  active=false;
  if(stream){stream.getTracks().forEach(t=>t.stop());stream=null;}
  video.srcObject=null;
  modal.hidden=true;
 }
 function found(code){
  if(target==='finish-worker'){verifyFinishWorker(code);return;}
  const select=document.getElementById(target);
  const option=Array.from(select.options).find(o=>o.dataset.code===String(code).trim());
  if(!option){status.textContent='Код '+code+' отсутствует в справочнике';return;}
  if(option.disabled){status.textContent='Станок занят. Сначала завершите предыдущую работу.';return;}
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
  if(!('BarcodeDetector'in window)){status.textContent='Браузер не поддерживает сканер. Выберите станок или работника вручную.';return;}
  try{
   const supported=await BarcodeDetector.getSupportedFormats();
   const formats=['qr_code','code_128'].filter(f=>supported.includes(f));
   if(!formats.length)throw Error('Нет поддержки QR или Code 128');
   detector=new BarcodeDetector({formats});
   stream=await navigator.mediaDevices.getUserMedia({audio:false,video:{facingMode:{ideal:'environment'}}});
   video.srcObject=stream;
   await video.play();
   active=true;status.textContent='Наведите камеру на код';requestAnimationFrame(loop);
  }catch(e){
   if(stream)stream.getTracks().forEach(t=>t.stop());stream=null;
   status.textContent='Камера недоступна: '+e.message;
  }
 }
 document.querySelectorAll('[data-target]').forEach(b=>b.addEventListener('click',()=>start(b.dataset.target)));
 document.querySelectorAll('[data-finish-entry]').forEach(b=>b.addEventListener('click',()=>openFinish(b)));
 document.getElementById('cancel-finish')?.addEventListener('click',closeFinish);
 finishModal?.addEventListener('click',e=>{if(e.target===finishModal)closeFinish();});
 const quality=document.getElementById('quality-result');
 const reason=document.querySelector('[name=quality_reason]');
 quality?.addEventListener('change',()=>{reason.required=quality.value!=='good';});
 document.querySelector('[name=rework_of]')?.addEventListener('change',e=>{const opt=e.target.selectedOptions[0];if(opt?.dataset.group)document.getElementById('group').value=opt.dataset.group;});
 document.getElementById('finish-form')?.addEventListener('submit',e=>{
  if(quality.value!=='good'&&!reason.value.trim()){e.preventDefault();finishMessage('Укажите причину брака или доработки.');return;}
  if(!finishCode.value||finishCode.value!==expectedFinishCode){e.preventDefault();finishMessage('Сначала отсканируйте код работника.');}
 });
 document.getElementById('close-scanner')?.addEventListener('click',stop);
 modal?.addEventListener('click',e=>{if(e.target===modal)stop();});
 document.addEventListener('keydown',e=>{if(e.key!=='Escape')return;if(!modal.hidden)stop();else if(finishModal&&!finishModal.hidden)closeFinish();});

 // USB HID scanner: receives a fast QR payload followed by Enter.
 let scanBuffer='', scanPrevious=0, scanStart=0, scanCount=0;
 let scanTimer;
 function clearScan(){scanBuffer='';scanCount=0;scanStart=0;scanPrevious=0;clearTimeout(scanTimer);}
 function usePayload(raw){
  const match=/^([123])([0-9]{11})$/.exec(raw.trim());
  const banner=finishModal&&!finishModal.hidden?finishStatus:document.getElementById('usb-scan-status');
  if(!match){
   const message='Неверный код: нужен цифровой код из 12 цифр (1/2/3 + 11 цифр). Получено: '+raw;
   if(finishModal&&!finishModal.hidden)finishMessage(message);
   else if(banner)banner.textContent=message;
   return;
  }
  const kind=match[1],code=match[0];
  if(finishModal&&!finishModal.hidden){verifyFinishWorker(code);return;}
  const select=document.getElementById(({'1':'machine','2':'worker','3':'group'})[kind]);
  if(!select){if(banner)banner.textContent='Откройте вкладку «Работа»';return;}
  const option=[...select.options].find(o=>o.dataset.code===code);
  if(!option){if(banner)banner.textContent='Код '+raw+' отсутствует среди доступных записей';return;}
  if(option.disabled){select.value='';if(banner)banner.textContent='Станок занят. Сначала завершите предыдущую работу.';return;}
  select.value=option.value;
  select.dispatchEvent(new Event('change',{bubbles:true}));
  if(banner)banner.textContent='✓ '+raw+' — '+option.textContent.trim();
 }
 document.addEventListener('keydown',event=>{
  if((!modal.hidden&&target!=='finish-worker')||event.ctrlKey||event.altKey||event.metaKey||event.isComposing){clearScan();return;}
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