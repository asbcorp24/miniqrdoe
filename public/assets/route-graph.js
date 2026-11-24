(()=>{'use strict';
function init(){
 const host=document.getElementById('route-graph'),builder=document.getElementById('route-builder');if(!host||!builder)return;
 const ns='http://www.w3.org/2000/svg',coords=new WeakMap();let source=null,drag=null,svg;
 const fields=()=>Array.from(builder.children);
 function parents(f,i){const raw=f.querySelector('[data-step-parents]').value.trim();return raw?raw.split(',').map(Number):[i];}
 function el(tag,attrs={},label){const n=document.createElementNS(ns,tag);for(const [k,v] of Object.entries(attrs))n.setAttribute(k,v);if(label!==undefined)n.textContent=label;return n;}
 function render(){
 const fs=fields(),positions=[{x:20,y:20}];fs.forEach((f,i)=>{if(!coords.has(f))coords.set(f,{x:20+((i+1)%3)*235,y:20+Math.floor((i+1)/3)*125});positions.push(coords.get(f));});
 const w=Math.max(720,...positions.map(p=>p.x+225)),h=Math.max(320,...positions.map(p=>p.y+110));
 svg=el('svg',{width:w,height:h,viewBox:'0 0 '+w+' '+h,role:'group','aria-label':'Граф технологического маршрута'});host.replaceChildren(svg);
 const defs=el('defs'),marker=el('marker',{id:'route-arrow',markerWidth:8,markerHeight:8,refX:7,refY:4,orient:'auto'});marker.append(el('path',{d:'M0 0 L8 4 L0 8 Z',fill:'#64748b'}));defs.append(marker);svg.append(defs);
 fs.forEach((f,i)=>{const end=positions[i+1];for(const p of parents(f,i)){if(!Number.isInteger(p)||p<0||p>i)continue;const start=positions[p];svg.append(el('path',{d:'M'+(start.x+190)+' '+(start.y+34)+' C'+(start.x+220)+' '+(start.y+34)+' '+(end.x-30)+' '+(end.y+34)+' '+end.x+' '+(end.y+34),stroke:'#64748b','stroke-width':2,fill:'none','marker-end':'url(#route-arrow)'}));}});
 positions.forEach((p,i)=>{const g=el('g',{transform:'translate('+p.x+' '+p.y+')'}),f=fs[i-1];g.append(el('rect',{width:190,height:68,rx:8,fill:'#f1f5f9',stroke:source===i?'#2563eb':'#64748b','stroke-width':source===i?3:1.5}));g.append(el('text',{x:12,y:22,fill:'#334155','font-size':12},i?'Этап '+i:'Исходная партия'));const name=i?(f.querySelector('[data-step-name]').value||'Без названия'): 'Вход деталей';g.append(el('text',{x:12,y:44,fill:'#0f172a','font-size':13},name.slice(0,23)));if(i){g.style.cursor='grab';g.addEventListener('pointerdown',e=>{if(e.target.tagName==='circle')return;drag={f,x:e.clientX,y:e.clientY,ox:p.x,oy:p.y};e.preventDefault();});const input=el('circle',{cx:0,cy:34,r:8,fill:'#16a34a',tabindex:0,role:'button','aria-label':'Вход этапа '+i});const connect=()=>{if(source===null||source>=i){status('Выберите источник с меньшим номером');return;}let ps=parents(f,i-1);ps=ps.includes(source)?ps.filter(x=>x!==source):[...ps,source];if(!ps.length||ps.includes(0)&&ps.length>1){status('Недопустимое соединение');return;}f.querySelector('[data-step-parents]').value=ps.join(',');source=null;render();status('Связи обновлены');};input.addEventListener('click',connect);input.addEventListener('keydown',e=>{if(e.key==='Enter'||e.key===' '){e.preventDefault();connect();}});g.append(input);}
 const out=el('circle',{cx:190,cy:34,r:8,fill:'#2563eb',tabindex:0,role:'button','aria-label':'Выход '+i});const choose=()=>{source=i;render();status('Выбран выход '+i+'. Нажмите на зелёный вход.');};out.addEventListener('click',choose);out.addEventListener('keydown',e=>{if(e.key==='Enter'||e.key===' '){e.preventDefault();choose();}});g.append(out);svg.append(g);});
 }
 function status(s){document.getElementById('graph-status').textContent=s;}
 window.addEventListener('pointermove',e=>{if(!drag)return;coords.set(drag.f,{x:Math.max(12,drag.ox+e.clientX-drag.x),y:Math.max(12,drag.oy+e.clientY-drag.y)});render();});window.addEventListener('pointerup',()=>drag=null);window.addEventListener('pointercancel',()=>drag=null);
 builder.addEventListener('input',render);new MutationObserver(()=>{source=null;render();}).observe(builder,{childList:true});document.getElementById('graph-layout').onclick=()=>{fields().forEach(f=>coords.delete(f));render();};render();
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
})();