/* Evrasia verified-current-email UI: no PII, no secrets, same-origin Bitrix. */
(function () {
  "use strict";
  // Load once, but listen on document to handle Bitrix AJAX/re-rendered
  // account content. No reliance on inline script positions or sibling order.
  if (window.__evEmailConfirmWidgetListenerV2 === true) return;
  window.__evEmailConfirmWidgetListenerV2 = true;
  const endpoint = "/local/ajax/ev-email-ownership.php";
  const id = "ev-verify-email-dialog";
  let busy = false;
  let challengeId = null;
  let countdownUntil = 0;

  function el(tag, className, value) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (value !== undefined) node.textContent = value;
    return node;
  }

  function openDialog(trigger) {
    if (document.getElementById(id)) return;
    const overlay = el("div","ev-email-otp-overlay");
    overlay.id = id;
    overlay.setAttribute("role","presentation");
    Object.assign(overlay.style, {
      position:"fixed",inset:"0",zIndex:"99999",background:"rgba(10,31,37,.55)",
      display:"flex",justifyContent:"center",alignItems:"center",padding:"16px",
      boxSizing:"border-box"
    });
    const dialog = el("section","ev-email-otp-dialog");
    dialog.setAttribute("role","dialog");
    dialog.setAttribute("aria-modal","true");
    dialog.setAttribute("aria-labelledby","ev-email-otp-title");
    dialog.setAttribute("tabindex","-1");
    Object.assign(dialog.style, {
      width:"100%",maxWidth:"420px",padding:"25px",background:"#fff",
      borderRadius:"12px",boxShadow:"0 14px 50px rgba(0,0,0,.18)",
      boxSizing:"border-box",fontFamily:"inherit",color:"#263b40"
    });
    const title=el("h3","", "Подтверждение почты");
    title.id="ev-email-otp-title";
    Object.assign(title.style,{margin:"0 0 10px",fontSize:"21px",fontWeight:"650"});
    const expl=el("p","", "Отправим 6-значный код на текущий адрес электронной почты в вашем профиле. Код действует 5 минут.");
    Object.assign(expl.style,{fontSize:"15px",lineHeight:"1.5",margin:"0 0 18px"});
    const input=el("input");
    input.type="text";
    input.inputMode="numeric";
    input.autocomplete="one-time-code";
    input.placeholder="6-значный код";
    input.maxLength=6;
    input.pattern="[0-9]{6}";
    input.setAttribute("aria-label","Код подтверждения из письма");
    Object.assign(input.style,{display:"block",width:"100%",border:"1px solid #bac8cb",
      boxSizing:"border-box",borderRadius:"7px",padding:"12px",font:"inherit",
      fontSize:"17px",marginBottom:"12px"});
    input.hidden=true;
    const status=el("p");
    status.setAttribute("role","status");
    status.setAttribute("aria-live","polite");
    Object.assign(status.style,{fontSize:"14px",lineHeight:"1.4",minHeight:"20px"});
    const controls=el("div");
    Object.assign(controls.style,{display:"flex",flexWrap:"wrap",gap:"10px"});
    const send=el("button","", "Отправить код");
    const verify=el("button","", "Подтвердить");
    const close=el("button","", "Закрыть");
    for (const button of [send,verify,close]) {
      button.type="button";
      Object.assign(button.style,{font:"inherit",padding:"9px 13px",
        borderRadius:"7px",border:"1px solid #5ba7ad",cursor:"pointer",
        background:"#e9f5f5",color:"#23757b"});
      controls.appendChild(button);
    }
    verify.hidden=true;
    dialog.append(title,expl,input,status,controls);
    overlay.appendChild(dialog);
    document.body.appendChild(overlay);
    dialog.focus();

    let closeModal=()=>{
      if (busy) return;
      overlay.remove();
      trigger.focus();
    };
    close.addEventListener("click",closeModal);
    overlay.addEventListener("click",e=>{if(e.target===overlay)closeModal();});
    overlay.addEventListener("keydown",e=>{
      if(e.key==="Escape") {e.preventDefault();closeModal();}
      if(e.key==="Tab") {
        const focusable=[...dialog.querySelectorAll("button:not([hidden]):not([disabled]),input:not([hidden]):not([disabled])")];
        if(!focusable.length)return;
        const idx=focusable.indexOf(document.activeElement);
        const next=(idx+(e.shiftKey?-1:1)+focusable.length)%focusable.length;
        e.preventDefault();focusable[next].focus();
      }
    });

    async function post(action, fields) {
      if(!window.BX || typeof window.BX.bitrix_sessid!=="function") {
        throw new Error("no_csrf");
      }
      const form=new URLSearchParams();
      form.set("action",action);
      form.set("sessid",window.BX.bitrix_sessid());
      Object.entries(fields||{}).forEach(([key,val])=>form.set(key,String(val)));
      const result=await fetch(endpoint,{method:"POST",credentials:"same-origin",
        cache:"no-store",headers:{"Content-Type":"application/x-www-form-urlencoded;charset=UTF-8"},
        body:form.toString()});
      if(!result.ok)throw new Error("http_error");
      const body=await result.json();
      if(!body || typeof body.status!=="string")throw new Error("malformed_result");
      return body;
    }
    send.addEventListener("click",async ()=>{
      if(busy)return;
      if(Date.now()<countdownUntil) {
        status.textContent="Повторная отправка доступна через минуту.";
        return;
      }
      busy=true;send.disabled=true;status.textContent="Отправляем код...";
      try {
        const r=await post("begin",{});
        if(r.status==="sent" && /^[a-f0-9]{32}$/.test(r.challenge_id||"")) {
          challengeId=r.challenge_id;
          countdownUntil=Date.now()+60000;
          input.hidden=false;verify.hidden=false;send.textContent="Отправить ещё раз";
          status.textContent="Код отправлен. Проверьте входящие и папку «Спам».";
          input.focus();
        } else if(r.status==="recent_login_required") {
          status.textContent="Для подтверждения почты выполните повторный вход в личный кабинет.";
        } else {
          status.textContent="Сейчас не удалось отправить код. Попробуйте позже.";
        }
      } catch(e) {
        status.textContent="Связь с сервером недоступна. Попробуйте позже.";
      } finally {
        busy=false;send.disabled=false;
      }
    });
    verify.addEventListener("click",async ()=>{
      if(busy || !challengeId)return;
      const code=input.value.trim();
      if(!/^[0-9]{6}$/.test(code)) {
        status.textContent="Введите шесть цифр из письма.";input.focus();return;
      }
      busy=true;verify.disabled=true;status.textContent="Проверяем код...";
      try {
        const r=await post("verify",{challenge_id:challengeId,code});
        if(r.status==="verified") {
          status.textContent="Почта подтверждена. Обновляем личный кабинет...";
          window.location.reload();
          return;
        }
        if(r.status==="recent_login_required") {
          status.textContent="Сеанс подтверждения истёк. Войдите в кабинет заново.";
        } else {
          status.textContent="Код неверный или срок его действия истёк.";
        }
        input.select();
      } catch(e) {
        status.textContent="Не удалось проверить код. Попробуйте ещё раз.";
      } finally {
        busy=false;verify.disabled=false;
      }
    });
  }

  document.addEventListener("click",function (event) {
    const node=event.target;
    const trigger=node && typeof node.closest==="function"
      ? node.closest(".ev-email-confirm-trigger") : null;
    if (!trigger) return;
    event.preventDefault();
    openDialog(trigger);
  });
})();
