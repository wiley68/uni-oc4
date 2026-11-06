'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const base = path.resolve(__dirname, '../..');
// Balanced function extraction without interpreting comments (our chosen functions have no brace comments).
function extract(source, name) {
  const token=`function ${name}(`;const p=source.indexOf(token);assert(p>=0,name);
  const prefix=source.slice(p-6,p)==='async '?'async ':'';
  let at=source.indexOf('{',p), depth=0, quote='',escaped=false;
  for(let i=at;i<source.length;i++){
    const c=source[i];
    if(quote){if(escaped)escaped=false;else if(c==='\\')escaped=true;else if(c===quote)quote='';continue;}
    if(c==='"'||c==="'"||c==='`'){quote=c;continue;}
    if(c==='{')depth++;
    if(c==='}'&&--depth===0)return prefix+source.slice(p,i+1);
  }
  throw new Error(name);
}
function makeRow(id, scope, mandatory=true) {
  const box=mandatory?{value:String(id),checked:false,disabled:true}:null;
  return {hidden:true,dataset:{mtucConsentRow:String(id),mtucConsentScope:scope,mtucConsentName:'Consent '+id,mtucConsentUrl:''},box,querySelector(){return box;}};
}
function fixture(name) {
  const source=fs.readFileSync(path.join(base,`catalog/view/javascript/mt_uni_credit_${name}.js`),'utf8');
  const rows=[makeRow(1,'all'),makeRow(2,'zero_interest'),makeRow(3,'nonzero_interest'),makeRow(4,'all',false)];
  const root={isConnected:true,hidden:false,querySelectorAll(){return rows;},querySelector(q){const id=q.match(/row="(\d+)"/);return id?rows.find(r=>r.dataset.mtucConsentRow===id[1]):null;}};
  const pending=[], cancelled=[];const error={textContent:''};const apply={disabled:false,setAttribute(){}};
  const preview=[], busy=[];
  const context=vm.createContext({root,modal:root,console,AbortController,quantityValue:()=>1,productOptions:()=>({}),calculator:{setAttribute(...args){busy.push(args);}},renderCalculator(c){preview.push(c);},clearTimeout(id){cancelled.push(id);},state:{issue_url:'/offline-issue'},popupErrorEl:()=>error,submitErrorEl:()=>({textContent:""}),
    applyBtn:()=>apply,updateSubmitState(){},updateConfirmState(){},selectedScheme:()=>({key:'A'}),syncSelectedSchemeFromDom:()=>({key:'A'}),
    buildSelectionPayload:()=>({}),clearEntryError(){},postJson:()=>new Promise((resolve,reject)=>pending.push({resolve,reject})),
    submitBtn:()=>({isConnected:true,setAttribute(){}}),activeProductFinancingForm:()=>({isConnected:true}),form:{isConnected:true},
  });
  vm.runInContext('let firstInstallmentTimer=null,calcBusy=false,issueFlight=null,submissionToken="",lastCalculation=null,submitBusy=false,confirmBusy=false,cartFingerprint="cart",sequence=0,abortController=null,currentStep=1;function renderCalculation(c){lastCalculation=c;}',context);
  const helper=source.slice(source.indexOf('    // Each form owns'),source.indexOf('    function schemeSelect()'));
  vm.runInContext(helper,context);
  const fn=name==='checkout'?'issueSubmission':'recalculateSelection';
  vm.runInContext(extract(source,'hasAuthoritativeCalculation'),context);
  vm.runInContext(extract(source,fn),context);
  vm.runInContext(extract(source,name==='checkout'?'confirmPayment':'submitForm'),context);
  const descriptors=[1,2,4].map(id=>({id,name:'Consent '+id,url:'',interest_scope:id===2?'zero_interest':'all',mandatory:id!==4}));
  const response=(token)=>({success:true,submission_token:token,calculation:{token},consent_context:{fingerprint:'a'.repeat(64),classification:'zero_interest',mandatory_ids:[1,2],descriptors}});
  return {source,rows,root,pending,cancelled,error,apply,context,response,preview,busy,call:()=>vm.runInContext(`${fn}()`,context),get:(s)=>vm.runInContext(s,context),run:(s)=>vm.runInContext(s,context)};
}
(async()=>{
for(const name of ['product','cart','checkout']){
  const h=fixture(name);
  assert.equal(h.get('consentAcceptanceReady()'),false);
  const p1=h.call(),p2=h.call();
  assert.equal(h.pending.length,2,name+' must allow replacement of an obsolete calculation');
  await h.run(name==='checkout'?'confirmPayment()':'submitForm()');
  assert.equal(h.pending.length,2,name+' pending submit cannot issue or submit');
  h.pending[1].resolve(h.response('B'));await p2;
  h.pending[0].resolve(h.response('A'));await p1;
  assert.equal(h.get('submissionToken'),'B',name+' ignores old response');
  assert.equal(h.rows[2].hidden,true);assert.equal(h.rows[2].box.disabled,true);assert.equal(h.rows[2].box.checked,false);
  assert.equal(h.rows[3].hidden,false);assert.equal(h.rows[3].box,null);
  h.rows[0].box.checked=true;h.rows[1].box.checked=true;
  assert.equal(h.get('consentAcceptanceReady()'),true);
  h.run('firstInstallmentTimer=42;invalidateConsent(false)');
  assert(h.cancelled.includes(42));assert.equal(h.get('consentAcceptanceReady()'),false);
  assert.equal(h.rows[1].box.checked,true,name+' first installment preserves scoped acceptance pending response');
  const p3=h.call();h.pending[2].resolve(h.response('C'));await p3;
  assert.equal(h.get('consentAcceptanceReady()'),true);
  h.run('invalidateConsent(true);invalidateConsent(true)'); // Isolated reset helper; actual A→B→A is exercised by mounted Checkout below.
  assert.equal(h.rows[0].box.checked,true);assert.equal(h.rows[1].box.checked,false);
  const p4=h.call(),p5=h.call();
  h.pending[3].reject(new Error('stale failure'));await p4;
  assert.equal(h.error.textContent,'');assert.equal(h.get('calcBusy'),true,name+' stale finally cannot finish newer flight');
  h.pending[4].resolve(h.response('D'));await p5;
  const saved=h.rows.splice(1,1);assert.equal(h.get('applyConsentContext('+JSON.stringify(h.response('').consent_context)+')'),false,name+' missing mandatory DOM row refreshes');h.rows.splice(1,0,...saved);
  h.root.isConnected=false;
  const count=h.pending.length;await h.call();assert.equal(h.pending.length,count,name+' detached form cannot issue');
  assert.equal(h.get('consentAcceptanceReady()'),false);
  const next=fixture(name);assert.equal(next.get('consentAcceptanceReady()'),false,name+' remount cannot inherit old authority');
  if(name==='checkout'){
    const start=h.source.indexOf('    root.mtucConsentTeardown =');const end=h.source.indexOf('\n    };',start)+7;
    h.run(h.source.slice(start,end));h.run('firstInstallmentTimer=99;root.mtucConsentTeardown()');assert(h.cancelled.includes(99));
    assert(h.source.includes('if (!root.isConnected) return;'));
  } else {
    assert(extract(h.source,'closeModal').includes('invalidateConsent(true)'));
    assert(h.source.includes('teardownObserver.disconnect()'));
    assert.equal(h.apply.disabled,true,name+' Apply is disabled during invalidation');
  }
  if(name!=='checkout') {
    const preview=fixture(name);preview.root.hidden=true;
    preview.run(extract(preview.source,'refreshCalculator'));
    const old=preview.run('refreshCalculator("old")'), latest=preview.run('refreshCalculator("latest")');
    preview.pending[0].reject(new Error('obsolete preview failure'));await old;
    assert.equal(preview.busy.length,2,name+' obsolete preview error cannot clear current busy state');
    preview.pending[1].resolve({success:true,calculator:{cart_fingerprint:'cart',id:'latest'}});await latest;
    assert.equal(preview.preview.length,1);assert.equal(preview.preview[0].id,'latest');
  }
  const cssName=name==='checkout'?'checkout':'product';const css=fs.readFileSync(path.join(base,`catalog/view/stylesheet/mt_uni_credit_${cssName}.css`),'utf8');
  assert(/\.mt-uni-credit-product-calculator__consent\s*\{[^}]*display:\s*flex/s.test(css));
  const hidden=css.match(/([^{}]+\.mt-uni-credit-product-calculator__consent\[hidden\])\s*\{([^}]+)\}/);
  assert(hidden&&/display:\s*none\s*!important/.test(hidden[2]),name+' hidden must outrank flex');
  assert(hidden[1].includes(cssName==='checkout'?'#mt-uni-credit-checkout-root':'#mt-uni-credit-cart-modal'));
  if(name==='checkout') await mountedCheckoutLifecycle();
  else await modalEventLifecycle(name);
  console.log('PASS '+name+': isolated consent helpers/promises; '+(name==='checkout'?'complete Checkout mount/events/timers/root replacement via DOM facade; ':'production selection/debounce/open-close handlers, UI collaborators stubbed; ')+'CSS declarations (browser rendering NOT RUN)');
}
})().catch(e=>{console.error(e);process.exitCode=1;});

// Small DOM facade for the complete Checkout script: real EventTarget listeners,
// real payload/render/readiness functions, deterministic timers and observer delivery.
// This is not a browser DOM/CSS engine. PHP separately renders Twig into DOMDocument.
async function mountedCheckoutLifecycle() {
  class Element extends EventTarget {
    constructor(){super();this.dataset={};this.isConnected=true;this.hidden=false;this.disabled=false;this.checked=false;this.value='';this.children=[];this.attrs={};this.classList={toggle(){},add(){},remove(){}};}
    setAttribute(k,v){this.attrs[k]=String(v);}
    getAttribute(k){return this.attrs[k]??null;}
    removeAttribute(k){delete this.attrs[k];}
    replaceChildren(){this.children=[];}
    appendChild(n){this.children.push(n);return n;}
    querySelector(q){return this.querySelectorAll(q)[0]||null;}
    querySelectorAll(){return [];}
    contains(n){return n===this;}
  }
  let now=0, serial=0;const timers=new Map(),requests=[],observers=[];
  const schemes=[{key:'A',scheme_type:'standard',kop_code:'K1',months:12,filter_id:1},{key:'B',scheme_type:'standard',kop_code:'K2',months:24,filter_id:2}];
  const document=new Element();document.readyState='complete';document.documentElement=new Element();document.body=new Element();document.head=new Element();document.createElement=()=>new Element();
  function mount() {
    const root=new Element(),select=new Element(),first=new Element(),button=new Element(),error=new Element();
    const form=new Element(),bootstrap=new Element();
    const rows=[makeRow(1,'all'),makeRow(2,'zero_interest'),makeRow(3,'nonzero_interest'),makeRow(4,'all',false)];
    // Keep checkbox values after adding event support.
    rows.forEach(row=>{if(row.box){const box=new Element();box.value=row.dataset.mtucConsentRow;box.disabled=true;row.box=box;row.querySelector=()=>row.box;}});
    bootstrap.textContent=JSON.stringify({issue_url:'/issue',confirm_url:'/confirm',calculator:{cart_fingerprint:'cart',offers:{standard:{preferred_scheme_key:'A',schemes}}}});
    const nodes={'#mt-uni-credit-checkout-bootstrap':bootstrap,'#mt-uni-credit-checkout-form':form,'[data-mtuc-schemes]':select,'[data-mtuc-first]':first,'#button-confirm, [data-mtuc-submit]':button,'[data-mtuc-popup-error]':error};
    root.querySelectorAll=q=>q==='[data-mtuc-consent-row]'?rows:q==='[data-mtuc-consent-checkbox]'?rows.flatMap(r=>r.box?[r.box]:[]):[];
    root.querySelector=q=>nodes[q]||rows.find(r=>q===`[data-mtuc-consent-row="${r.dataset.mtucConsentRow}"]`)||null;
    select.querySelector=()=>null;
    return {root,select,first,button,error,rows,form};
  }
  let active=mount();
  document.getElementById=id=>id==='mt-uni-credit-checkout-root'?active.root:null;
  document.querySelector=()=>null;
  class Observer {constructor(callback){this.callback=callback;observers.push(this);}observe(){}disconnect(){}}
  const context=vm.createContext({document,window:{location:{assign(){throw Error('Unexpected navigation');}}},MutationObserver:Observer,Event,FormData,URLSearchParams,console,
    setTimeout(fn,delay){const id=++serial;timers.set(id,{at:now+delay,fn});return id;},clearTimeout(id){timers.delete(id);},
    fetch(url,options){return new Promise((resolve,reject)=>requests.push({url,options,resolve:json=>resolve({json:async()=>json}),reject}));}});
  const flush=async()=>{for(let i=0;i<12;i++)await Promise.resolve();};
  const tick=async ms=>{now+=ms;for(const [id,t] of [...timers])if(t.at<=now){timers.delete(id);t.fn();}await flush();};
  const response=(token,zero=true)=>({success:true,submission_token:token,calculation:{price:1200,first_installment:0},consent_context:{fingerprint:'a'.repeat(64),classification:zero?'zero_interest':'nonzero_interest',mandatory_ids:[1,zero?2:3],descriptors:[1,zero?2:3,4].map(id=>({id,name:'Consent '+id,url:'',mandatory:id!==4,interest_scope:id===1||id===4?'all':zero?'zero_interest':'nonzero_interest'}))}});
  const check=(form,index,value)=>{form.rows[index].box.checked=value;form.rows[index].box.dispatchEvent(new Event('change'));};
  vm.runInContext(fs.readFileSync(path.join(base,'catalog/view/javascript/mt_uni_credit_checkout.js'),'utf8'),context);
  assert.equal(requests.length,1,'complete Checkout script mounted and issued');
  requests[0].resolve(response('initial'));await flush();
  check(active,0,true);check(active,1,true);assert.equal(active.button.disabled,false,'real updateConfirmState enables accepted form');
  active.first.value='25';active.first.dispatchEvent(new Event('input'));
  assert.equal(active.button.disabled,true,'real input handler invalidates immediately');
  await tick(399);assert.equal(requests.length,1);await tick(1);assert.equal(requests.length,2);
  assert.equal(requests[1].options.body.get('first_installment'),'25');
  requests[1].resolve(response('installment'));await flush();assert.equal(active.button.disabled,false,'matching installment recalculation retains acceptance');
  active.select.value='B';active.select.dispatchEvent(new Event('change'));
  assert.equal(active.rows[1].box.checked,false);assert.equal(active.rows[0].box.checked,true);
  assert.equal(requests[2].options.body.get('kop_code'),'K2');assert.equal(requests[2].options.body.get('months'),'24');
  requests[2].resolve(response('B',false));await flush();assert.equal(active.rows[1].hidden,true);assert.equal(active.rows[2].hidden,false);
  check(active,2,true);assert.equal(active.button.disabled,false);
  active.select.value='A';active.select.dispatchEvent(new Event('change'));
  assert.equal(active.rows[2].box.checked,false);assert.equal(active.button.disabled,true);
  // Obsolete rejection/finally cannot enable the form or surface an error over the newer request.
  active.select.value='B';active.select.dispatchEvent(new Event('change'));
  requests[3].reject(new Error('obsolete'));await flush();assert.equal(active.error.textContent||'','');assert.equal(active.button.disabled,true);
  requests[4].resolve(response('latest',false));await flush();check(active,2,true);assert.equal(active.button.disabled,false);
  // A pending timer and request from a detached Checkout root cannot affect its replacement.
  active.first.dispatchEvent(new Event('input'));await tick(400);const detachedRequest=requests[5];
  active.first.dispatchEvent(new Event('input'));const old=active;old.root.isConnected=false;active=mount();
  observers.forEach(o=>o.callback());assert.equal(requests.length,7);assert.equal(timers.size,0);
  detachedRequest.resolve(response('detached'));await flush();assert.equal(active.button.disabled,true);
  const count=requests.length;old.select.dispatchEvent(new Event('change'));await tick(400);assert.equal(requests.length,count);
  requests[6].resolve(response('replacement'));await flush();assert.equal(active.button.disabled,true);
  assert.equal(active.rows[0].box.checked,false,'replacement has no inherited acceptance');
  check(active,0,true);check(active,1,true);assert.equal(active.button.disabled,false);
}

// Product/Cart use production event registrations and open/close functions with
// UI-only collaborators stubbed. Full page/theme mounting is deliberately NOT certified.
async function modalEventLifecycle(name) {
  const h=fixture(name),select=new EventTarget(),first=new EventTarget();select.value='A';first.value='0';
  let now=0,serial=0;const timers=new Map();
  const schemes={A:{key:'A',scheme_type:'standard',kop_code:'K1',months:12},B:{key:'B',scheme_type:'promo',kop_code:'K2',months:24}};
  const doc=new EventTarget();doc.body={};
  Object.assign(h.root,{parentElement:doc.body,setAttribute(){},removeAttribute(){}});
  Object.assign(h.context,{document:doc,schemeSelect:()=>select,firstInput:()=>first,selectedScheme:()=>schemes[select.value],syncSelectedSchemeFromDom:()=>schemes[select.value],findSchemeAcrossOffers:key=>schemes[key],
    resetFirstInstallmentForSchemeChange:()=>{first.value='0';},syncOfferTypeFromScheme(){},notifyBuySchemeUserOverride(){},
    setTimeout(fn,delay){const id=++serial;timers.set(id,{at:now+delay,fn});return id;},clearTimeout(id){timers.delete(id);},
    setBackgroundInert(){},trapFocus(){},onDocumentKeydown(){},unbindNativeCartAddObserver(){},setProcessing(){},clearFieldErrors(){},populateSchemeSelect(){},dialogEl:()=>null,hideSecondaryAction(){},resetCartModalOfferState(){}});
  h.run("let selectedSchemeKey='A',selectedOfferType='standard',refreshTimer=null,modalHomeParent=null,modalHomeNext=null,lastTrigger=null;function setStep(step){currentStep=step;}");
  const start=h.source.indexOf('    schemeSelect()?.addEventListener("change"');
  const firstAt=h.source.indexOf('    firstInput()?.addEventListener("input"',start);
  const end=h.source.indexOf('\n    });',firstAt)+8;
  assert(start>=0&&firstAt>start&&end>firstAt);
  h.run(h.source.slice(start,end));
  h.run(extract(h.source,'closeModal'));h.run(extract(h.source,'openModal'));
  const flush=async()=>{for(let i=0;i<8;i++)await Promise.resolve();};
  const tick=async ms=>{now+=ms;for(const [id,t] of [...timers])if(t.at<=now){timers.delete(id);t.fn();}await flush();};
  let p=h.call();h.pending[0].resolve(h.response('initial'));await p;
  h.rows[0].box.checked=true;h.rows[1].box.checked=true;
  first.value='25';first.dispatchEvent(new Event('input'));assert.equal(h.get('consentAcceptanceReady()'),false);
  await tick(399);assert.equal(h.pending.length,1);await tick(1);assert.equal(h.pending.length,2);
  h.pending[1].resolve(h.response('installment'));await flush();assert.equal(h.get('consentAcceptanceReady()'),true);
  select.value='B';select.dispatchEvent(new Event('change'));assert.equal(h.get('selectedSchemeKey'),'B');assert.equal(h.rows[1].box.checked,false);
  select.value='A';select.dispatchEvent(new Event('change'));assert.equal(h.get('selectedSchemeKey'),'A');assert.equal(h.rows[0].box.checked,true);assert.equal(h.rows[1].box.checked,false);
  h.pending[3].resolve(h.response('latestA'));await flush();h.pending[2].resolve(h.response('staleB'));await flush();assert.equal(h.get('submissionToken'),'latestA');
  first.dispatchEvent(new Event('input'));assert.equal(timers.size,1);h.run('closeModal()');assert.equal(timers.size,0);assert.equal(h.root.hidden,true);
  await tick(400);assert.equal(h.pending.length,4,'close cancels real pending debounce');
  p=h.run('openModal(null)');assert.equal(h.root.hidden,false);assert.equal(h.get('consentAcceptanceReady()'),false);
  h.pending[4].resolve(h.response('reopen'));await p;assert.equal(h.rows[1].box.checked,false,'scoped consent not restored on reopen');
}
