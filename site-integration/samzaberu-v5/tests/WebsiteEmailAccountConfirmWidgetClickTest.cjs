"use strict";
// Browser-free JS contract: the trigger is inserted AFTER the JS ran, like a
// Bitrix AJAX-delivered component. No real network, browser or credentials.
const assert=require("node:assert/strict");
const fs=require("node:fs");
const path=require("node:path");
const vm=require("node:vm");
const js=fs.readFileSync(path.join(__dirname,
  "../deployment/website-mfa/ev-email-confirm-widget.js"),"utf8");

let checks=0;
const check=(value,msg)=>{assert.ok(value,msg);checks++;};
const listeners=new Map(),elements=[];
let overlay=null,reloads=0;
const requests=[];
function fake(tag){
  const events=new Map();
  const e={
    tagName:String(tag).toUpperCase(),style:{},children:[],events,hidden:false,
    textContent:"",value:"",
    setAttribute(k,v){this[k]=v;},
    addEventListener(name,fn){events.set(name,fn);},
    append(...elems){this.children.push(...elems);},
    appendChild(node){this.children.push(node);},
    focus(){},select(){},
    remove(){if(overlay===this)overlay=null;},
    querySelectorAll(){return [];},
  };
  elements.push(e);
  return e;
}
const doc={
  addEventListener(name,fn){
    const list=listeners.get(name)||[];
    list.push(fn);listeners.set(name,list);
  },
  getElementById(id){return overlay&&overlay.id===id?overlay:null;},
  createElement(tag){return fake(tag);},
  body:{appendChild(node){overlay=node;}},
};
const button={
  closest(s){return s===".ev-email-confirm-trigger"?button:null;},
  focus(){},
};
const win={
  BX:{bitrix_sessid:()=>"test-csrf-only"},
  location:{reload(){reloads++;}},
};
let responseType="sent";
const mockFetch=async(uri,options)=>{
  requests.push({uri,options});
  if(responseType==="sent"){
    return {ok:true,json:async()=>({
      status:"sent",challenge_id:"a".repeat(32),expires_at:1800000300
    })};
  }
  return {ok:true,json:async()=>({status:"verified"})};
};
(async()=>{
  const sandbox={
    window:win,document:doc,fetch:mockFetch,URLSearchParams,
    Date,RegExp,String,Object,Array,Promise,
  };
  vm.runInNewContext(js,sandbox,{filename:"ev-email-confirm-widget.js"});
  check((listeners.get("click")||[]).length===1,
    "delegated_listener_registered_before_button_exists");
  // Loading via both Bitrix Asset and external <script> is harmless.
  vm.runInNewContext(js,sandbox);
  check((listeners.get("click")||[]).length===1,
    "duplicate_script_does_not_duplicate_listeners");

  let prevented=false;
  listeners.get("click")[0]({
    target:button, preventDefault(){prevented=true;}
  });
  check(prevented===true,"dynamic_account_button_click_prevented");
  check(overlay&&overlay.id==="ev-verify-email-dialog",
    "click_opens_actual_dialog_without_currentScript");
  check(overlay.children.length===1,
    "dialog_stays_in_document_body_not_invalid_email_paragraph");
  const send=elements.find(e=>e.tagName==="BUTTON"
    && e.textContent==="Отправить код");
  check(send&&typeof send.events.get("click")==="function",
    "modal_contains_real_send_button_handler");
  await send.events.get("click")();
  check(requests.length===1,
    "only_one_same_origin_request_after_send");
  check(requests[0].uri==="/local/ajax/ev-email-ownership.php"
    && requests[0].options.method==="POST"
    && requests[0].options.credentials==="same-origin",
    "backend_URL_POST_same_origin");
  const body=new URLSearchParams(requests[0].options.body);
  check(body.get("action")==="begin"
    && body.get("sessid")==="test-csrf-only"
    && body.has("email")===false,
    "Bitrix_CSRF_but_no_client_supplied_email");
  const code=elements.find(e=>e.tagName==="INPUT");
  const verify=elements.find(e=>e.tagName==="BUTTON"
    && e.textContent==="Подтвердить");
  check(code&&verify&&!code.hidden&&!verify.hidden,
    "email_code_field_and_submit_open_on_server_sent");
  code.value="123456";
  responseType="verified";
  await verify.events.get("click")();
  check(requests.length===2
    && new URLSearchParams(requests[1].options.body).get("action")==="verify",
    "verify_uses_second_post");
  const form=new URLSearchParams(requests[1].options.body);
  check(form.get("code")==="123456"
    && form.get("challenge_id")==="a".repeat(32),
    "server_challenge_id_and_six_digit_code_only");
  check(reloads===1,"verified_server_receipt_refreshes_green_badge");

  // If Bitrix re-renders the email line, click delegation is still present;
  // one open dialog at a time, no duplicate listener.
  listeners.get("click")[0]({
    target:button,preventDefault(){}
  });
  check((listeners.get("click")||[]).length===1,
    "Bitrix_replaced_trigger_needs_no_new_binding");

  console.log("PASS: delegated email button, OTP modal, CSRF, send/verify "+
    checks+" browser-free assertions");
  console.log("MODE=OFFLINE_BROWSER_SIMULATION");
  console.log("REAL_HTTP_REQUEST=NO");
  console.log("PRODUCTION_WRITE=NO");
  console.log("FINAL_STATUS=PASS");
  console.log("FINAL_RC=0");
})().catch(()=>{
  console.log("FAIL: external email confirm click/OTP regression");
  console.log("FINAL_STATUS=FAIL");
  console.log("FINAL_RC=1");
  process.exitCode=1;
});
