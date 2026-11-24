'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const root = path.resolve(__dirname, '../..');
function extract(source, name) {
  const at = source.indexOf(`function ${name}(`);
  assert(at >= 0, name);
  const start = source.slice(at - 6, at) === 'async ' ? at - 6 : at;
  let depth = 0, quote = '', escaped = false;
  for (let i = source.indexOf('{', at); i < source.length; i++) {
    const c = source[i];
    if (quote) { if (escaped) escaped = false; else if (c === '\\') escaped = true; else if (c === quote) quote = ''; continue; }
    if ('"\'`'.includes(c)) { quote = c; continue; }
    if (c === '{') depth++;
    if (c === '}' && --depth === 0) return source.slice(start, i + 1);
  }
  throw new Error(name);
}
const aliases = ['egn', 'EGN', 'phone2', 'clientEGN', 'clientegn', 'unipayment_egn', 'unipayment_phone2', 'second_phone', 'secondary_phone', 'personal_id', 'phone_2'];
const applicant = {firstname:'Synthetic', lastname:'Applicant', address:'Synthetic address', phone:'+359 (88) 123-456', email:'synthetic@example.test', address_id:'900', shipping_address_id:'901', address_2:'Unit 2', company:'Synthetic', city:'Sofia', postcode:'1000', country_id:'33', zone_id:'4239'};
(async () => {
  for (const name of ['product', 'cart', 'checkout']) {
    const source = fs.readFileSync(path.join(root, `catalog/view/javascript/mt_uni_credit_${name}.js`), 'utf8');
    const twig = fs.readFileSync(path.join(root, name === 'checkout' ? 'catalog/view/template/payment/mt_uni_credit.twig' : `catalog/view/template/module/mt_uni_credit_${name}_modal.twig`), 'utf8');
    for (const retired of ['egn', 'phone2']) assert(!twig.includes(`name="${retired}"`));
    assert(!source.includes('isValidEgn'));
    for (const process2 of [true, false]) {
      const fields = {...applicant, legacy_hidden:'keep-process1', 'consent[]':'1'};
      aliases.forEach(key => fields[key] = 'retired-marker');
      let accepted = true, authoritative = true, captured = [], resolve;
      const error = {textContent:''};
      const button = {isConnected:true, setAttribute(){}, classList:{toggle(){}}};
      const form = {isConnected:true, querySelectorAll:()=>accepted ? [{value:'1'}] : []};
      const modal = {isConnected:true, querySelector:()=>error};
      const context = vm.createContext({state:{modal:{process2}, submit_url:'/synthetic-submit', confirm_url:'/synthetic-confirm'}, root:{isConnected:true}, modal, form, window:{},
        FormData:class {forEach(callback){Object.entries(fields).forEach(([key,value])=>callback(value,key));}},
        activeProductFinancingForm:()=>form, submitBtn:()=>button, submitErrorEl:()=>error,
        selectedScheme:()=>({key:'scheme'}), syncSelectedSchemeFromDom:()=>({key:'scheme'}),
        hasAuthoritativeCalculation:()=>authoritative, areMandatoryConsentsChecked:()=>accepted,
        updateSubmitState:()=>accepted && authoritative, updateConfirmState(){}, clearFieldErrors(){}, setProcessing(){}, showFieldErrors(){},
        buildSelectionPayload:()=>({csrf_token:'csrf', scheme_key:'scheme'}), navigateCheckoutTerminalThankYou:()=>false,
        postJson:async (_url,payload)=>{captured.push(payload);return new Promise(r=>resolve=r);},
      });
      vm.runInContext('let submitBusy=false,confirmBusy=false,redirectTerminal=false,submissionToken="token",lastCalculation={};', context);
      const fn = name === 'checkout' ? 'confirmPayment' : 'submitForm';
      vm.runInContext(extract(source, fn), context);
      accepted = false; await vm.runInContext(`${fn}()`, context); assert.equal(captured.length, 0);
      accepted = true; authoritative = false; await vm.runInContext(`${fn}()`, context); assert.equal(captured.length, 0);
      authoritative = true;
      const pending = vm.runInContext(`${fn}()`, context);
      assert.equal(captured.length, 1, `${name} submits without retired fields`);
      await vm.runInContext(`${fn}()`, context); assert.equal(captured.length, 1, 'duplicate in-flight submit blocked');
      const payload = captured[0];
      assert.equal(payload.csrf_token, 'csrf'); assert.equal(payload.submission_token, 'token'); assert.equal(payload.scheme_key, 'scheme'); assert.equal(payload['consent[0]'], '1');
      if (process2) {
        aliases.forEach(key=>assert(!(key in payload), `${name}: ${key}`));
        assert(!('legacy_hidden' in payload));
        if (name === 'checkout') Object.keys(applicant).forEach(key=>assert(!(key in payload)));
        else Object.entries(applicant).forEach(([key,value])=>assert.equal(payload[key], value));
      } else {
        assert.equal(payload.legacy_hidden, 'keep-process1');
        aliases.forEach(key=>assert.equal(payload[key], 'retired-marker', 'P1 serialization unchanged'));
      }
      resolve({success:false,message:'synthetic retry'}); await pending;
      assert.equal(vm.runInContext(name === 'checkout' ? 'confirmBusy' : 'submitBusy', context), false);
    }
    if (name !== 'checkout') {
      const values = {...applicant, phone:'0888123456'};
      const context = vm.createContext({customerField:key=>values[key] === undefined ? null : {value:values[key]}, areMandatoryConsentsChecked:()=>true});
      const constants = source.match(/    const (?:PHONE_VALID_PATTERN|PHONE_ALLOWED_PATTERN|EMAIL_VALID_PATTERN) = .*;/g) || [];
      vm.runInContext(constants.join('\n'), context);
      for (const fn of ['sanitizePhoneValue','isNonEmpty','isValidPhone','isValidEmail','getStep2FieldErrors']) vm.runInContext(extract(source,fn),context);
      assert.equal(Object.keys(vm.runInContext('getStep2FieldErrors()',context)).length,0);
      values.email='invalid'; assert('email' in vm.runInContext('getStep2FieldErrors()',context)); values.email=applicant.email;
      values.phone=''; assert('phone' in vm.runInContext('getStep2FieldErrors()',context));
      assert.equal(vm.runInContext('isValidPhone("(1)")',context),name === 'product','distinct primary-phone contract');
      assert.equal(vm.runInContext('isValidPhone("+35988123456")',context),true);
    }
  }
  console.log('PASS: Process 2 forms, applicant allowlists, readiness, consents, duplicate-submit, retry, and Process 1 serialization');
})().catch(error=>{console.error(error);process.exitCode=1;});
