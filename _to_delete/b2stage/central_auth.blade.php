<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>Client Portal — SmartEPT by Ametecs</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Inter','Segoe UI',sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center;
background:linear-gradient(160deg,#04252C 0%,#083A44 60%,#0B4A56 100%);padding:24px}
.wrap{display:grid;grid-template-columns:1.08fr 1fr;gap:0;width:100%;max-width:960px;background:#fff;border-radius:20px;overflow:hidden;box-shadow:0 30px 90px rgba(0,0,0,.45)}
.summary{background:linear-gradient(165deg,#04252C 0%,#0B4A56 100%);color:#fff;padding:30px 30px;display:flex;flex-direction:column}
.brand{display:flex;align-items:center;gap:11px;margin-bottom:16px}
.mk{width:42px;height:42px;border-radius:12px;background:linear-gradient(135deg,#0E7C8F,#22B8CF);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:14px;color:#fff}
.brand b{font-size:18px;color:#fff}.brand small{display:block;font-size:9px;letter-spacing:2px;color:#7FA8AF}
.plan-name{font-size:21px;font-weight:800;margin-bottom:2px}
.plan-tag{font-size:12px;color:#9FC5CC;margin-bottom:14px}
.ctrl{margin-bottom:11px}
.ctrl>label{display:block;font-size:11px;color:#9FC5CC;font-weight:700;margin-bottom:5px;text-transform:uppercase;letter-spacing:.4px}
.dev{display:flex;align-items:center;gap:8px}
.dev button{width:28px;height:28px;border-radius:7px;border:1px solid rgba(255,255,255,.3);background:rgba(255,255,255,.08);color:#fff;font-size:16px;font-weight:700;cursor:pointer;line-height:1}
.dev input{width:64px;text-align:center;padding:6px;border-radius:7px;border:1px solid rgba(255,255,255,.25);background:rgba(0,0,0,.2);color:#fff;font-size:14px;font-weight:700}
.seg{display:flex;background:rgba(0,0,0,.22);border-radius:8px;padding:3px;gap:2px}
.seg button{flex:1;border:none;background:none;color:#9FC5CC;font-size:11px;font-weight:700;padding:6px 6px;border-radius:6px;cursor:pointer;white-space:nowrap}
.seg button.on{background:#0E7C8F;color:#fff}
.seg button .off{display:block;font-size:9px;color:#7FE0EC;font-weight:700}
.seg button.on .off{color:#CFF3F8}
.inv{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);border-radius:12px;padding:13px 15px;margin-top:4px}
.inv .ln{display:flex;justify-content:space-between;gap:10px;font-size:12.5px;color:#CFE0E3;padding:4px 0;line-height:1.35}
.inv .ln.disc b{color:#7FE0EC}
.inv .ln.sub{border-top:1px solid rgba(255,255,255,.12);margin-top:4px;padding-top:8px}
.inv .ln.tot{border-top:1px solid rgba(255,255,255,.18);margin-top:6px;padding-top:9px;font-size:17px;font-weight:800;color:#fff}
.inv .ln b{color:#fff;white-space:nowrap}
.inv .eff{font-size:11px;color:#7FA8AF;margin-top:7px;text-align:right}
.trust{margin-top:14px;font-size:10.5px;color:#7FA8AF;line-height:1.6}
.card{padding:28px 28px;display:flex;flex-direction:column;max-height:94vh;overflow-y:auto}
p.sub{font-size:13px;color:#565A66;margin:0 0 12px}
.tabs{display:flex;gap:6px;margin-bottom:12px}
.tabs button{flex:1;padding:9px 4px;border-radius:9px;border:1.5px solid #DCDFE7;background:#fff;font-weight:700;font-size:12.5px;color:#565A66;cursor:pointer;font-family:inherit}
.tabs button.on{background:#E3F4F7;border-color:#0E7C8F;color:#0B6373}
label{display:block;font-size:12px;font-weight:700;color:#565A66;margin:9px 0 4px}
input,select{width:100%;padding:10px 12px;border:1.5px solid #DCDFE7;border-radius:9px;font-size:14px;font-family:inherit;background:#fff}
input:focus,select:focus{outline:none;border-color:#0E7C8F}
.row{display:grid;grid-template-columns:1fr 1fr;gap:0 12px}
button.go{width:100%;margin-top:14px;padding:13px;border:none;border-radius:9px;font-weight:700;font-size:14.5px;color:#fff;background:linear-gradient(135deg,#0E7C8F,#1899AE);cursor:pointer;font-family:inherit}
button.go:disabled{opacity:.55;cursor:not-allowed}
a.quote{display:block;text-align:center;margin-top:9px;padding:11px;border:1.5px solid #0E7C8F;border-radius:9px;color:#0B6373;font-weight:700;font-size:13px;text-decoration:none}
.msg{font-size:12.5px;padding:10px 12px;border-radius:8px;margin-top:12px;display:none;line-height:1.5}
.msg.err{background:#FBE9ED;color:#D02748;display:block}
.msg.ok{background:#E6F5EE;color:#08875D;display:block}
.msg.demo{background:#FBF3E2;color:#7A5614;display:block;font-weight:700}
.foot{text-align:center;font-size:11px;color:#878C99;margin-top:12px;line-height:1.7}
.foot a{color:#0B6373;font-weight:700;text-decoration:none}
.trial-note{background:#E3F4F7;border-radius:9px;padding:9px 12px;font-size:11.5px;color:#0B6373;margin-top:10px;line-height:1.55}
.hint{font-size:11px;color:#878C99;margin-top:-2px;margin-bottom:2px}
.otp-wrap{display:none}
@media(max-width:820px){.wrap{grid-template-columns:1fr;max-width:440px}.summary{display:none}}
</style>
</head>
<body>
<div class="wrap">
  <aside class="summary">
    <div class="brand"><div class="mk"><img src="data:image/png;base64,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" alt="SmartEPT" style="width:72%;height:72%;object-fit:contain;display:block"></div><div><b>SmartEPT</b><small>BY AMETECS</small></div></div>
    <div class="plan-name" id="sumPlan">SmartEPT Professional</div>
    <div class="plan-tag" id="sumTag">Estimate your subscription — free for the first 7 days.</div>

    <div class="ctrl"><label>Devices to track</label>
      <div class="dev"><button type="button" id="devMinus">−</button><input id="devCount" type="number" min="1" value="25"><button type="button" id="devPlus">+</button></div>
    </div>
    <div class="ctrl"><label>Deployment</label>
      <div class="seg"><button type="button" class="on" data-host="hosted" id="hHosted">Client-Hosted</button><button type="button" data-host="cloud" id="hCloud">SmartEPT Cloud</button></div>
    </div>
    <div class="ctrl"><label>Advance payment</label>
      <div class="seg">
        <button type="button" data-cyc="q" id="cQ">Quarterly<span class="off">0% off</span></button>
        <button type="button" data-cyc="h" id="cH">6 Months<span class="off">10% off</span></button>
        <button type="button" class="on" data-cyc="y" id="cY">12 Months<span class="off">25% off</span></button>
      </div>
    </div>
    <label style="display:flex;align-items:flex-start;gap:8px;font-size:11.5px;color:#BFD6DA;font-weight:600;margin-bottom:11px;cursor:pointer;text-transform:none;letter-spacing:0">
      <input type="checkbox" id="setupChk" style="width:auto;margin-top:2px;accent-color:#22B8CF">
      <span>Add professional installation &amp; onboarding <span style="color:#7FA8AF">(one-time — we install &amp; set up SmartEPT for you). Skip it to self-install; you can request it later.</span></span>
    </label>
    <div class="ctrl" id="couponCtrl"><label>Coupon code</label>
      <div style="display:flex;gap:6px">
        <input id="couponInp" placeholder="e.g. DIWALI25" style="flex:1;padding:7px 10px;border-radius:7px;border:1px solid rgba(255,255,255,.25);background:rgba(0,0,0,.2);color:#fff;font-size:12px;text-transform:uppercase">
        <button type="button" id="couponBtn" style="padding:7px 12px;border-radius:7px;border:1px solid rgba(255,255,255,.3);background:rgba(255,255,255,.1);color:#fff;font-size:11.5px;font-weight:700;cursor:pointer">Apply</button>
      </div>
      <div id="couponMsg" style="font-size:10.5px;margin-top:5px;color:#7FE0EC"></div>
    </div>

    <div class="inv" id="inv">
      <div class="ln"><span id="ivSubLbl">Subscription</span><b id="ivSub">—</b></div>
      <div class="ln disc" id="ivDiscRow" style="display:none"><span id="ivDiscLbl">Advance discount</span><b id="ivDisc">—</b></div>
      <div class="ln" id="ivSetupRow" style="display:none"><span>Setup &amp; onboarding (one-time)</span><b id="ivSetup">—</b></div>
      <div class="ln" id="ivHostRow" style="display:none"><span id="ivHostLbl">Cloud hosting &amp; storage</span><b id="ivHost">—</b></div>
      <div class="ln disc" id="ivCoupRow" style="display:none"><span id="ivCoupLbl">Coupon</span><b id="ivCoup">—</b></div>
      <div class="ln sub"><span>GST 18%</span><b id="ivGst">—</b></div>
      <div class="ln tot"><span id="ivTotLbl">Payable now</span><b id="ivTot">—</b></div>
      <div class="eff" id="ivEff"></div>
    </div>
    <div class="trust">First invoice includes one-time setup; renewals bill the subscription only. Cloud adds ×1.5 rate + storage rental. Your data stays in your infrastructure — Ametecs manages only your licence.</div>
  </aside>

  <div class="card">
  <p class="sub">Start your free trial or sign in — self-service licence, billing &amp; cloud.</p>
  <div class="tabs">
    <button class="on" data-mode="login" onclick="mode('login',this)">Sign in</button>
    <button data-mode="signup" onclick="mode('signup',this)">Start free trial</button>
    <button data-mode="forgot" onclick="mode('forgot',this)">Forgot password</button>
  </div>
  <form id="f-login" onsubmit="return doLogin(event)">
    <label>Email</label><input type="email" name="email" required autocomplete="username">
    <label>Password</label><input type="password" name="password" required autocomplete="current-password">
    <button class="go" type="submit">Sign in →</button>
  </form>
  <form id="f-signup" style="display:none" onsubmit="return doSignup(event)">
    <label>Company name</label><input name="company_name" required maxlength="190">
    <div class="row">
      <div><label>Your name</label><input name="contact_name" required maxlength="190"></div>
      <div><label>Mobile</label><input name="phone" maxlength="20" placeholder="98480 12345"></div>
    </div>
    <label>Work email</label><input type="email" name="email" required maxlength="190">
    <label>Choose a password (min 8 characters)</label><input type="password" name="password" required minlength="8" autocomplete="new-password">
    <div class="row">
      <div><label>State <span style="color:#D02748">*</span></label><select name="state_code" id="stateSel" required><option value="">Select…</option></select></div>
      <div><label>GSTIN <span style="font-weight:400;color:#878C99">(optional)</span></label><input name="gstin" id="gstinInp" maxlength="15" placeholder="36AAHCT0971F1ZB" style="text-transform:uppercase"></div>
    </div>
    <div class="hint">Your state decides how GST appears on your invoice — CGST+SGST for Telangana, IGST for other states. GSTIN lets you claim input credit.</div>
    <input type="hidden" name="plan" id="planField">
    <input type="hidden" name="device_estimate" id="devField">
    <label style="display:flex;align-items:flex-start;gap:8px;font-size:12px;color:#565A66;font-weight:600;margin-top:10px;cursor:pointer">
      <input type="checkbox" name="terms_accepted" required style="width:auto;margin-top:2px;accent-color:#0E7C8F">
      <span>I agree to the <a href="/terms" target="_blank" style="color:#0B6373;font-weight:700">Terms</a> and <a href="/refunds" target="_blank" style="color:#0B6373;font-weight:700">Refund policy</a></span>
    </label>
    <div class="otp-wrap" id="signupOtp">
      <label>Enter the 6-digit code we emailed you</label>
      <input name="otp" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" placeholder="••••••">
    </div>
    <button class="go" type="submit" id="signupBtn">Email me a verification code →</button>
    <a class="quote" id="quoteCta" href="#" target="_blank">Prefer a formal quotation? Request one →</a>
    <div class="trial-note"><b>7-day free trial · Professional features · up to 10 devices.</b><br>No card needed. Your data auto-deletes if you don't continue.</div>
  </form>
  <form id="f-forgot" style="display:none" onsubmit="return doForgot(event)">
    <label>Account email</label><input type="email" name="email" required>
    <div class="otp-wrap" id="forgotOtp">
      <label>6-digit code from your email</label>
      <input name="otp" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" placeholder="••••••">
      <label>New password (min 8 characters)</label>
      <input type="password" name="password" minlength="8" autocomplete="new-password">
    </div>
    <button class="go" type="submit" id="forgotBtn">Email me a reset code →</button>
  </form>
  <div class="msg" id="msg"></div>
  <div class="msg demo" id="demoMsg" style="display:none"></div>
  <div class="foot">Prefer a human? WhatsApp <a href="https://wa.me/919000098877?text=Hi%20Ametecs" target="_blank">90000 98877</a><br>
  Ametecs India Private Limited · sales@ametecsindia.com<br>
  <a href="/privacy">Privacy</a> · <a href="/terms">Terms</a> · <a href="/refunds">Refunds</a> · <a href="/contact">Contact</a></div>
  </div>
</div>

<script>
const CSRF = document.querySelector('meta[name=csrf-token]').content;
let signupStep = 1, forgotStep = 1;
let PLANS=[], GST=18, CLOUDX=1.5, SETUP={base:5000,included:25,per:100}, STOR={slabs:[[1,500,3],[501,2048,2.5],[2049,null,2]],min_gb:50,min_inr:150};
let SEL='professional', HOST='hosted', CYC='y', COUPON=null;
const PERIOD = {q:{m:3,d:0,label:'Quarterly (3 months)'}, h:{m:6,d:0.10,label:'6-month advance'}, y:{m:12,d:0.25,label:'12-month advance'}};

const STATES=[['37','Andhra Pradesh'],['12','Arunachal Pradesh'],['18','Assam'],['10','Bihar'],['22','Chhattisgarh'],['30','Goa'],['24','Gujarat'],['06','Haryana'],['02','Himachal Pradesh'],['20','Jharkhand'],['29','Karnataka'],['32','Kerala'],['23','Madhya Pradesh'],['27','Maharashtra'],['14','Manipur'],['17','Meghalaya'],['15','Mizoram'],['13','Nagaland'],['21','Odisha'],['03','Punjab'],['08','Rajasthan'],['11','Sikkim'],['33','Tamil Nadu'],['36','Telangana'],['16','Tripura'],['09','Uttar Pradesh'],['05','Uttarakhand'],['19','West Bengal'],['07','Delhi'],['04','Chandigarh'],['01','Jammu & Kashmir'],['26','Dadra & Nagar Haveli and Daman & Diu'],['31','Lakshadweep'],['35','Andaman & Nicobar'],['38','Ladakh'],['34','Puducherry']];

function mode(m,btn){document.querySelectorAll('.tabs button').forEach(b=>b.classList.toggle('on',b.dataset.mode===m));['login','signup','forgot'].forEach(x=>document.getElementById('f-'+x).style.display=x===m?'':'none');show('','');document.getElementById('demoMsg').style.display='none';}
function show(kind,text){const el=document.getElementById('msg');el.className='msg'+(kind?' '+kind:'');el.textContent=text;el.style.display=kind?'block':'none';}
function demo(code){if(!code)return;const el=document.getElementById('demoMsg');el.textContent='TEST MODE — your code is '+code+' (real customers get it by email)';el.style.display='block';}
async function post(url,data){const res=await fetch(url,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':CSRF,'Accept':'application/json'},body:JSON.stringify(data)});let body={};try{body=await res.json();}catch(e){}if(!res.ok)throw new Error(body.error||(body.errors?Object.values(body.errors).flat().join(' '):(res.status===429?'Too many tries — please wait a minute.':'Something went wrong.')));return body;}
const formData=f=>Object.fromEntries(new FormData(f).entries());
async function doLogin(e){e.preventDefault();try{const out=await post('/client/login',formData(e.target));location.href=out.redirect||'/client';}catch(err){show('err',err.message);}return false;}
async function doSignup(e){e.preventDefault();const f=e.target,data=formData(f),btn=document.getElementById('signupBtn');btn.disabled=true;try{if(signupStep===1){const out=await post('/client/signup/request-otp',data);signupStep=2;document.getElementById('signupOtp').style.display='block';btn.textContent='Verify code & start my trial →';show('ok',out.message);demo(out.demo_otp);}else{if(!/^[0-9]{6}$/.test(data.otp||''))throw new Error('Please enter the 6-digit code.');const out=await post('/client/signup/verify',data);show('ok','Trial activated — opening your portal…');location.href=out.redirect||'/client';}}catch(err){show('err',err.message);}btn.disabled=false;return false;}
async function doForgot(e){e.preventDefault();const f=e.target,data=formData(f),btn=document.getElementById('forgotBtn');btn.disabled=true;try{if(forgotStep===1){const out=await post('/client/forgot/request-otp',{email:data.email});forgotStep=2;document.getElementById('forgotOtp').style.display='block';btn.textContent='Set new password →';show('ok',out.message);demo(out.demo_otp);}else{if(!/^[0-9]{6}$/.test(data.otp||''))throw new Error('Please enter the 6-digit code.');if(!data.password||data.password.length<8)throw new Error('New password needs at least 8 characters.');const out=await post('/client/forgot/reset',data);show('ok',out.message+' Use the Sign in tab above.');forgotStep=1;document.getElementById('forgotOtp').style.display='none';btn.textContent='Email me a reset code →';}}catch(err){show('err',err.message);}btn.disabled=false;return false;}

const inr=n=>'₹'+Math.round(n).toLocaleString('en-IN');
function annualRate(plan,dev){const p=PLANS.find(x=>(x.code||'').toLowerCase()===plan)||PLANS.find(x=>(x.code||'').toLowerCase()==='professional');if(!p)return{r:0,p:null};let r=p.inr_annual;(p.volume_tiers||[]).forEach(t=>{if(dev>=t.min&&(t.max===null||dev<=t.max))r=t.rate;});return{r,p};}
function storagePerMonth(dev){let gb=Math.max(STOR.min_gb,Math.ceil(dev*2));let cost=0,rem=gb;for(const[lo,hi,rate] of STOR.slabs){const cap=hi===null?Infinity:hi;const inSlab=Math.max(0,Math.min(rem+ (lo-1), cap)-(lo-1));if(gb> (lo-1)){const units=Math.min(gb,cap)-(lo-1);if(units>0)cost+=units*rate;}}return{gb,cost:Math.max(STOR.min_inr,Math.round(cost))};}
function render(){
  const dev=Math.max(1,parseInt(document.getElementById('devCount').value||'1',10));
  document.getElementById('devField').value=dev;
  const {r:aRate,p}=annualRate(SEL,dev); if(!p)return;
  const baseMonthly=aRate/0.75;                     // 12-mo @25% off == annual rate
  const perDevMonth=HOST==='cloud'?baseMonthly*CLOUDX:baseMonthly;
  const per=PERIOD[CYC];
  const gross=dev*perDevMonth*per.m;                // before advance discount
  const discAmt=gross*per.d;
  const sub=gross-discAmt;                          // subscription after advance discount
  const wantSetup=document.getElementById('setupChk').checked;
  const setup=wantSetup?(SETUP.base+Math.max(0,dev-SETUP.included)*SETUP.per):0;
  let host=0,gb=0;
  if(HOST==='cloud'){const st=storagePerMonth(dev);host=st.cost*per.m;gb=st.gb;}
  let taxable=sub+setup+host;
  // Coupon applies AFTER the advance discount, BEFORE GST (server does the same maths).
  let coupDisc=0;
  const cr=document.getElementById('ivCoupRow');
  if(COUPON){coupDisc=COUPON.type==='percent'?taxable*COUPON.value/100:Math.min(COUPON.value,taxable);
    cr.style.display='';document.getElementById('ivCoupLbl').textContent='Coupon '+COUPON.code+(COUPON.type==='percent'?' ('+COUPON.value+'% off)':'');
    document.getElementById('ivCoup').textContent='− '+inr(coupDisc);taxable-=coupDisc;}
  else cr.style.display='none';
  const gstAmt=taxable*GST/100;
  const total=taxable+gstAmt;
  const effPerMo=sub/dev/per.m;
  const nm=(p.name||'').replace(/smartept\s*/i,'')||'Professional';
  document.getElementById('ivSubLbl').textContent=nm+' · '+dev+' device'+(dev>1?'s':'')+' × '+per.m+' mo'+(HOST==='cloud'?' (cloud)':'');
  document.getElementById('ivSub').textContent=inr(gross);
  const dr=document.getElementById('ivDiscRow');
  if(per.d>0){dr.style.display='';document.getElementById('ivDiscLbl').textContent='Advance discount ('+(per.d*100)+'%)';document.getElementById('ivDisc').textContent='− '+inr(discAmt);}else dr.style.display='none';
  document.getElementById('ivSetupRow').style.display=wantSetup?'':'none';
  document.getElementById('ivSetup').textContent=inr(setup);
  const hr=document.getElementById('ivHostRow');
  if(HOST==='cloud'){hr.style.display='';document.getElementById('ivHostLbl').textContent='Cloud hosting & storage (~'+gb+' GB × '+per.m+' mo)';document.getElementById('ivHost').textContent=inr(host);}else hr.style.display='none';
  document.getElementById('ivGst').textContent=inr(gstAmt);
  document.getElementById('ivTotLbl').textContent='Payable now ('+per.m+'-month advance)';
  document.getElementById('ivTot').textContent=inr(total);
  document.getElementById('ivEff').textContent='≈ '+inr(effPerMo)+' /device/month'+(HOST==='cloud'?' + storage':'')+' · GST extra shown above';
}

(async function init(){
  const sel=document.getElementById('stateSel');
  STATES.slice().sort((a,b)=>a[1].localeCompare(b[1])).forEach(([c,n])=>{const o=document.createElement('option');o.value=c;o.textContent=n+' ('+c+')';sel.appendChild(o);});
  const params=new URLSearchParams(location.search);const plan=(params.get('plan')||'').toLowerCase();
  const KNOWN=['core','professional','enterprise'];
  SEL=KNOWN.includes(plan)?plan:'professional';
  document.getElementById('planField').value=SEL;
  if(plan)mode('signup',document.querySelector('.tabs button[data-mode=signup]'));
  // controls
  const dc=document.getElementById('devCount');
  document.getElementById('devMinus').onclick=()=>{dc.value=Math.max(1,(parseInt(dc.value||'1',10)-5));render();upd();};
  document.getElementById('devPlus').onclick=()=>{dc.value=(parseInt(dc.value||'1',10)+5);render();upd();};
  dc.oninput=()=>{render();upd();};
  function segHost(v){HOST=v;document.getElementById('hHosted').classList.toggle('on',v==='hosted');document.getElementById('hCloud').classList.toggle('on',v==='cloud');render();}
  document.getElementById('hHosted').onclick=()=>segHost('hosted');
  document.getElementById('hCloud').onclick=()=>segHost('cloud');
  function segCyc(v){CYC=v;['q','h','y'].forEach(k=>document.getElementById('c'+k.toUpperCase()).classList.toggle('on',k===v));render();}
  document.getElementById('cQ').onclick=()=>segCyc('q');
  document.getElementById('cH').onclick=()=>segCyc('h');
  document.getElementById('cY').onclick=()=>segCyc('y');
  document.getElementById('setupChk').onchange=render;
  // ---- Coupon apply (public coupon-check keeps signup, portal and admin on the same rules) ----
  const cMsg=document.getElementById('couponMsg');
  async function applyCoupon(code,quiet){
    code=(code||'').trim().toUpperCase();
    if(!code){COUPON=null;cMsg.textContent='';render();return;}
    try{
      const dev=Math.max(1,parseInt(document.getElementById('devCount').value||'1',10));
      const email=(document.querySelector('#f-signup [name=email]')?.value||'').trim();
      const r=await fetch('/api/v1/public/coupon-check',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':CSRF,'Accept':'application/json'},body:JSON.stringify({code,devices:dev,email:email||null})});
      const j=await r.json();
      if(j.ok){COUPON={code:j.code,type:j.type,value:j.value};cMsg.style.color='#7FE0EC';cMsg.textContent='✓ '+j.code+' applied'+(j.description?' — '+j.description:'');document.getElementById('couponInp').value=j.code;}
      else{COUPON=null;cMsg.style.color='#FFB3C0';cMsg.textContent=quiet?'':'✗ Code not valid ('+(j.reason||'unknown')+')';}
    }catch(e){if(!quiet){cMsg.style.color='#FFB3C0';cMsg.textContent='Could not check the code — try again.';}}
    render();
  }
  document.getElementById('couponBtn').onclick=()=>applyCoupon(document.getElementById('couponInp').value,false);
  document.getElementById('couponInp').addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();applyCoupon(e.target.value,false);}});
  // ---- Exclusive-offer catch (blueprint §6): email typed → quietly check for a personal coupon ----
  const emailInp=document.querySelector('#f-signup [name=email]');
  if(emailInp)emailInp.addEventListener('blur',async()=>{
    const email=emailInp.value.trim();
    if(!email||COUPON)return;
    try{
      const r=await fetch('/api/v1/public/exclusive-offer',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':CSRF,'Accept':'application/json'},body:JSON.stringify({email})});
      const j=await r.json();
      if(j.ok){COUPON={code:j.code,type:j.type,value:j.value};document.getElementById('couponInp').value=j.code;
        cMsg.style.color='#7FE0EC';cMsg.textContent='🎁 An exclusive offer for you was waiting — '+j.code+' applied automatically!';render();}
    }catch(e){}
  });
  try{
    const r=await fetch('/api/v1/public/plans',{headers:{Accept:'application/json'},cache:'no-store'});const j=await r.json();
    PLANS=j.plans||j.data||[];GST=j.gst_rate||18;CLOUDX=j.cloud_multiplier||1.5;
    if(j.setup)SETUP={base:j.setup.base,included:j.setup.included,per:j.setup.per_extra};
    if(j.storage)STOR={slabs:j.storage.slabs,min_gb:j.storage.min_gb,min_inr:j.storage.min_inr};
    const p=PLANS.find(x=>(x.code||'').toLowerCase()===SEL);
    if(p){const nm=/smartept/i.test(p.name)?p.name:('SmartEPT '+p.name);document.getElementById('sumPlan').textContent=nm;document.getElementById('sumTag').textContent='Estimate your '+p.name.replace(/smartept\s*/i,'')+' subscription — free for 7 days.';}
    render();
  }catch(e){document.getElementById('inv').style.opacity=.5;}
  function upd(){const dev=document.getElementById('devCount').value;const t='Hi Ametecs, I would like a quotation for SmartEPT '+SEL+' — '+dev+' devices, '+(HOST==='cloud'?'SmartEPT Cloud':'client-hosted')+', '+PERIOD[CYC].label+'.';document.getElementById('quoteCta').href='https://wa.me/919000098877?text='+encodeURIComponent(t);}
  window.upd=upd; upd();
})();
</script>
</body>
</html>
