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
})();