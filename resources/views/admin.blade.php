<!DOCTYPE html>
<html lang="en">
<head>
  <link rel="icon" href="/favicon.ico?v=2" sizes="any">
  <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png?v=2">
  <link rel="apple-touch-icon" href="/apple-touch-icon.png?v=2">
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SmartEPT — Admin Console</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">
<style>
  /* ============================================================
     SmartEPT Admin Console — Design System v2 (Ocean Teal)
     17-Jul-2026 visual upgrade: SAME classes, SAME behaviour —
     only the design layer changed. Matches Central + Client Portal.
     ============================================================ */
  :root{
    --canvas:#F1EFEA;--card:#FFFFFF;--card-2:#FAF9F5;--border:#E5E1D8;--hairline:#EDEAE2;
    --ink:#0F1E26;--ink-2:#4A5A66;--ink-3:#8494A0;
    --accent:#0E7C8F;--accent-2:#22B8CF;--accent-3:#31D2E8;--accent-weak:#E1F3F6;--accent-ink:#0A6273;
    --ok:#0A9464;--ok-w:#E3F6EE;--warn:#B7791F;--warn-w:#FBF3E2;--danger:#D22A4C;--danger-w:#FBE9ED;
    --info:#0B72C9;--info-w:#E6F1FB;--idle:#6D28D9;--idle-w:#F0EAFC;
    --navy:#052A33;--navy-2:#0B4A56;
    --font-head:'Plus Jakarta Sans','Inter','Segoe UI',sans-serif;
    --shadow-1:0 1px 2px rgba(16,42,51,.05),0 6px 18px rgba(16,42,51,.07);
    --shadow-2:0 6px 16px rgba(16,42,51,.09),0 22px 48px rgba(16,42,51,.13);
    --ring:0 0 0 3px rgba(34,184,207,.22);
  }
  *{box-sizing:border-box;margin:0;padding:0}
  html{scrollbar-color:#C6D2DA transparent;scrollbar-width:thin;overflow-x:clip}
  body{font-family:'Inter','Segoe UI',system-ui,Arial,sans-serif;background-color:var(--canvas);
    background-image:
    url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='160' height='160'%3E%3Cfilter id='p'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.8' numOctaves='2' stitchTiles='stitch'/%3E%3CfeColorMatrix type='saturate' values='0'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23p)' opacity='0.35'/%3E%3C/svg%3E"),
    radial-gradient(900px 320px at 85% -80px, rgba(34,184,207,.09), transparent 60%);
    background-blend-mode:soft-light,normal;background-attachment:fixed,fixed;
    color:var(--ink);font-size:13.5px;-webkit-font-smoothing:antialiased;overflow-x:clip}
  ::selection{background:rgba(34,184,207,.25)}
  ::-webkit-scrollbar{width:9px;height:9px}
  ::-webkit-scrollbar-thumb{background:#C6D2DA;border-radius:8px;border:2px solid var(--canvas)}
  ::-webkit-scrollbar-thumb:hover{background:#AEBEC9}
  .hide{display:none!important}
  a{color:var(--accent);text-decoration:none}
  a:hover{color:var(--accent-ink)}
  h1,h2,h3,h4{font-family:var(--font-head);letter-spacing:-.01em}

  /* ---------- Login ---------- */
  .login{min-height:100vh;display:flex;align-items:center;justify-content:center;
    background:linear-gradient(150deg,var(--navy) 0%,#083A44 55%,var(--navy-2) 100%);position:relative;overflow:hidden}
  .login::before{content:'';position:absolute;width:640px;height:640px;border-radius:50%;
    background:radial-gradient(circle,rgba(34,184,207,.20),transparent 65%);top:-260px;right:-160px}
  .login::after{content:'';position:absolute;width:520px;height:520px;border-radius:50%;
    background:radial-gradient(circle,rgba(14,124,143,.25),transparent 65%);bottom:-240px;left:-140px}
  .login .box{background:var(--card);border:none;border-radius:20px;padding:34px 32px;width:384px;
    box-shadow:0 30px 90px rgba(0,0,0,.45);position:relative;z-index:2}
  .login .box::before{content:'';display:block;position:absolute;top:0;left:24px;right:24px;height:4px;
    border-radius:0 0 6px 6px;background:linear-gradient(90deg,var(--accent),var(--accent-3))}
  .lock{display:flex;align-items:center;gap:11px;margin-bottom:22px}
  .mark{width:42px;height:42px;border-radius:12px;background:linear-gradient(135deg,var(--accent),var(--accent-2));
    display:flex;align-items:center;justify-content:center;color:#fff;font-weight:800;font-size:13px;flex:none;
    box-shadow:0 6px 16px rgba(14,124,143,.35);font-family:var(--font-head)}
  .lock h1{font-size:19px;font-weight:800}
  .lock small{display:block;color:var(--ink-3);font-size:9px;font-weight:700;letter-spacing:2px}
  label{display:block;font-size:11px;color:var(--ink-2);font-weight:700;margin:13px 0 5px}
  input,select,textarea{width:100%;background:var(--card-2);border:1.5px solid var(--border);border-radius:10px;
    padding:10px 12px;font-size:13px;font-family:inherit;color:var(--ink);transition:border-color .15s, box-shadow .15s}
  input:focus,select:focus,textarea:focus{outline:none;border-color:var(--accent-2);box-shadow:var(--ring);background:#fff}
  input[type=checkbox],input[type=radio]{width:auto;accent-color:var(--accent);transform:scale(1.15);cursor:pointer;box-shadow:none}
  button.primary{width:100%;margin-top:20px;background:linear-gradient(135deg,var(--accent),var(--accent-2));color:#fff;
    border:none;border-radius:10px;padding:12px;font-weight:700;font-size:13.5px;cursor:pointer;font-family:inherit;
    box-shadow:0 8px 20px rgba(14,124,143,.35);transition:transform .12s, box-shadow .12s}
  button.primary:hover{transform:translateY(-1px);box-shadow:0 12px 26px rgba(14,124,143,.45)}
  .err{color:var(--danger);font-size:12px;margin-top:12px;min-height:15px}

  /* ---------- Shell ---------- */
  .shell{display:flex;min-height:100vh}
  .side{width:232px;background:linear-gradient(178deg,var(--navy) 0%,#07333D 60%,#083A44 100%);
    border-right:none;position:fixed;height:100vh;padding:18px 13px 14px;display:flex;flex-direction:column;gap:2px;
    overflow:hidden;z-index:6}
  .navwrap{flex:1 1 auto;min-height:0;overflow-y:auto;overflow-x:hidden;display:flex;flex-direction:column;gap:2px;
    margin:0 -4px;padding:2px 4px;scrollbar-width:thin;scrollbar-color:rgba(255,255,255,.18) transparent}
  .navwrap::-webkit-scrollbar{width:5px}
  .navwrap::-webkit-scrollbar-thumb{background:rgba(255,255,255,.16);border-radius:3px}
  .side::-webkit-scrollbar{width:5px}
  .side::-webkit-scrollbar-thumb{background:rgba(255,255,255,.16);border:none}
  .side .lock{padding:4px 8px 16px;margin:0;border-bottom:1px solid rgba(255,255,255,.09);flex:0 0 auto}
  .side .lock h1{color:#fff}
  .side .lock small{color:#7FA8AF}
  .navgrp{font-size:9.5px;letter-spacing:2.2px;color:#5E858C;font-weight:800;margin:16px 12px 6px;font-family:var(--font-head)}
  .nav{display:flex;align-items:center;gap:11px;padding:9.5px 12px;border-radius:10px;color:#A9CBD1;font-size:13.5px;
    font-weight:600;letter-spacing:.1px;cursor:pointer;transition:background .12s,color .12s}
  .nav:hover{background:rgba(255,255,255,.07);color:#fff}
  .nav.active{background:linear-gradient(135deg,var(--accent),#1899AE);color:#fff;box-shadow:0 4px 12px rgba(0,0,0,.25)}
  .nav .ic{width:18px;height:18px;display:flex;align-items:center;justify-content:center;opacity:.9;flex:none}
  .nav .ic svg{width:16.5px;height:16.5px;display:block}
  .nav.active .ic{opacity:1}
  .side .foot{flex:0 0 auto;font-size:11px;color:#7FA8AF;padding:12px 8px 2px;border-top:1px solid rgba(255,255,255,.09);line-height:1.7}
  .side .foot a{color:#A9CBD1!important}
  .side .foot a:hover{color:#fff!important}
  .main{margin-left:232px;flex:1;padding:0 28px 44px;min-width:0;display:flex;flex-direction:column;min-height:100vh}
  .page-copy{margin-top:auto;padding-top:18px;border-top:1px solid var(--border);text-align:center;font-size:10.5px;color:var(--ink-3);line-height:1.5}
  .top{display:flex;align-items:center;justify-content:space-between;padding:18px 0 14px;position:sticky;top:0;
    background:var(--canvas);border-bottom:1px solid var(--border);margin-bottom:18px;z-index:30}
  .top h2{font-size:21px;font-weight:800}
  .top .sub{color:var(--ink-3);font-size:12px;margin-top:2px}
  .top-l{display:flex;align-items:center;gap:12px;min-width:0;flex:1}
  .top-l > div{min-width:0}
  .top h2,.top .sub{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:100%}
  .who{font-size:12px;color:var(--ink-2);display:flex;align-items:center;gap:12px;flex:none}
  .who b{color:var(--ink)}
  .help-i{width:28px;height:28px;border-radius:50%;border:1.5px solid var(--accent);color:var(--accent);flex:none;
    background:var(--accent-weak);font-weight:800;font-size:13px;cursor:pointer;flex:none;transition:transform .12s}
  .help-i:hover{transform:scale(1.08);background:var(--accent);color:#fff}
  #btn-refresh{font-size:17px;font-weight:700;line-height:1}
  .help-i.spin{animation:spin .65s linear}
  @keyframes spin{to{transform:rotate(360deg)}}
  .view{display:none}
  .view.active{display:block;animation:viewin .18s ease}
  @keyframes viewin{from{opacity:0;transform:translateY(4px)}to{opacity:1;transform:none}}

  /* ---------- KPI cards ---------- */
  .kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:20px}
  .kpi{background:var(--card);border:1px solid var(--border);border-radius:15px;padding:15px 16px;position:relative;
    overflow:hidden;box-shadow:var(--shadow-1);transition:transform .14s, box-shadow .14s}
  .kpi:hover{transform:translateY(-2px);box-shadow:var(--shadow-2)}
  .kpi::before{content:'';position:absolute;left:0;top:12px;bottom:12px;width:3.5px;border-radius:0 4px 4px 0;
    background:linear-gradient(180deg,var(--kc,var(--accent)),var(--kc2,var(--accent-3)))}
  .kpi .l{font-size:10.5px;color:var(--ink-3);text-transform:uppercase;letter-spacing:.7px;font-weight:700}
  .kpi .v{font-size:27px;font-weight:800;margin-top:7px;font-family:var(--font-head);letter-spacing:-.02em;color:var(--kc,var(--ink))}
  /* EPT-23: colour-coded, clickable drill-down KPIs. --kc set per class below. */
  .k-total{--kc:var(--accent);--kc2:var(--accent-2);--kcw:var(--accent-weak)}
  .k-ok{--kc:var(--ok);--kc2:var(--ok);--kcw:var(--ok-w)}
  .k-idle{--kc:var(--idle);--kc2:var(--idle);--kcw:var(--idle-w)}
  .k-away{--kc:var(--warn);--kc2:var(--warn);--kcw:var(--warn-w)}
  .k-off{--kc:var(--ink-3);--kc2:var(--ink-3);--kcw:#EDF1F4}
  .k-break{--kc:var(--info);--kc2:var(--info);--kcw:var(--info-w)}
  .k-cam{--kc:var(--danger);--kc2:var(--danger);--kcw:var(--danger-w)}
  .k-viol{--kc:var(--danger);--kc2:var(--danger);--kcw:var(--danger-w)}
  .k-shot{--kc:var(--info);--kc2:var(--info);--kcw:var(--info-w)}
  .kpi.drill{cursor:pointer}
  .kpi.drill .go{position:absolute;right:12px;top:14px;font-size:9.5px;font-weight:800;color:var(--kc,var(--accent));
    opacity:0;transform:translateX(-3px);transition:opacity .14s,transform .14s;letter-spacing:.3px;text-transform:uppercase}
  .kpi.drill:hover .go{opacity:.85;transform:translateX(0)}
  .kpi.drill:hover{background:var(--kcw)}
  .kpi.sel{background:var(--kcw);box-shadow:inset 0 0 0 1.5px var(--kc)}
  .fchip{display:inline-flex;align-items:center;gap:7px;background:var(--kcw,var(--accent-weak));color:var(--kc,var(--accent-ink));
    border:1px solid var(--kc,var(--accent));border-radius:20px;padding:3px 5px 3px 11px;font-size:11px;font-weight:700;vertical-align:middle}
  .fchip .x{cursor:pointer;width:17px;height:17px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;
    background:rgba(0,0,0,.07);font-size:11px;line-height:1}
  .fchip .x:hover{background:rgba(0,0,0,.16)}
  /* 50/50 colour KPI cards (Ejaz 19-Jul): a solid colour half holds the label, the big value fills the rest. */
  .kpi{padding:0;display:flex;align-items:stretch;min-height:76px}
  .kpi::before{display:none}
  .kpi .kside{flex:0 0 46%;background:var(--kc,var(--accent));display:flex;align-items:center;padding:12px 14px}
  .kpi .kside .l{color:#fff;font-size:11px;line-height:1.25;font-weight:800;text-transform:uppercase;letter-spacing:.5px;margin:0}
  .kpi .kmain{flex:1;display:flex;align-items:center;padding:10px 18px;position:relative}
  .kpi .kmain .v{font-size:34px;font-weight:800;color:var(--kc,var(--ink));margin:0;font-family:var(--font-head);letter-spacing:-.02em}
  .kpi.drill .go{position:absolute;right:10px;top:8px;color:var(--kc,var(--accent));opacity:0;transform:none}
  .kpi.drill:hover{background:var(--card)}
  .kpi.drill:hover .go{opacity:.85}
  .kpi.drill:hover .kmain{background:var(--kcw,var(--accent-weak))}
  .kpi.sel{box-shadow:inset 0 0 0 2px var(--kc,var(--accent))}
  .kpi.sel .kmain{background:var(--kcw,var(--accent-weak))}

  /* ---------- Dashboard charts ---------- */
  .dash-charts{display:grid;grid-template-columns:340px 1fr;gap:18px;margin-bottom:18px}
  @media(max-width:920px){.dash-charts{grid-template-columns:1fr}}
  .wf-wrap{display:flex;flex-direction:column;gap:16px;align-items:center;padding-top:4px}
  .wf-leg{display:flex;flex-direction:column;gap:11px;width:100%;max-width:290px}
  .wf-leg .r{display:flex;align-items:center;gap:8px;font-size:12px}
  .wf-leg .dot{width:10px;height:10px;border-radius:3px;flex:none}
  .wf-leg .nm{color:var(--ink-2);flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .wf-leg .ct{font-weight:800;font-variant-numeric:tabular-nums}
  .wf-leg .pc{color:var(--ink-3);font-size:11px;font-variant-numeric:tabular-nums;min-width:38px;text-align:right}
  .tu-grid{display:grid;grid-template-columns:1fr 1fr;gap:22px}
  @media(max-width:620px){.tu-grid{grid-template-columns:1fr}}
  .tu-h{font-size:10.5px;text-transform:uppercase;letter-spacing:.7px;color:var(--ink-3);font-weight:700;margin-bottom:12px}
  .tu-row{display:grid;grid-template-columns:1fr auto;gap:3px 10px;margin-bottom:11px}
  .tu-name{font-size:12px;color:var(--ink-2);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .tu-val{font-size:11.5px;font-weight:700;font-variant-numeric:tabular-nums;white-space:nowrap}
  .tu-val .pc{color:var(--ink-3);font-weight:600;margin-left:5px}
  .tu-track{grid-column:1/-1;height:7px;border-radius:4px;background:rgba(148,163,184,.20);overflow:hidden}
  .tu-fill{height:100%;border-radius:4px;min-width:3px}

  /* ---------- Dashboard org roll-up filter ---------- */
  .org-filter{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:16px;padding:11px 14px;
    background:var(--card);border:1px solid var(--border);border-radius:13px;box-shadow:var(--shadow-1)}
  .org-filter .of-l{font-size:10.5px;text-transform:uppercase;letter-spacing:.7px;color:var(--ink-3);font-weight:700}
  .org-filter select{font-size:12px;padding:6px 10px;border-radius:9px;border:1px solid var(--border);
    background:var(--card);color:var(--ink);max-width:200px}
  .org-filter .of-scope{font-size:11.5px;color:var(--ink-3);margin-left:auto;font-weight:600}
  #dash-org{position:sticky;top:74px;z-index:20;box-shadow:var(--shadow-1)}
  .dash-charts .card{display:flex;flex-direction:column;max-height:400px}
  .dash-charts .card h3{flex:none}
  .dash-charts .tu-grid,.dash-charts .wf-wrap{overflow-y:auto;min-height:0}
  .quickpick{margin-top:2px}
  .qp-l{font-size:11px;color:var(--ink-3);font-weight:700;margin-bottom:7px}
  .qp-row{display:flex;flex-wrap:wrap;gap:6px}
  .qchip{width:auto;margin:0;padding:5px 10px;font-size:11.5px;font-weight:600;background:var(--card-2);
    border:1px solid var(--border);color:var(--ink-2);border-radius:16px;cursor:pointer;transition:all .12s}
  .qchip:hover{border-color:var(--accent);color:var(--accent-ink);background:var(--accent-weak)}
  .rst{font-weight:700;padding:5px 8px;max-width:150px}
  .rst-ALLOWED{color:var(--ok)}.rst-TRACKED{color:var(--info)}.rst-BLOCKED{color:var(--warn)}.rst-VIOLATION{color:var(--danger)}

  /* ---------- Cards & tables ---------- */
  .card{background:var(--card);border:1px solid var(--hairline);border-radius:18px;padding:19px 22px;margin-bottom:18px;
    box-shadow:var(--shadow-1),inset 0 1px 0 rgba(255,255,255,.7)}
  .card h3{font-size:13.5px;font-weight:800;margin-bottom:14px;display:flex;justify-content:space-between;
    align-items:center;gap:10px;flex-wrap:wrap;color:var(--accent-ink)}
  .card h3 .hint{font-size:10.5px;color:var(--ink-3);font-weight:600;font-family:'Inter',sans-serif;letter-spacing:0}
  table{width:100%;border-collapse:separate;border-spacing:0;font-size:12.5px}
  th{text-align:left;background:var(--accent-weak);color:var(--accent-ink);font-weight:700;font-size:10.5px;
    text-transform:uppercase;letter-spacing:.5px;padding:9px 10px;border-bottom:none}
  th.sortable-h{cursor:pointer;user-select:none;white-space:nowrap}
  th.sortable-h:hover{color:var(--accent);background:#D2ECF1}
  th.sortable-h .arw{opacity:.35;font-size:9px;margin-left:3px}
  th.sortable-h.sorted .arw{opacity:1}
  th:first-child{border-radius:9px 0 0 9px}
  th:last-child{border-radius:0 9px 9px 0}
  td{padding:10px;border-bottom:1px solid var(--hairline);color:var(--ink-2);vertical-align:middle}
  tr:last-child td{border-bottom:none}
  td b,td .nm{color:var(--ink);font-weight:600}
  tbody tr{transition:background .1s}
  tbody tr:hover td{background:var(--card-2)}
  .tag{font-size:10px;font-weight:700;padding:3.5px 10px;border-radius:12px;display:inline-block;white-space:nowrap}
  .t-ok{background:var(--ok-w);color:var(--ok)}
  .t-idle{background:var(--idle-w);color:var(--idle)}
  .t-warn{background:var(--warn-w);color:var(--warn)}
  .t-off{background:#EDF1F4;color:var(--ink-3)}
  .t-danger{background:var(--danger-w);color:var(--danger)}
  .t-info{background:var(--info-w);color:var(--info)}
  .clk{cursor:pointer}
  .clk:hover td{background:var(--accent-weak)!important}
  .btn{background:var(--card);border:1.5px solid var(--border);border-radius:9px;padding:7px 13px;font-size:12px;
    font-weight:700;color:var(--ink-2);cursor:pointer;font-family:inherit;transition:all .13s}
  .btn:hover{border-color:var(--accent);color:var(--accent);box-shadow:0 2px 8px rgba(14,124,143,.12)}
  .btn.acc{background:var(--accent-weak);color:var(--accent-ink);border-color:transparent}
  .btn.acc:hover{background:#D2ECF1}
  .btn.solid{background:linear-gradient(135deg,var(--accent),var(--accent-2));color:#fff;border-color:transparent;
    box-shadow:0 4px 12px rgba(14,124,143,.28)}
  .btn.solid:hover{color:#fff;transform:translateY(-1px);box-shadow:0 7px 16px rgba(14,124,143,.38)}
  .btn.danger{background:var(--danger-w);color:var(--danger);border-color:transparent}
  .btn.danger:hover{background:#F7D6DE;color:var(--danger);border-color:transparent}
  .row{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
  .filters{display:flex;gap:9px;flex-wrap:wrap;align-items:center;margin-bottom:16px;background:var(--card);
    border:1px solid var(--border);border-radius:13px;padding:11px 14px;box-shadow:var(--shadow-1)}
  .filters label{margin:0;white-space:nowrap}
  .filters input,.filters select{width:auto;min-width:150px;padding:8px 11px}
  .mut{color:var(--ink-3);font-size:12px;padding:10px 0}
  .grid2{display:grid;grid-template-columns:1fr 1fr;gap:18px;align-items:start}
  .grid2 > .card{margin-bottom:0}
  @media(max-width:1100px){.grid2{grid-template-columns:1fr}.kpis{grid-template-columns:1fr 1fr}}
  /* ---- Mobile ---- */
  .ham{display:none;width:38px;height:38px;border-radius:10px;border:1px solid var(--border);background:var(--card);color:var(--ink);font-size:19px;line-height:1;cursor:pointer;align-items:center;justify-content:center;flex:none}
  .nav-backdrop{position:fixed;inset:0;background:rgba(4,20,25,.5);z-index:100;opacity:0;visibility:hidden;transition:opacity .26s ease,visibility .26s}
  @media(max-width:860px){
    .side{transform:translateX(-100%);transition:transform .26s ease;width:270px;z-index:110;box-shadow:0 0 50px rgba(0,0,0,.45)}
    #app.nav-open .side{transform:translateX(0)}
    #app.nav-open .nav-backdrop{opacity:1;visibility:visible}
    .main{margin-left:0;padding:0 14px 40px}
    .ham{display:inline-flex}
    .top{padding:14px 0 12px}
    .top h2{font-size:18px}
    .who{gap:8px}
    .who #company-name{display:none}
    #dash-org{top:64px}
    .kpis{grid-template-columns:1fr 1fr;gap:12px}
    .dash-charts{grid-template-columns:1fr}
    .grid2{grid-template-columns:1fr}
    .fgrid{grid-template-columns:1fr}
    .filters input,.filters select{min-width:0;flex:1 1 140px}
    .org-filter select{max-width:none;flex:1 1 44%}
    .card{overflow-x:auto}
    .card table{min-width:600px}
    .modal{width:96vw}
    .drawer{width:100vw;max-width:100vw}
  }
  @media(max-width:520px){
    .kpis{grid-template-columns:1fr}
    .top h2{font-size:16.5px}
  }

  /* ---------- Screenshots ---------- */
  .shots{display:grid;grid-template-columns:repeat(auto-fill,minmax(196px,1fr));gap:13px}
  .shotcard{border:1px solid var(--border);border-radius:12px;overflow:hidden;background:var(--card);cursor:pointer;
    transition:transform .13s, box-shadow .13s, border-color .13s;box-shadow:var(--shadow-1)}
  .shotcard:hover{border-color:var(--accent-2);transform:translateY(-2px);box-shadow:var(--shadow-2)}
  .shotcard .img{height:112px;background:linear-gradient(135deg,#E7EDF3,#F2F5F9);display:flex;align-items:center;
    justify-content:center;color:var(--ink-3);font-size:10px;font-weight:700;overflow:hidden}
  .shotcard .img img{width:100%;height:100%;object-fit:cover;display:block}
  .shotcard .m{padding:9px 11px;font-size:10.5px;color:var(--ink-2);line-height:1.5}
  .shotcard .m b{display:block;color:var(--ink);font-size:11px}
  .shotcard.ev-hit{outline:3px solid var(--warn);outline-offset:2px;animation:evpulse 1.6s ease 2}
  .shotcard.ev-hit::after{content:'⚠ VIOLATION EVIDENCE';display:block;background:var(--warn-w);color:var(--warn);
    font-size:9.5px;font-weight:800;letter-spacing:.6px;text-align:center;padding:4px}
  @keyframes evpulse{0%,100%{box-shadow:var(--shadow-1)}50%{box-shadow:0 0 0 8px rgba(183,121,31,.22)}}

  /* ---------- Overlays / modals ---------- */
  .ovl{position:fixed;inset:0;background:rgba(5,42,51,.55);backdrop-filter:blur(3px);display:none;align-items:center;
    justify-content:center;z-index:80;padding:20px}
  .ovl.open{display:flex;animation:viewin .15s ease}
  .modal{background:var(--card);border-radius:16px;width:640px;max-width:100%;max-height:88vh;display:flex;
    flex-direction:column;overflow:hidden;box-shadow:0 40px 110px rgba(0,0,0,.45)}
  .mhead{background:linear-gradient(135deg,var(--navy),var(--navy-2));color:#fff;padding:15px 20px;display:flex;align-items:center;gap:12px}
  .mhead b{font-size:14px;display:block;font-family:var(--font-head)}
  .mhead span{font-size:10.5px;color:#9FC5CC}
  .mhead .mt{flex:1}
  .mhead .x{background:rgba(255,255,255,.1);border:none;color:#fff;font-size:16px;cursor:pointer;font-family:inherit;
    width:30px;height:30px;border-radius:8px;transition:background .12s}
  .mhead .x:hover{background:rgba(255,255,255,.22)}
  .mbody{padding:18px 20px;overflow-y:auto;font-size:12.5px;line-height:1.65;color:var(--ink-2)}
  .mbody h5{font-size:12px;color:var(--accent-ink);margin:13px 0 5px;font-family:var(--font-head)}
  .mbody h5:first-child{margin-top:0}
  .mfoot{padding:13px 20px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:8px;background:var(--card-2)}
  .shotbig{max-width:92vw;max-height:78vh;border-radius:12px;display:block;background:#fff;box-shadow:0 30px 80px rgba(0,0,0,.5)}
  .shotmeta{color:#fff;font-size:12px;text-align:center;margin-top:10px}

  /* ---------- Forms ---------- */
  .fgrid{display:grid;grid-template-columns:1fr 1fr;gap:0 16px}
  .fgrid .full{grid-column:1 / -1}
  .fbool{display:flex;align-items:center;gap:9px;padding:9px 2px 0;font-size:12.5px;color:var(--ink-2);font-weight:600}
  textarea{min-height:64px;resize:vertical;font-size:12px}
  .search{min-width:220px}

  /* ---------- Policies ---------- */
  .never{background:var(--danger-w);border:1px solid #F3C2CE;border-left:4px solid var(--danger);border-radius:12px;
    padding:14px 16px;font-size:12px;color:var(--ink-2);margin-bottom:16px}
  .never b{color:var(--danger);display:block;margin-bottom:7px;font-size:12px}
  .never ul{margin:0 0 0 18px;line-height:1.8}

  /* ---------- Reports ---------- */
  .exp-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:15px}
  .exp{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:17px;box-shadow:var(--shadow-1);
    transition:transform .13s, box-shadow .13s;position:relative;overflow:hidden}
  .exp::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,var(--accent),var(--accent-3));opacity:0;transition:opacity .13s}
  .exp:hover{transform:translateY(-2px);box-shadow:var(--shadow-2)}
  .exp:hover::before{opacity:1}
  .exp b{font-size:13px;font-family:var(--font-head)}
  .exp p{font-size:11.5px;color:var(--ink-3);margin:7px 0 11px;line-height:1.55}
  .exp .row input{min-width:0;width:auto;flex:1;padding:8px 10px}

  /* ---------- Drawer ---------- */
  /* Section 7: the drawer must sit ABOVE the sticky top bar (z-index:30) and the org
     roll-up (z-index:20) — it was z-index:20 so its header slid under the page chrome.
     Now it is a true top-level overlay (150) with a click-catching backdrop (140). */
  .drawer-backdrop{position:fixed;inset:0;background:rgba(4,20,25,.5);backdrop-filter:blur(2px);
    z-index:140;opacity:0;visibility:hidden;transition:opacity .2s ease,visibility .2s}
  .drawer-backdrop.open{opacity:1;visibility:visible}
  .drawer{position:fixed;top:0;right:0;width:530px;max-width:92vw;height:100vh;background:var(--card);border-left:1px solid var(--border);
    box-shadow:-24px 0 60px rgba(5,42,51,.18);padding:22px;overflow-y:auto;transform:translateX(100%);
    transition:transform .22s cubic-bezier(.2,.8,.3,1);z-index:150;border-radius:18px 0 0 18px}
  .drawer.open{transform:none}
  body.drawer-lock{overflow:hidden}
  .drawer .x{float:right;cursor:pointer;color:var(--ink-3);font-size:18px;width:30px;height:30px;text-align:center;
    line-height:30px;border-radius:8px;transition:background .12s}
  .drawer .x:hover{background:var(--card-2);color:var(--ink)}
  .tabs{display:flex;gap:6px;margin:14px 0}
  .tab{font-size:11.5px;padding:7px 13px;border-radius:9px;background:var(--card-2);border:1.5px solid var(--border);
    cursor:pointer;color:var(--ink-2);font-weight:700;transition:all .12s}
  .tab:hover{border-color:var(--accent-2);color:var(--accent-ink)}
  .tab.active{background:linear-gradient(135deg,var(--accent),var(--accent-2));color:#fff;border-color:transparent;
    box-shadow:0 3px 10px rgba(14,124,143,.3)}
  .tl{border-left:2px solid var(--accent-weak);margin-left:6px;padding-left:15px}
  .tl .ev{position:relative;padding:7px 0;font-size:12px}
  .tl .ev::before{content:'';position:absolute;left:-20px;top:11px;width:8px;height:8px;border-radius:50%;
    background:var(--accent-2);box-shadow:0 0 0 3px var(--accent-weak)}
  .tl .ev .tm{color:var(--ink-3);font-size:10.5px;margin-right:8px;font-variant-numeric:tabular-nums}

  /* ---------- Empty / denied ---------- */
  .denied{text-align:center;padding:44px 20px;color:var(--ink-3)}
  .denied .big{font-size:32px;margin-bottom:10px}
  .empty{border:1.5px dashed #C9D6DE;border-radius:14px;padding:38px 22px;text-align:center;color:var(--ink-3);
    font-size:12.5px;line-height:1.75;background:var(--card-2)}
  .empty b{color:var(--ink-2)}
</style>
</head>
<body>

<!-- LOGIN -->
<div class="login" id="login">
  <div class="box">
    <div class="lock" style="justify-content:center"><img src="/img/smartept-logo-h-light.png" alt="SmartEPT by Ametecs" style="width:210px;max-width:90%;height:auto;display:block"></div>
    <label>Work email</label><input id="email" type="email" value="admin@ametecs.io">
    <label>Password</label><input id="password" type="password" value="password">
    <button class="primary" id="btn-login">Sign in</button>
    <div class="err" id="login-err"></div>
  </div>
</div>

<!-- APP -->
<div class="shell hide" id="app">
  <div class="side">
    <div class="lock" style="justify-content:center;padding-top:2px"><img src="/img/smartept-logo-h-dark.png" alt="SmartEPT by Ametecs" style="width:170px;max-width:94%;height:auto;display:block"></div>
    <div class="navwrap">
    <div class="navgrp">MONITOR</div>
    <div class="nav active" data-view="dashboard"><span class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7.5" height="7.5" rx="1.6"/><rect x="13.5" y="3" width="7.5" height="7.5" rx="1.6"/><rect x="3" y="13.5" width="7.5" height="7.5" rx="1.6"/><rect x="13.5" y="13.5" width="7.5" height="7.5" rx="1.6"/></svg></span> Live Dashboard</div>
    <div class="nav" data-view="attendance"><span class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="8.6"/><path d="M12 7.4V12l3.2 1.9"/></svg></span> Attendance</div>
    <div class="nav" data-view="screenshots"><span class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4.5" width="18" height="13" rx="2"/><path d="M8.5 21h7M12 17.5V21"/><circle cx="9" cy="9.4" r="1.5"/><path d="M21 14.5l-4.2-4.2-5.3 5.2"/></svg></span> Screenshots</div>
    <div class="nav" data-view="usage"><span class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12A9 9 0 1 1 12 3"/><path d="M12 3a9 9 0 0 1 9 9h-9z"/></svg></span> Usage &amp; Compliance</div>
    <div class="nav" data-view="violations"><span class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M10.3 4.1 2.9 17a2 2 0 0 0 1.7 3h14.8a2 2 0 0 0 1.7-3L13.7 4.1a2 2 0 0 0-3.4 0z"/><path d="M12 9.5v4.2M12 16.9h.01"/></svg></span> Violations</div>
    <div class="navgrp">MANAGE</div>
    <div class="nav" data-view="employees"><span class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8.2" r="3.4"/><path d="M2.8 20.2a6.2 6.2 0 0 1 12.4 0"/><circle cx="17.2" cy="9.4" r="2.6"/><path d="M16 15.6a5 5 0 0 1 5.2 4.6"/></svg></span> Employees</div>
    <div class="nav" data-view="org"><span class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18M5 21V7l7-4 7 4v14M9 21v-5h6v5M9 10h.01M15 10h.01M12 10h.01"/></svg></span> Organisation</div>
    <div class="nav" data-view="meetings"><span class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4.5" width="18" height="16" rx="2"/><path d="M3 9h18M8 3v3M16 3v3M8 13h3M8 16.5h6"/></svg></span> Meetings</div>
    <div class="nav" data-view="users"><span class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8.4" r="3.6"/><path d="M4.8 20.4a7.2 7.2 0 0 1 14.4 0"/></svg></span> Users</div>
    <div class="nav" data-view="devices"><span class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4.5" width="18" height="12.5" rx="2"/><path d="M8.5 21h7M12 17v4"/></svg></span> Devices</div>
    <div class="nav" data-view="policies"><span class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l7.5 3v5.2c0 4.8-3.2 8.2-7.5 9.8-4.3-1.6-7.5-5-7.5-9.8V6z"/><path d="M9 11.8l2.1 2.1 3.9-4.2"/></svg></span> Policies</div>
    <div class="nav" data-view="rules"><span class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M4 6h16M4 12h11M4 18h7"/><circle cx="18.5" cy="16.5" r="3"/><path d="M20.6 18.6 23 21"/></svg></span> App &amp; Web Rules</div>
    <div class="nav" data-view="biometric"><span class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M7 4.8A9 9 0 0 1 21 12c0 2.6-.4 5-1.2 7"/><path d="M3.6 8.4A9 9 0 0 0 3 12c0 2.8.6 5.2 1.6 7.2"/><path d="M12 8.4a3.6 3.6 0 0 1 3.6 3.6c0 2.3-.3 4.5-1 6.6"/><path d="M8.4 12a3.6 3.6 0 0 1 .4-1.7M8.6 15.6c.3 1.5.2 3-.2 4.6"/><path d="M12 12v2.4c0 1.7-.2 3.4-.7 5"/></svg></span> Biometric</div>
    <div class="navgrp">INSIGHT</div>
    <div class="nav" data-view="reports"><span class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v11.5M7.5 10l4.5 4.5L16.5 10"/><path d="M4 17v2.4A1.6 1.6 0 0 0 5.6 21h12.8a1.6 1.6 0 0 0 1.6-1.6V17"/></svg></span> Reports &amp; Exports</div>
    <div class="nav" data-view="license"><span class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="12" r="4.6"/><path d="M12.6 12H21M17.5 12v3.4M21 12v2.4"/></svg></span> Licence</div>
    <div class="nav" data-view="integrations"><span class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7 0l2-2a5 5 0 0 0-7-7l-1 1"/><path d="M14 11a5 5 0 0 0-7 0l-2 2a5 5 0 0 0 7 7l1-1"/></svg></span> API &amp; Integrations</div>
    <div class="nav" data-view="ops"><span class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12h4l2.5-6.5 5 13L17 12h4"/></svg></span> Audit &amp; Ops</div>
    <div class="nav" data-view="help"><span class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M9.2 9.3a2.9 2.9 0 0 1 5.6 1c0 1.9-2.8 2.6-2.8 2.6"/><path d="M12 17h.01"/></svg></span> Help &amp; Troubleshooting</div>
    </div>
    <div class="foot"><span id="who"></span><br><a id="signout" style="color:var(--ink-3);cursor:pointer">Sign out</a></div>
  </div>
  <div class="nav-backdrop" id="nav-backdrop"></div>
  <div class="main">
    <div class="top">
      <div class="top-l"><button id="nav-toggle" class="ham" aria-label="Menu" title="Menu">☰</button><div><h2 id="page-title">Live Dashboard</h2><div class="sub" id="page-sub">Real-time workforce status</div></div></div>
      <div class="who"><span id="company-name">Ametecs Pvt Ltd</span><button class="help-i" id="btn-refresh" title="Refresh this screen">⟳</button><button class="help-i" id="btn-help" title="About this screen">ⓘ</button></div>
    </div>

    <!-- 1. DASHBOARD -->
    <div class="view active" id="v-dashboard">
      <div class="org-filter" id="dash-org">
        <span class="of-l">View</span>
        <select id="dof-branch"><option value="">All branches</option></select>
        <select id="dof-dept"><option value="">All departments</option></select>
        <select id="dof-team"><option value="">All teams</option></select>
        <select id="dof-emp"><option value="">All employees</option></select>
        <button class="btn" id="dof-reset" type="button">Reset</button>
        <span class="of-scope" id="dof-scope"></span>
      </div>
      <div class="kpis" id="kpis"></div>
      <div class="dash-charts">
        <div class="card" style="margin-bottom:0">
          <h3>Workforce status <span class="hint">live · share of tracked employees</span></h3>
          <div class="wf-wrap"><div id="wf-donut"></div><div class="wf-leg" id="wf-leg"></div></div>
        </div>
        <div class="card" style="margin-bottom:0">
          <h3>Time utilization — today <span class="hint">where work hours went · top apps &amp; sites · % of tracked time</span></h3>
          <div class="tu-grid" id="tu-grid"><div class="mut" style="font-size:12px">Loading…</div></div>
        </div>
      </div>
      <div class="card">
        <h3>Live productivity — all employees <span class="hint">today · working vs present time · click Reports for the full range</span>
          <span class="row"><input id="dash-prod-q" placeholder="Search employee" autocomplete="off" style="width:170px;font-weight:400;font-size:12px;padding:5px 9px"></span>
        </h3>
        <div style="overflow-x:auto">
        <table><thead><tr><th>Code</th><th>Employee</th><th>Dept</th><th>Present</th><th>Working</th><th>Idle</th><th>Breaks</th><th>Violations</th><th>Productivity</th></tr></thead>
        <tbody id="dash-prod-rows"></tbody></table>
        </div>
      </div>
      <div class="card">
        <h3>Employees — live <span id="live-filter"></span>
          <span class="row">
            <span class="hint">auto-refreshes every 15s · click a KPI card to filter · click a row for detail</span>
            <button class="btn" data-export="productivity">Export productivity CSV</button>
            <button class="btn" data-export="attendance">Export attendance CSV</button>
          </span>
        </h3>
        <table><thead><tr><th>Employee</th><th>Team</th><th>Status</th><th>Active</th><th>Idle</th><th>Last seen</th></tr></thead>
        <tbody id="live-rows"></tbody></table>
      </div>
      <div class="card">
        <h3>Device health <span class="hint">agent heartbeat · sync queue · compliance</span></h3>
        <table><thead><tr><th>Device</th><th>Employee</th><th>Agent ver</th><th>Agent</th><th>Compliance</th><th>Sync queue</th><th>Last heartbeat</th></tr></thead>
        <tbody id="dash-dev-rows"></tbody></table>
      </div>
    </div>

    <!-- 1b. ATTENDANCE -->
    <div class="view" id="v-attendance">
      <div class="filters">
        <label>Date</label><input type="date" id="at-date" style="min-width:0">
        <label>Status</label><select id="at-status">
          <option value="">All statuses</option><option value="PRESENT">PRESENT</option><option value="ABSENT">ABSENT</option>
          <option value="HALF_DAY">HALF_DAY</option><option value="ON_LEAVE">ON_LEAVE</option>
        </select>
        <button class="btn acc" id="at-load">Load</button>
        <button class="btn solid" id="at-add" style="margin-left:auto">+ Add missed day</button>
      </div>
      <div class="card">
        <h3>Attendance sheet <span class="hint">corrections require a reason and are audit-logged — they feed payroll</span></h3>
        <table><thead><tr><th>Employee</th><th>Status</th><th>Check-in</th><th>Check-out</th><th>Late (min)</th><th>Source</th><th>Notes</th><th></th></tr></thead>
        <tbody id="at-rows"></tbody></table>
      </div>
      <div class="card">
        <h3>Holiday calendar
          <span class="row">
            <span class="hint">no late/absent marking on these days · HD in the register</span>
            <select id="hol-year" style="width:auto;min-width:90px;padding:6px 9px"></select>
          </span>
        </h3>
        <table><thead><tr><th>Date</th><th>Name</th><th>Type</th><th></th></tr></thead><tbody id="hol-rows"></tbody></table>
        <div class="row" style="margin-top:12px">
          <input type="date" id="hol-date" style="width:auto">
          <input id="hol-name" placeholder="Holiday name, e.g. Republic Day" style="width:auto;min-width:220px;flex:1">
          <select id="hol-type" style="width:auto"><option value="PUBLIC">PUBLIC</option><option value="COMPANY">COMPANY</option></select>
          <button class="btn solid" id="hol-add">Add holiday</button>
        </div>
        <div class="mut" id="hol-msg"></div>
      </div>
    </div>

    <!-- 2. SCREENSHOTS -->
    <div class="view" id="v-screenshots">
      <div class="filters">
        <label>Employee</label><input id="ss-emp-q" placeholder="Search name / code" autocomplete="off" style="min-width:0;width:160px"><select id="ss-emp" style="min-width:220px"></select>
        <label>Date</label><input type="date" id="ss-date" style="min-width:0">
        <button class="btn acc" id="ss-load">Load</button>
        <span class="tag t-info" style="margin-left:auto">EVERY VIEW IS AUDIT-LOGGED</span>
      </div>
      <div class="card">
        <h3 id="ss-title">Screenshot timeline</h3>
        <div id="ss-grid" class="shots"></div>
        <div id="ss-empty" class="hide"></div>
      </div>
    </div>

    <!-- 3. USAGE & COMPLIANCE -->
    <div class="view" id="v-usage">
      <div class="filters">
        <label>Employee</label><input id="us-emp-q" placeholder="Search name / code" autocomplete="off" style="min-width:0;width:160px"><select id="us-emp"></select>
        <label>Date</label><input type="date" id="us-date" style="min-width:0">
        <button class="btn acc" id="us-load">Load</button>
        <button class="btn" id="us-open-drawer">Open full profile</button>
      </div>
      <div class="card" id="us-summary-card">
        <h3>All employees — usage &amp; compliance <span class="hint">click any row to open that person · click a column to sort</span></h3>
        <table><thead><tr><th>Code</th><th>Employee</th><th>Department</th><th>Team</th><th>App time</th><th>Website time</th><th>Violations</th></tr></thead>
        <tbody id="us-sum-rows"><tr><td colspan="7" class="mut">Loading…</td></tr></tbody></table>
      </div>
      <div class="grid2" id="us-individual" style="display:none">
        <div class="card">
          <h3>Application usage</h3>
          <table><thead><tr><th>App</th><th>Category</th><th>Time</th><th>Status</th></tr></thead><tbody id="us-app-rows"></tbody></table>
        </div>
        <div class="card">
          <h3>Website usage <span class="hint">MVP: site from browser window title</span></h3>
          <table><thead><tr><th>Site</th><th>Category</th><th>Time</th><th>Status</th></tr></thead><tbody id="us-web-rows"></tbody></table>
        </div>
      </div>
      <div class="card" id="us-comp-card" style="margin-top:16px;display:none">
        <h3 id="us-comp-title">Compliance events (selected day)</h3>
        <table><thead><tr><th>Time</th><th>Type</th><th>Severity</th><th>Detected</th><th>Action taken</th></tr></thead><tbody id="us-comp-rows"></tbody></table>
      </div>
    </div>

    <!-- 4. VIOLATIONS -->
    <div class="view" id="v-violations">
      <div class="filters">
        <label>Employee</label><input id="viol-emp-q" placeholder="Search name / code" autocomplete="off" style="min-width:0;width:160px"><select id="viol-emp" style="min-width:200px"><option value="">All employees</option></select>
        <label>Date</label><input type="date" id="viol-date" style="min-width:0">
        <button class="btn acc" id="viol-load">Load</button>
        <button class="btn" id="viol-clear">Clear</button>
      </div>
      <div class="card"><h3>Compliance violations
        <button class="btn" data-export="compliance">Export CSV</button></h3>
        <table><thead><tr><th>Time</th><th>Employee</th><th>Category</th><th>Type</th><th>Severity</th><th>Detected</th><th>Action taken</th><th>Evidence</th></tr></thead>
        <tbody id="viol-rows"></tbody></table>
      </div>
    </div>

    <!-- 5. EMPLOYEES -->
    <div class="view" id="v-employees">
      <div class="filters">
        <input id="emp-q" class="search" placeholder="Search name / code…" style="min-width:220px">
        <button class="btn" id="emp-template" style="margin-left:auto">Download CSV template</button>
        <button class="btn acc" id="emp-import">Bulk import (CSV)</button>
        <button class="btn solid" id="emp-add">+ Add employee</button>
      </div>
      <div class="card"><h3>All employees</h3>
        <table><thead><tr><th>Code</th><th>Name</th><th>Email</th><th>Department</th><th>Team</th><th>Shift</th><th>Status</th><th>Devices</th><th></th></tr></thead>
        <tbody id="emp-rows"></tbody></table>
      </div>
    </div>

    <!-- 5b. USERS -->
    <!-- 5b. ORGANISATION (17-Jul) -->
    <div class="view" id="v-org">
      <div class="card">
        <h3>Attendance source <span class="hint">how this organisation records attendance</span></h3>
        <div class="fbool"><input type="radio" name="attmode" id="attmode-bio" value="BIOMETRIC"> <b>With biometric device</b> <span class="mut" style="font-size:11.5px">— door punches (cloud API / middleware / CSV) merge with agent sessions; Gate-to-PC available</span></div>
        <div class="fbool"><input type="radio" name="attmode" id="attmode-agent" value="AGENT_ONLY"> <b>Without biometric device</b> <span class="mut" style="font-size:11.5px">— attendance comes purely from agent login/logout; the Biometric screen is hidden</span></div>
        <div class="row" style="margin-top:10px"><button class="btn solid" id="attmode-save">Save</button><span class="mut" id="attmode-msg"></span></div>
      </div>
      <div class="card" id="co-tz-card">
        <h3>Company time zone <span class="hint">local day boundary for dashboards &amp; reports · individual branches can override below</span></h3>
        <div class="row"><select id="co-tz" style="max-width:280px"></select><button class="btn solid" id="co-tz-save">Save</button><span class="mut" id="co-tz-msg"></span></div>
      </div>
      <div class="card" id="co-break-card">
        <h3>Break time limits <span class="hint">permitted minutes per break — the agent asks the employee for a reason when a break runs over</span></h3>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;max-width:460px">
          <div><label>Lunch (min)</label><input id="brk-lunch" type="number" min="1" max="600" value="30"></div>
          <div><label>Tea (min)</label><input id="brk-tea" type="number" min="1" max="600" value="10"></div>
          <div><label>Other (min)</label><input id="brk-other" type="number" min="1" max="600" value="10"></div>
        </div>
        <div class="row" style="margin-top:10px"><button class="btn solid" id="brk-save">Save</button><span class="mut" id="brk-msg"></span></div>
      </div>
      <div class="card" id="co-ipx-card">
        <h3>Privacy — raw-IP &amp; local websites <span class="hint">for pages opened by a bare IP address (routers, NAS, internal tools, localhost)</span></h3>
        <div class="fbool"><input type="checkbox" id="co-ipx"> <b>Don't capture raw-IP / local-IP websites</b> <span class="mut" style="font-size:11.5px">— the working-hours time still counts, but it's logged as “Unknown source” with no address, page title or screenshot</span></div>
        <div class="row" style="margin-top:10px"><button class="btn solid" id="co-ipx-save">Save</button><span class="mut" id="co-ipx-msg"></span></div>
        <div class="mut" style="font-size:11px;margin-top:6px">Best-effort from the browser window title until the URL-reporting browser extension ships. Set individual people or teams to “Do Not Track” under the tabs below or on the employee record.</div>
      </div>
      <div class="tabs" id="org-tabs">
        <div class="tab active" data-org="branches">Branches</div>
        <div class="tab" data-org="departments">Departments</div>
        <div class="tab" data-org="teams">Teams</div>
        <div class="tab" data-org="designations">Designations</div>
        <div class="tab" data-org="shifts">Shifts</div>
        <div class="tab" data-org="roles">Roles</div>
      </div>
      <div class="card" id="org-main-card">
        <h3 id="org-title">Branches <span class="row"><button class="btn solid" id="org-add">+ Add</button></span></h3>
        <table><thead id="org-head"></thead><tbody id="org-rows"></tbody></table>
      </div>
      <div class="card hide" id="roles-card">
        <h3>Organisation roles <span class="row"><button class="btn solid" id="role-add">+ Add role</button></span></h3>
        <table><thead><tr><th>Role</th><th>Type</th><th>Based on</th><th>Users</th><th>Modules</th><th></th></tr></thead><tbody id="role-rows"></tbody></table>
        <div class="mut" style="font-size:11.5px;margin-top:8px">System roles are built in — their permission matrix can be tuned (Super/Company Admin always keep full access so you can never lock yourself out). Custom roles inherit screen access from the role they are based on; the matrix then decides which modules their users see.</div>
      </div>
    </div>

    <div class="view" id="v-users">
      <div class="filters">
        <input id="u-q" placeholder="Search name / email…" style="min-width:220px">
        <button class="btn solid" id="u-add" style="margin-left:auto">+ Add user</button>
      </div>
      <div class="card"><h3>Login accounts <span class="hint">console &amp; self-service logins — separate from the employee directory</span></h3>
        <table><thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Linked employee</th><th>Status</th><th>Last login</th><th></th></tr></thead>
        <tbody id="u-rows"></tbody></table>
      </div>
    </div>

    <!-- 6. DEVICES -->
    <div class="view" id="v-devices">
      <div class="card" style="border:1px solid var(--accent);background:linear-gradient(120deg,var(--accent-weak),var(--card))">
        <h3 style="color:var(--accent-ink)">🔒 Agent exit &amp; uninstall lock <span class="hint">stop employees quitting or removing the SmartEPT agent</span></h3>
        <div class="fbool"><input type="checkbox" id="al-enabled"> <b>Require this password before the desktop agent can be quit, stopped, or uninstalled</b></div>
        <div class="row" style="margin-top:10px;align-items:flex-end">
          <div><label>Password</label><input id="al-pass" type="password" autocomplete="new-password" placeholder="Enter a password" style="min-width:240px"></div>
          <button class="btn solid" id="al-save">Save lock password</button>
          <button class="btn" id="al-clear" type="button">Clear password</button>
          <span class="mut" id="al-msg"></span>
        </div>
        <div class="mut" style="font-size:11.5px;margin-top:8px">The agent receives only a one-way hash of this password — the plaintext never leaves this server. Share it only with IT/admins allowed to service a machine. Leave the field blank when saving to keep the current password; use <b>Clear password</b> to remove it and turn the lock off. Agents apply it on their next policy sync (~30s). <b>Note:</b> the installed agent build must support the lock for it to take effect.</div>
      </div>
      <div class="card"><h3>Registered devices &amp; agent health <input id="dev-q" placeholder="Search device / employee / OS" autocomplete="off" style="width:230px;font-weight:400;font-size:12px;margin-left:10px"></h3>
        <table><thead><tr><th>Device</th><th>Employee</th><th>OS</th><th>Agent ver</th><th>Agent health</th><th>Compliance</th><th>Status</th><th>Sync queue</th><th>Last heartbeat</th><th></th></tr></thead>
        <tbody id="dev-rows"></tbody></table>
      </div>
    </div>

    <!-- 7. POLICIES -->
    <div class="view" id="v-policies">
      <div class="filters">
        <label>Policy type</label><select id="pol-type"></select>
        <button class="btn solid" id="pol-new" style="margin-left:auto">+ New policy</button>
      </div>
      <div class="grid2">
        <div>
          <div class="card">
            <h3 id="pol-list-title">Policies <span class="hint">editing bumps the version — agents pick it up on next heartbeat</span></h3>
            <table><thead><tr><th>Name</th><th>Version</th><th>Updated</th><th></th></tr></thead><tbody id="pol-rows"></tbody></table>
          </div>
          <div class="never">
            <b>⛔ NEVER captured — locked at product level (cannot be switched on by anyone)</b>
            <ul>
              <li>Keystrokes, passwords, clipboard contents</li>
              <li>Personal files, documents, emails content</li>
              <li>Banking / UPI / payment apps &amp; sites (auto-excluded from screenshots and usage)</li>
              <li>Anything outside duty hours — tracking hard-stops at Duty End / shift end</li>
              <li>Webcam video or audio recording — presence is a yes/no signal only</li>
            </ul>
          </div>
        </div>
        <div>
          <div class="card" id="pol-form-card">
            <h3 id="pol-form-title">Create policy</h3>
            <div id="pol-form" class="fgrid"></div>
            <div class="fgrid" style="margin-top:10px;border-top:1px solid var(--line);padding-top:12px">
              <div><label>Apply to (optional)</label><select id="pol-scope-type">
                <option value="">— Save only (assign below) —</option>
                <option value="COMPANY">Company</option><option value="BRANCH">Branch</option>
                <option value="DEPARTMENT">Department</option><option value="TEAM">Team</option>
                <option value="EMPLOYEE">Employee</option><option value="DEVICE">Device</option>
              </select></div>
              <div><label>Target</label><div style="display:flex;gap:8px"><select id="pol-scope-target" style="flex:1"></select><button class="btn" id="pol-scope-add" type="button" style="display:none">+ New</button></div></div>
            </div>
            <div class="row" style="margin-top:14px;justify-content:flex-end">
              <button class="btn" id="pol-cancel">Reset</button>
              <button class="btn solid" id="pol-save">Save policy</button>
            </div>
            <div class="err" id="pol-err"></div>
          </div>
          <div class="card" style="margin-top:16px">
            <h3>Assign a policy <span class="hint">precedence: device › employee › team › dept › branch › company</span></h3>
            <div class="fgrid">
              <div><label>Policy</label><select id="as-policy"></select></div>
              <div><label>Assign to (level)</label><select id="as-type">
                <option value="COMPANY">Company</option><option value="BRANCH">Branch</option>
                <option value="DEPARTMENT">Department</option><option value="TEAM">Team</option>
                <option value="EMPLOYEE">Employee</option><option value="DEVICE">Device</option>
              </select></div>
              <div class="full"><label>Target</label><div style="display:flex;gap:8px"><select id="as-target" style="flex:1"></select><button class="btn" id="as-target-add" type="button" style="display:none">+ New</button></div></div>
              <div><label>Effective from (optional)</label><input type="date" id="as-from"></div>
              <div><label>Effective to (optional)</label><input type="date" id="as-to"></div>
            </div>
            <div class="row" style="margin-top:14px;justify-content:flex-end">
              <button class="btn solid" id="as-save">Assign</button>
            </div>
            <div class="mut" id="as-log"></div>
          </div>
        </div>
      </div>
    </div>

    <!-- 6b. APP & WEB RULES -->
    <div class="view" id="v-rules">
      <div class="filters">
        <input id="rule-add-item" placeholder="app.exe  or  website.com" style="min-width:200px" autocomplete="off">
        <select id="rule-add-type"><option value="app">Application</option><option value="site">Website</option></select>
        <select id="rule-add-status"><option value="TRACKED">Tracked</option><option value="ALLOWED">Allowed</option><option value="BLOCKED">Blocked</option><option value="VIOLATION">Violation</option></select>
        <button class="btn solid" id="rule-add-btn" type="button">+ Add</button>
        <span class="row" style="margin-left:auto">
          <select id="rule-action" title="What the agent does when a Blocked/Violation item is opened"><option value="WARN">On block: warn employee</option><option value="SCREENSHOT">On block: warn + screenshot</option><option value="NOTIFY">On block: notify manager</option><option value="CLOSE">On block: close the app</option></select>
          <button class="btn" id="rule-seed" type="button">Load common defaults</button>
          <button class="btn solid" id="rule-save" type="button">Save rules</button>
        </span>
      </div>
      <div class="card">
        <h3>Apps &amp; Websites Rules <span class="hint">what the agent tracks, allows, blocks or flags as a violation \u00b7 applies company-wide</span>
          <input id="rule-q" placeholder="Search item" autocomplete="off" style="width:170px;font-weight:400;font-size:12px;margin-left:auto">
        </h3>
        <div style="overflow-x:auto"><table><thead><tr><th>Item</th><th>Type</th><th>Status</th><th></th></tr></thead><tbody id="rule-rows"></tbody></table></div>
        <div class="mut" style="margin-top:10px;font-size:11.5px"><b>Allowed</b> = whitelisted/productive \u00b7 <b>Tracked</b> = monitored only \u00b7 <b>Blocked</b> = employee warned + logged as a violation \u00b7 <b>Violation</b> = blocked and flagged for review. Agents pick up changes on their next heartbeat (~30s).</div>
        <div class="mut" id="rule-msg" style="margin-top:8px"></div>
      </div>
    </div>

    <!-- 7b. MEETINGS (Section 2) -->
    <div class="view" id="v-meetings">
      <div class="filters">
        <select id="mtg-filter-status">
          <option value="">All statuses</option>
          <option value="SCHEDULED">Scheduled</option>
          <option value="IN_PROGRESS">In progress</option>
          <option value="COMPLETED">Completed</option>
          <option value="CANCELLED">Cancelled</option>
        </select>
        <input id="mtg-from" type="date" title="From date">
        <input id="mtg-to" type="date" title="To date">
        <button class="btn" id="mtg-reload">Apply</button>
        <button class="btn solid" id="mtg-new" style="margin-left:auto">+ Schedule meeting</button>
      </div>
      <div class="card"><h3>Meetings</h3>
        <table><thead><tr><th>Title</th><th>Date</th><th>Time</th><th>Participants</th><th>Status</th><th></th></tr></thead>
        <tbody id="mtg-rows"></tbody></table>
      </div>
    </div>

    <!-- 8. BIOMETRIC -->
    <div class="view" id="v-biometric">
      <div class="card" style="border:1px solid var(--accent);background:linear-gradient(120deg,var(--accent-weak),var(--card))">
        <h3 style="color:var(--accent-ink)">🚪 Gate-to-PC <span class="hint">the SmartEPT USP — the PC agent only starts after a real door punch</span></h3>
        <div class="fbool"><input type="checkbox" id="gate-enabled"> <b>Require a door / biometric IN punch before the desktop agent can start a work session</b></div>
        <div class="row" style="margin-top:10px;align-items:flex-end">
          <div><label>Gate mode</label><select id="gate-mode" style="min-width:280px">
            <option value="auto">Auto — ON when a punch device is registered (or box above ticked)</option>
            <option value="on">Always ON (force)</option>
            <option value="off">OFF (pilot / observe only)</option>
          </select></div>
          <div><label>Grace window (minutes)</label><input id="gate-grace" type="number" min="0" style="width:120px" placeholder="0"></div>
          <button class="btn solid" id="gate-save">Save gate policy</button>
          <span class="mut" id="gate-msg"></span>
        </div>
        <div class="mut" style="font-size:11.5px;margin-top:8px">When ON: an employee who opens the agent sees a "Punch in at the door" wall — tracking and the work clock start the instant their gate IN punch reaches SmartEPT (physical device or an IN punch pushed via the API). No punch = no login. The grace window lets a punch that lands a few minutes late still open the gate. Mid-day OUT punch = automatic out-of-office break + soft lock; the return punch closes it to the second (under 2 min merges away, over 45 min flagged, over 3 hours HR is emailed). OFF mode never gates anyone, whatever else is set.</div>
      </div>
      <div class="filters">
        <label>Date</label><input type="date" id="bio-date" style="min-width:0">
        <button class="btn acc" id="bio-load">Load</button>
      </div>
      <div class="card">
        <h3>Biometric Device Setup <span class="hint">connect a cloud attendance API — punches sync continuously (every 5 minutes) into Attendance, payroll and the Biometric Gate</span></h3>
        <table><thead><tr><th>Provider</th><th>API</th><th>Auto sync</th><th>Status</th><th>Last sync</th><th>Last result</th><th>Punches</th><th></th></tr></thead><tbody id="biodev-rows"></tbody></table>
        <div style="margin-top:14px;max-width:560px">
          <label>Automatic sync <span class="hint">runs in the background via the scheduler — no need to open this tab and click Sync</span></label>
          <select id="bd-mode">
            <option value="INTERVAL">Automatic — every N minutes</option>
            <option value="SCHEDULED">Automatic — at set daily times</option>
            <option value="MANUAL">Manual only — sync when I click Sync</option>
          </select>
          <div class="grid2">
            <div><label>Interval (minutes)</label><input id="bd-interval" type="number" min="1" max="1440" value="5"></div>
            <div><label>Scheduled times <span class="mut" style="font-weight:400">(24h, comma-separated)</span></label><input id="bd-times" placeholder="09:00, 13:30"></div>
          </div>
          <label>Provider</label><input id="bd-provider" placeholder="etimeoffice">
          <div class="mut" style="font-size:11.5px;margin:2px 0 8px">Cloud attendance provider name.</div>
          <label>API base URL</label><input id="bd-base" placeholder="https://api.etimeoffice.com/api">
          <label>Endpoint</label><input id="bd-endpoint" placeholder="DownloadPunchDataMCID">
          <label>Corporate ID</label><input id="bd-corp" placeholder="Your eTimeOffice corporate ID">
          <label>Username</label><input id="bd-user" placeholder="API username">
          <label>Password</label><input id="bd-pass" type="password" autocomplete="new-password" placeholder="••••••••">
          <label>Employee code filter</label><input id="bd-filter" placeholder="ALL">
          <div class="mut" style="font-size:11.5px;margin:2px 0 8px">Usually ALL.</div>
          <label>Employee ID prefix</label><input id="bd-prefix" placeholder="e.g. A">
          <div class="mut" style="font-size:11.5px;margin:2px 0 8px">If the device returns 12345 and your employees are A12345, enter A. Leave blank when codes match exactly.</div>
          <div class="grid2">
            <div><label>IN machine ID</label><input id="bd-inmc" placeholder="e.g. 1"><div class="mut" style="font-size:11.5px;margin:2px 0 8px">Machine number of the ENTRY device — its punches are marked IN.</div></div>
            <div><label>OUT machine ID</label><input id="bd-outmc" placeholder="e.g. 2"><div class="mut" style="font-size:11.5px;margin:2px 0 8px">Machine number of the EXIT device — its punches are marked OUT.</div></div>
          </div>
          <div class="mut" style="font-size:11.5px;margin:2px 0 8px">Use these when separate devices handle entry and exit: the machine number decides the punch direction, overriding the feed's IN/OUT flag. Leave BOTH blank if one device reports direction itself. Run Test connection to see each punch's MC number.</div>
          <div class="row" style="margin-top:12px">
            <button class="btn solid" id="bd-save">Save</button>
            <button class="btn acc" id="bd-test">✓ Test connection</button>
            <button class="btn" id="bd-syncnow">⟳ Sync now</button>
            <button class="btn" id="bd-reset">Clear</button>
          </div>
          <div class="mut" id="bd-msg"></div>
          <div id="bd-test-out" style="margin-top:8px"></div>
        </div>
      </div>
      <div class="card">
        <h3>Punch log <span class="hint">from cloud sync, device middleware push or CSV import</span></h3>
        <table><thead><tr><th>Time</th><th>Employee</th><th>Bio ID</th><th>Punch</th><th>Mode</th></tr></thead><tbody id="bio-rows"></tbody></table>
      </div>
      <div class="card">
        <h3>Biometric vs system login — mismatch report</h3>
        <table><thead><tr><th>Employee</th><th>Biometric IN</th><th>Agent login</th><th>Gap (min)</th><th>Status</th></tr></thead><tbody id="bio-mm-rows"></tbody></table>
      </div>
      <div class="grid2">
        <div class="card">
          <h3>Import punches (CSV)</h3>
          <div class="mut" style="padding-top:0">Columns: <b>biometric_employee_id, punch_type, punched_at</b> (header row required).</div>
          <input type="file" id="bio-file" accept=".csv,.txt" style="padding:8px">
          <div class="row" style="margin-top:12px;justify-content:flex-end"><button class="btn solid" id="bio-import">Import</button></div>
          <div class="mut" id="bio-import-msg"></div>
        </div>
        <div class="card">
          <h3>Map biometric ID → employee <span class="hint">unmapped punches feed no one until the device ID is linked to a person</span></h3>
          <label>Unmapped biometric IDs <span class="mut" style="font-weight:400">(seen in punches, not yet linked)</span></label>
          <select id="bio-unmapped"><option value="">— pick an unmapped ID —</option></select>
          <label>Biometric employee ID (as on the device)</label><input id="bio-map-id" placeholder="e.g. 1043">
          <label>SmartEPT employee</label><select id="bio-map-emp"></select>
          <div class="row" style="margin-top:12px;justify-content:flex-end"><button class="btn solid" id="bio-map-save">Map</button></div>
          <div class="mut" id="bio-map-msg"></div>
          <h3 style="margin-top:18px">Current mappings</h3>
          <table><thead><tr><th>Biometric ID</th><th>Employee</th><th></th></tr></thead><tbody id="bio-map-rows"></tbody></table>
        </div>
      </div>
    </div>

    <!-- 9. REPORTS & EXPORTS -->
    <div class="view" id="v-reports">
      <div class="card">
        <h3>Live productivity — all employees <span class="hint">day-wise · today updates live · click a column to sort</span></h3>
        <div class="filters" style="border:none;box-shadow:none;padding:0;background:none;margin-bottom:12px">
          <label>From</label><input type="date" id="pr-from" style="min-width:0">
          <label>To</label><input type="date" id="pr-to" style="min-width:0">
          <button class="btn" id="pr-today">Today</button>
          <button class="btn" id="pr-week">This week</button>
          <button class="btn" id="pr-month">This month</button>
          <button class="btn acc" id="pr-load">Show</button>
          <input id="pr-q" placeholder="Search employee" autocomplete="off" style="min-width:0;width:160px">
          <span style="flex:1"></span>
          <button class="btn" id="pr-csv">⇓ CSV</button>
          <button class="btn solid" id="pr-pdf">⇓ PDF</button>
        </div>
        <div style="overflow-x:auto">
        <table id="pr-table"><thead><tr>
          <th>Date</th><th>Code</th><th>Employee</th><th>Dept</th>
          <th>Logged in</th><th>Logged out</th><th>Present</th><th>Working</th><th>Idle</th>
          <th>Breaks</th><th>Break time</th><th>Meeting</th><th>Time-outs</th><th>Non-prod.</th><th>Violations</th><th>Prod. %</th>
        </tr></thead><tbody id="pr-rows"><tr><td colspan="16" class="mut">Pick a range and press Show.</td></tr></tbody></table>
        </div>
        <div class="mut" id="pr-note" style="margin-top:8px"></div>
      </div>

      <!-- Section 3 & 14: Break report -->
      <div class="card">
        <h3>Break report <span class="hint">permitted vs actual, excess &amp; the employee's reason · Meeting is never a break</span></h3>
        <div class="filters" style="border:none;box-shadow:none;padding:0;background:none;margin-bottom:12px">
          <label>From</label><input type="date" id="br-from" style="min-width:0">
          <label>To</label><input type="date" id="br-to" style="min-width:0">
          <select id="br-type"><option value="">All break types</option><option value="LUNCH">Lunch</option><option value="TEA">Tea</option><option value="CUSTOM">Other</option></select>
          <label style="display:flex;align-items:center;gap:6px;font-size:12px"><input type="checkbox" id="br-exceeded" style="width:auto;margin:0"> Exceeded only</label>
          <button class="btn acc" id="br-load">Show</button>
          <span style="flex:1"></span>
          <button class="btn" id="br-csv">⇓ CSV</button>
        </div>
        <div style="overflow-x:auto">
        <table><thead><tr><th>Date</th><th>Employee</th><th>Type</th><th>Start</th><th>End</th><th>Permitted</th><th>Actual</th><th>Excess</th><th>Reason</th><th>Review</th></tr></thead>
        <tbody id="br-rows"><tr><td colspan="10" class="mut">Pick a range and press Show.</td></tr></tbody></table>
        </div>
      </div>

      <!-- Section 14: Meeting report -->
      <div class="card">
        <h3>Meeting report <span class="hint">scheduled vs actual attendance · meeting time is productive</span></h3>
        <div class="filters" style="border:none;box-shadow:none;padding:0;background:none;margin-bottom:12px">
          <label>From</label><input type="date" id="mr-from" style="min-width:0">
          <label>To</label><input type="date" id="mr-to" style="min-width:0">
          <button class="btn acc" id="mr-load">Show</button>
        </div>
        <div style="overflow-x:auto">
        <table><thead><tr><th>Meeting</th><th>Date</th><th>Status</th><th>Participants</th><th>Attended</th><th>Scheduled</th><th>Actual total</th></tr></thead>
        <tbody id="mr-rows"><tr><td colspan="7" class="mut">Pick a range and press Show.</td></tr></tbody></table>
        </div>
      </div>

      <div class="exp-grid">
        <div class="exp"><b>Attendance report</b>
          <p>Punch in/out, agent login, late marks, source — day-wise per employee. CSV opens directly in Excel.</p>
          <div class="row"><input type="date" id="rp-att-from"><input type="date" id="rp-att-to"></div>
          <div class="row" style="margin-top:10px"><button class="btn acc" id="rp-att">⇓ Export CSV</button></div>
        </div>
        <div class="exp"><b>Productivity report</b>
          <p>Active vs idle vs break hours per employee over a date range — the raw material for productivity %.</p>
          <div class="row"><input type="date" id="rp-prod-from"><input type="date" id="rp-prod-to"></div>
          <div class="row" style="margin-top:10px"><button class="btn acc" id="rp-prod">⇓ Export CSV</button></div>
        </div>
        <div class="exp"><b>Compliance report</b>
          <p>Violations by employee &amp; type, severity, detected value and the action the agent took.</p>
          <div class="row"><input type="date" id="rp-comp-from"><input type="date" id="rp-comp-to"></div>
          <div class="row" style="margin-top:10px"><button class="btn acc" id="rp-comp">⇓ Export CSV</button></div>
        </div>
        <div class="exp"><b>Daily summary (scoring)</b>
          <p>Nightly per-employee rollup over a date range: hours, violations, productivity &amp; compliance scores (0–100).</p>
          <div class="row"><input type="date" id="rp-sum-from"><input type="date" id="rp-sum-to"></div>
          <div class="row" style="margin-top:10px"><button class="btn acc" id="rp-sum">⇓ Export CSV</button></div>
        </div>
        <div class="exp"><b>Attendance register (monthly)</b>
          <p>The classic month matrix — one row per employee, one letter per day (P/A/H/L, WO weekly off, HD holiday) with payable-day totals.</p>
          <div class="row"><input type="month" id="rp-reg-month"></div>
          <div class="row" style="margin-top:10px"><button class="btn acc" id="rp-reg">⇓ Export CSV</button></div>
        </div>
        <div class="exp"><b>Monthly summary</b>
          <p>Per-employee month rollup: working days, P/A/H/L counts, payable days and average productivity — rendered right here.</p>
          <div class="row"><input type="month" id="rp-ms-month"></div>
          <div class="row" style="margin-top:10px"><button class="btn acc" id="rp-ms">View summary</button></div>
        </div>
      </div>
      <div class="mut" id="rp-msg"></div>
      <div class="card hide" id="ms-card">
        <h3 id="ms-title">Monthly summary <span class="hint">payable days = present + 0.5 × half-day + paid leave</span></h3>
        <table><thead><tr><th>Code</th><th>Employee</th><th>Working days</th><th>P</th><th>A</th><th>H</th><th>L</th><th>Payable days</th><th>Avg productivity</th></tr></thead>
        <tbody id="ms-rows"></tbody></table>
      </div>
    </div>

    <!-- 12. LICENCE (R2-1) -->
    <div class="view" id="v-license">
      <div class="card"><h3>Licence status <span class="hint">this server validates with SmartEPT Central once a day — licence metadata only, never monitoring data</span></h3>
        <div id="lic-status" class="mut">Loading…</div>
      </div>
      <div class="card"><h3>Licence key</h3>
        <div class="filters">
          <input id="lic-key" placeholder="SEPT-XXXX-XXXX-XXXX-XXXX" style="min-width:300px;text-transform:uppercase">
          <button class="btn solid" id="lic-save">Save &amp; validate</button>
          <button class="btn" id="lic-check">Validate now</button>
        </div>
        <div class="mut" id="lic-msg">Paste the key from your SmartEPT order email or the client portal (Billing &amp; Licences). Without a key the server runs a 7-day free evaluation, then monitoring stops until a key is entered.</div>
      </div>
      <div class="card"><h3>Offline licence file <span class="hint">no internet needed — a signed file locked to this PC</span></h3>
        <p class="mut" style="margin-bottom:10px">Send this machine's fingerprint to Ametecs. They return a <code>license.lic</code> file locked to this PC — import it below to activate. Works fully offline (no SMARTEPT_LICENSE_URL, no SSL).</p>
        <label>This machine's fingerprint</label>
        <div class="row" style="margin:4px 0 14px">
          <input id="lic-fp" readonly style="min-width:340px;font-family:ui-monospace,Menlo,Consolas,monospace" placeholder="—">
          <button class="btn" id="lic-fp-copy">Copy</button>
        </div>
        <label>Import licence file (.lic)</label>
        <div class="row" style="margin-top:4px">
          <input type="file" id="lic-file" accept=".lic,.txt">
          <button class="btn solid" id="lic-import">Import &amp; activate</button>
        </div>
        <div class="mut" id="lic-file-msg" style="margin-top:8px"></div>
      </div>
    </div>

    <!-- 13. AUDIT & OPS (R2-4) -->
    <!-- API & INTEGRATIONS (17-Jul) -->
    <div class="view" id="v-integrations">
      <div class="grid2">
        <div class="card">
          <h3>API keys <span class="row"><span class="hint">for external devices/apps to push in &amp; read out</span><button class="btn solid" id="key-add">+ New key</button></span></h3>
          <table><thead><tr><th>Name</th><th>Key</th><th>Scopes</th><th>Last used</th><th>Status</th><th></th></tr></thead>
          <tbody id="key-rows"><tr><td colspan="6" class="mut">Loading…</td></tr></tbody></table>
        </div>
        <div class="card">
          <h3>Outbound targets <span class="row"><span class="hint">SmartEPT pushes attendance here (SmartPRS etc.)</span><button class="btn solid" id="tgt-add">+ Add target</button></span></h3>
          <table><thead><tr><th>Name</th><th>URL</th><th>Last push</th><th>Status</th><th></th></tr></thead>
          <tbody id="tgt-rows"><tr><td colspan="5" class="mut">Loading…</td></tr></tbody></table>
        </div>
      </div>
      <div class="card"><h3>Integration guide <span class="hint">base URL, auth, formats — copy-paste ready</span></h3>
        <div id="api-docs" style="font-size:12.5px;line-height:1.7"></div>
      </div>
    </div>

    <div class="view" id="v-ops">
      <div class="exp-grid">
        <div class="exp"><b>Storage usage</b>
          <p>Screenshot &amp; webcam evidence on disk, per company — watch this before it surprises you.</p>
          <div id="ops-storage" class="mut">…</div>
          <div class="row" style="margin-top:10px"><button class="btn danger" id="ops-cleanup">Free up storage…</button></div>
        </div>
        <div class="exp"><b>Database backups</b>
          <p>Nightly at 01:30, newest 14 kept in <code>storage/app/backups</code>. You can also run one right now.</p>
          <div id="ops-backups" class="mut">…</div>
          <div class="row" style="margin-top:10px"><button class="btn acc" id="ops-backup-now">Back up now</button><span class="mut" id="ops-backup-msg"></span></div>
        </div>
      </div>
      <div class="card">
        <h3>Automatic cleanup schedule <span class="hint">runs nightly at 02:00 — frees disk by deleting data past each window</span></h3>
        <div class="fbool" style="margin-bottom:10px"><input type="checkbox" id="rt-enabled"> Automatic nightly cleanup is ON for this company</div>
        <div class="fgrid" style="grid-template-columns:1fr 1fr 1fr">
          <div><label>Screenshots &amp; webcam — keep (days)</label><input id="rt-shots" type="number" min="1" placeholder="e.g. 30"></div>
          <div><label>Activity / breaks / presence — keep (days)</label><input id="rt-activity" type="number" min="1" placeholder="e.g. 90"></div>
          <div><label>App / website usage — keep (days)</label><input id="rt-usage" type="number" min="1" placeholder="e.g. 90"></div>
          <div><label>Violation evidence — keep (days)</label><input id="rt-viol" type="number" min="1" placeholder="e.g. 365"></div>
          <div><label>Fallback for anything else (days)</label><input id="rt-base" type="number" min="1" placeholder="90"></div>
          <div style="display:flex;align-items:flex-end"><label class="fbool" style="padding:0"><input type="checkbox" id="rt-keepviol"> Protect violation screenshots from routine cleanup</label></div>
        </div>
        <div class="row" style="margin-top:12px">
          <button class="btn solid" id="rt-save">Save schedule</button>
          <button class="btn" id="rt-preview">Preview what would be deleted (dry run)</button>
          <span class="mut" id="rt-msg"></span>
        </div>
        <div id="rt-out" style="margin-top:10px;font-size:11.5px;max-height:180px;overflow:auto"></div>
      </div>
      <div class="card">
        <h3>Local / On-premise storage <span class="hint">keep screenshots &amp; evidence on this server, a LAN share or NAS — client data stays with the client</span></h3>
        <div id="loc-status" class="mut" style="margin-bottom:10px"></div>
        <label>Storage folder</label>
        <input id="loc-path" autocomplete="off" placeholder="D:\\SmartEPT\\evidence   or   \\\\NAS\\smartept   (blank = default app storage)">
        <div class="mut" style="font-size:11.5px;margin-top:6px">Same server: an absolute folder (e.g. <code>D:\\SmartEPT\\evidence</code>). Local network: a UNC share the server can reach (e.g. <code>\\\\NAS\\smartept</code>) — the Windows service account needs write access. Blank = the app default storage. Used automatically whenever Cloud Storage below is off.</div>
        <div class="row" style="margin-top:12px">
          <button class="btn" id="loc-test">Test folder</button>
          <button class="btn solid" id="loc-save">Save</button>
          <span class="mut" id="loc-msg"></span>
        </div>
      </div>
      <div class="card">
        <h3>Cloud Storage (Google Cloud) <span class="hint">keep screenshots &amp; evidence in your own GCS bucket — no server setup</span></h3>
        <div id="gcs-status" class="mut" style="margin-bottom:10px">…</div>
        <div class="fgrid" style="grid-template-columns:1fr 1fr">
          <div><label>Bucket name</label><input id="gcs-bucket" placeholder="e.g. smartept-evidence"></div>
          <div><label>GCP project ID (optional)</label><input id="gcs-project" placeholder="e.g. my-project-123456"></div>
        </div>
        <label style="margin-top:10px;display:block">Service-account key (JSON) <span class="hint">paste the whole file from Google Cloud → IAM → Service Accounts → Keys</span></label>
        <textarea id="gcs-key" rows="5" placeholder='{ "type": "service_account", "project_id": "…", "private_key": "…" }' style="width:100%;font-family:monospace;font-size:11.5px"></textarea>
        <div class="fbool" style="margin-top:10px"><input type="checkbox" id="gcs-enabled"> Store new screenshots &amp; evidence in this bucket</div>
        <div class="row" style="margin-top:12px">
          <button class="btn" id="gcs-test">Test connection</button>
          <button class="btn solid" id="gcs-save">Save</button>
          <span class="mut" id="gcs-msg"></span>
        </div>
      </div>
      <div class="card"><h3>Audit trail <span class="hint">every admin action, export and screenshot view — accountable and searchable</span></h3>
        <div class="filters">
          <input id="au-action" placeholder="Action contains… e.g. DELETE, EXPORT, LOGIN" style="min-width:240px">
          <input type="date" id="au-from"><input type="date" id="au-to">
          <button class="btn" id="au-go">Search</button>
        </div>
        <table><thead><tr><th>When</th><th>User</th><th>Action</th><th>Subject</th><th>Details</th><th>IP</th></tr></thead>
        <tbody id="au-rows"></tbody></table>
      </div>
    </div>

    <!-- HELP & TROUBLESHOOTING (Ametecs troubleshooting-in-app standard) -->
    <div class="view" id="v-help">
      <style>
        #v-help .dg-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
        @media(max-width:820px){#v-help .dg-grid{grid-template-columns:1fr}}
        #v-help .dg-item{display:flex;gap:11px;align-items:flex-start;padding:12px 13px;border:1px solid var(--border);border-radius:11px;background:var(--card)}
        #v-help .dg-item .dot{width:11px;height:11px;border-radius:50%;flex:none;margin-top:4px}
        #v-help .dg-ok .dot{background:var(--ok)}#v-help .dg-warn .dot{background:var(--warn)}#v-help .dg-down .dot{background:var(--danger)}
        #v-help .dg-item.dg-down{background:var(--danger-w);border-color:transparent}
        #v-help .dg-item.dg-warn{background:var(--warn-w);border-color:transparent}
        #v-help .dg-l b{font-size:12.5px;color:var(--ink)}
        #v-help .dg-l p{margin:3px 0 0;font-size:11.5px;color:var(--ink-2);line-height:1.5}
        #v-help .dg-l a{color:var(--accent);font-weight:700;cursor:pointer;font-size:11px;white-space:nowrap}
        #v-help details.kb{border:1px solid var(--border);border-radius:11px;margin-bottom:9px;background:var(--card);overflow:hidden}
        #v-help details.kb[open]{box-shadow:var(--shadow-1)}
        #v-help details.kb>summary{cursor:pointer;padding:13px 15px;font-weight:700;font-size:12.5px;color:var(--ink);list-style:none;display:flex;align-items:center;gap:10px}
        #v-help details.kb>summary::-webkit-details-marker{display:none}
        #v-help details.kb>summary::after{content:'\25be';margin-left:auto;color:var(--ink-3);transition:transform .15s}
        #v-help details.kb[open]>summary::after{transform:rotate(180deg)}
        #v-help .kb-tag{font-size:10px;font-weight:700;padding:3px 9px;border-radius:10px;white-space:nowrap}
        #v-help .kb-body{padding:2px 16px 15px;font-size:12px;line-height:1.6;color:var(--ink-2)}
        #v-help .kb-body p{margin:7px 0}#v-help .kb-body b{color:var(--ink)}
        #v-help .kb-body ol,#v-help .kb-body ul{margin:6px 0 6px 18px;padding:0}
        #v-help .kb-body li{margin:3px 0}
        #v-help .kb-body code{background:var(--card-2);padding:1.5px 6px;border-radius:5px;font-size:11px;font-family:ui-monospace,Menlo,Consolas,monospace}
        #v-help .kb-esc{background:var(--accent-weak);border-radius:8px;padding:8px 11px;margin-top:10px!important;color:var(--accent-ink)}
        #v-help .kb-flash{outline:2px solid var(--accent);outline-offset:1px}
        #v-help .logbox{background:#0E1726;color:#D6E2F0;border-radius:11px;padding:14px;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:11px;line-height:1.55;max-height:440px;overflow:auto;white-space:pre-wrap;word-break:break-word;margin:0}
        #v-help .htabs{display:flex;gap:4px;margin-bottom:16px;border-bottom:1px solid var(--border)}
        #v-help .htab{appearance:none;border:0;background:none;cursor:pointer;padding:9px 16px;font-size:12.5px;font-weight:700;color:var(--ink-3);border-bottom:2px solid transparent;margin-bottom:-1px}
        #v-help .htab.on{color:var(--accent);border-bottom-color:var(--accent)}
        #v-help .htab:hover{color:var(--ink)}
        #v-help .htab-panel{display:none}
        #v-help .htab-panel.on{display:block}
      </style>

      <div class="htabs">
        <button class="htab on" data-ht="health" onclick="helpTab('health')">System Health</button>
        <button class="htab" data-ht="fix" onclick="helpTab('fix')">Fix a problem</button>
        <button class="htab" data-ht="log" onclick="helpTab('log')">Application log</button>
      </div>

      <div class="card htab-panel on" data-ht="health">
        <h3>System Health <span class="hint">one click checks the database, storage, agents, email &amp; more — green is good, amber needs attention, red needs a fix now</span></h3>
        <div class="row" style="margin-bottom:12px">
          <button class="btn solid" id="dg-run">Run checks</button>
          <span class="tag" id="dg-overall" style="display:none"></span>
          <span class="mut" id="dg-when"></span>
        </div>
        <div id="dg-results" class="dg-grid"><div class="mut">Press “Run checks” to test this SmartEPT server.</div></div>
      </div>

      <div class="card htab-panel" data-ht="fix">
        <h3>Known Issues — how to fix common problems <span class="hint">plain-language steps, no technical background needed</span></h3>
        <div class="filters" style="margin-bottom:14px">
          <input id="kb-search" placeholder="Search problems… e.g. screenshots, 500, slow, email">
        </div>
        <div id="kb-list">

          <details class="kb" id="kb-db" data-kb="agent data screenshots stopped not arriving database sqlite mysql login token rejected uploads">
            <summary><span class="kb-tag t-danger">Data &amp; agents</span> Agent data or screenshots stopped reaching the console</summary>
            <div class="kb-body">
              <p><b>What you see:</b> The agent looks fine on the PC, but new screenshots, attendance or activity stop appearing in the console.</p>
              <p><b>Likely cause:</b> The web app lost its database settings and quietly fell back to an empty local database, so the agent’s login can no longer be verified and every upload is rejected.</p>
              <p><b>How to check:</b> Run <b>System Health</b> above — the “Database connection” row will be red and say it connected to SQLite instead of MySQL.</p>
              <p><b>How to fix:</b></p>
              <ol>
                <li>Open the <code>.env</code> file in the app folder and confirm <code>DB_CONNECTION=mysql</code> and <code>DB_DATABASE=smartept</code>.</li>
                <li>Run <code>migrate.bat</code> in the app folder to make sure all tables exist.</li>
                <li>In Laragon, do a full <b>Stop All</b> then <b>Start All</b> (not just reload).</li>
                <li>Re-open the agent on one PC and sign in again; new data should appear within a minute.</li>
              </ol>
              <p class="kb-esc"><b>When to call Ametecs:</b> If the Database row is still red after these steps, contact support on WhatsApp <b>90000 98877</b> with a copy of the log (below).</p>
            </div>
          </details>

          <details class="kb" id="kb-opcache" data-kb="changes not taking effect frozen opcache validate timestamps restart cache clear nothing happens">
            <summary><span class="kb-tag t-warn">Updates not applying</span> My changes or fixes don’t take effect no matter what I do</summary>
            <div class="kb-body">
              <p><b>What you see:</b> You edit a file or change a setting, clear the cache, even restart — and the app still behaves the old way.</p>
              <p><b>Likely cause:</b> PHP’s code cache (OPcache) is set to never re-read files, so it keeps serving a frozen copy of the code.</p>
              <p><b>How to check:</b> Run <b>System Health</b> — the “PHP code cache (OPcache)” row will be amber.</p>
              <p><b>How to fix:</b></p>
              <ol>
                <li>Open <code>php.ini</code> (Laragon → Menu → PHP → php.ini) and set <code>opcache.validate_timestamps=1</code>.</li>
                <li>Save, then in Laragon do a full <b>Stop All</b> then <b>Start All</b> — a full stop, not a reload.</li>
                <li>Re-run System Health; the OPcache row should turn green.</li>
              </ol>
              <p class="kb-esc"><b>When to call Ametecs:</b> If you can’t find php.ini or the row stays amber after a full restart, contact WhatsApp <b>90000 98877</b>.</p>
            </div>
          </details>

          <details class="kb" id="kb-500" data-kb="500 internal server error undefined constant blank screen white page crash">
            <summary><span class="kb-tag t-danger">A screen errors</span> A page shows “500” / “Internal Server Error”</summary>
            <div class="kb-body">
              <p><b>What you see:</b> A whole screen fails to load and shows a 500 error or “Internal Server Error”.</p>
              <p><b>Likely cause:</b> Either a code/template error on that page, or the database problem described in the first card.</p>
              <p><b>How to check:</b> Open the <b>Application log</b> below and load the last 100 lines — the newest <code>ERROR</code> line names the file and line number.</p>
              <p><b>How to fix:</b> If the log points at the database, follow the first card. Otherwise use “Copy for developer” below and send the log to Ametecs — the file and line number let us fix it quickly.</p>
              <p class="kb-esc"><b>When to call Ametecs:</b> Any 500 that isn’t the database — send the copied log to WhatsApp <b>90000 98877</b>.</p>
            </div>
          </details>

          <details class="kb" id="kb-storage" data-kb="storage evidence folder not writable disk full screenshots not saving space nas share">
            <summary><span class="kb-tag t-danger">Storage</span> Screenshots aren’t being saved / disk is full</summary>
            <div class="kb-body">
              <p><b>What you see:</b> Screenshots or webcam photos stop being saved even though agents are online.</p>
              <p><b>Likely cause:</b> The evidence folder is not writable, missing, or the disk is out of space.</p>
              <p><b>How to check:</b> Run <b>System Health</b> — the “Evidence storage folder” row shows the exact folder and whether it’s writable or nearly full.</p>
              <p><b>How to fix:</b></p>
              <ol>
                <li>Free up disk space, or point storage at a bigger drive/NAS in <b>Audit &amp; Ops → Local / On-premise storage</b>.</li>
                <li>Make sure the Windows service account can write to that folder.</li>
                <li>Consider turning on automatic cleanup (Audit &amp; Ops) to keep the folder from filling up again.</li>
              </ol>
              <p class="kb-esc"><b>When to call Ametecs:</b> If the folder is writable and has space but screenshots still don’t save, contact WhatsApp <b>90000 98877</b>.</p>
            </div>
          </details>

          <details class="kb" id="kb-license" data-kb="storage paused licence license key evaluation ended monitoring blocked screenshots paused validate">
            <summary><span class="kb-tag t-warn">Licence</span> Recording is paused / “evaluation ended”</summary>
            <div class="kb-body">
              <p><b>What you see:</b> Monitoring or screenshot storage is paused, or a banner says the evaluation has ended.</p>
              <p><b>Likely cause:</b> The licence key hasn’t been entered or validated, so recording is held until it is.</p>
              <p><b>How to check:</b> Run <b>System Health</b> — the “Evidence recording” row will be amber and say storage is paused.</p>
              <p><b>How to fix:</b> Open <b>Licence</b> in the menu, enter your key and press validate. Recording resumes immediately. Get a key from the client portal or WhatsApp <b>90000 98877</b>.</p>
              <p class="kb-esc"><b>When to call Ametecs:</b> If your key won’t validate, contact WhatsApp <b>90000 98877</b>.</p>
            </div>
          </details>

          <details class="kb" id="kb-migrate" data-kb="database updates pending migration migrate.bat feature missing new column table missing after update">
            <summary><span class="kb-tag t-warn">After an update</span> A new feature is missing, or a screen errors after an update</summary>
            <div class="kb-body">
              <p><b>What you see:</b> After receiving an updated build, a new feature isn’t there, or a screen throws an error mentioning a missing column or table.</p>
              <p><b>Likely cause:</b> The database updates that come with the new build haven’t been applied yet.</p>
              <p><b>How to check:</b> Run <b>System Health</b> — the “Database updates” row will be amber with the number of pending updates.</p>
              <p><b>How to fix:</b> Run <code>migrate.bat</code> in the app folder (or in Laragon Terminal: <code>cd /d C:\laragon\www\smartept</code> then <code>php artisan migrate</code>). Re-run System Health to confirm it turns green.</p>
              <p class="kb-esc"><b>When to call Ametecs:</b> If migrate reports an error, copy it and send to WhatsApp <b>90000 98877</b>.</p>
            </div>
          </details>

          <details class="kb" id="kb-agent-silent" data-kb="agent stopped reporting offline silent no heartbeat pc not checking in went offline">
            <summary><span class="kb-tag t-warn">Agents</span> An agent stopped checking in</summary>
            <div class="kb-body">
              <p><b>What you see:</b> A monitored PC shows offline, or System Health says no agent has checked in recently.</p>
              <p><b>Likely cause:</b> The PC is switched off, sleeping, off the network, or the agent was stopped.</p>
              <p><b>How to check:</b> Open <b>Devices</b> to see each PC’s last check-in time.</p>
              <p><b>How to fix:</b> Confirm the PC is on and online. If it’s in use but still silent, ask IT to reopen the SmartEPT agent from the Start Menu. Data recorded while offline syncs automatically once it returns.</p>
              <p class="kb-esc"><b>When to call Ametecs:</b> If the agent is running and online but never checks in, contact WhatsApp <b>90000 98877</b>.</p>
            </div>
          </details>

          <details class="kb" id="kb-agent-install" data-kb="agent not responding after install windows defender smartscreen unsigned first run scan">
            <summary><span class="kb-tag t-warn">Agents</span> The agent “isn’t responding” right after installing</summary>
            <div class="kb-body">
              <p><b>What you see:</b> Just after installing the agent on a PC, it seems frozen or “not responding”.</p>
              <p><b>Likely cause:</b> Windows Defender / SmartScreen scans a new app the first time it runs, which briefly holds it.</p>
              <p><b>How to fix:</b> Wait about 30 seconds, then reopen the agent from the Start Menu — it settles on its own. This is a one-time, first-run delay.</p>
              <p class="kb-esc"><b>When to call Ametecs:</b> If it’s still unresponsive after a couple of minutes and a reopen, contact WhatsApp <b>90000 98877</b>.</p>
            </div>
          </details>

          <details class="kb" id="kb-agent-upgrade" data-kb="update agent over old preserves pairing config reinstall upgrade keeps device paired">
            <summary><span class="kb-tag t-info">Good to know</span> Updating an agent keeps the PC paired</summary>
            <div class="kb-body">
              <p><b>What to expect:</b> Installing a newer agent over an existing one does <b>not</b> wipe its pairing or settings — the PC stays paired and keeps its identity.</p>
              <p><b>So:</b> It’s safe to push agent updates to a fleet without re-pairing every machine.</p>
              <p class="kb-esc"><b>Note:</b> Only a full uninstall clears pairing. Questions? WhatsApp <b>90000 98877</b>.</p>
            </div>
          </details>

          <details class="kb" id="kb-mail" data-kb="email not sending smtp mail credentials not received password reset alert not delivered">
            <summary><span class="kb-tag t-warn">Email</span> Credential or alert emails aren’t being delivered</summary>
            <div class="kb-body">
              <p><b>What you see:</b> New users don’t receive their sign-in email, or offline/violation alert emails never arrive.</p>
              <p><b>Likely cause:</b> Email isn’t configured to actually send — it’s only being written to the log.</p>
              <p><b>How to check:</b> Run <b>System Health</b> — the “Email sending” row will be amber.</p>
              <p><b>How to fix:</b> Set real SMTP details in the <code>.env</code> file (<code>MAIL_MAILER=smtp</code>, <code>MAIL_HOST</code>, <code>MAIL_USERNAME</code>, <code>MAIL_PASSWORD</code>, <code>MAIL_PORT</code>), then do a full Laragon Stop All → Start All.</p>
              <p class="kb-esc"><b>When to call Ametecs:</b> Ask WhatsApp <b>90000 98877</b> for the recommended SMTP settings for your setup.</p>
            </div>
          </details>

          <details class="kb" id="kb-gcs" data-kb="cloud storage libraries not installed google gcs composer bucket enable">
            <summary><span class="kb-tag t-warn">Cloud storage</span> Cloud Storage says “libraries not installed”</summary>
            <div class="kb-body">
              <p><b>What you see:</b> Turning on Google Cloud Storage in Audit &amp; Ops reports that the required libraries aren’t installed.</p>
              <p><b>Likely cause:</b> The Google Cloud packages haven’t been added to this server yet.</p>
              <p><b>How to fix:</b> Run <code>deployment\installers\ENABLE-CLOUD-STORAGE.bat</code>, or in Laragon Terminal: <code>cd /d C:\laragon\www\smartept</code> then <code>composer require google/cloud-storage league/flysystem-google-cloud-storage</code> (one command per line).</p>
              <p class="kb-esc"><b>When to call Ametecs:</b> If composer errors, copy the message and send to WhatsApp <b>90000 98877</b>.</p>
            </div>
          </details>

          <details class="kb" id="kb-composer" data-kb="composer does not contain valid json error variable one line cd terminal">
            <summary><span class="kb-tag t-warn">Setup</span> Composer fails with “does not contain valid JSON”</summary>
            <div class="kb-body">
              <p><b>What you see:</b> Running a composer command errors with “composer.json does not contain valid JSON”.</p>
              <p><b>Likely cause:</b> A batch variable was named <code>COMPOSER</code> (a reserved name), or a <code>cd</code> and a command were typed on the same line.</p>
              <p><b>How to fix:</b> In Laragon Terminal, type one command per line, and never use a variable called <code>COMPOSER</code>. For example: <code>cd /d C:\laragon\www\smartept</code> on its own line, then the <code>composer require …</code> line.</p>
              <p class="kb-esc"><b>When to call Ametecs:</b> Still stuck? WhatsApp <b>90000 98877</b>.</p>
            </div>
          </details>

        </div>
      </div>

      <div class="card htab-panel" data-ht="log">
        <h3>Application log <span class="hint">the most recent messages — copy them for the developer if you need help</span></h3>
        <div class="row" style="margin-bottom:10px">
          <label style="margin:0">Show last</label>
          <select id="lg-lines" style="width:auto;min-width:90px"><option>100</option><option selected>200</option><option>500</option></select>
          <label style="margin:0">lines</label>
          <button class="btn" id="lg-load">Load log</button>
          <button class="btn acc" id="lg-copy">Copy for developer</button>
          <span class="mut" id="lg-meta"></span>
        </div>
        <pre id="lg-out" class="logbox">Press “Load log” to read the most recent messages.</pre>
        <div class="mut" style="font-size:11px">Tip: if a screen just failed, load the log right after — the newest lines at the bottom describe what went wrong.</div>
      </div>
    </div>
    <div class="page-copy">© 2026 SmartEPT, developed by Ametecs India Private Limited — all rights reserved.</div>
  </div>
</div>

<!-- ORG UNIT EDITOR (17-Jul) -->
<div class="ovl" id="org-ovl">
  <div class="modal" style="width:520px">
    <div class="mhead"><div class="mt"><b id="org-m-title">Add</b><span>Organisation structure — used across employees, attendance &amp; policies</span></div>
      <button class="x" id="org-x">&#10005;</button></div>
    <div class="mbody"><div id="org-form"></div><div class="err" id="org-err"></div></div>
    <div class="mfoot"><button class="btn" id="org-cancel">Cancel</button><button class="btn solid" id="org-save">Save</button></div>
  </div>
</div>

<!-- API KEY / TARGET editors (17-Jul) -->
<div class="ovl" id="key-ovl"><div class="modal" style="width:520px">
  <div class="mhead"><div class="mt"><b>New API key</b><span>Shown once — copy it now. Store it in the external app.</span></div><button class="x" id="key-x">&#10005;</button></div>
  <div class="mbody" id="key-body"></div>
  <div class="mfoot" id="key-foot"></div>
</div></div>
<div class="ovl" id="tgt-ovl"><div class="modal" style="width:560px">
  <div class="mhead"><div class="mt"><b id="tgt-title">Add outbound target</b><span>SmartEPT POSTs signed attendance JSON to this URL</span></div><button class="x" id="tgt-x">&#10005;</button></div>
  <div class="mbody">
    <div class="fgrid">
      <div class="full"><label>Name</label><input id="tgt-name" placeholder="SmartPRS Production"></div>
      <div class="full"><label>Endpoint URL</label><input id="tgt-url" placeholder="https://smartprs.com/api/ingest/attendance"></div>
      <div class="full"><label>Signing secret <span style="font-weight:400;color:var(--ink-3)">(blank = auto-generate; leave blank on edit to keep current)</span></label><input id="tgt-secret" placeholder="shared HMAC secret"></div>
    </div>
    <label>What to send</label>
    <select id="tgt-events">
      <option value="attendance.daily">Daily attendance summary (nightly batch)</option>
      <option value="attendance.punch">Real-time IN/OUT punches (like a biometric device → SmartPRS)</option>
      <option value="both">Both — real-time punches + nightly summary</option>
    </select>
    <div class="fbool" style="margin-top:8px"><input type="checkbox" id="tgt-active" checked> Active</div>
    <div class="mut" style="font-size:11px">Real-time mode makes SmartEPT behave like a punch device: every login/unlock sends an IN, every logout/lock sends an OUT, HMAC-signed, the instant it happens.</div>
    <div class="err" id="tgt-err"></div>
  </div>
  <div class="mfoot"><button class="btn" id="tgt-cancel">Cancel</button><button class="btn solid" id="tgt-save">Save</button></div>
</div></div>

<!-- BULK EMPLOYEE IMPORT (17-Jul, SmartPRS parity) -->
<div class="ovl" id="import-ovl">
  <div class="modal" style="width:640px">
    <div class="mhead"><div class="mt"><b>Bulk import employees</b><span>Upload a CSV — one row per employee. Departments, teams, branches, shifts &amp; designations are matched by name and created if new.</span></div>
      <button class="x" id="import-x">&#10005;</button></div>
    <div class="mbody">
      <div class="never" style="background:var(--accent-weak);border-color:var(--accent);margin-bottom:14px">
        <b style="color:var(--accent-ink)">CSV columns</b>
        <div style="font-size:11.5px;line-height:1.7">
          <b>Required:</b> employee_code, first_name<br>
          <b>Optional:</b> last_name, email, mobile, department, team, branch, designation, shift, date_of_joining, biometric_id<br>
          A login is created for every row that has an email (opt out below). Download the template for the exact header row.
        </div>
      </div>
      <label>Choose CSV file</label>
      <input type="file" id="import-file" accept=".csv,text/csv">
      <div class="fbool" style="margin-top:10px"><input type="checkbox" id="import-login" checked> Create a self-service login for each employee with an email</div>
      <div class="row" style="margin-top:14px">
        <button class="btn" id="import-preview">Preview (dry run)</button>
        <button class="btn solid" id="import-run" disabled>Import now</button>
      </div>
      <div id="import-result" style="margin-top:14px;font-size:12px"></div>
    </div>
  </div>
</div>

<!-- STORAGE CLEANUP (17-Jul): bulk delete evidence & logs by date range -->
<div class="ovl" id="cleanup-ovl">
  <div class="modal" style="width:560px">
    <div class="mhead"><div class="mt"><b>Free up storage — bulk delete</b><span>Deletes are permanent and audit-logged. Attendance, breaks and the audit trail are never touched.</span></div>
      <button class="x" id="cleanup-x">✕</button></div>
    <div class="mbody">
      <div class="fgrid">
        <div><label>From date</label><input type="date" id="cl-from"></div>
        <div><label>To date</label><input type="date" id="cl-to"></div>
      </div>
      <label style="margin-top:14px">What to delete in this range</label>
      <div class="fbool"><input type="checkbox" id="cl-shots" checked> Screenshots (frees disk space)</div>
      <div class="fbool"><input type="checkbox" id="cl-activity"> Activity events (active/idle stretches)</div>
      <div class="fbool"><input type="checkbox" id="cl-apps"> Application usage logs</div>
      <div class="fbool"><input type="checkbox" id="cl-sites"> Website usage logs</div>
      <div class="fbool"><input type="checkbox" id="cl-presence"> Webcam presence logs</div>
      <div class="never" style="margin-top:14px;margin-bottom:0">
        <b>Violation evidence protection</b>
        <div class="fbool" style="padding-top:2px"><input type="checkbox" id="cl-keepviol" checked> Keep violation screenshots (recommended — evidence survives cleanup)</div>
        <div class="fbool"><input type="checkbox" id="cl-delviol"> Also delete violation RECORDS in this range (needs the confirmation word)</div>
      </div>
      <label style="margin-top:14px">Type <b>DELETE</b> to confirm</label>
      <input id="cl-confirm" placeholder="DELETE" autocomplete="off">
      <div class="mut" id="cl-msg" style="min-height:18px"></div>
    </div>
    <div class="mfoot">
      <button class="btn" id="cleanup-cancel">Cancel</button>
      <button class="btn danger" id="cleanup-run">Delete permanently</button>
    </div>
  </div>
</div>

<!-- MEETING SCHEDULE / EDIT (Section 2) -->
<div class="ovl" id="mtg-ovl">
  <div class="modal" style="width:700px">
    <h3 id="mtg-modal-title">Schedule meeting</h3>
    <label>Title</label><input id="mtg-title" maxlength="200" placeholder="e.g. Sprint planning">
    <label>Purpose / description</label><textarea id="mtg-purpose" rows="2" maxlength="2000" style="width:100%;background:var(--card-2);border:1.5px solid var(--border-2);border-radius:9px;padding:9px 11px;color:var(--ink);font-family:inherit;font-size:13px;resize:vertical"></textarea>
    <div class="grid2">
      <div><label>Scheduled start</label><input id="mtg-start" type="datetime-local"></div>
      <div><label>Scheduled end</label><input id="mtg-end" type="datetime-local"></div>
    </div>
    <label>Notes (optional)</label><textarea id="mtg-notes" rows="2" maxlength="2000" style="width:100%;background:var(--card-2);border:1.5px solid var(--border-2);border-radius:9px;padding:9px 11px;color:var(--ink);font-family:inherit;font-size:13px;resize:vertical"></textarea>
    <label>Participants</label>
    <div class="row" style="gap:8px;flex-wrap:wrap">
      <select id="mtg-f-branch" style="flex:1;min-width:120px"></select>
      <select id="mtg-f-dept" style="flex:1;min-width:120px"></select>
      <select id="mtg-f-team" style="flex:1;min-width:120px"></select>
      <input id="mtg-f-search" placeholder="Search name / code…" class="search" style="flex:2;min-width:140px">
    </div>
    <div class="row" style="justify-content:space-between;margin:8px 0 4px">
      <span class="mut" id="mtg-part-count">0 selected</span>
      <span style="font-size:12px"><a href="#" id="mtg-sel-all">Select shown</a> &nbsp;·&nbsp; <a href="#" id="mtg-sel-none">Clear all</a></span>
    </div>
    <div id="mtg-part-list" style="max-height:230px;overflow:auto;border:1px solid var(--border);border-radius:10px;padding:8px;background:var(--card-2)"></div>
    <div class="err" id="mtg-err"></div>
    <div class="row" style="justify-content:flex-end;gap:8px;margin-top:14px">
      <button class="btn" id="mtg-close-btn">Close</button>
      <button class="btn solid" id="mtg-save">Save meeting</button>
    </div>
  </div>
</div>

<!-- MEETING PARTICIPATION (Section 2) -->
<div class="ovl" id="mtg-part-ovl">
  <div class="modal" style="width:720px">
    <h3 id="mtg-part-title">Participation</h3>
    <table><thead><tr><th>Employee</th><th>Scheduled</th><th>Joined</th><th>Left</th><th>Time</th><th>Attendance</th></tr></thead>
    <tbody id="mtg-part-rows"></tbody></table>
    <div class="row" style="justify-content:flex-end;margin-top:14px"><button class="btn" id="mtg-part-close">Close</button></div>
  </div>
</div>

<!-- EMPLOYEE DRAWER -->
<div class="drawer-backdrop" id="drawer-backdrop"></div>
<div class="drawer" id="drawer">
  <span class="x" id="drawer-x">✕</span>
  <h2 id="d-name" style="font-size:17px">Employee</h2>
  <div class="sub" id="d-sub" style="color:var(--ink-3);font-size:12px"></div>
  <div class="tabs">
    <div class="tab active" data-tab="timeline">Timeline</div>
    <div class="tab" data-tab="apps">Apps</div>
    <div class="tab" data-tab="sites">Websites</div>
    <div class="tab" data-tab="compliance">Compliance</div>
  </div>
  <div id="d-body"></div>
</div>

<!-- HELP MODAL -->
<div class="ovl" id="help-ovl">
  <div class="modal">
    <div class="mhead">
      <div class="mark" style="width:30px;height:30px;font-size:10px;border-radius:8px"><img src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAACwAAAAsCAYAAAAehFoBAAALNklEQVR42u1Ze3CUVxX/nXu/x2Z3CY8lgSaxYMur2AeKRVvQQBUsapn6SKxtWp+l09ZpB1/F2nZJnVE6UwfbAgq22uowMhvtVFJsB+lAZLTDCNYCxUAhpJAmlDw32f3e9x7/2CQmNDxicdTRO3P3m93v3u/73d/53XPOPQv8v13AxkxpZgFm+j8ZF7AV2Jw7Nz6j/vktV7/W+MfZW198skA4k/hPMn/ljh1GJbMEM6GkxOCiMYvM1KRrZSxxzcAw4z+HV+IGIBr8XlWp883Hs8oPEqq7s2eA+n8/4HRaoLZWpx54YGbivXOXCEZR38H9v+186KHDfbHp1/Ua0lBBrndgUfTvBjtl6lRr6tSp0cE9r96avHb+z+KJOPJNx25sjpznUV2tRikrJmaWzGxc4P62vRO774Hbijc/xxdteZFLfvLT64dZYIhbo7OAFUSk/5UET3j00cVFFRUfIscLOra+0KAunTnNMhGQ47bEL7k0CDo68z213903dI5xJmaJSC9eXJNYvXrF9dls36UQAgJAFGkSp/GjtcbA/cEVFn4CAAYAAQHFiqWU1NqTbbz5xhu2iMS428zpl9Xw8eMomnP5xd0PPrgrBJB6ZvPvErNmLDWPHDnaA8wCUdTPMhsjMSsE6UwmM++66z7yy1RqwowLzezfDr/+JwBbdFublz10FHCyQRhF7sB99+Rb44zyCui+XPHpc0dk+Pbbl5sL5i94MpWaMMPzvGxvb25fLGY5vu/bzCwjpQwVRWYYhiYzEQBYhhHowY0stBDgWCzmSikjklITMyml2LZt8lxnFwDETr35aI/vPkeBP0YaYuqEp54qDY4cafeaXn+mz5J7RG/3SQAazIOWehu7APDss89eEwQh5/N5t77+hYX/amcR/8JX54x9Yl1QmqmLJjzxxL1nG2uMFBaFECWmaXB7R7t/ww1LX+WCfkT/KhkApJR8tgcrpWjIM2ljYT4AYO/evdhYX6/Q1ibx0Y9qtX1XKcgy4Siw62tUZSQ+njfR3ByhtjY6pyTCMNQAyJCSli1bVkRE3cysiYjPP3ANjh24juRTGRs3sl9V1WgdS6bV2PGm6O74M+q+qTA7zait1eelYSmtwkZnRls2+05cGwHgkvTapFVKSzhig5hZ2Db5bm7Pqa9/vQnMBKLjAfDwsJm1tRrMhLo6MTSAjBya5QUSZyZTeFmFvcC+Ys5vBBNIKZjFSYijTY8DuLdy507ZACik03LQIRbAShCp0y0zMmB1QeDS0NdwzlHMUCQFI2JDJuLRMGkM1WqBdQUgVrbxyZvyTUd+m129uhvAOdJLHiVEZhrsRIyqKoCZVG8uoUFS+57Urit1GEitz5DHpNMCRFT2q8yds/68Z1+iomJ59n3v663KZCQAPnc+7IwuRRzsBZMCRGxYttbMiJSGUgpRGCAKPR5BQhIPP6xTP3ysMjlr5noSxnS37c3voLpa1Z1NEkqpQaPG4+elVYnqalXy+LrbimbNuFsEkSYpbDJNhE1Hbs2RecpwHSCKwAQgDKG8EADQMGS5lSUl1MCM5LSptdKOa7fxbw0tX/lKA5hFv0TOnQ87ZzL9qlV02jZVsKwFNG7iPOrsBBkGZPEYsG1fFPV5fUopIAgLNvUDRGE4/JkbNhgNixaF5Zt+db9ZMeVDQVcX+x0daRABdXV0Drc2RMPOGUw/XOFB4VM5qrtHqXwuEtIQDJY6ZAXfI+2H4MCDJgJ8DzoIhoI1cccd4cTH1y81S0prdQR4bzSvbVv+5V0D1jv/I1L87X51zMrvp4zyMWWwbY0oIl8bhrP+Rwe177EKAkmamUkTtBah61iRHzjKcwHXBUmByLahfB8AUNHebrbccYc78bHH5toVZXUiUWx4LSdea214aSUyGYmqKn0egUOOnC33+1UxPrbWLLv4JoSBFgRh2jb0Bz94rY5UXkUKFIYQYQSWAlHoceiGMB0HFAQgIcBxpaIwMJHJyJbqanfiI49UmhVT6sx4ccI72dbnNh66CRs3OtiwQeC06DoM8M6dOwkA6VDTAN7hBFcVLpaVBATICzQMyQKhtJLJiIlCrVTB5JohJEFrrQo5M4AwVAQQh4GBbI+Pr92pxj+y5vNixrSfy1jc9j1fB8dbarq/+60DyGQGAseZQ/PChQsVAGYhzuCB+52L6yntOIAfgLSJgYxehSGR64IcB0QENiQi1yXkXOIxCQUhLQWADh9aK7e/sHrCT558QCST34PS7Dt5hCdPfqlnxV1bsGOHgUWLorNlawSAl9x6axxls2JePm8WWGE4I2w65QdC+gGgFEAE4RMgbNZdXdCpFBBGIBJMuZxSjhOykx/DNFmis313ePTYty0z9rq8/a5fytLSpQhC1mHEUevxL/Z8Y8UvzgYWGEj50mkCgL0zL/sGJo0tTRJ5zAxmRmy8bWYyGblz505ZBUhmJoSBq4MAOgihPQ/KD8D5nKXzjlA5h5UfRFEYUmRaUlvxIimoRR9vrnF+UDs/Nm/ue4wlCw+JZHKpzuUQOU6fd+KNT58P2ALD/XWBxPK7rxBjx34Sr+x+OCyyphMRx2I2dm3bFu7atk0NdROJ+x6ydBiBPRcEEJh10NkZx+SLDZImUeDH4DpQTv4P6uXdzcG25xuL70+PsdesbRCpifPBGmyYCLu7DniN+2vCNWtePR+wA5IQALROlSyMsvl3A5B9vt/s+z6NGzeu6JVXXr33L3/Z85KUtur18/FkFO2767VmFvmc0oGvYVoCWgsi8gkQ8P0O3dnxtD5ycL1fX38svmLlx61PfPLHMI2FJATY88CsEXV1rs8/9+v70NCQQyYjzwdsAXBbWcF5GbJcTyydmLrlCwu/ePPNL81tPLTp8pkzbpkz58qVc+ZcuXJgwvH9+x/1dh/wY8XjpO7LSu3koX3vdW0X9Uzasrm2paVlFQAZvz/9mfg1lZtlUdE8siwADC0IUa5vn8r23OfXPvjiYLIzioKJgYtaGQAon3c1gNyUd6+aXVm5+4pZM2sOHjh4OFVa8lnbthIkhBZERo/jtIPVKW59k1RPVz0dPrDD3769FTfemDpVXbPYHjvuc8IwlkrbGi9MC6w1OPChw7AFbm6N8+D96wD4/RFMj3SqOJckNABQR++vldmcJttecOSaypfNufMem3357HX4x0kgBsDvD8kiC0wyamouxlXv/6w5b8HHhGHOE5Y1UdoxCCmglEZfe0dgmGaTFTjPOH/dswFbt3YX3HmVHHUZaliSXVUlUVenYneu+A7KK74PISBYQfRluxjcqEEOM0dQKkaxIoODsJyZJ1FRLE6mVUirpQCUhpvt0SB5ImaYR2+7pLzjw8WJH9RUV/+VAPAAq6PPtEc4FfSDtu5deQ8lkw8JIVJkWSDLKoDREVjpwhStwVqDBkK460CHQYuled/15ZPal3/g6mTlZbOmG8XFs/18ftk9mzZtv2f+fKqre08ErELtKGVwhmPMP0qfRcuWlel3TfsUTOsjkHIaWJUCbIA1IIwQ4E7B9Jbrem0Io/3I5/9wpdd9dPdTT9XHxo+/sl8+YAAnWluXTCkv//2FqtsZbzupVmWkW1fdCmBdfwcuuWQspk0r0NnZqbB3bxYAtt19d9ni6+arnG3z5lNdV0W2PS/SmlnrUAqhAJjHjh69esPTTzdREEiimN6/f08fEb11wcv3SKcNFM5Rw0MjEdLptNXU/MZWHkXTSjMzc18up1/Zt++WfqblO2N4eIIenS6bqkxG1FVX68mTJ5c4udykEy0nXo7CiLkQ7aAU85D6JQQESBKRlAXdA8q2bctz3asAbPof+ovpn1IN0zuYz6Mpe/1Xt78DKJBLZqtAbdEAAAAASUVORK5CYII=" alt="SmartEPT" style="width:72%;height:72%;object-fit:contain;display:block"></div>
      <div class="mt"><b id="help-title">Screen help</b><span>SmartEPT · what this screen does and how to use it</span></div>
      <button class="x" data-close="help-ovl">✕</button>
    </div>
    <div class="mbody" id="help-body"></div>
  </div>
</div>

<!-- EMPLOYEE ADD/EDIT MODAL -->
<div class="ovl" id="emp-ovl">
  <div class="modal">
    <div class="mhead">
      <div class="mt"><b id="emp-m-title">Add employee</b><span>Employee record · monitored via the desktop agent after device registration</span></div>
      <button class="x" data-close="emp-ovl">✕</button>
    </div>
    <div class="mbody">
      <div class="fgrid">
        <div><label>First name *</label><input id="f-first"></div>
        <div><label>Last name</label><input id="f-last"></div>
        <div><label>Employee code *</label><input id="f-code" placeholder="EMP001"></div>
        <div><label>Email</label><input id="f-email" type="email"></div>
        <div><label>Mobile</label><input id="f-mobile"></div>
        <div><label>Status</label><select id="f-status">
          <option value="ACTIVE">ACTIVE</option><option value="ON_LEAVE">ON_LEAVE</option><option value="RELIEVED">RELIEVED</option>
        </select></div>
        <div><label>Branch</label><select id="f-branch"></select></div>
        <div><label>Department</label><select id="f-dept"></select></div>
        <div><label>Team</label><select id="f-team"></select></div>
        <div><label>Designation</label><select id="f-desig"></select></div>
        <div><label>Shift</label><select id="f-shift"></select></div>
        <div><label>Date of joining</label><input id="f-doj" type="date"></div>
        <div class="full"><label>Biometric ID (optional)</label><input id="f-bio" placeholder="ID on the punch device"></div>
        <div class="full"><label>Tracking mode <span style="font-weight:400;color:var(--ink-3)">— what the agent captures on this person's PCs</span></label>
          <select id="f-track">
            <option value="">Inherit (from team / department / company)</option>
            <option value="FULL">Full — capture everything</option>
            <option value="PRESENCE_ONLY">Presence &amp; breaks only — no screenshots, no activity</option>
            <option value="EXCLUDED">Do Not Track — capture nothing at all</option>
          </select></div>
      </div>
      <div class="err" id="emp-m-err"></div>
    </div>
    <div class="mfoot">
      <button class="btn" data-close="emp-ovl">Cancel</button>
      <button class="btn solid" id="emp-m-save">Save</button>
    </div>
  </div>
</div>

<!-- USER ADD/EDIT MODAL -->
<div class="ovl" id="user-ovl">
  <div class="modal" style="width:540px">
    <div class="mhead">
      <div class="mt"><b id="u-m-title">Add user</b><span>Login account · a temporary password is generated and shown once</span></div>
      <button class="x" data-close="user-ovl">✕</button>
    </div>
    <div class="mbody">
      <div class="fgrid">
        <div><label>Full name *</label><input id="uf-name"></div>
        <div><label>Email *</label><input id="uf-email" type="email"></div>
        <div><label>Role *</label><select id="uf-role"></select></div>
        <div><label>Status</label><select id="uf-status"><option value="ACTIVE">ACTIVE</option><option value="DISABLED">DISABLED</option></select></div>
        <div class="full"><label>Linked employee (optional — set at creation only)</label><select id="uf-emp"></select></div>
      </div>
      <div class="err" id="u-m-err"></div>
    </div>
    <div class="mfoot">
      <button class="btn" data-close="user-ovl">Cancel</button>
      <button class="btn solid" id="u-m-save">Save</button>
    </div>
  </div>
</div>

<!-- ONE-TIME CREDENTIALS PANEL -->
<div class="ovl" id="role-ovl">
  <div class="modal" style="width:640px;max-height:86vh;overflow:auto">
    <div class="mhead">
      <div class="mt"><b id="role-m-title">Role</b><span id="role-m-sub"></span></div>
      <button class="btn" id="role-x">✕</button>
    </div>
    <div class="mbody">
      <div id="role-name-wrap" class="fgrid">
        <div><label>Role name <span style="color:var(--danger)">*</span></label><input id="role-name" placeholder="e.g. Floor Supervisor"></div>
        <div><label>Based on (screen access)</label><select id="role-base"></select></div>
      </div>
      <h3 style="margin:12px 0 6px">Module permissions</h3>
      <div id="role-matrix"></div>
      <div class="mut" id="role-err"></div>
    </div>
    <div class="mfoot"><button class="btn" id="role-cancel">Cancel</button><button class="btn solid" id="role-save">Save</button></div>
  </div>
</div>

<div class="ovl" id="cred-ovl">
  <div class="modal" style="width:480px">
    <div class="mhead">
      <div class="mt"><b>One-time credentials</b><span>Share these with the user — shown only once</span></div>
    </div>
    <div class="mbody">
      <div class="never" style="margin-bottom:14px"><b>⚠ Shown only once</b>
        Only a hash of this password is stored — once this box closes it cannot be retrieved, only reset. The user must change it at first sign-in.</div>
      <label>Email</label><input id="cred-email" readonly>
      <label>Temporary password</label>
      <div class="row" style="flex-wrap:nowrap">
        <input id="cred-pass" style="font-family:ui-monospace,Menlo,Consolas,monospace;font-weight:700;font-size:15px;letter-spacing:1px">
        <button class="btn acc" id="cred-copy" style="white-space:nowrap">Copy</button>
      </div>
      <div class="row hide" id="cred-apply-row" style="margin-top:8px">
        <button class="btn solid" id="cred-apply">Apply password</button>
        <span class="mut" style="font-size:11.5px">Keep the suggestion or type your own (min 8 chars), then Apply.</span>
      </div>
      <div class="mut" id="cred-msg"></div>
    </div>
    <div class="mfoot"><button class="btn solid" data-close="cred-ovl">Done — I've shared it</button></div>
  </div>
</div>

<!-- ATTENDANCE REGULARIZE / ADD MODAL -->
<div class="ovl" id="att-ovl">
  <div class="modal" style="width:560px">
    <div class="mhead">
      <div class="mt"><b id="att-m-title">Regularize attendance</b><span>Corrections feed payroll — a reason is required and audit-logged</span></div>
      <button class="x" data-close="att-ovl">✕</button>
    </div>
    <div class="mbody">
      <div class="fgrid">
        <div><label>Employee *</label><select id="af-emp"></select></div>
        <div><label>Date *</label><input type="date" id="af-date"></div>
        <div><label>Status *</label><select id="af-status">
          <option value="PRESENT">PRESENT</option><option value="ABSENT">ABSENT</option>
          <option value="HALF_DAY">HALF_DAY</option><option value="ON_LEAVE">ON_LEAVE</option>
        </select></div>
        <div></div>
        <div><label>Check-in — date &amp; time (manual punch)</label><input type="datetime-local" id="af-in"></div>
        <div><label>Check-out — date &amp; time (manual punch)</label><input type="datetime-local" id="af-out"></div><div class="full mut" style="font-size:11px;padding-top:2px">A manual check-in with a time counts as this employee's gate punch for that day — if Gate-to-PC is on, it lets their agent start. Time is captured, not just the date.</div>
        <div class="full"><label>Reason * — kept on the record with your name</label>
          <textarea id="af-reason" placeholder="e.g. Biometric reader was down — verified with the branch manager"></textarea></div>
      </div>
      <div class="err" id="att-m-err"></div>
    </div>
    <div class="mfoot">
      <button class="btn" data-close="att-ovl">Cancel</button>
      <button class="btn solid" id="att-m-save">Save</button>
    </div>
  </div>
</div>

<!-- FORCED PASSWORD CHANGE (not dismissible) -->
<div class="ovl" id="pwd-ovl">
  <div class="modal" style="width:420px">
    <div class="mhead">
      <div class="mt"><b>Set a new password</b><span>You signed in with a temporary password — choose your own to continue</span></div>
    </div>
    <div class="mbody">
      <label>Current (temporary) password</label><input type="password" id="pw-cur" autocomplete="current-password">
      <label>New password (min 8 characters)</label><input type="password" id="pw-new" autocomplete="new-password">
      <label>Confirm new password</label><input type="password" id="pw-conf" autocomplete="new-password">
      <div class="err" id="pw-err"></div>
    </div>
    <div class="mfoot">
      <button class="btn" id="pw-signout">Sign out</button>
      <button class="btn solid" id="pw-save">Change password &amp; continue</button>
    </div>
  </div>
</div>

<!-- SCREENSHOT LIGHTBOX -->
<div class="ovl" id="shot-ovl">
  <div style="max-width:92vw">
    <img class="shotbig" id="shot-img" alt="Screenshot">
    <div class="shotmeta" id="shot-meta"></div>
  </div>
</div>

@verbatim
<script>
const API = '/api';
let TOKEN = null, ME = null, CURRENT = null, poll = null;

const $ = (s) => document.querySelector(s);
const $$ = (s) => document.querySelectorAll(s);
// Universal click-to-sort for EVERY table (17-Jul UX): click a column header to
// sort its rows; click again to reverse. Numeric & date aware. Empty/action
// headers and any table/th marked "nosort" are skipped. Pure client-side.
document.addEventListener('click', (e) => {
  const th = e.target.closest('th');
  if (!th) return;
  const table = th.closest('table');
  if (!table || table.classList.contains('nosort') || th.classList.contains('nosort')) return;
  const thead = th.closest('thead'); if (!thead) return;
  const tbody = table.querySelector('tbody'); if (!tbody) return;
  const rows = [...tbody.querySelectorAll('tr')].filter((r) => r.children.length > 1);
  if (rows.length < 2) return;
  const idx = [...th.parentElement.children].indexOf(th);
  if (idx < 0 || !th.textContent.trim()) return; // skip action columns

  const asc = !(th.dataset.sortDir === 'asc');
  th.dataset.sortDir = asc ? 'asc' : 'desc';
  thead.querySelectorAll('th').forEach((h) => { h.classList.remove('sorted'); const a = h.querySelector('.arw'); if (a) a.remove(); });
  th.classList.add('sortable-h', 'sorted');
  const arw = document.createElement('span'); arw.className = 'arw'; arw.textContent = asc ? '▲' : '▼'; th.appendChild(arw);

  const val = (r) => {
    const c = r.children[idx]; if (!c) return '';
    return (c.getAttribute('data-sort') ?? c.textContent).trim();
  };
  const num = (v) => { const n = parseFloat(String(v).replace(/[^0-9.\-]/g, '')); return isNaN(n) ? null : n; };
  rows.sort((a, b) => {
    const va = val(a), vb = val(b), na = num(va), nb = num(vb);
    let r; if (na !== null && nb !== null && va !== '' && vb !== '') r = na - nb;
    else r = va.localeCompare(vb, undefined, { numeric: true, sensitivity: 'base' });
    return asc ? r : -r;
  });
  rows.forEach((r) => tbody.appendChild(r));
});
// Mark header cells as sortable-looking on first hover of any table thead.
document.addEventListener('mouseover', (e) => {
  const th = e.target.closest('thead th');
  if (th && th.textContent.trim() && !th.classList.contains('sortable-h')
      && !th.closest('table').classList.contains('nosort')) th.classList.add('sortable-h');
}, { once: false });
// Lightweight non-blocking toast (17-Jul) — used by org/import/cleanup actions.
function toast(msg) {
  let el = document.getElementById('_toast');
  if (!el) {
    el = document.createElement('div');
    el.id = '_toast';
    el.style.cssText = 'position:fixed;bottom:22px;right:22px;background:var(--navy);color:#fff;padding:12px 18px;'
      + 'border-radius:11px;font-size:13px;font-weight:600;z-index:200;box-shadow:0 12px 30px rgba(0,0,0,.35);'
      + 'max-width:340px;opacity:0;transform:translateY(8px);transition:opacity .18s,transform .18s';
    document.body.appendChild(el);
  }
  el.textContent = msg;
  requestAnimationFrame(() => { el.style.opacity = '1'; el.style.transform = 'none'; });
  clearTimeout(el._t);
  el._t = setTimeout(() => { el.style.opacity = '0'; el.style.transform = 'translateY(8px)'; }, 3000);
}

async function api(path, opts = {}) {
  const isForm = opts.body instanceof FormData;
  const r = await fetch(API + path, {
    ...opts,
    headers: {
      'Accept': 'application/json',
      ...(isForm ? {} : { 'Content-Type': 'application/json' }),
      ...(TOKEN ? { Authorization: 'Bearer ' + TOKEN } : {}),
      ...(opts.headers || {}),
    },
  });
  if (!r.ok) {
    const e = await r.json().catch(() => ({}));
    const msg = e?.error?.message || e?.message
      || (e?.errors ? Object.values(e.errors).flat().join(' ') : '')
      || ('HTTP ' + r.status);
    const err = new Error(msg); err.status = r.status; err.body = e; throw err;
  }
  return r.status === 204 ? null : r.json();
}
async function apiBlob(path) {
  const r = await fetch(API + path, { headers: { Authorization: 'Bearer ' + TOKEN, 'Accept': '*/*' } });
  if (!r.ok) { const err = new Error('HTTP ' + r.status); err.status = r.status; throw err; }
  return r.blob();
}
const secH = (s) => { s = s || 0; const h = Math.floor(s / 3600), m = Math.round((s % 3600) / 60); return h ? h + 'h ' + m + 'm' : m + 'm'; };
const t = (iso) => iso ? new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '—';
const dt = (iso) => iso ? new Date(iso).toLocaleString([], { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : '—';
// esc() also escapes quotes so escaped values are safe inside HTML attributes (data-*).
const esc = (s) => (s == null ? '' : String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])));
const today = () => { const d = new Date(); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }; // LOCAL date, not UTC — after midnight IST the console must default to the IST day
const fullName = (e) => ((e?.first_name || '') + ' ' + (e?.last_name || '')).trim();
const deniedCard = () => '<tr><td colspan="12"><div class="denied"><div class="big">🔒</div><b>Your role cannot view this</b><br>Ask a company admin to grant access.</div></td></tr>';
const isDenied = (e) => e && e.status === 403;

// ---- session ----
function enterApp() {
  $('#who').innerHTML = '<b>' + esc(ME.name) + '</b><br>' + esc(ME.role_name || ME.role || '');
  if (ME.company) $('#company-name').textContent = ME.company;
  $('#login').classList.add('hide'); $('#app').classList.remove('hide');
  applyAttendanceMode();
  applyPermissionNav();
  show('dashboard');
  // Temp-password logins must set their own password before doing anything else.
  if (ME.must_change_password) openForcedPwd();
}
// R4 item 3: organisations without a biometric device hide the Biometric screen.
function applyAttendanceMode() {
  const off = ME && ME.attendance_mode === 'AGENT_ONLY';
  const nav = document.querySelector('.nav[data-view="biometric"]');
  if (nav) nav.style.display = off ? 'none' : '';
}
// R4 item 5: the role's permission matrix decides which modules appear.
function applyPermissionNav() {
  if (!ME || !ME.role) return;
  if (ME.role === 'SUPER_ADMIN' || ME.role === 'COMPANY_ADMIN') return;
  const perms = ME.permissions || [];
  const NAVP = {
    dashboard: 'dashboard.view', screenshots: 'screenshot.view', usage: 'activity.view',
    attendance: 'attendance.view', violations: 'dashboard.view', reports: 'export.data',
    policies: 'policy.view', ops: 'audit.view',
  };
  Object.entries(NAVP).forEach(([view, perm]) => {
    const el = document.querySelector('.nav[data-view="' + view + '"]');
    if (el && !perms.includes(perm)) el.style.display = 'none';
  });
}
$('#btn-login').onclick = async () => {
  $('#login-err').textContent = '';
  try {
    const res = await api('/auth/login', { method: 'POST', body: JSON.stringify({ email: $('#email').value, password: $('#password').value }) });
    TOKEN = res.token; ME = res.user;
    sessionStorage.setItem('ept_token', TOKEN); // survive refresh
    enterApp();
  } catch (e) { $('#login-err').textContent = e.message; }
};
$('#password').addEventListener('keydown', (e) => { if (e.key === 'Enter') $('#btn-login').click(); });
$('#signout').onclick = async () => {
  try { await api('/auth/logout', { method: 'POST' }); } catch (e) { /* token may already be dead */ }
  TOKEN = null; ME = null; sessionStorage.removeItem('ept_token');
  clearInterval(poll); $('#app').classList.add('hide'); $('#login').classList.remove('hide');
};
// Restore session on refresh — or sign in via a cloud SSO ticket (EPT-27).
(async () => {
  // A signed ?sso= ticket from SmartEPT Central signs the tenant admin straight in.
  const ssoTicket = new URLSearchParams(location.search).get('sso');
  if (ssoTicket) {
    try {
      const res = await api('/auth/sso', { method: 'POST', body: JSON.stringify({ ticket: ssoTicket }) });
      TOKEN = res.token; ME = res.user;
      sessionStorage.setItem('ept_token', TOKEN);
      history.replaceState(null, '', location.pathname); // strip the ticket from the URL
      enterApp();
      return;
    } catch (e) {
      history.replaceState(null, '', location.pathname);
      const le = $('#login-err'); if (le) le.textContent = e.message || 'Sign-in link invalid or expired.';
      // fall through to the normal login screen
    }
  }
  const saved = sessionStorage.getItem('ept_token');
  if (!saved) return;
  TOKEN = saved;
  try { const res = await api('/auth/me'); ME = res.user; enterApp(); }
  catch (e) { TOKEN = null; sessionStorage.removeItem('ept_token'); }
})();

// ---- nav ----
const TITLES = {
  dashboard: ['Live Dashboard', 'Real-time workforce status'],
  attendance: ['Attendance', 'Daily sheet, regularization & holiday calendar'],
  screenshots: ['Screenshots', 'Policy-driven screen captures — every view is audit-logged'],
  usage: ['Usage & Compliance', 'Per-employee application and website time'],
  violations: ['Violations', 'Compliance events across the company'],
  employees: ['Employees', 'Directory & lifecycle'],
  org: ['Organisation', 'Branches, departments, teams, designations & shifts'],
  meetings: ['Meetings', 'Schedule meetings & track participation'],
  users: ['Users', 'Login accounts, roles & credentials'],
  devices: ['Devices', 'Registered endpoints & agent health'],
  policies: ['Policies', 'The control room — what is tracked, for whom'],
  rules: ['App & Web Rules', 'Track, allow, block or flag apps & websites — company-wide'],
  biometric: ['Biometric', 'Cloud punch sync, mapping & reconciliation'],
  reports: ['Reports & Exports', 'CSV exports for Excel and payroll'],
  license: ['Licence', 'Key, plan, device seats & daily validation'],
  integrations: ['API & Integrations', 'Connect SmartEPT to SmartPRS & any external device or app'],
  ops: ['Audit & Ops', 'Who did what, storage growth & database backups'],
  help: ['Help & Troubleshooting', 'System health, common fixes & the application log'],
};
$$('.nav').forEach((n) => n.onclick = () => show(n.dataset.view));
(function(){ const a=document.getElementById('app'), t=document.getElementById('nav-toggle'), b=document.getElementById('nav-backdrop');
  if(t) t.onclick=()=>a.classList.toggle('nav-open'); if(b) b.onclick=()=>a.classList.remove('nav-open'); })();
function show(v) {
  $$('.nav').forEach((n) => n.classList.toggle('active', n.dataset.view === v));
  $$('.view').forEach((el) => el.classList.remove('active'));
  $('#v-' + v).classList.add('active');
  $('#page-title').textContent = TITLES[v][0]; $('#page-sub').textContent = TITLES[v][1];
  clearInterval(poll);
  if (v === 'dashboard') { initDashOrgFilter(); loadDashboard(); poll = setInterval(loadDashboard, 15000); }
  if (v === 'attendance') initAttendance();
  if (v === 'screenshots') initScreenshots();
  if (v === 'usage') initUsage();
  if (v === 'violations') initViolations();
  if (v === 'employees') loadEmployees();
  if (v === 'org') initOrg();
  if (v === 'meetings') initMeetings();
  if (v === 'users') loadUsers();
  if (v === 'devices') loadDevices();
  if (v === 'policies') initPolicies();
  if (v === 'rules') initRules();
  if (v === 'biometric') initBiometric();
  if (v === 'reports') initReports();
  if (v === 'license') loadLicense();
  if (v === 'integrations') initIntegrations();
  if (v === 'ops') loadOps();
  if (v === 'help') initHelp();
  { const a=document.getElementById('app'); if(a) a.classList.remove('nav-open'); }
  CURRENT = v;
}

// Refresh button — reloads the active view's data in place (keeps filters where possible).
function refreshView() {
  const b = document.getElementById('btn-refresh');
  if (b) { b.classList.add('spin'); setTimeout(() => b.classList.remove('spin'), 700); }
  const v = CURRENT;
  if (v === 'dashboard') loadDashboard();
  else if (v === 'attendance') initAttendance();
  else if (v === 'screenshots') loadScreenshots();
  else if (v === 'usage') initUsage();
  else if (v === 'violations') initViolations();
  else if (v === 'employees') loadEmployees();
  else if (v === 'org') initOrg();
  else if (v === 'meetings') initMeetings();
  else if (v === 'users') loadUsers();
  else if (v === 'devices') loadDevices();
  else if (v === 'policies') initPolicies();
  else if (v === 'rules') initRules();
  else if (v === 'biometric') initBiometric();
  else if (v === 'reports') initReports();
  else if (v === 'license') loadLicense();
  else if (v === 'integrations') initIntegrations();
  else if (v === 'ops') loadOps();
  else if (v === 'help') runDiagnostics();
}
(function bindRefresh(){ const b = document.getElementById('btn-refresh'); if (b) b.onclick = refreshView; })();

// ---- Help & Troubleshooting (system health self-check + known issues + log viewer) ----
let helpBound = false;
function initHelp() {
  if (!helpBound) {
    helpBound = true;
    const run = $('#dg-run'); if (run) run.onclick = runDiagnostics;
    const ll = $('#lg-load'); if (ll) ll.onclick = loadLog;
    const lc = $('#lg-copy'); if (lc) lc.onclick = copyLog;
    const s = $('#kb-search'); if (s) s.oninput = filterKb;
  }
  runDiagnostics();
}
function helpTab(name) {
  $$('#v-help .htab').forEach((b) => b.classList.toggle('on', b.dataset.ht === name));
  $$('#v-help .htab-panel').forEach((p) => p.classList.toggle('on', p.dataset.ht === name));
}
async function runDiagnostics() {
  const box = $('#dg-results'); if (!box) return;
  box.innerHTML = '<div class="mut">Running checks…</div>';
  const ov = $('#dg-overall'); ov.style.display = 'none'; $('#dg-when').textContent = '';
  try {
    const r = await api('/ops/diagnostics');
    const M = { ok: ['t-ok', 'All good'], warn: ['t-warn', 'Needs attention'], down: ['t-danger', 'Action needed'] };
    const o = M[r.overall] || M.ok;
    ov.className = 'tag ' + o[0]; ov.textContent = o[1]; ov.style.display = '';
    $('#dg-when').textContent = 'Checked ' + r.checked_at;
    box.innerHTML = (r.checks || []).map((c) => {
      const cls = c.status === 'ok' ? 'dg-ok' : (c.status === 'warn' ? 'dg-warn' : 'dg-down');
      const fix = c.fix ? ' <a onclick="openKb(\'' + c.fix + '\')">How to fix this &rarr;</a>' : '';
      return '<div class="dg-item ' + cls + '"><span class="dot"></span><div class="dg-l"><b>'
        + esc(c.label) + '</b><p>' + esc(c.detail) + fix + '</p></div></div>';
    }).join('');
  } catch (e) {
    const extra = e.status === 403 ? ' Sign in as a company admin to use System Health.' : '';
    box.innerHTML = '<div class="mut" style="color:var(--danger)">Could not run checks: ' + esc(e.message) + '.' + extra + '</div>';
  }
}
function openKb(id) {
  const el = document.getElementById(id); if (!el) return;
  helpTab('fix'); // the KB lives on the "Fix a problem" tab — switch to it first
  el.open = true;
  el.scrollIntoView({ behavior: 'smooth', block: 'center' });
  el.classList.add('kb-flash'); setTimeout(() => el.classList.remove('kb-flash'), 1600);
}
function filterKb() {
  const q = ($('#kb-search').value || '').toLowerCase().trim();
  $$('#kb-list details.kb').forEach((d) => {
    const hay = (d.getAttribute('data-kb') || '') + ' ' + d.textContent.toLowerCase();
    d.style.display = (!q || hay.indexOf(q) !== -1) ? '' : 'none';
  });
}
async function loadLog() {
  const out = $('#lg-out'); if (!out) return;
  out.textContent = 'Loading…'; $('#lg-meta').textContent = '';
  try {
    const n = $('#lg-lines').value || 200;
    const r = await api('/ops/logs?lines=' + n);
    if (!r.exists) { out.textContent = r.note || 'No log file yet.'; return; }
    out.textContent = r.text || '(the log is empty)';
    $('#lg-meta').textContent = r.path + ' · ' + r.size_human + ' · last ' + r.lines + ' lines';
    out.scrollTop = out.scrollHeight;
  } catch (e) {
    out.textContent = 'Could not load the log: ' + e.message + (e.status === 403 ? ' (company admin only)' : '');
  }
}
function copyLog() {
  const t = $('#lg-out').textContent || '';
  if (navigator.clipboard && t) {
    navigator.clipboard.writeText(t).then(() => toast('Log copied — paste it to the developer.'),
      () => toast('Copy failed — select the text and copy manually.'));
  } else { toast('Nothing to copy yet — load the log first.'); }
}

// ---- shared caches ----
let EMP_CACHE = null, ORG_CACHE = null, DEV_CACHE = null;
async function employeesList(force = false) {
  if (!EMP_CACHE || force) {
    const d = await api('/employees?per_page=500');
    EMP_CACHE = d.data || [];
  }
  return EMP_CACHE;
}
async function orgLists(force = false) {
  if (!ORG_CACHE || force) {
    const types = ['branches', 'departments', 'teams', 'designations', 'shifts'];
    ORG_CACHE = {};
    await Promise.all(types.map(async (ty) => {
      try { ORG_CACHE[ty] = (await api('/org/' + ty)).data || []; }
      catch (e) { ORG_CACHE[ty] = []; }
    }));
  }
  return ORG_CACHE;
}
async function devicesList(force = false) {
  if (!DEV_CACHE || force) {
    const d = await api('/devices?per_page=500');
    DEV_CACHE = d.data || [];
  }
  return DEV_CACHE;
}
function fillSelect(sel, rows, labelFn, valueFn, emptyLabel) {
  sel.innerHTML = (emptyLabel ? '<option value="">' + esc(emptyLabel) + '</option>' : '')
    + rows.map((r) => '<option value="' + esc(valueFn(r)) + '">' + esc(labelFn(r)) + '</option>').join('');
}
// Refill an employee picker, keeping the current selection when it still exists.
function fillEmpPicker(sel, emps) {
  const prev = sel.value;
  fillSelect(sel, emps, (e) => fullName(e) + ' (' + (e.employee_code || '#' + e.id) + ')', (e) => e.id, emps.length ? null : 'No employees yet');
  if (prev && [...sel.options].some((o) => o.value === prev)) sel.value = prev;
}
// Wire a text box to type-to-filter an employee <select> (client-side over the cached list).
function attachEmpSearch(input, sel, emps, allLabel) {
  if (!input) return;
  input.oninput = () => {
    const q = input.value.trim().toLowerCase();
    const list = q ? emps.filter((e) => (fullName(e) + ' ' + (e.employee_code || '')).toLowerCase().includes(q)) : emps;
    if (allLabel != null) fillSelect(sel, list, (e) => fullName(e) + ' (' + (e.employee_code || '#' + e.id) + ')', (e) => e.id, allLabel);
    else fillEmpPicker(sel, list);
  };
}
// Filter visible table rows by free text (reusable across list screens).
function attachTableFilter(input, tbodySel) {
  if (!input) return;
  const apply = () => {
    const q = input.value.trim().toLowerCase();
    document.querySelectorAll(tbodySel + ' tr').forEach((tr) => {
      tr.style.display = (!q || tr.textContent.toLowerCase().includes(q)) ? '' : 'none';
    });
  };
  input.oninput = apply;
  apply();
}

// ---- 1. dashboard ----
// ---- dashboard mini-charts (inline SVG, CSP-safe) ----
function catColor(c){
  c=(c||'').toUpperCase();
  if(['PRODUCTIVE','CLIENT_REQUIRED','COMMUNICATION'].includes(c)) return '#16A34A';
  if(['NON_PRODUCTIVE','RESTRICTED'].includes(c)) return '#D97706';
  if(c==='BLOCKED') return '#DC2626';
  return '#64748B';
}
function svgDonut(segs, total){
  const R=52, cx=64, cy=64, sw=19, C=2*Math.PI*R;
  const ring='<circle cx="64" cy="64" r="'+R+'" fill="none" stroke="rgba(148,163,184,.18)" stroke-width="'+sw+'"/>';
  let off=0, arcs='';
  (segs||[]).forEach((seg)=>{
    if(!seg.value) return;
    const len=C*seg.value/total, gap=Math.min(2,len);
    arcs+='<circle cx="'+cx+'" cy="'+cy+'" r="'+R+'" fill="none" stroke="'+seg.color+'" stroke-width="'+sw
      +'" stroke-dasharray="'+(len-gap)+' '+(C-(len-gap))+'" stroke-dashoffset="'+(-off)
      +'" transform="rotate(-90 64 64)"><title>'+esc(seg.label)+': '+seg.value+' ('+Math.round(seg.value/total*100)+'%)</title></circle>';
    off+=len;
  });
  return '<svg viewBox="0 0 128 128" width="190" height="190">'+ring+arcs
    +'<text x="64" y="60" text-anchor="middle" font-size="26" font-weight="800" fill="var(--ink)" font-family="var(--font-head)">'+(total||0)+'</text>'
    +'<text x="64" y="80" text-anchor="middle" font-size="8.5" letter-spacing="1" fill="var(--ink-3)">EMPLOYEES</text></svg>';
}
function utilBars(rows, label){
  rows=(rows||[]).filter((r)=>+r.secs>0);
  if(!rows.length) return '<div class="tu-h">'+label+'</div><div class="mut" style="font-size:12px">No activity tracked today.</div>';
  const tot=rows.reduce((a,b)=>a+(+b.secs||0),0);
  const max=rows.reduce((a,b)=>Math.max(a,+b.secs||0),1);
  return '<div class="tu-h">'+label+'</div>'+rows.map((r)=>{
    const secs=+r.secs||0, pc=tot?Math.round(secs/tot*100):0, w=Math.max(3,secs/max*100);
    return '<div class="tu-row" title="'+esc(r.name)+' — '+secH(secs)+' ('+pc+'%)">'
      +'<div class="tu-name">'+esc(r.name)+'</div>'
      +'<div class="tu-val">'+secH(secs)+'<span class="pc">'+pc+'%</span></div>'
      +'<div class="tu-track"><div class="tu-fill" style="width:'+w+'%;background:'+catColor(r.category)+'"></div></div></div>';
  }).join('');
}
// ---- dashboard org roll-up (company > branch > department > team > individual) ----
let DASH_ORG = { branch_id: '', department_id: '', team_id: '', employee_id: '' };
let DASH_ORG_READY = false;
function dashOrgQS() { return Object.entries(DASH_ORG).filter(([, v]) => v).map(([k, v]) => k + '=' + encodeURIComponent(v)).join('&'); }
function dashOrgQ() { const q = dashOrgQS(); return q ? '?' + q : ''; }
function dashOrgAmp() { const q = dashOrgQS(); return q ? '&' + q : ''; }
async function initDashOrgFilter() {
  if (DASH_ORG_READY) return;
  const bSel = $('#dof-branch'), dSel = $('#dof-dept'), tSel = $('#dof-team'), eSel = $('#dof-emp');
  if (!bSel) return;
  let org, emps;
  try { [org, emps] = await Promise.all([orgLists(), employeesList()]); } catch (e) { $('#dash-org').style.display = 'none'; return; }
  const branches = org.branches || [], depts = org.departments || [], teams = org.teams || [];
  emps = emps || [];
  if (!branches.length && !depts.length && !teams.length && !emps.length) { $('#dash-org').style.display = 'none'; return; }
  const opt = (v, l) => '<option value="' + v + '">' + esc(l) + '</option>';
  const fill = (sel, rows, all, label) => { sel.innerHTML = opt('', all) + rows.map((r) => opt(r.id, label ? label(r) : r.name)).join(''); };
  const repaintDept = () => {
    const b = DASH_ORG.branch_id;
    fill(dSel, depts.filter((d) => !b || String(d.branch_id) === String(b)), 'All departments');
    dSel.value = DASH_ORG.department_id || '';
  };
  const repaintTeam = () => {
    const d = DASH_ORG.department_id, b = DASH_ORG.branch_id;
    const inBranch = (t) => { if (!b) return true; const dep = depts.find((x) => String(x.id) === String(t.department_id)); return dep && String(dep.branch_id) === String(b); };
    fill(tSel, teams.filter((t) => (d ? String(t.department_id) === String(d) : inBranch(t))), 'All teams');
    tSel.value = DASH_ORG.team_id || '';
  };
  const repaintEmp = () => {
    const { branch_id: b, department_id: d, team_id: t } = DASH_ORG;
    const rows = emps.filter((e) => (t ? String(e.team_id) === String(t) : d ? String(e.department_id) === String(d) : b ? String(e.branch_id) === String(b) : true));
    fill(eSel, rows, 'All employees', (e) => fullName(e) + (e.employee_code ? ' (' + e.employee_code + ')' : ''));
    eSel.value = DASH_ORG.employee_id || '';
  };
  const scope = () => {
    const nm = (rows, id) => (rows.find((r) => String(r.id) === String(id)) || {}).name;
    const emp = emps.find((e) => String(e.id) === String(DASH_ORG.employee_id));
    const parts = [nm(branches, DASH_ORG.branch_id), nm(depts, DASH_ORG.department_id), nm(teams, DASH_ORG.team_id), emp ? fullName(emp) : null].filter(Boolean);
    $('#dof-scope').textContent = parts.length ? 'Showing: ' + parts.join(' › ') : 'Showing: whole company';
  };
  fill(bSel, branches, 'All branches'); repaintDept(); repaintTeam(); repaintEmp(); scope();
  bSel.onchange = () => { DASH_ORG = { branch_id: bSel.value, department_id: '', team_id: '', employee_id: '' }; repaintDept(); repaintTeam(); repaintEmp(); scope(); loadDashboard(); };
  dSel.onchange = () => { DASH_ORG.department_id = dSel.value; DASH_ORG.team_id = ''; DASH_ORG.employee_id = ''; repaintTeam(); repaintEmp(); scope(); loadDashboard(); };
  tSel.onchange = () => { DASH_ORG.team_id = tSel.value; DASH_ORG.employee_id = ''; repaintEmp(); scope(); loadDashboard(); };
  eSel.onchange = () => { DASH_ORG.employee_id = eSel.value; scope(); loadDashboard(); };
  $('#dof-reset').onclick = () => { DASH_ORG = { branch_id: '', department_id: '', team_id: '', employee_id: '' }; bSel.value = ''; repaintDept(); repaintTeam(); repaintEmp(); scope(); loadDashboard(); };
  DASH_ORG_READY = true;
}
let DASH_EMP = [];      // last live-status employees payload (EPT-23 drill-down)
let DASH_FILTER = null; // active KPI filter: null | 'all' | 'ONLINE' | 'IDLE' | 'AWAY' | 'OFFLINE'
const KPI_STATUS = { ONLINE: 't-ok', IDLE: 't-idle', AWAY: 't-warn', OFFLINE: 't-off' };
function renderLiveRows() {
  const f = DASH_FILTER;
  const active = f && f !== 'all' && KPI_STATUS[f];
  const list = active ? DASH_EMP.filter((e) => e.status === f) : DASH_EMP;
  if (active) {
    const cc = { ONLINE: 'k-ok', IDLE: 'k-idle', AWAY: 'k-away', OFFLINE: 'k-off' }[f] || 'k-total';
    const lbl = { ONLINE: 'Active now', IDLE: 'Idle', AWAY: 'Away', OFFLINE: 'Offline' }[f] || f;
    $('#live-filter').innerHTML = '<span class="fchip ' + cc + '">' + esc(lbl) + ' · ' + list.length
      + ' <span class="x" id="clr-filter" title="Clear filter">✕</span></span>';
  } else {
    $('#live-filter').innerHTML = '';
  }
  $('#live-rows').innerHTML = list.map((e) => {
    const cls = KPI_STATUS[e.status] || 't-off';
    return '<tr class="clk" data-id="' + e.employee_id + '" data-name="' + esc(e.name) + '">'
      + '<td><span class="nm">' + esc(e.name) + '</span></td><td>' + esc(e.team || '—') + '</td>'
      + '<td><span class="tag ' + cls + '">' + esc(e.status) + '</span></td>'
      + '<td>' + secH(e.active_seconds) + '</td><td>' + secH(e.idle_seconds) + '</td><td>' + t(e.last_seen) + '</td></tr>';
  }).join('') || '<tr><td colspan="6" class="mut">' + (active ? 'No employees match this filter right now.' : 'No employees online yet.') + '</td></tr>';
}
async function loadDashboard() {
  try {
    const d = await api('/dashboard/live-status' + dashOrgQ());
    const c = d.cards;
    DASH_EMP = d.employees || [];
    const KPI = [
      ['Employees', c.total_employees, 'k-total', 'all'],
      ['Active now', c.active_now, 'k-ok', 'ONLINE'],
      ['Idle', c.idle_now, 'k-idle', 'IDLE'],
      ['Away', c.away_now, 'k-away', 'AWAY'],
      ['Offline', c.offline, 'k-off', 'OFFLINE'],
      ['On break', c.on_break, 'k-break', 'view:attendance'],
      ['Violations today', c.violations_today, 'k-viol', 'view:violations'],
      ['Screenshots', c.screenshots_today, 'k-shot', 'view:screenshots'],
    ];
    $('#kpis').innerHTML = KPI.map(([l, v, cls, act]) => {
      const isView = act.indexOf('view:') === 0;
      const sel = (!isView && DASH_FILTER === act) ? ' sel' : '';
      const go = isView ? 'Open →' : (act === 'all' ? 'View all →' : 'Filter →');
      return '<div class="kpi drill ' + cls + sel + '" data-act="' + act + '" title="'
        + esc(l) + ' — click to ' + (isView ? 'open that screen' : 'filter the list below') + '">'
        + '<div class="kside"><div class="l">' + esc(l) + '</div></div>'
        + '<div class="kmain"><span class="go">' + go + '</span><div class="v">' + (v ?? 0) + '</div></div></div>';
    }).join('');
    const wf = [['Active', c.active_now, '#16A34A'], ['Idle', c.idle_now, '#D97706'], ['On break', c.on_break, '#EA580C'], ['Offline', c.offline, '#94A3B8']];
    const wfTotal = c.total_employees || wf.reduce((a, [, v]) => a + (v || 0), 0);
    $('#wf-donut').innerHTML = svgDonut(wf.map(([label, value, color]) => ({ label, value: value || 0, color })), wfTotal);
    $('#wf-leg').innerHTML = wf.map(([l, v, col]) => '<div class="r"><span class="dot" style="background:' + col + '"></span><span class="nm">' + l + '</span><span class="ct">' + (v || 0) + '</span><span class="pc">' + (wfTotal ? Math.round((v || 0) / wfTotal * 100) : 0) + '%</span></div>').join('');
    renderLiveRows();
  } catch (e) {
    if (isDenied(e)) { $('#live-rows').innerHTML = deniedCard(); $('#kpis').innerHTML = ''; }
  }
  try {
    const tu = await api('/reports/time-utilization?date=' + today() + dashOrgAmp());
    const apps = (tu.apps || []).map((a) => ({ name: a.app_name, secs: a.secs, category: a.category }));
    const sites = (tu.sites || []).map((x) => ({ name: x.site, secs: x.secs, category: x.category }));
    $('#tu-grid').innerHTML = '<div>' + utilBars(apps, 'Top applications') + '</div><div>' + utilBars(sites, 'Top websites') + '</div>';
  } catch (e) {
    if (isDenied(e)) $('#tu-grid').innerHTML = '<div class="mut" style="font-size:12px">Your role cannot view activity data.</div>';
  }
  try {
    const pr = await api('/reports/productivity?from=' + today() + '&to=' + today() + dashOrgAmp());
    $('#dash-prod-rows').innerHTML = (pr.data || []).map((x) =>
      '<tr><td>' + esc(x.employee_code || '—') + '</td><td><b>' + esc(x.name) + '</b></td>'
      + '<td class="mut">' + esc(x.department || '—') + '</td>'
      + '<td>' + hms(x.present_seconds) + '</td><td><b>' + hms(x.work_seconds) + '</b></td>'
      + '<td>' + hms(x.idle_seconds) + '</td><td>' + x.break_count + '</td>'
      + '<td>' + (x.violations ? '<span class="tag t-danger">' + x.violations + '</span>' : '0') + '</td>'
      + '<td><b>' + Number(x.productivity).toFixed(0) + '%</b></td></tr>'
    ).join('') || '<tr><td colspan="9" class="mut">No activity today yet.</td></tr>';
    attachTableFilter($('#dash-prod-q'), '#dash-prod-rows');
  } catch (e) {
    if (isDenied(e)) $('#dash-prod-rows').innerHTML = deniedCard();
  }
  try {
    const h = await api('/dashboard/device-health' + dashOrgQ());
    $('#dash-dev-rows').innerHTML = (h.data || []).map((v) => {
      const hc = { HEALTHY: 't-ok', DEGRADED: 't-warn', STOPPED: 't-danger' }[v.agent_health] || 't-off';
      const cc = { COMPLIANT: 't-ok', WARNING: 't-warn', NON_COMPLIANT: 't-danger', CRITICAL: 't-danger' }[v.compliance_status] || 't-off';
      return '<tr><td><b>' + esc(v.computer_name || v.device_uuid || '—') + '</b></td>'
        + '<td>' + esc(fullName(v.employee) || '—') + '</td><td>' + esc(v.app_version || '—') + '</td>'
        + '<td><span class="tag ' + hc + '">' + esc(v.agent_health || '—') + '</span></td>'
        + '<td><span class="tag ' + cc + '">' + esc(v.compliance_status || '—') + '</span></td>'
        + '<td>' + (v.sync_pending_count || 0) + '</td><td>' + dt(v.last_heartbeat_at) + '</td></tr>';
    }).join('') || '<tr><td colspan="7" class="mut">No devices registered — install the SmartEPT agent to see health here.</td></tr>';
  } catch (e) {
    if (isDenied(e)) $('#dash-dev-rows').innerHTML = deniedCard();
  }
}
$('#live-rows').addEventListener('click', (e) => {
  const tr = e.target.closest('tr[data-id]');
  if (tr) openEmployee(Number(tr.dataset.id), tr.dataset.name);
});
// EPT-23: KPI card drill-down — status cards filter the live table, count cards jump to their screen.
$('#kpis').addEventListener('click', (ev) => {
  const k = ev.target.closest('.kpi.drill');
  if (!k) return;
  const act = k.dataset.act || '';
  if (act.indexOf('view:') === 0) { show(act.slice(5)); return; }
  DASH_FILTER = (DASH_FILTER === act) ? null : act; // toggle same card off
  document.querySelectorAll('#kpis .kpi').forEach((x) => x.classList.toggle('sel', !!DASH_FILTER && x.dataset.act === DASH_FILTER));
  renderLiveRows();
  if (DASH_FILTER && DASH_FILTER !== 'all') $('#live-rows').closest('.card').scrollIntoView({ behavior: 'smooth', block: 'start' });
});
$('#live-filter').addEventListener('click', (ev) => {
  if (ev.target.id !== 'clr-filter') return;
  DASH_FILTER = null;
  document.querySelectorAll('#kpis .kpi').forEach((x) => x.classList.remove('sel'));
  renderLiveRows();
});

// ---- 2. screenshots ----
let SS_URLS = []; // object URLs to revoke between loads
let SS_META = {}; // id -> metadata for lightbox
let SS_SEQ = 0;   // guards against overlapping loads (e.g. evidence-link jump)
async function initScreenshots() {
  if (!$('#ss-date').value) $('#ss-date').value = today();
  try {
    const emps = await employeesList();
    fillSelect($('#ss-emp'), emps, (e) => fullName(e) + ' (' + (e.employee_code || '#' + e.id) + ')', (e) => e.id, '— All employees —');
    attachEmpSearch($('#ss-emp-q'), $('#ss-emp'), emps, '— All employees —');
    loadScreenshots();
  } catch (e) {
    if (isDenied(e)) { $('#ss-grid').innerHTML = ''; showSsEmpty('Your role cannot view the employee list, so screenshots cannot be browsed.'); }
  }
}
function showSsEmpty(html) { const el = $('#ss-empty'); el.className = 'empty'; el.innerHTML = html; }
function clearSsEmpty() { const el = $('#ss-empty'); el.className = 'hide'; el.innerHTML = ''; }
// Company-wide screenshot wall (default 'All employees' view).
async function loadAllScreenshots(date) {
  const seq = ++SS_SEQ;
  SS_URLS.forEach((u) => URL.revokeObjectURL(u)); SS_URLS = []; SS_META = {};
  const grid = $('#ss-grid'); grid.innerHTML = '<div class="mut">Loading all employees…</div>'; clearSsEmpty();
  $('#ss-title').textContent = 'Screenshot timeline — All employees · ' + date;
  try {
    const d = await api('/reports/screenshots?date=' + encodeURIComponent(date));
    if (seq !== SS_SEQ) return;
    const shots = d.data || [];
    if (!shots.length) { grid.innerHTML = ''; showSsEmpty('<b>No screenshots for this day.</b><br>Screenshots appear here once the desktop agent uploads them for any employee whose policy has capture enabled.'); return; }
    grid.innerHTML = shots.map((s) => {
      SS_META[s.id] = s;
      return '<div class="shotcard" data-shot="' + s.id + '">'
        + '<div class="img" id="ss-img-' + s.id + '">loading…</div>'
        + '<div class="m"><b>' + esc(s.employee_name || '—') + '</b> · ' + t(s.captured_at) + '<br>'
        + esc(s.active_app || s.window_title || '—')
        + ' · <span style="color:var(--ink-3)">' + esc(s.trigger_reason || '') + '</span></div></div>';
    }).join('');
    shots.forEach(async (s) => {
      const slot = document.getElementById('ss-img-' + s.id);
      try {
        const blob = await apiBlob('/screenshots/' + s.id + '/file');
        const url = URL.createObjectURL(blob);
        if (seq !== SS_SEQ) { URL.revokeObjectURL(url); return; }
        SS_URLS.push(url); SS_META[s.id].objectUrl = url;
        if (slot) slot.innerHTML = '<img src="' + url + '" alt="Screenshot">';
      } catch (e) { if (slot && seq === SS_SEQ) slot.textContent = e.status === 403 ? 'no access' : 'file missing'; }
    });
  } catch (e) {
    grid.innerHTML = ''; showSsEmpty(isDenied(e) ? 'Your role cannot view screenshots.' : esc(e.message));
  }
}
async function loadScreenshots() {
  const empId = $('#ss-emp').value, date = $('#ss-date').value || today();
  if (!empId) { loadAllScreenshots(date); return; }
  const seq = ++SS_SEQ;
  SS_URLS.forEach((u) => URL.revokeObjectURL(u)); SS_URLS = []; SS_META = {};
  const grid = $('#ss-grid'); grid.innerHTML = '<div class="mut">Loading…</div>'; clearSsEmpty();
  const empName = $('#ss-emp').selectedOptions[0] ? $('#ss-emp').selectedOptions[0].textContent : '';
  $('#ss-title').textContent = 'Screenshot timeline — ' + empName + ' · ' + date;
  try {
    const d = await api('/reports/employee/' + empId + '/screenshots?date=' + encodeURIComponent(date));
    if (seq !== SS_SEQ) return; // a newer load superseded this one
    const shots = d.data || [];
    if (!shots.length) {
      grid.innerHTML = '';
      showSsEmpty('<b>No screenshots for this day.</b><br>Screenshots appear here once the desktop agent uploads them — capture must be enabled in the employee\'s effective screenshot policy, and the agent captures on interval, at random, or on violations per that policy.');
      return;
    }
    grid.innerHTML = shots.map((s) => {
      SS_META[s.id] = s;
      return '<div class="shotcard" data-shot="' + s.id + '">'
        + '<div class="img" id="ss-img-' + s.id + '">loading…</div>'
        + '<div class="m"><b>' + t(s.captured_at) + '</b>'
        + esc(s.active_app || s.window_title || '—')
        + ' · <span style="color:var(--ink-3)">' + esc(s.trigger_reason || '') + '</span></div></div>';
    }).join('');
    // Evidence jump: highlight the capture taken at (or nearest to) the
    // violation moment, so "View evidence" lands on the exact screenshot.
    if (window._EV_TIME || window._EV_DETECTED) {
      // The agent captures a screenshot tagged BLOCKED_APP/BLOCKED_SITE with the
      // offending app/site the instant a violation happens. Match on that FIRST
      // (robust), and only use time as a tiebreaker — the violation timestamp and
      // the screenshot clock can disagree (timezone / sync delay), so time alone
      // used to land on the latest interval screenshot instead of the evidence.
      const target = new Date(String(window._EV_TIME || '').replace(' ', 'T')).getTime();
      const det = String(window._EV_DETECTED || '').toLowerCase().trim();
      window._EV_TIME = null; window._EV_DETECTED = null;
      const VIOL = ['VIOLATION', 'BLOCKED_APP', 'BLOCKED_SITE'];
      const isViol = (s) => VIOL.indexOf(String(s.trigger_reason || '').toUpperCase()) !== -1;
      const hay = (s) => ((s.active_app || '') + ' ' + (s.window_title || '') + ' ' + (s.website_domain || '')).toLowerCase();
      const timeGap = (s) => { const t2 = new Date(String(s.captured_at).replace(' ', 'T')).getTime(); return (isNaN(t2) || isNaN(target)) ? Infinity : Math.abs(t2 - target); };
      const nearest = (list) => list.slice().sort((a, b) => timeGap(a) - timeGap(b))[0] || null;

      let best = null;
      // 1) a violation-triggered capture whose tagged app/site matches the offender
      if (det) best = nearest(shots.filter(isViol).filter((s) => hay(s).indexOf(det) !== -1));
      // 2) any violation-triggered capture, nearest in time
      if (!best) best = nearest(shots.filter(isViol));
      // 3) last resort — nearest interval capture within 15 min (old behaviour)
      if (!best) { const c = nearest(shots); if (c && timeGap(c) <= 15 * 60 * 1000) best = c; }

      if (best) {
        const el = document.querySelector('[data-shot="' + best.id + '"]');
        if (el) {
          el.classList.add('ev-hit');
          setTimeout(() => el.scrollIntoView({ behavior: 'smooth', block: 'center' }), 150);
        }
      }
    }

    // Fetch each image as a blob with the bearer token (the file route is protected).
    shots.forEach(async (s) => {
      const slot = document.getElementById('ss-img-' + s.id);
      try {
        const blob = await apiBlob('/screenshots/' + s.id + '/file');
        const url = URL.createObjectURL(blob);
        if (seq !== SS_SEQ) { URL.revokeObjectURL(url); return; }
        SS_URLS.push(url);
        SS_META[s.id].objectUrl = url;
        if (slot) slot.innerHTML = '<img src="' + url + '" alt="Screenshot">';
      } catch (e) { if (slot && seq === SS_SEQ) slot.textContent = e.status === 403 ? 'no access' : 'file missing'; }
    });
  } catch (e) {
    if (seq !== SS_SEQ) return;
    grid.innerHTML = '';
    showSsEmpty(isDenied(e) ? '<b>Your role cannot view screenshots.</b><br>The screenshot.view permission is required.' : esc(e.message));
  }
}
$('#ss-load').onclick = loadScreenshots;
$('#ss-emp').addEventListener('change', loadScreenshots);
$('#ss-date').addEventListener('change', loadScreenshots);
$('#ss-grid').addEventListener('click', (e) => {
  const card = e.target.closest('[data-shot]');
  if (!card) return;
  const s = SS_META[card.dataset.shot];
  if (!s || !s.objectUrl) return;
  $('#shot-img').src = s.objectUrl;
  $('#shot-meta').textContent = [t(s.captured_at), s.active_app, s.window_title, s.trigger_reason].filter(Boolean).join(' · ');
  $('#shot-ovl').classList.add('open');
});
$('#shot-ovl').addEventListener('click', () => $('#shot-ovl').classList.remove('open'));

// ---- 3. usage & compliance ----
async function initUsage() {
  if (!$('#us-date').value) $('#us-date').value = today();
  try {
    const emps = await employeesList();
    // Default view = ALL employees (17-Jul). "— All employees —" is the first option.
    fillSelect($('#us-emp'), emps, (e) => fullName(e) + ' (' + (e.employee_code || '#' + e.id) + ')', (e) => e.id, '— All employees —');
    attachEmpSearch($('#us-emp-q'), $('#us-emp'), emps, '— All employees —');
    $('#us-emp').value = '';
    loadUsage();
  } catch (e) {
    if (isDenied(e)) $('#us-sum-rows').innerHTML = deniedCard();
  }
}
async function loadUsageSummary() {
  const date = $('#us-date').value || today();
  $('#us-summary-card').style.display = '';
  $('#us-individual').style.display = 'none';
  $('#us-comp-card').style.display = 'none';
  try {
    const r = await api('/reports/usage-summary?date=' + encodeURIComponent(date));
    $('#us-sum-rows').innerHTML = (r.data || []).length ? r.data.map((e) =>
      '<tr class="clk" data-uemp="' + e.employee_id + '" data-uname="' + esc(e.name) + '">'
      + '<td>' + esc(e.employee_code || '—') + '</td><td><b>' + esc(e.name) + '</b></td>'
      + '<td>' + esc(e.department || '—') + '</td><td>' + esc(e.team || '—') + '</td>'
      + '<td data-sort="' + e.app_seconds + '">' + secH(e.app_seconds) + '</td>'
      + '<td data-sort="' + e.site_seconds + '">' + secH(e.site_seconds) + '</td>'
      + '<td data-sort="' + e.violations + '">' + (e.violations ? '<span class="tag t-danger">' + e.violations + '</span>' : '0') + '</td></tr>'
    ).join('') : '<tr><td colspan="7" class="mut">No app/website activity recorded for this day yet.</td></tr>';
  } catch (e) { $('#us-sum-rows').innerHTML = isDenied(e) ? deniedCard() : '<tr><td colspan="7" class="mut">' + esc(e.message) + '</td></tr>'; }
}
async function loadUsage() {
  const id = $('#us-emp').value, date = $('#us-date').value || today();
  if (!id) { return loadUsageSummary(); }
  // Individual view.
  $('#us-summary-card').style.display = 'none';
  $('#us-individual').style.display = '';
  $('#us-comp-card').style.display = '';
  const q = '?date=' + encodeURIComponent(date);
  const secTable = (rows, keys, tbody, empty) => {
    tbody.innerHTML = (rows || []).map((r) => '<tr>'
      + '<td><b>' + esc(r[keys[0]] || '—') + '</b></td><td>' + esc(r.category || '—') + '</td>'
      + '<td>' + secH(Number(r.seconds)) + '</td>'
      + '<td><span class="tag ' + (r.status === 'VIOLATION' ? 't-danger' : 't-ok') + '">' + esc(r.status || 'OK') + '</span></td></tr>').join('')
      || '<tr><td colspan="4" class="mut">' + empty + '</td></tr>';
  };
  try {
    const a = await api('/reports/employee/' + id + '/app-usage' + q);
    secTable(a.data, ['app_name'], $('#us-app-rows'), 'No app usage recorded for this day.');
  } catch (e) { $('#us-app-rows').innerHTML = isDenied(e) ? deniedCard() : '<tr><td colspan="4" class="mut">' + esc(e.message) + '</td></tr>'; }
  try {
    const w = await api('/reports/employee/' + id + '/website-usage' + q);
    secTable(w.data, ['site'], $('#us-web-rows'), 'No website usage recorded for this day.');
  } catch (e) { $('#us-web-rows').innerHTML = isDenied(e) ? deniedCard() : '<tr><td colspan="4" class="mut">' + esc(e.message) + '</td></tr>'; }
  try {
    const c = await api('/reports/employee/' + id + '/compliance' + q);
    $('#us-comp-rows').innerHTML = (c.data || []).map((v) => {
      const sc = { LOW: 't-info', MEDIUM: 't-warn', HIGH: 't-danger', CRITICAL: 't-danger' }[v.severity] || 't-off';
      return '<tr><td>' + t(v.started_at) + '</td><td>' + esc(String(v.event_type || '').replace(/_/g, ' ')) + '</td>'
        + '<td><span class="tag ' + sc + '">' + esc(v.severity) + '</span></td>'
        + '<td>' + esc(v.detected_value || '—') + '</td><td>' + esc(v.action_taken || '—') + '</td></tr>';
    }).join('') || '<tr><td colspan="5" class="mut">No compliance events for this day.</td></tr>';
  } catch (e) { $('#us-comp-rows').innerHTML = isDenied(e) ? deniedCard() : '<tr><td colspan="5" class="mut">' + esc(e.message) + '</td></tr>'; }
}
$('#us-load').onclick = loadUsage;
$('#us-sum-rows').addEventListener('click', (e) => {
  const tr = e.target.closest('[data-uemp]'); if (!tr) return;
  $('#us-emp').value = tr.dataset.uemp; loadUsage();
});
$('#us-emp').addEventListener('change', loadUsage);
$('#us-date').addEventListener('change', loadUsage);
$('#us-open-drawer').onclick = () => {
  const sel = $('#us-emp');
  if (sel.value) openEmployee(Number(sel.value), sel.selectedOptions[0].textContent.replace(/\s*\(.*\)$/, ''));
};

// ---- 4. violations ----
let VIOL_INIT = false;
async function initViolations() {
  if (!VIOL_INIT) {
    VIOL_INIT = true;
    employeesList().then((emps) => {
      const sel = $('#viol-emp');
      sel.innerHTML = '<option value="">All employees</option>'
        + emps.map((e) => '<option value="' + e.id + '">' + esc(fullName(e) + ' (' + (e.employee_code || '#' + e.id) + ')') + '</option>').join('');
      attachEmpSearch($('#viol-emp-q'), sel, emps, 'All employees');
    }).catch(() => {});
    $('#viol-load').onclick = loadViolations;
    $('#viol-emp').onchange = loadViolations;
    $('#viol-date').onchange = loadViolations;
    $('#viol-clear').onclick = () => { $('#viol-emp').value = ''; $('#viol-date').value = ''; loadViolations(); };
  }
  loadViolations();
}
async function loadViolations() {
  try {
    const q = ['per_page=100'];
    if ($('#viol-emp').value) q.push('employee_id=' + $('#viol-emp').value);
    if ($('#viol-date').value) q.push('date=' + $('#viol-date').value);
    const d = await api('/dashboard/violations?' + q.join('&'));
    $('#viol-rows').innerHTML = (d.data || []).map((v) => {
      const sc = { LOW: 't-info', MEDIUM: 't-warn', HIGH: 't-danger', CRITICAL: 't-danger' }[v.severity] || 't-off';
      const date = v.started_at ? String(v.started_at).slice(0, 10) : today();
      const evidence = v.screenshot_captured
        ? '<a data-ev-emp="' + (v.employee?.id ?? '') + '" data-ev-name="' + esc(fullName(v.employee)) + '" data-ev-date="' + esc(date) + '" data-ev-time="' + esc(String(v.started_at || '')) + '" data-ev-detected="' + esc(String(v.detected_value || '')) + '" style="cursor:pointer;font-weight:600">View evidence</a>'
        : '<span class="mut">—</span>';
      return '<tr><td>' + dt(v.started_at) + '</td><td><span class="nm">' + esc(fullName(v.employee) || '—') + '</span></td>'
        + '<td>' + esc(v.event_category) + '</td><td>' + esc(String(v.event_type || '').replace(/_/g, ' ')) + '</td>'
        + '<td><span class="tag ' + sc + '">' + esc(v.severity) + '</span></td><td>' + esc(v.detected_value || '—') + '</td>'
        + '<td>' + esc(v.action_taken || '—') + '</td><td>' + evidence + '</td></tr>';
    }).join('') || '<tr><td colspan="8" class="mut">No violations recorded.</td></tr>';
  } catch (e) {
    $('#viol-rows').innerHTML = isDenied(e) ? deniedCard() : '<tr><td colspan="8" class="mut">' + esc(e.message) + '</td></tr>';
  }
}
$('#viol-rows').addEventListener('click', async (e) => {
  const a = e.target.closest('[data-ev-emp]');
  if (!a || !a.dataset.evEmp) return;
  window._EV_TIME = a.dataset.evTime || null; // highlight the exact capture
  window._EV_DETECTED = a.dataset.evDetected || null; // the offending app/site, for a robust match
  show('screenshots');
  await initScreenshots();
  $('#ss-emp').value = a.dataset.evEmp;
  $('#ss-date').value = a.dataset.evDate;
  loadScreenshots();
});

// ---- 5. employees ----
let EMP_EDIT_ID = null, EMP_DEV_COUNTS = null, EMP_SEARCH_TIMER = null;
// Small badge shown when an employee is not on Full tracking (own override or inherited).
function trackBadge(m) {
  if (m === 'EXCLUDED') return ' <span class="tag t-off" title="Do Not Track — the agent captures nothing on this person\'s PCs">Not tracked</span>';
  if (m === 'PRESENCE_ONLY') return ' <span class="tag t-warn" title="Presence &amp; breaks only — no screenshots or activity">Presence only</span>';
  return '';
}
async function loadEmployees() {
  const q = $('#emp-q').value.trim();
  try {
    const d = await api('/employees?per_page=200' + (q ? '&q=' + encodeURIComponent(q) : ''));
    const rows = d.data || [];
    if (!q) EMP_CACHE = rows; // refresh picker cache on unfiltered loads
    if (EMP_DEV_COUNTS === null) {
      EMP_DEV_COUNTS = {};
      try {
        (await devicesList()).forEach((dev) => {
          if (dev.employee_id) EMP_DEV_COUNTS[dev.employee_id] = (EMP_DEV_COUNTS[dev.employee_id] || 0) + 1;
        });
      } catch (e) { /* devices list may be role-gated; counts stay 0 */ }
    }
    $('#emp-rows').innerHTML = rows.map((e) => {
      const st = { ACTIVE: 't-ok', ON_LEAVE: 't-warn', RELIEVED: 't-off' }[e.employment_status] || 't-off';
      return '<tr class="clk" data-id="' + e.id + '" data-name="' + esc(fullName(e)) + '">'
        + '<td>' + esc(e.employee_code) + '</td>'
        + '<td><span class="nm">' + esc(fullName(e)) + '</span>' + trackBadge(e.tracking_mode) + '</td>'
        + '<td>' + esc(e.email || '—') + '</td>'
        + '<td>' + esc(e.department?.name || '—') + '</td><td>' + esc(e.team?.name || '—') + '</td>'
        + '<td>' + esc(e.shift?.name || '—') + '</td>'
        + '<td><span class="tag ' + st + '">' + esc(e.employment_status || '—') + '</span></td>'
        + '<td>' + (EMP_DEV_COUNTS[e.id] || 0) + '</td>'
        + '<td class="row" style="flex-wrap:nowrap">'
        + '<button class="btn" data-act="edit">Edit</button>'
        + (e.employment_status !== 'RELIEVED' ? '<button class="btn danger" data-act="relieve">Relieve</button>' : '')
        + '<button class="btn danger" data-act="del">Delete</button></td></tr>';
    }).join('') || '<tr><td colspan="9" class="mut">No employees' + (q ? ' matching "' + esc(q) + '"' : '') + '.</td></tr>';
  } catch (e) {
    $('#emp-rows').innerHTML = isDenied(e) ? deniedCard() : '<tr><td colspan="9" class="mut">' + esc(e.message) + '</td></tr>';
  }
}
$('#emp-q').addEventListener('input', () => {
  clearTimeout(EMP_SEARCH_TIMER);
  EMP_SEARCH_TIMER = setTimeout(loadEmployees, 300);
});
$('#emp-rows').addEventListener('click', async (e) => {
  const tr = e.target.closest('tr[data-id]');
  if (!tr) return;
  const id = Number(tr.dataset.id);
  const act = e.target.closest('[data-act]');
  if (act && act.dataset.act === 'edit') { openEmpModal(id); return; }
  if (act && act.dataset.act === 'relieve') {
    const reason = prompt('Relieve ' + tr.dataset.name + '?\n\nThis disables their login, stops the monitoring agent on all their devices and frees the licence seats.\n\nEnter the reason (required, kept in the audit log):');
    if (reason === null) return;
    if (!reason.trim()) { alert('A reason is required.'); return; }
    try {
      await api('/employees/' + id + '/relieve', { method: 'POST', body: JSON.stringify({ reason: reason.trim() }) });
      EMP_CACHE = null; DEV_CACHE = null;
      loadEmployees();
    } catch (err) { alert(err.message); }
    return;
  }
  if (act && act.dataset.act === 'del') {
    if (!confirm('Delete ' + tr.dataset.name + '? This removes the employee record (activity history is retained per policy).')) return;
    try {
      await api('/employees/' + id, { method: 'DELETE' });
      EMP_CACHE = null; EMP_DEV_COUNTS = null;
      loadEmployees();
    } catch (err) { alert(err.message); }
    return;
  }
  openEmployee(id, tr.dataset.name);
});
async function openEmpModal(id) {
  EMP_EDIT_ID = id || null;
  $('#emp-m-title').textContent = id ? 'Edit employee' : 'Add employee';
  $('#emp-m-err').textContent = '';
  const org = await orgLists();
  fillSelect($('#f-branch'), org.branches, (r) => r.name, (r) => r.id, '— none —');
  fillSelect($('#f-dept'), org.departments, (r) => r.name, (r) => r.id, '— none —');
  fillSelect($('#f-team'), org.teams, (r) => r.name, (r) => r.id, '— none —');
  fillSelect($('#f-desig'), org.designations, (r) => r.name, (r) => r.id, '— none —');
  fillSelect($('#f-shift'), org.shifts, (r) => r.name + (r.start_time ? ' (' + String(r.start_time).slice(0, 5) + '–' + String(r.end_time || '').slice(0, 5) + ')' : ''), (r) => r.id, '— none —');
  const set = (sel, v) => { $(sel).value = v ?? ''; };
  if (id) {
    try {
      const d = await api('/employees/' + id);
      const e = d.data;
      set('#f-first', e.first_name); set('#f-last', e.last_name); set('#f-code', e.employee_code);
      set('#f-email', e.email); set('#f-mobile', e.mobile); set('#f-status', e.employment_status || 'ACTIVE');
      set('#f-branch', e.branch_id); set('#f-dept', e.department_id); set('#f-team', e.team_id);
      set('#f-desig', e.designation_id); set('#f-shift', e.shift_id);
      set('#f-doj', e.date_of_joining ? String(e.date_of_joining).slice(0, 10) : '');
      set('#f-bio', e.biometric_id);
      set('#f-track', e.tracking_mode || '');
    } catch (err) { $('#emp-m-err').textContent = err.message; }
  } else {
    ['#f-first', '#f-last', '#f-code', '#f-email', '#f-mobile', '#f-doj', '#f-bio'].forEach((s) => set(s, ''));
    set('#f-status', 'ACTIVE');
    ['#f-branch', '#f-dept', '#f-team', '#f-desig', '#f-shift', '#f-track'].forEach((s) => set(s, ''));
  }
  $('#emp-ovl').classList.add('open');
}
$('#emp-add').onclick = () => openEmpModal(null);

// ---- API & integrations (17-Jul) ----
async function initIntegrations() { loadKeys(); loadTargets(); renderApiDocs(); }
async function loadKeys() {
  try {
    const d = await api('/integrations/keys');
    $('#key-rows').innerHTML = (d.data || []).length ? d.data.map((k) =>
      '<tr><td><b>' + esc(k.name) + '</b></td><td><code>' + esc(k.prefix) + '…</code></td>'
      + '<td class="mut">' + esc((k.scopes || []).join(', ') || 'all') + '</td>'
      + '<td class="mut">' + (k.last_used_at ? esc(k.last_used_at) : 'never') + '</td>'
      + '<td>' + (k.active ? '<span class="tag t-ok">active</span>' : '<span class="tag t-off">revoked</span>') + '</td>'
      + '<td>' + (k.active ? '<button class="btn danger" onclick="revokeKey(' + k.id + ')">Revoke</button>' : '') + '</td></tr>'
    ).join('') : '<tr><td colspan="6" class="mut">No keys yet. Create one for SmartPRS or a gate device.</td></tr>';
  } catch (e) { $('#key-rows').innerHTML = '<tr><td colspan="6" class="mut">' + esc(e.message) + '</td></tr>'; }
}
window.revokeKey = async (id) => {
  if (!confirm('Revoke this key? Any app using it stops working immediately.')) return;
  try { await api('/integrations/keys/' + id + '/revoke', { method: 'POST' }); toast('Key revoked'); loadKeys(); }
  catch (e) { toast(e.message); }
};
$('#key-add').onclick = () => {
  $('#key-body').innerHTML = '<label>Key name</label><input id="key-name" placeholder="e.g. SmartPRS Production">'
    + '<label style="margin-top:10px">What can this key do?</label>'
    + '<div class="fbool"><input type="checkbox" id="key-ingest" checked> Push data IN (ingest attendance)</div>'
    + '<div class="fbool"><input type="checkbox" id="key-read" checked> Read data OUT (attendance)</div>'
    + '<div class="err" id="key-err"></div>';
  $('#key-foot').innerHTML = '<button class="btn" onclick="document.getElementById(\'key-ovl\').classList.remove(\'open\')">Cancel</button>'
    + '<button class="btn solid" id="key-create">Create key</button>';
  $('#key-ovl').classList.add('open');
  $('#key-create').onclick = async () => {
    const scopes = []; if ($('#key-ingest').checked) scopes.push('ingest'); if ($('#key-read').checked) scopes.push('read');
    if (!$('#key-name').value.trim()) { $('#key-err').textContent = 'Give the key a name.'; return; }
    try {
      const r = await api('/integrations/keys', { method: 'POST', body: JSON.stringify({ name: $('#key-name').value.trim(), scopes }) });
      $('#key-body').innerHTML = '<div class="never" style="background:var(--ok-w);border-color:#B6E5CE">'
        + '<b style="color:var(--ok)">Key created — copy it now, it is shown only once:</b>'
        + '<div style="margin-top:8px;word-break:break-all;background:#fff;border:1px solid var(--border);border-radius:8px;padding:10px;font-family:monospace;font-size:12px" id="key-secret">' + esc(r.secret) + '</div>'
        + '<button class="btn acc" style="margin-top:8px" onclick="navigator.clipboard.writeText(document.getElementById(\'key-secret\').textContent);toast(\'Copied\')">Copy key</button></div>';
      $('#key-foot').innerHTML = '<button class="btn solid" onclick="document.getElementById(\'key-ovl\').classList.remove(\'open\');loadKeys()">Done</button>';
    } catch (e) { $('#key-err').textContent = e.message; }
  };
};
$('#key-x').onclick = () => $('#key-ovl').classList.remove('open');

async function loadTargets() {
  try {
    const d = await api('/integrations/targets');
    $('#tgt-rows').innerHTML = (d.data || []).length ? d.data.map((t) =>
      '<tr><td><b>' + esc(t.name) + '</b>' + (t.active ? '' : ' <span class="tag t-off">off</span>') + '</td>'
      + '<td class="mut" style="max-width:200px;overflow:hidden;text-overflow:ellipsis">' + esc(t.url) + (((t.events||[]).includes('attendance.punch')) ? '<div style="color:var(--accent-ink);font-weight:700">⚡ real-time punches</div>' : '') + '</td>'
      + '<td class="mut">' + (t.last_pushed_at ? esc(t.last_pushed_at) : '—') + '</td>'
      + '<td class="mut">' + esc(t.last_status || '—') + '</td>'
      + '<td style="white-space:nowrap"><button class="btn" onclick="pushTarget(' + t.id + ')">Test push</button> '
      + '<button class="btn" onclick=\'editTarget(' + JSON.stringify(t).replace(/'/g, "&#39;") + ')\'>Edit</button> '
      + '<button class="btn danger" onclick="delTarget(' + t.id + ')">Delete</button></td></tr>'
    ).join('') : '<tr><td colspan="5" class="mut">No targets yet. Add SmartPRS to push attendance there nightly.</td></tr>';
  } catch (e) { $('#tgt-rows').innerHTML = '<tr><td colspan="5" class="mut">' + esc(e.message) + '</td></tr>'; }
}
let TGT_EDIT = null;
function openTarget(t) {
  TGT_EDIT = t ? t.id : null;
  $('#tgt-title').textContent = t ? 'Edit target' : 'Add outbound target';
  $('#tgt-name').value = t ? t.name : ''; $('#tgt-url').value = t ? t.url : '';
  $('#tgt-secret').value = ''; $('#tgt-active').checked = t ? !!t.active : true; $('#tgt-err').textContent = '';
  const ev = t && t.events ? t.events : ['attendance.daily'];
  $('#tgt-events').value = (ev.includes('attendance.daily') && ev.includes('attendance.punch')) ? 'both'
    : ev.includes('attendance.punch') ? 'attendance.punch' : 'attendance.daily';
  $('#tgt-ovl').classList.add('open');
}
window.editTarget = (t) => openTarget(t);
$('#tgt-add').onclick = () => openTarget(null);
$('#tgt-x').onclick = $('#tgt-cancel').onclick = () => $('#tgt-ovl').classList.remove('open');
$('#tgt-save').onclick = async () => {
  const mode = $('#tgt-events').value;
  const events = mode === 'both' ? ['attendance.daily', 'attendance.punch'] : [mode];
  const body = { name: $('#tgt-name').value.trim(), url: $('#tgt-url').value.trim(),
    secret: $('#tgt-secret').value.trim() || null, active: $('#tgt-active').checked, events };
  if (!body.name || !body.url) { $('#tgt-err').textContent = 'Name and URL are required.'; return; }
  try {
    if (TGT_EDIT) await api('/integrations/targets/' + TGT_EDIT, { method: 'PUT', body: JSON.stringify(body) });
    else await api('/integrations/targets', { method: 'POST', body: JSON.stringify(body) });
    $('#tgt-ovl').classList.remove('open'); toast('Saved'); loadTargets();
  } catch (e) { $('#tgt-err').textContent = e.message; }
};
window.pushTarget = async (id) => {
  toast('Pushing yesterday\'s attendance…');
  try { const r = await api('/integrations/targets/' + id + '/push', { method: 'POST', body: JSON.stringify({}) });
    toast((r.ok ? '✓ ' : '✕ ') + r.status + ' · ' + r.records + ' records'); loadTargets(); }
  catch (e) { toast(e.message); }
};
window.delTarget = async (id) => { if (!confirm('Delete this target?')) return;
  try { await api('/integrations/targets/' + id, { method: 'DELETE' }); toast('Deleted'); loadTargets(); } catch (e) { toast(e.message); } };

function renderApiDocs() {
  const base = location.origin + '/api/v1';
  $('#api-docs').innerHTML =
    '<h5 style="color:var(--accent-ink);margin:0 0 6px">Base URL</h5><code>' + esc(base) + '</code>'
    + '<h5 style="color:var(--accent-ink);margin:14px 0 6px">Authentication</h5>'
    + 'Send your API key on every request:<br><code>Authorization: Bearer sk_live_xxxxxxxx</code> &nbsp;(or <code>X-Api-Key: sk_live_...</code>)'
    + '<h5 style="color:var(--accent-ink);margin:14px 0 6px">1 · Verify a key</h5>'
    + '<code>GET ' + esc(base) + '/ping</code>'
    + '<h5 style="color:var(--accent-ink);margin:14px 0 6px">2 · Push punches IN (ingest scope)</h5>'
    + '<code>POST ' + esc(base) + '/attendance/punches</code>'
    + '<pre style="background:var(--card-2);border:1px solid var(--border);border-radius:8px;padding:10px;overflow:auto;font-size:11.5px;margin-top:6px">'
    + esc(JSON.stringify({ punches: [{ employee_code: 'EMP001', punch_type: 'IN', punched_at: '2026-07-17T09:15:00+05:30', source: 'gate-1' }, { employee_code: 'EMP001', punch_type: 'OUT', punched_at: '2026-07-17T18:05:00+05:30' }] }, null, 2)) + '</pre>'
    + 'Rule: earliest IN / latest OUT wins per day; unknown employee_codes are returned so you can fix them.'
    + '<h5 style="color:var(--accent-ink);margin:14px 0 6px">3 · Read attendance OUT (read scope)</h5>'
    + '<code>GET ' + esc(base) + '/attendance?date=2026-07-17&employee_code=EMP001</code><br>'
    + 'Returns per-employee first_in, last_out, worked_seconds, break_seconds, status.'
    + '<h5 style="color:var(--accent-ink);margin:14px 0 6px">Outbound (SmartEPT → your app)</h5>'
    + 'Add a target above with your endpoint URL + a shared secret. Every night (and on Test push) SmartEPT POSTs:'
    + '<pre style="background:var(--card-2);border:1px solid var(--border);border-radius:8px;padding:10px;overflow:auto;font-size:11.5px;margin-top:6px">'
    + esc(JSON.stringify({ event: 'attendance.daily', date: '2026-07-17', count: 2, records: [{ employee_code: 'EMP001', work_date: '2026-07-17', first_in: '…', last_out: '…', worked_seconds: 27300, break_seconds: 1320, status: 'PRESENT' }] }, null, 2)) + '</pre>'
    + 'We sign the raw body: <code>X-SmartEPT-Signature: hex(hmac_sha256(body, secret))</code> — verify it before trusting the data.'
    + '<h5 style="color:var(--accent-ink);margin:14px 0 6px">Real-time IN/OUT (biometric-device mode → SmartPRS)</h5>'
    + 'Set a target to <b>Real-time punches</b>. Then every login/unlock (IN) and logout/lock (OUT) is POSTed the instant it happens — SmartEPT acts like a punch device:'
    + '<pre style="background:var(--card-2);border:1px solid var(--border);border-radius:8px;padding:10px;overflow:auto;font-size:11.5px;margin-top:6px">'
    + esc(JSON.stringify({ event: 'attendance.punch', device_id: 'SMARTEPT-ab12cd34', employee_code: 'EMP001', biometric_employee_id: 'EMP001', punch_type: 'IN', punched_at: '2026-07-17T09:15:00+05:30', verification_mode: 'SYSTEM', source: 'AGENT' }, null, 2)) + '</pre>'
    + 'Same signature header. Point this at your SmartPRS punch-ingest endpoint and attendance flows straight through.';
}

// ---- organisation management (17-Jul) ----// ---- organisation management (17-Jul) ----
const TZ_LIST = ['UTC',
  'America/Los_Angeles','America/Denver','America/Chicago','America/New_York','America/Toronto','America/Mexico_City','America/Bogota','America/Sao_Paulo','America/Argentina/Buenos_Aires',
  'Europe/London','Europe/Dublin','Europe/Lisbon','Europe/Paris','Europe/Madrid','Europe/Berlin','Europe/Rome','Europe/Amsterdam','Europe/Zurich','Europe/Athens','Europe/Istanbul','Europe/Moscow',
  'Africa/Casablanca','Africa/Lagos','Africa/Cairo','Africa/Nairobi','Africa/Johannesburg',
  'Asia/Jerusalem','Asia/Riyadh','Asia/Dubai','Asia/Karachi','Asia/Kolkata','Asia/Colombo','Asia/Kathmandu','Asia/Dhaka','Asia/Bangkok','Asia/Jakarta','Asia/Singapore','Asia/Kuala_Lumpur','Asia/Hong_Kong','Asia/Shanghai','Asia/Taipei','Asia/Manila','Asia/Tokyo','Asia/Seoul',
  'Australia/Perth','Australia/Sydney','Pacific/Auckland'];
const ORG_DEFS = {
  branches:     { label: 'Branch',      cols: ['name','code','city','state','timezone'],
                  fields: [['name','Name','text',1],['code','Code','text'],['city','City','text'],['state','State','text'],['timezone','Time zone (overrides company default)','tz'],['tracking_mode','Tracking mode for this branch','trackmode']] },
  departments:  { label: 'Department',  cols: ['name','code','branch'],
                  fields: [['name','Name','text',1],['code','Code','text'],['branch_id','Branch','select:branches'],['tracking_mode','Tracking mode for this department','trackmode']] },
  teams:        { label: 'Team',        cols: ['name','code','department'],
                  fields: [['name','Name','text',1],['code','Code','text'],['department_id','Department','select:departments'],['tracking_mode','Tracking mode for this team','trackmode']] },
  designations: { label: 'Designation', cols: ['name','code','level'],
                  fields: [['name','Name','text',1],['code','Code','text'],['level','Level (0=junior)','num']] },
  shifts:       { label: 'Shift',       cols: ['name','code','timing'],
                  fields: [['name','Name','text',1],['code','Code','text'],['start_time','Start (HH:MM)','time'],['end_time','End (HH:MM)','time'],['grace_minutes','Grace (min)','num'],['break_minutes_allowed','Break allowed (min)','num']] },
};
let ORG_TAB = 'branches';
async function initOrg() {
  $$('#org-tabs .tab').forEach((t) => t.onclick = () => { ORG_TAB = t.dataset.org;
    $$('#org-tabs .tab').forEach((x) => x.classList.toggle('active', x === t)); renderOrg(); });
  if (!window.ATTMODE_INIT) {
    window.ATTMODE_INIT = true;
    ((ME && ME.attendance_mode) === 'AGENT_ONLY' ? $('#attmode-agent') : $('#attmode-bio')).checked = true;
    $('#attmode-save').onclick = async () => {
      const mode = $('#attmode-agent').checked ? 'AGENT_ONLY' : 'BIOMETRIC';
      try {
        await api('/companies/' + ME.company_id, { method: 'PUT', body: JSON.stringify({ attendance_mode: mode }) });
        ME.attendance_mode = mode;
        applyAttendanceMode();
        toast('Attendance source saved');
        $('#attmode-msg').textContent = '✓ Saved.';
      } catch (e) { $('#attmode-msg').textContent = '✕ ' + e.message; }
    };
  }
  if (!window.COTZ_INIT) {
    window.COTZ_INIT = true;
    const sel = $('#co-tz');
    if (sel) {
      sel.innerHTML = TZ_LIST.map((z) => '<option value="' + z + '">' + z + '</option>').join('');
      (async () => {
        try { const c = (await api('/companies/' + ME.company_id)).data; if (c && c.timezone) sel.value = c.timezone; }
        catch (e) { const card = $('#co-tz-card'); if (card) card.style.display = 'none'; }
      })();
      $('#co-tz-save').onclick = async () => {
        try {
          await api('/companies/' + ME.company_id, { method: 'PUT', body: JSON.stringify({ timezone: sel.value }) });
          toast('Company time zone saved');
          $('#co-tz-msg').textContent = '✓ Saved — dashboards now use ' + sel.value + '.';
        } catch (e) { $('#co-tz-msg').textContent = '✕ ' + e.message; }
      };
    }
  }
  if (!window.COBRK_INIT) {
    window.COBRK_INIT = true;
    // Section 3: load & save this company's break-time limits.
    (async () => {
      try {
        const c = (await api('/companies/' + ME.company_id)).data;
        if (c) {
          if (c.break_limit_lunch_min) $('#brk-lunch').value = c.break_limit_lunch_min;
          if (c.break_limit_tea_min) $('#brk-tea').value = c.break_limit_tea_min;
          if (c.break_limit_other_min) $('#brk-other').value = c.break_limit_other_min;
        }
      } catch (e) { /* keep defaults */ }
    })();
    $('#brk-save').onclick = async () => {
      const body = {
        break_limit_lunch_min: Math.max(1, parseInt($('#brk-lunch').value, 10) || 30),
        break_limit_tea_min: Math.max(1, parseInt($('#brk-tea').value, 10) || 10),
        break_limit_other_min: Math.max(1, parseInt($('#brk-other').value, 10) || 10),
      };
      try {
        await api('/companies/' + ME.company_id, { method: 'PUT', body: JSON.stringify(body) });
        toast('Break limits saved');
        $('#brk-msg').textContent = '✓ Saved.';
      } catch (e) { $('#brk-msg').textContent = '✕ ' + e.message; }
    };
  }
  if (!window.COIPX_INIT) {
    window.COIPX_INIT = true;
    const box = $('#co-ipx');
    if (box) {
      (async () => {
        try { const c = (await api('/companies/' + ME.company_id)).data; box.checked = c ? c.exclude_ip_sites !== false : true; }
        catch (e) { const card = $('#co-ipx-card'); if (card) card.style.display = 'none'; }
      })();
      $('#co-ipx-save').onclick = async () => {
        try {
          await api('/companies/' + ME.company_id, { method: 'PUT', body: JSON.stringify({ exclude_ip_sites: box.checked }) });
          toast('Saved');
          $('#co-ipx-msg').textContent = '✓ Saved — applies after each PC’s agent next syncs its policy.';
        } catch (e) { $('#co-ipx-msg').textContent = '✕ ' + e.message; }
      };
    }
  }
  renderOrg();
}
async function renderOrg() {
  const isRoles = ORG_TAB === 'roles';
  $('#roles-card').classList.toggle('hide', !isRoles);
  $('#org-main-card').classList.toggle('hide', isRoles);
  if (isRoles) {
    loadRoles().catch((e) => { $('#role-rows').innerHTML = '<tr><td colspan="6" class="mut">' + esc(e.message) + '</td></tr>'; });
    return;
  }
  const def = ORG_DEFS[ORG_TAB];
  $('#org-title').childNodes[0].nodeValue = def.label + 's ';
  $('#org-head').innerHTML = '<tr>' + def.cols.map((c) => '<th>' + c[0].toUpperCase() + c.slice(1) + '</th>').join('') + '<th></th></tr>';
  $('#org-rows').innerHTML = '<tr><td colspan="' + (def.cols.length + 1) + '" class="mut">Loading…</td></tr>';
  const org = await orgLists(true);
  const rows = org[ORG_TAB] || [];
  const nameOf = (list, id) => { const r = (org[list] || []).find((x) => x.id === id); return r ? r.name : '—'; };
  $('#org-rows').innerHTML = rows.length ? rows.map((r) => {
    const cells = def.cols.map((c) => {
      if (c === 'branch') return esc(nameOf('branches', r.branch_id));
      if (c === 'department') return esc(nameOf('departments', r.department_id));
      if (c === 'timing') return r.start_time ? esc(String(r.start_time).slice(0,5) + '–' + String(r.end_time||'').slice(0,5)) : '—';
      return esc(r[c] != null && r[c] !== '' ? r[c] : '—');
    }).map((v) => '<td>' + v + '</td>').join('');
    return '<tr>' + cells + '<td style="white-space:nowrap">'
      + '<button class="btn" onclick="orgEdit(' + r.id + ')">Edit</button> '
      + '<button class="btn danger" onclick="orgDelete(' + r.id + ',&#39;' + esc(r.name) + '&#39;)">Delete</button></td></tr>';
  }).join('') : '<tr><td colspan="' + (def.cols.length + 1) + '" class="mut">No ' + def.label.toLowerCase() + 's yet — click + Add. (Bulk employee import also creates these automatically by name.)</td></tr>';
}
let ORG_EDIT_ID = null;
function orgField(f, val) {
  const [k, label, type, req] = f;
  const star = req ? ' <span style="color:var(--danger)">*</span>' : '';
  if (type && type.startsWith('select:')) {
    const list = (ORG_CACHE && ORG_CACHE[type.slice(7)]) || [];
    return '<label>' + label + star + '</label><select data-k="' + k + '"><option value="">— none —</option>'
      + list.map((r) => '<option value="' + r.id + '"' + (val == r.id ? ' selected' : '') + '>' + esc(r.name) + '</option>').join('') + '</select>';
  }
  if (type === 'tz') {
    return '<label>' + label + star + '</label><select data-k="' + k + '"><option value="">— company default —</option>'
      + TZ_LIST.map((z) => '<option value="' + z + '"' + (val === z ? ' selected' : '') + '>' + z + '</option>').join('') + '</select>';
  }
  if (type === 'trackmode') {
    const opts = [['', 'Inherit (from parent / company)'], ['FULL', 'Full — capture everything'],
      ['PRESENCE_ONLY', 'Presence & breaks only — no screenshots or activity'], ['EXCLUDED', 'Do Not Track — capture nothing']];
    return '<label>' + label + star + '</label><select data-k="' + k + '">'
      + opts.map((o) => '<option value="' + o[0] + '"' + ((val || '') === o[0] ? ' selected' : '') + '>' + o[1] + '</option>').join('') + '</select>';
  }
  const t = type === 'num' ? 'number' : type === 'time' ? 'time' : 'text';
  return '<label>' + label + star + '</label><input data-k="' + k + '" type="' + t + '" value="' + esc(val == null ? '' : val) + '">';
}
function orgOpen(row) {
  const def = ORG_DEFS[ORG_TAB];
  ORG_EDIT_ID = row ? row.id : null;
  $('#org-m-title').textContent = (row ? 'Edit ' : 'Add ') + def.label;
  $('#org-err').textContent = '';
  $('#org-form').innerHTML = '<div class="fgrid">' + def.fields.map((f) => {
    let v = row ? row[f[0]] : '';
    if ((f[2] === 'time') && v) v = String(v).slice(0,5);
    return '<div class="' + (f[3] ? 'full' : '') + '">' + orgField(f, v) + '</div>';
  }).join('') + '</div>';
  $('#org-ovl').classList.add('open');
}
window.orgEdit = async (id) => { const org = await orgLists(); orgOpen((org[ORG_TAB] || []).find((r) => r.id === id)); };
window.orgDelete = async (id, name) => {
  if (!confirm('Delete ' + ORG_DEFS[ORG_TAB].label + ' "' + name + '"? Employees mapped to it keep their record but lose this link.')) return;
  try { await api('/org/' + ORG_TAB + '/' + id, { method: 'DELETE' }); toast('Deleted'); ORG_CACHE = null; renderOrg(); }
  catch (e) { toast(e.message); }
};
$('#org-add').onclick = () => orgOpen(null);
$('#org-x').onclick = $('#org-cancel').onclick = () => $('#org-ovl').classList.remove('open');
$('#org-save').onclick = async () => {
  const body = {};
  $$('#org-form [data-k]').forEach((el) => {
    let v = el.value.trim();
    if (el.type === 'time' && v && v.length === 5) v = v + ':00';
    body[el.dataset.k] = v === '' ? null : (el.type === 'number' ? Number(v) : v);
  });
  if (!body.name) { $('#org-err').textContent = 'Name is required.'; return; }
  try {
    if (ORG_EDIT_ID) await api('/org/' + ORG_TAB + '/' + ORG_EDIT_ID, { method: 'PUT', body: JSON.stringify(body) });
    else await api('/org/' + ORG_TAB, { method: 'POST', body: JSON.stringify(body) });
    $('#org-ovl').classList.remove('open'); toast('Saved'); ORG_CACHE = null; renderOrg();
  } catch (e) { $('#org-err').textContent = e.message; }
};

// ---- roles & permission matrix (R4 item 5) ----
let ROLE_DATA = null, ROLE_EDIT = null;
async function loadRoles() {
  const d = await api('/roles');
  ROLE_DATA = d;
  $('#role-rows').innerHTML = (d.data || []).map((r) => '<tr>'
    + '<td><span class="nm">' + esc(r.name) + '</span></td>'
    + '<td>' + (r.is_system ? '<span class="tag t-info">SYSTEM</span>' : '<span class="tag t-ok">CUSTOM</span>') + '</td>'
    + '<td>' + esc((r.base_slug || (r.is_system ? '—' : '')) || '—') + '</td>'
    + '<td>' + (r.users_count ?? 0) + '</td>'
    + '<td>' + (r.locked ? 'All modules' : ((r.permission_ids || []).length + ' of ' + (d.permissions || []).length)) + '</td>'
    + '<td style="white-space:nowrap"><button class="btn" data-role-perm="' + r.id + '">' + (r.locked ? 'View' : 'Permissions') + '</button>'
    + (!r.is_system ? ' <button class="btn danger" data-role-del="' + r.id + '">Delete</button>' : '')
    + '</td></tr>').join('') || '<tr><td colspan="6" class="mut">No roles yet.</td></tr>';
}
function roleMatrixHtml(checkedIds, disabled) {
  const groups = {};
  (ROLE_DATA.permissions || []).forEach((p) => { (groups[p.group] = groups[p.group] || []).push(p); });
  return Object.keys(groups).map((g) => '<div style="margin-bottom:10px"><b style="font-size:12px;color:var(--accent-ink)">' + esc(g) + '</b>'
    + groups[g].map((p) => '<div class="fbool"><input type="checkbox" data-perm="' + p.id + '"' + (checkedIds.includes(p.id) ? ' checked' : '') + (disabled ? ' disabled' : '') + '> ' + esc(p.name) + '</div>').join('')
    + '</div>').join('');
}
function openRoleModal(role) {
  ROLE_EDIT = role || null;
  $('#role-m-title').textContent = role ? role.name : 'Add custom role';
  $('#role-m-sub').textContent = role
    ? (role.locked ? 'Always full access' : (role.is_system ? 'System role — tune its module permissions' : 'Custom role'))
    : 'Inherits screen access from the base role, starts with its permissions';
  const baseSel = $('#role-base');
  baseSel.innerHTML = (ROLE_DATA.bases || []).map((b) => '<option value="' + b + '">' + b.replace(/_/g, ' ') + '</option>').join('');
  $('#role-name').value = role ? role.name : '';
  if (role && role.base_slug) baseSel.value = role.base_slug;
  $('#role-name-wrap').style.display = role && role.is_system ? 'none' : '';
  $('#role-matrix').innerHTML = roleMatrixHtml(role ? (role.permission_ids || []) : [], !!(role && role.locked));
  $('#role-save').classList.toggle('hide', !!(role && role.locked));
  $('#role-err').textContent = '';
  $('#role-ovl').classList.add('open');
}
$('#role-add').onclick = () => openRoleModal(null);
$('#role-x').onclick = $('#role-cancel').onclick = () => $('#role-ovl').classList.remove('open');
$('#role-save').onclick = async () => {
  const permIds = $$('#role-matrix [data-perm]').filter((c) => c.checked).map((c) => Number(c.dataset.perm));
  try {
    let id = ROLE_EDIT ? ROLE_EDIT.id : null;
    if (!ROLE_EDIT) {
      const name = $('#role-name').value.trim();
      if (!name) { $('#role-err').textContent = 'Role name is required.'; return; }
      const r = await api('/roles', { method: 'POST', body: JSON.stringify({ name, base_slug: $('#role-base').value }) });
      id = r.data.id;
    } else if (!ROLE_EDIT.is_system) {
      await api('/roles/' + id, { method: 'PUT', body: JSON.stringify({ name: $('#role-name').value.trim() || ROLE_EDIT.name, base_slug: $('#role-base').value }) });
    }
    if (!(ROLE_EDIT && ROLE_EDIT.locked)) {
      await api('/roles/' + id + '/permissions', { method: 'PUT', body: JSON.stringify({ permission_ids: permIds }) });
    }
    $('#role-ovl').classList.remove('open');
    toast('Role saved');
    loadRoles();
  } catch (e) { $('#role-err').textContent = e.message; }
};
$('#role-rows').addEventListener('click', async (ev) => {
  const pBtn = ev.target.closest('[data-role-perm]');
  const dBtn = ev.target.closest('[data-role-del]');
  const find = (id) => (ROLE_DATA.data || []).find((x) => x.id === Number(id));
  if (pBtn) openRoleModal(find(pBtn.dataset.rolePerm));
  if (dBtn) {
    const r = find(dBtn.dataset.roleDel);
    if (!r) return;
    if (!confirm('Delete role "' + r.name + '"? Users must be reassigned first.')) return;
    try { await api('/roles/' + r.id, { method: 'DELETE' }); toast('Deleted'); loadRoles(); }
    catch (e) { toast(e.message); }
  }
});

// ---- bulk import (17-Jul) ----
const EMP_CSV_HEADER = 'employee_code,first_name,last_name,email,mobile,department,team,branch,designation,shift,date_of_joining,biometric_id';
$('#emp-template').onclick = () => {
  const sample = EMP_CSV_HEADER + '\n'
    + 'E-2001,Rahul,Sharma,rahul.sharma@company.com,9848012345,Operations,Team A,Head Office,Executive,General Shift,2026-07-01,\n'
    + 'E-2002,Sneha,Iyer,sneha.iyer@company.com,9848067890,Sales,Team B,Head Office,Manager,General Shift,2026-07-01,BIO-114';
  const blob = new Blob([sample], { type: 'text/csv' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob); a.download = 'smartept-employees-template.csv';
  a.click(); URL.revokeObjectURL(a.href);
};
$('#emp-import').onclick = () => {
  $('#import-file').value = ''; $('#import-result').innerHTML = ''; $('#import-run').disabled = true;
  $('#import-ovl').classList.add('open');
};
$('#import-x').onclick = () => $('#import-ovl').classList.remove('open');
function importForm(dry) {
  const f = $('#import-file').files[0];
  if (!f) { $('#import-result').innerHTML = '<span style="color:var(--danger)">Choose a CSV file first.</span>'; return null; }
  const fd = new FormData();
  fd.append('file', f);
  fd.append('dry_run', dry ? '1' : '0');
  fd.append('create_login', $('#import-login').checked ? '1' : '0');
  return fd;
}
async function runImport(dry) {
  const fd = importForm(dry); if (!fd) return;
  $('#import-result').innerHTML = '<span class="mut">' + (dry ? 'Checking…' : 'Importing…') + '</span>';
  try {
    const r = await api('/employees/bulk-import', { method: 'POST', body: fd });
    const su = r.summary || {};
    const bad = (r.results || []).filter((x) => !x.ok);
    let html = '<div class="' + (bad.length ? 'never' : '') + '" style="' + (bad.length ? '' : 'background:var(--ok-w);border:1px solid #B6E5CE;border-radius:10px;padding:12px') + '">'
      + '<b style="color:' + (bad.length ? 'var(--danger)' : 'var(--ok)') + '">'
      + (dry ? 'Preview: ' : '✓ Imported: ') + (su.created || 0) + (dry ? ' rows ready' : ' created')
      + (su.failed ? ' · ' + su.failed + ' with problems' : '') + '</b>';
    if (bad.length) {
      html += '<div style="max-height:180px;overflow:auto;margin-top:8px;font-size:11.5px">'
        + bad.map((x) => 'Line ' + x.line + ' (' + esc(x.code || '—') + '): ' + esc(x.error)).join('<br>') + '</div>';
    }
    if (!dry && (r.credentials || []).length) {
      html += '<div style="margin-top:10px;font-size:11.5px"><b>Temp passwords (shown once — hand these out):</b><br>'
        + r.credentials.map((c) => esc(c.email) + ' → <code>' + esc(c.temp_password) + '</code>').join('<br>') + '</div>';
    }
    html += '</div>';
    $('#import-result').innerHTML = html;
    $('#import-run').disabled = dry ? false : true;
    if (!dry) { loadEmployees(); }
  } catch (e) { $('#import-result').innerHTML = '<span style="color:var(--danger)">' + esc(e.message) + '</span>'; }
}
$('#import-preview').onclick = () => runImport(true);
$('#import-run').onclick = () => runImport(false);
$('#import-file').onchange = () => { $('#import-run').disabled = true; $('#import-result').innerHTML = ''; };
$('#emp-m-save').onclick = async () => {
  $('#emp-m-err').textContent = '';
  const numOrNull = (s) => $(s).value ? Number($(s).value) : null;
  const body = {
    first_name: $('#f-first').value.trim(),
    last_name: $('#f-last').value.trim() || null,
    employee_code: $('#f-code').value.trim(),
    email: $('#f-email').value.trim() || null,
    mobile: $('#f-mobile').value.trim() || null,
    employment_status: $('#f-status').value,
    branch_id: numOrNull('#f-branch'),
    department_id: numOrNull('#f-dept'),
    team_id: numOrNull('#f-team'),
    designation_id: numOrNull('#f-desig'),
    shift_id: numOrNull('#f-shift'),
    date_of_joining: $('#f-doj').value || null,
    biometric_id: $('#f-bio').value.trim() || null,
    tracking_mode: $('#f-track').value || null,
  };
  try {
    if (EMP_EDIT_ID) {
      await api('/employees/' + EMP_EDIT_ID, { method: 'PUT', body: JSON.stringify(body) });
    } else {
      const r = await api('/employees', { method: 'POST', body: JSON.stringify(body) });
      // A self-service login may have been auto-created — surface its one-time password.
      if (r && r.temp_password) showCredentials((r.login && r.login.email) || body.email, r.temp_password);
    }
    $('#emp-ovl').classList.remove('open');
    EMP_CACHE = null;
    loadEmployees();
  } catch (err) { $('#emp-m-err').textContent = err.message; }
};

// ---- 6. devices ----
async function loadAgentLock() {
  try {
    const a = (await api('/ops/agent-lock')).data;
    $('#al-enabled').checked = !!a.enabled;
    $('#al-pass').value = '';
    $('#al-pass').placeholder = a.password_set ? 'Password is set — leave blank to keep' : 'Enter a password';
    const m = $('#al-msg'); m.style.color = ''; m.textContent = a.password_set ? 'A password is set.' : 'No password set yet.';
  } catch (e) { const m = $('#al-msg'); if (m) { m.textContent = e.message; m.style.color = '#DC2626'; } }
}
async function saveAgentLock(clear) {
  const m = $('#al-msg'); m.style.color = '';
  try {
    await api('/ops/agent-lock', { method: 'PUT', body: JSON.stringify({
      enabled: $('#al-enabled').checked,
      password: $('#al-pass').value || null,
      clear: !!clear,
    }) });
    toast(clear ? 'Lock password cleared' : 'Agent lock saved');
    loadAgentLock();
  } catch (e) { m.textContent = e.message; m.style.color = '#DC2626'; }
}
// Section 10: a small session pill next to the activity status. "Signed in" only when
// the login session is ACTIVE and a heartbeat has landed within the stale window.
function devSessionTag(v) {
  if (!v.session_status || v.session_status === 'LOGGED_OUT') return '';
  if (v.session_status === 'FORCE_LOGOUT') return ' <span class="tag t-danger">forced out</span>';
  const recent = v.last_heartbeat_at && (Date.now() - new Date(v.last_heartbeat_at).getTime()) < 10 * 60 * 1000;
  return recent ? ' <span class="tag t-ok">signed in</span>' : ' <span class="tag t-warn">stale</span>';
}
async function loadDevices() {
  loadAgentLock();
  $('#al-save').onclick = () => saveAgentLock(false);
  $('#al-clear').onclick = () => saveAgentLock(true);
  try {
    const [list, health] = await Promise.all([
      devicesList(true),
      api('/dashboard/device-health').then((r) => r.data || []).catch(() => []),
    ]);
    const hByUuid = {};
    health.forEach((h) => { hByUuid[h.device_uuid] = h; });
    $('#dev-rows').innerHTML = list.map((v) => {
      const h = hByUuid[v.device_uuid] || v; // merge freshest health snapshot
      const hc = { HEALTHY: 't-ok', DEGRADED: 't-warn', STOPPED: 't-danger' }[h.agent_health] || 't-off';
      const cc = { COMPLIANT: 't-ok', WARNING: 't-warn', NON_COMPLIANT: 't-danger', CRITICAL: 't-danger' }[h.compliance_status] || 't-off';
      const sc = { ONLINE: 't-ok', IDLE: 't-idle', AWAY: 't-warn', OFFLINE: 't-off' }[h.current_status] || 't-off';
      return '<tr data-devid="' + v.id + '" data-devname="' + esc(v.computer_name || v.device_uuid) + '"><td><b>' + esc(v.computer_name || v.device_uuid) + '</b></td>'
        + '<td>' + esc(fullName(v.employee) || '—') + '</td>'
        + '<td>' + esc(v.os_version || '—') + '</td><td>' + esc(h.app_version || v.app_version || '—') + '</td>'
        + '<td><span class="tag ' + hc + '">' + esc(h.agent_health || '—') + '</span></td>'
        + '<td><span class="tag ' + cc + '">' + esc(h.compliance_status || '—') + '</span></td>'
        + '<td>' + (v.unbound_at ? '<span class="tag t-danger">UNBOUND</span>' : '<span class="tag ' + sc + '">' + esc(h.current_status || '—') + '</span>' + devSessionTag(v)) + '</td>'
        + '<td>' + (h.sync_pending_count || 0) + '</td><td>' + dt(h.last_heartbeat_at) + '</td>'
        + '<td>' + (v.unbound_at
            ? '<button class="btn" data-devact="rebind">Approve re-bind</button>'
            : ((v.session_status === 'ACTIVE' ? '<button class="btn" data-devact="force-logout">Force logout</button> ' : '') + '<button class="btn danger" data-devact="unbind">Unbind</button>')) + '</td></tr>';
    }).join('') || '<tr><td colspan="10" class="mut">No devices registered. Devices appear when the SmartEPT desktop agent registers on an employee\'s PC.</td></tr>';
    attachTableFilter($('#dev-q'), '#dev-rows');
  } catch (e) {
    $('#dev-rows').innerHTML = isDenied(e) ? deniedCard() : '<tr><td colspan="10" class="mut">' + esc(e.message) + '</td></tr>';
  }
}
$('#dev-rows').addEventListener('click', async (e) => {
  const btn = e.target.closest('[data-devact]');
  if (!btn) return;
  const tr = e.target.closest('tr[data-devid]');
  const id = Number(tr.dataset.devid);
  if (btn.dataset.devact === 'unbind') {
    if (!confirm('Unbind ' + tr.dataset.devname + '?\n\nThe agent on this PC stops immediately, its licence seat is freed, and it cannot re-register until you approve a re-bind.')) return;
    try { await api('/devices/' + id + '/unbind', { method: 'POST' }); DEV_CACHE = null; loadDevices(); }
    catch (err) { alert(err.message); }
  }
  if (btn.dataset.devact === 'rebind') {
    try { await api('/devices/' + id + '/rebind', { method: 'POST' }); DEV_CACHE = null; loadDevices(); }
    catch (err) { alert(err.message); }
  }
  if (btn.dataset.devact === 'force-logout') {
    if (!confirm('Force logout ' + tr.dataset.devname + '?\n\nThe agent on this PC returns to the sign-in screen at its next heartbeat. The employee can sign in again on any PC (the licence seat is kept).')) return;
    try { await api('/devices/' + id + '/force-logout', { method: 'POST' }); DEV_CACHE = null; loadDevices(); }
    catch (err) { alert(err.message); }
  }
});

// ---- 7. policies ----
const POLICY_TYPES = [
  ['monitoring', 'Monitoring (master)'], ['screenshot', 'Screenshot'], ['webcam', 'Webcam presence'],
  ['application', 'Application'], ['website', 'Website'], ['network', 'Network'],
  ['device', 'Device compliance'], ['usb', 'USB'], ['vpn_proxy', 'VPN / Proxy'],
  ['break', 'Break'], ['attendance', 'Attendance'], ['compliance', 'Compliance scoring'],
];
// Field schemas mirror the policy table columns (see policy migrations).
// t: text | bool | num | dec | time | select | list (comma-separated → JSON array) | json (raw JSON object)
const POLICY_FIELDS = {
  monitoring: [
    { k: 'name', l: 'Policy name', t: 'text', full: 1 },
    { k: 'description', l: 'Description', t: 'text', full: 1 },
    { k: 'tracking_enabled', l: 'Tracking enabled', t: 'bool' },
    { k: 'working_hours_only', l: 'Track working hours only', t: 'bool' },
    { k: 'working_start', l: 'Working start', t: 'time' },
    { k: 'working_end', l: 'Working end', t: 'time' },
    { k: 'tracking_interval_seconds', l: 'Tracking interval (sec)', t: 'num' },
    { k: 'idle_threshold_seconds', l: 'Idle threshold (sec)', t: 'num' },
    { k: 'away_threshold_seconds', l: 'Away threshold (sec)', t: 'num' },
    { k: 'data_retention_days', l: 'Data retention (days)', t: 'num' },
    { k: 'app_usage_enabled', l: 'App usage tracking', t: 'bool' },
    { k: 'website_usage_enabled', l: 'Website usage tracking', t: 'bool' },
    { k: 'network_compliance_enabled', l: 'Network compliance', t: 'bool' },
    { k: 'usb_tracking_enabled', l: 'USB tracking', t: 'bool' },
    { k: 'vpn_proxy_detection_enabled', l: 'VPN/proxy detection', t: 'bool' },
    { k: 'remote_access_detection_enabled', l: 'Remote-access detection', t: 'bool' },
    { k: 'employee_status_visible', l: 'Employee sees own status', t: 'bool' },
    { k: 'consent_required', l: 'Consent required', t: 'bool' },
    { k: 'is_active', l: 'Active', t: 'bool' },
  ],
  screenshot: [
    { k: 'name', l: 'Policy name', t: 'text', full: 1 },
    { k: 'enabled', l: 'Screenshots enabled', t: 'bool' },
    { k: 'interval_enabled', l: 'Timed captures (OFF = violations only)', t: 'bool' },
    { k: 'interval_seconds', l: 'Interval (sec)', t: 'num' },
    { k: 'random_enabled', l: 'Random captures', t: 'bool' },
    { k: 'on_violation', l: 'Capture on violation', t: 'bool' },
    { k: 'on_blocked_website', l: 'On blocked website', t: 'bool' },
    { k: 'on_blocked_app', l: 'On blocked app', t: 'bool' },
    { k: 'during_idle', l: 'Capture during idle', t: 'bool' },
    { k: 'active_work_only', l: 'Active work only', t: 'bool' },
    { k: 'blur_sensitive', l: 'Blur sensitive areas', t: 'bool' },
    { k: 'retention_days', l: 'Retention (days)', t: 'num' },
    { k: 'excluded_apps', l: 'Excluded apps (comma-separated)', t: 'list', full: 1 },
    { k: 'excluded_websites', l: 'Excluded websites (comma-separated)', t: 'list', full: 1 },
  ],
  webcam: [
    { k: 'name', l: 'Policy name', t: 'text', full: 1 },
    { k: 'presence_enabled', l: 'Presence detection (yes/no signal)', t: 'bool' },
    { k: 'photo_enabled', l: 'Presence photos', t: 'bool' },
    { k: 'photo_interval_seconds', l: 'Photo interval (sec)', t: 'num' },
    { k: 'photo_on_violation', l: 'Photo on violation', t: 'bool' },
    { k: 'photo_on_attendance', l: 'Photo on attendance', t: 'bool' },
    { k: 'face_confidence_threshold', l: 'Face confidence (0–1)', t: 'dec' },
    { k: 'away_threshold_seconds', l: 'Away threshold (sec)', t: 'num' },
    { k: 'camera_blocked_threshold_seconds', l: 'Camera-blocked threshold (sec)', t: 'num' },
    { k: 'multiple_face_threshold_seconds', l: 'Multiple-face threshold (sec)', t: 'num' },
    { k: 'photo_retention_days', l: 'Photo retention (days)', t: 'num' },
  ],
  application: [
    { k: 'name', l: 'Policy name', t: 'text', full: 1 },
    { k: 'allowed_apps', l: 'Allowed apps (comma-separated)', t: 'list', full: 1 },
    { k: 'blocked_apps', l: 'Blocked apps (comma-separated)', t: 'list', full: 1 },
    { k: 'categories', l: 'Categories (JSON: {"excel.exe":"PRODUCTIVE"})', t: 'json', full: 1 },
    { k: 'action_on_blocked', l: 'Action on blocked', t: 'select', opts: ['LOG', 'WARN', 'NOTIFY', 'SCREENSHOT', 'CLOSE'] },
  ],
  website: [
    { k: 'name', l: 'Policy name', t: 'text', full: 1 },
    { k: 'allowed_sites', l: 'Allowed sites (comma-separated)', t: 'list', full: 1 },
    { k: 'blocked_sites', l: 'Blocked sites (comma-separated)', t: 'list', full: 1 },
    { k: 'categories', l: 'Categories (JSON: {"youtube":"NON_PRODUCTIVE"})', t: 'json', full: 1 },
    { k: 'track_full_url', l: 'Track full URL', t: 'bool' },
    { k: 'action_on_blocked', l: 'Action on blocked', t: 'select', opts: ['LOG', 'WARN', 'NOTIFY', 'SCREENSHOT', 'BLOCK'] },
  ],
  network: [
    { k: 'name', l: 'Policy name', t: 'text', full: 1 },
    { k: 'allowed_public_ips', l: 'Allowed public IPs (comma-separated)', t: 'list', full: 1 },
    { k: 'allowed_lan_ranges', l: 'Allowed LAN ranges (comma-separated)', t: 'list', full: 1 },
    { k: 'allowed_ssids', l: 'Allowed Wi-Fi SSIDs (comma-separated)', t: 'list', full: 1 },
    { k: 'allowed_vpn_networks', l: 'Allowed VPN networks (comma-separated)', t: 'list', full: 1 },
    { k: 'remote_work_allowed', l: 'Remote work allowed', t: 'bool' },
    { k: 'block_unknown_network', l: 'Block unknown network', t: 'bool' },
    { k: 'action_on_unauthorized', l: 'Action on unauthorized', t: 'select', opts: ['LOG', 'WARN', 'NOTIFY', 'BLOCK_LOGIN', 'REQUIRE_APPROVAL'] },
  ],
  device: [
    { k: 'name', l: 'Policy name', t: 'text', full: 1 },
    { k: 'require_antivirus', l: 'Require antivirus', t: 'bool' },
    { k: 'require_firewall', l: 'Require firewall', t: 'bool' },
    { k: 'require_disk_encryption', l: 'Require disk encryption', t: 'bool' },
    { k: 'min_os_version', l: 'Minimum OS version', t: 'text' },
    { k: 'blocked_software', l: 'Blocked software (comma-separated)', t: 'list', full: 1 },
    { k: 'settings', l: 'Extra settings (JSON object)', t: 'json', full: 1 },
  ],
  usb: [
    { k: 'name', l: 'Policy name', t: 'text', full: 1 },
    { k: 'action', l: 'Action on USB insert', t: 'select', opts: ['LOG', 'ALERT', 'VIOLATION', 'SCREENSHOT', 'REQUIRE_APPROVAL', 'BLOCK_STORAGE'] },
    { k: 'allowed_device_classes', l: 'Allowed device classes (comma-separated)', t: 'list', full: 1 },
  ],
  vpn_proxy: [
    { k: 'name', l: 'Policy name', t: 'text', full: 1 },
    { k: 'approved_tools', l: 'Approved tools (comma-separated)', t: 'list', full: 1 },
    { k: 'blocked_tools', l: 'Blocked tools (comma-separated)', t: 'list', full: 1 },
    { k: 'action_on_unauthorized', l: 'Action on unauthorized', t: 'select', opts: ['ALERT', 'VIOLATION', 'SCREENSHOT'] },
  ],
  break: [
    { k: 'name', l: 'Policy name', t: 'text', full: 1 },
    { k: 'break_types', l: 'Break types (comma-separated, e.g. TEA, LUNCH)', t: 'list', full: 1 },
    { k: 'auto_detect_from_idle', l: 'Auto-detect from idle', t: 'bool' },
    { k: 'requires_approval', l: 'Requires approval', t: 'bool' },
  ],
  attendance: [
    { k: 'name', l: 'Policy name', t: 'text', full: 1 },
    { k: 'late_grace_minutes', l: 'Late grace (min)', t: 'num' },
    { k: 'early_logout_grace_minutes', l: 'Early-logout grace (min)', t: 'num' },
    { k: 'min_working_hours', l: 'Minimum working hours', t: 'num' },
    { k: 'attendance_sources', l: 'Sources (comma-separated, e.g. AGENT, BIOMETRIC)', t: 'list', full: 1 },
    { k: 'settings', l: 'Extra settings (JSON object)', t: 'json', full: 1 },
  ],
  compliance: [
    { k: 'name', l: 'Policy name', t: 'text', full: 1 },
    { k: 'description', l: 'Description', t: 'text', full: 1 },
    { k: 'settings', l: 'Rules (JSON: per-violation severity, action, score penalty)', t: 'json', full: 1 },
    { k: 'is_active', l: 'Active', t: 'bool' },
  ],
};
let POL_LIST = [], POL_EDIT_ID = null;

function initPolicies() {
  const sel = $('#pol-type');
  if (sel.options.length === 0) {
    fillSelect(sel, POLICY_TYPES, (p) => p[1], (p) => p[0]);
    sel.addEventListener('change', () => { POL_EDIT_ID = null; loadPolicies(); });
  }
  loadPolicies();
  initAssignPanel();
}
async function loadPolicies() {
  const type = $('#pol-type').value;
  renderPolicyForm(type, null);
  try {
    const d = await api('/policies/' + type);
    POL_LIST = d.data || [];
    $('#pol-rows').innerHTML = POL_LIST.map((p) => '<tr>'
      + '<td><b>' + esc(p.name) + '</b></td><td><span class="tag t-info">v' + (p.version ?? 1) + '</span></td>'
      + '<td>' + dt(p.updated_at) + '</td>'
      + '<td class="row" style="flex-wrap:nowrap"><button class="btn" data-pol-edit="' + p.id + '">Edit</button>'
      + '<button class="btn danger" data-pol-del="' + p.id + '">Delete</button></td></tr>').join('')
      || '<tr><td colspan="4" class="mut">No ' + esc(type.replace('_', '/')) + ' policies yet — create the first one on the right.</td></tr>';
  } catch (e) {
    POL_LIST = [];
    $('#pol-rows').innerHTML = isDenied(e) ? deniedCard() : '<tr><td colspan="4" class="mut">' + esc(e.message) + '</td></tr>';
  }
  refreshAssignPolicyOptions();
}
function renderPolicyForm(type, policy) {
  POL_EDIT_ID = policy ? policy.id : null;
  const label = (POLICY_TYPES.find((p) => p[0] === type) || [type, type])[1];
  $('#pol-form-title').textContent = (policy ? 'Edit "' + policy.name + '" (v' + (policy.version ?? 1) + ' → v' + ((policy.version ?? 1) + 1) + ' on save)' : 'Create ' + label + ' policy');
  $('#pol-err').textContent = '';
  $('#pol-form').innerHTML = (POLICY_FIELDS[type] || []).map((f, i) => {
    const v = policy ? policy[f.k] : undefined;
    const wrap = (inner) => '<div' + (f.full ? ' class="full"' : '') + '>' + inner + '</div>';
    const id = 'pf-' + f.k;
    if (f.t === 'bool') {
      return wrap('<div class="fbool"><input type="checkbox" id="' + id + '"' + (v ? ' checked' : '') + '><label for="' + id + '" style="margin:0;cursor:pointer">' + esc(f.l) + '</label></div>');
    }
    if (f.t === 'select') {
      return wrap('<label>' + esc(f.l) + '</label><select id="' + id + '">'
        + f.opts.map((o) => '<option' + (v === o ? ' selected' : '') + '>' + esc(o) + '</option>').join('') + '</select>');
    }
    if (f.t === 'list') {
      const val = Array.isArray(v) ? v.join(', ') : '';
      return wrap('<label>' + esc(f.l) + '</label><input id="' + id + '" value="' + esc(val) + '">');
    }
    if (f.t === 'json') {
      const val = (v && typeof v === 'object') ? JSON.stringify(v, null, 1) : (typeof v === 'string' ? v : '');
      return wrap('<label>' + esc(f.l) + '</label><textarea id="' + id + '">' + esc(val) + '</textarea>');
    }
    const typeAttr = f.t === 'num' ? 'number' : f.t === 'dec' ? 'number" step="0.05' : f.t === 'time' ? 'time' : 'text';
    const val = v == null ? '' : (f.t === 'time' ? String(v).slice(0, 5) : String(v));
    return wrap('<label>' + esc(f.l) + '</label><input type="' + typeAttr + '" id="' + id + '" value="' + esc(val) + '">');
  }).join('') + polQuickPick(type);
}
const QUICK_LIB = {
  website: { field: 'blocked_sites', label: 'Common distraction sites — click to add to Blocked (agent warns + logs a violation on next heartbeat)',
    items: ['facebook.com', 'instagram.com', 'youtube.com', 'x.com', 'twitter.com', 'tiktok.com', 'reddit.com', 'netflix.com', 'hotstar.com', 'primevideo.com', 'web.whatsapp.com', 'telegram.org'] },
  application: { field: 'blocked_apps', label: 'Common distraction apps — click to add to Blocked (agent warns + logs a violation on next heartbeat)',
    items: ['whatsapp.exe', 'telegram.exe', 'discord.exe', 'steam.exe', 'epicgameslauncher.exe', 'spotify.exe', 'vlc.exe'] },
};
function polQuickPick(type) {
  const q = QUICK_LIB[type]; if (!q) return '';
  return '<div class="full quickpick"><div class="qp-l">' + esc(q.label) + '</div><div class="qp-row">'
    + q.items.map((i) => '<button type="button" class="qchip" data-qf="' + q.field + '" data-qv="' + esc(i) + '">+ ' + esc(i) + '</button>').join('')
    + '</div></div>';
}
function polQuickAdd(field, value) {
  const el = document.getElementById('pf-' + field); if (!el) return;
  const set = el.value.split(',').map((x) => x.trim()).filter(Boolean);
  if (!set.some((x) => x.toLowerCase() === value.toLowerCase())) set.push(value);
  el.value = set.join(', ');
  el.style.borderColor = 'var(--accent)'; setTimeout(() => { el.style.borderColor = ''; }, 700);
}
function collectPolicyForm(type) {
  const body = {};
  for (const f of POLICY_FIELDS[type] || []) {
    const el = document.getElementById('pf-' + f.k);
    if (!el) continue;
    if (f.t === 'bool') { body[f.k] = el.checked; continue; }
    const raw = el.value.trim();
    if (f.t === 'num') { if (raw !== '') body[f.k] = Number(raw); continue; }
    if (f.t === 'dec') { if (raw !== '') body[f.k] = parseFloat(raw); continue; }
    if (f.t === 'time') { if (raw !== '') body[f.k] = raw.length === 5 ? raw + ':00' : raw; continue; }
    if (f.t === 'list') { body[f.k] = raw ? raw.split(',').map((s) => s.trim()).filter(Boolean) : []; continue; }
    if (f.t === 'json') {
      if (!raw) { body[f.k] = null; continue; }
      try { body[f.k] = JSON.parse(raw); }
      catch (e) { throw new Error('"' + f.l + '" is not valid JSON.'); }
      continue;
    }
    body[f.k] = raw || null;
  }
  return body;
}
$('#pol-save').onclick = async () => {
  const type = $('#pol-type').value;
  $('#pol-err').textContent = '';
  let body;
  try { body = collectPolicyForm(type); } catch (e) { $('#pol-err').textContent = e.message; return; }
  if (!body.name) { $('#pol-err').textContent = 'Policy name is required.'; return; }
  try {
    let created = null;
    if (POL_EDIT_ID) await api('/policies/' + type + '/' + POL_EDIT_ID, { method: 'PUT', body: JSON.stringify(body) });
    else { const r = await api('/policies/' + type, { method: 'POST', body: JSON.stringify(body) }); created = r.data; }
    const stype = $('#pol-scope-type') ? $('#pol-scope-type').value : '';
    const stgt = $('#pol-scope-target') ? $('#pol-scope-target').value : '';
    if (!POL_EDIT_ID && created && stype && stgt) {
      try {
        await api('/policies/assign', { method: 'POST', body: JSON.stringify({
          policy_type: type.toUpperCase(), policy_id: created.id, assignable_type: stype, assignable_id: Number(stgt),
        }) });
        toast('Policy created and applied to ' + stype.toLowerCase());
      } catch (e) { $('#pol-err').textContent = 'Policy saved, but applying it failed: ' + e.message; }
    }
    loadPolicies();
  } catch (e) { $('#pol-err').textContent = e.message; }
};
$('#pol-cancel').onclick = () => renderPolicyForm($('#pol-type').value, null);
$('#pol-new').onclick = () => renderPolicyForm($('#pol-type').value, null);
$('#pol-form').addEventListener('click', (e) => { const c = e.target.closest('.qchip'); if (c) polQuickAdd(c.dataset.qf, c.dataset.qv); });

// ---- App & Website Rules (dedicated screen) ----
let RULES = [];
let RULE_POL = { app: null, site: null };
const RULE_ORDER = ['TRACKED', 'ALLOWED', 'BLOCKED', 'VIOLATION'];
const RULE_LABEL = { TRACKED: 'Tracked', ALLOWED: 'Allowed', BLOCKED: 'Blocked', VIOLATION: 'Violation' };
const RULE_TAG = { TRACKED: 't-info', ALLOWED: 't-ok', BLOCKED: 't-warn', VIOLATION: 't-danger' };
const RULE_SEED = [
  { item: 'excel.exe', kind: 'app', status: 'ALLOWED' }, { item: 'winword.exe', kind: 'app', status: 'ALLOWED' },
  { item: 'code.exe', kind: 'app', status: 'ALLOWED' }, { item: 'teams.exe', kind: 'app', status: 'ALLOWED' },
  { item: 'outlook.exe', kind: 'app', status: 'ALLOWED' }, { item: 'chrome.exe', kind: 'app', status: 'TRACKED' },
  { item: 'whatsapp.exe', kind: 'app', status: 'BLOCKED' }, { item: 'discord.exe', kind: 'app', status: 'BLOCKED' },
  { item: 'steam.exe', kind: 'app', status: 'VIOLATION' }, { item: 'spotify.exe', kind: 'app', status: 'TRACKED' },
  { item: 'github.com', kind: 'site', status: 'ALLOWED' }, { item: 'stackoverflow.com', kind: 'site', status: 'ALLOWED' },
  { item: 'youtube.com', kind: 'site', status: 'TRACKED' }, { item: 'gmail.com', kind: 'site', status: 'TRACKED' },
  { item: 'facebook.com', kind: 'site', status: 'BLOCKED' }, { item: 'instagram.com', kind: 'site', status: 'BLOCKED' },
  { item: 'x.com', kind: 'site', status: 'BLOCKED' }, { item: 'reddit.com', kind: 'site', status: 'BLOCKED' },
  { item: 'tiktok.com', kind: 'site', status: 'VIOLATION' }, { item: 'netflix.com', kind: 'site', status: 'VIOLATION' },
];
function rulesFromPolicy(pol, kind) {
  if (!pol) return [];
  const allowed = (kind === 'app' ? pol.allowed_apps : pol.allowed_sites) || [];
  const blocked = (kind === 'app' ? pol.blocked_apps : pol.blocked_sites) || [];
  const cats = pol.categories || {};
  const seen = new Set(); const out = [];
  const add = (item, status) => { const k = String(item).toLowerCase(); if (!item || seen.has(k)) return; seen.add(k); out.push({ item: String(item), kind, status }); };
  Object.keys(cats).forEach((it) => { const c = String(cats[it]).toUpperCase(); if (RULE_ORDER.includes(c)) add(it, c); });
  allowed.forEach((it) => add(it, 'ALLOWED'));
  blocked.forEach((it) => { const c = String(cats[it] || '').toUpperCase(); add(it, c === 'VIOLATION' ? 'VIOLATION' : 'BLOCKED'); });
  return out;
}
async function initRules() {
  $('#rule-rows').innerHTML = '<tr><td colspan="4" class="mut">Loading…</td></tr>';
  try {
    const [ap, wp] = await Promise.all([api('/policies/application'), api('/policies/website')]);
    RULE_POL.app = (ap.data || [])[0] || null;
    RULE_POL.site = (wp.data || [])[0] || null;
    RULES = rulesFromPolicy(RULE_POL.app, 'app').concat(rulesFromPolicy(RULE_POL.site, 'site'));
    const act = (RULE_POL.app && RULE_POL.app.action_on_blocked) || (RULE_POL.site && RULE_POL.site.action_on_blocked);
    if (act) $('#rule-action').value = act;
    renderRules();
    if (!RULES.length) $('#rule-msg').innerHTML = 'No rules yet. Click <b>Load common defaults</b> to start, then <b>Save rules</b>.';
  } catch (e) {
    $('#rule-rows').innerHTML = isDenied(e) ? deniedCard() : '<tr><td colspan="4" class="mut">' + esc(e.message) + '</td></tr>';
  }
}
function renderRules() {
  const q = ($('#rule-q').value || '').toLowerCase();
  const list = RULES.map((r, i) => ({ r, i })).filter(({ r }) => !q || r.item.toLowerCase().includes(q));
  $('#rule-rows').innerHTML = list.map(({ r, i }) => '<tr>'
    + '<td><b>' + esc(r.item) + '</b></td>'
    + '<td>' + (r.kind === 'app' ? 'Application' : 'Website') + '</td>'
    + '<td><select data-rule-status="' + i + '" class="rst rst-' + r.status + '">'
    + RULE_ORDER.map((sx) => '<option value="' + sx + '"' + (sx === r.status ? ' selected' : '') + '>' + RULE_LABEL[sx] + '</option>').join('')
    + '</select></td>'
    + '<td><button class="btn danger" data-rule-del="' + i + '" type="button">Remove</button></td></tr>').join('')
    || '<tr><td colspan="4" class="mut">No rules match. Add one above or load defaults.</td></tr>';
}
function addRule() {
  const item = ($('#rule-add-item').value || '').trim().toLowerCase();
  if (!item) return;
  const kind = $('#rule-add-type').value, status = $('#rule-add-status').value;
  if (RULES.some((r) => r.item.toLowerCase() === item && r.kind === kind)) { toast('Already in the list'); return; }
  RULES.push({ item, kind, status }); $('#rule-add-item').value = ''; renderRules();
}
async function saveRules() {
  const action = $('#rule-action').value;
  const build = (kind) => {
    const allowed = [], blocked = [], categories = {};
    RULES.filter((r) => r.kind === kind).forEach((r) => {
      categories[r.item] = r.status;
      if (r.status === 'ALLOWED') allowed.push(r.item);
      if (r.status === 'BLOCKED' || r.status === 'VIOLATION') blocked.push(r.item);
    });
    return { allowed, blocked, categories };
  };
  const msg = $('#rule-msg'); msg.style.color = ''; msg.textContent = 'Saving…';
  try {
    const a = build('app');
    const appBody = { name: (RULE_POL.app && RULE_POL.app.name) || 'Company App Rules', allowed_apps: a.allowed, blocked_apps: a.blocked, categories: a.categories, action_on_blocked: action };
    if (RULE_POL.app) { await api('/policies/application/' + RULE_POL.app.id, { method: 'PUT', body: JSON.stringify(appBody) }); }
    else { const r = await api('/policies/application', { method: 'POST', body: JSON.stringify(appBody) }); RULE_POL.app = r.data;
      await api('/policies/assign', { method: 'POST', body: JSON.stringify({ policy_type: 'APPLICATION', policy_id: RULE_POL.app.id, assignable_type: 'COMPANY', assignable_id: ME.company_id }) }); }
    const w = build('site');
    const webBody = { name: (RULE_POL.site && RULE_POL.site.name) || 'Company Website Rules', allowed_sites: w.allowed, blocked_sites: w.blocked, categories: w.categories, action_on_blocked: action };
    if (RULE_POL.site) { await api('/policies/website/' + RULE_POL.site.id, { method: 'PUT', body: JSON.stringify(webBody) }); }
    else { const r = await api('/policies/website', { method: 'POST', body: JSON.stringify(webBody) }); RULE_POL.site = r.data;
      await api('/policies/assign', { method: 'POST', body: JSON.stringify({ policy_type: 'WEBSITE', policy_id: RULE_POL.site.id, assignable_type: 'COMPANY', assignable_id: ME.company_id }) }); }
    msg.style.color = 'var(--ok)'; msg.textContent = '\u2713 Saved & applied company-wide — agents pick it up on their next heartbeat (~30s).';
    initRules();
  } catch (e) { msg.style.color = 'var(--danger)'; msg.textContent = 'Error: ' + (e.message || e); }
}
$('#rule-add-btn').onclick = addRule;
$('#rule-add-item').addEventListener('keydown', (e) => { if (e.key === 'Enter') addRule(); });
$('#rule-save').onclick = saveRules;
$('#rule-seed').onclick = () => { RULE_SEED.forEach((sd) => { if (!RULES.some((r) => r.item.toLowerCase() === sd.item && r.kind === sd.kind)) RULES.push({ item: sd.item, kind: sd.kind, status: sd.status }); }); renderRules(); toast('Loaded common defaults — review, then Save rules'); };
$('#rule-q').addEventListener('input', renderRules);
$('#rule-rows').addEventListener('change', (e) => { const s = e.target.closest('[data-rule-status]'); if (s) { RULES[+s.dataset.ruleStatus].status = s.value; s.className = 'rst rst-' + s.value; } });
$('#rule-rows').addEventListener('click', (e) => { const d = e.target.closest('[data-rule-del]'); if (d) { RULES.splice(+d.dataset.ruleDel, 1); renderRules(); } });
$('#pol-rows').addEventListener('click', async (e) => {
  const edit = e.target.closest('[data-pol-edit]');
  if (edit) {
    const p = POL_LIST.find((x) => x.id === Number(edit.dataset.polEdit));
    if (p) renderPolicyForm($('#pol-type').value, p);
    return;
  }
  const del = e.target.closest('[data-pol-del]');
  if (del) {
    const p = POL_LIST.find((x) => x.id === Number(del.dataset.polDel));
    if (!p || !confirm('Delete policy "' + p.name + '"? Assignments pointing to it stop applying.')) return;
    try { await api('/policies/' + $('#pol-type').value + '/' + p.id, { method: 'DELETE' }); loadPolicies(); }
    catch (err) { alert(err.message); }
  }
});
// Assignments panel
function refreshAssignPolicyOptions() {
  fillSelect($('#as-policy'), POL_LIST, (p) => p.name + ' (v' + (p.version ?? 1) + ')', (p) => p.id, POL_LIST.length ? null : 'No policies of this type yet');
}
async function initAssignPanel() {
  await refreshAssignTargets();
}
async function populateTargets(kind, sel) {
  try {
    if (kind === 'COMPANY') {
      fillSelect(sel, [{ id: ME.company_id, name: ME.company || 'This company' }], (r) => r.name, (r) => r.id);
    } else if (kind === 'BRANCH' || kind === 'DEPARTMENT' || kind === 'TEAM') {
      const org = await orgLists();
      const rows = { BRANCH: org.branches, DEPARTMENT: org.departments, TEAM: org.teams }[kind] || [];
      fillSelect(sel, rows, (r) => r.name, (r) => r.id, rows.length ? null : 'None yet — click + New');
    } else if (kind === 'EMPLOYEE') {
      const emps = await employeesList();
      fillSelect(sel, emps, (e) => fullName(e) + ' (' + (e.employee_code || '#' + e.id) + ')', (e) => e.id, emps.length ? null : 'No employees yet');
    } else if (kind === 'DEVICE') {
      const devs = await devicesList();
      fillSelect(sel, devs, (d) => (d.computer_name || d.device_uuid) + ' — ' + (fullName(d.employee) || 'unassigned'), (d) => d.id, devs.length ? null : 'No devices yet');
    } else {
      fillSelect(sel, [], (x) => x, (x) => x);
    }
  } catch (e) {
    fillSelect(sel, [], (x) => x, (x) => x, isDenied(e) ? 'Your role cannot list these' : e.message);
  }
}
// Create a branch/department/team inline so empty levels are instantly usable.
async function quickAddOrg(kind) {
  const map = { BRANCH: 'branches', DEPARTMENT: 'departments', TEAM: 'teams' };
  const ty = map[kind]; if (!ty) return null;
  const name = prompt('Name of the new ' + kind.toLowerCase() + ':'); if (!name || !name.trim()) return null;
  try { await api('/org/' + ty, { method: 'POST', body: JSON.stringify({ name: name.trim() }) }); }
  catch (e) { alert(e.message); return null; }
  await orgLists(true);
  return name.trim();
}
async function refreshAssignTargets() {
  const kind = $('#as-type').value;
  await populateTargets(kind, $('#as-target'));
  if ($('#as-target-add')) $('#as-target-add').style.display = ['BRANCH', 'DEPARTMENT', 'TEAM'].includes(kind) ? '' : 'none';
}
$('#as-type').addEventListener('change', refreshAssignTargets);
if ($('#as-target-add')) $('#as-target-add').onclick = async () => {
  const name = await quickAddOrg($('#as-type').value); if (!name) return;
  await refreshAssignTargets(); toast('Created "' + name + '"');
};
if ($('#pol-scope-type')) $('#pol-scope-type').addEventListener('change', async () => {
  const k = $('#pol-scope-type').value;
  await populateTargets(k, $('#pol-scope-target'));
  if ($('#pol-scope-add')) $('#pol-scope-add').style.display = ['BRANCH', 'DEPARTMENT', 'TEAM'].includes(k) ? '' : 'none';
});
if ($('#pol-scope-add')) $('#pol-scope-add').onclick = async () => {
  const name = await quickAddOrg($('#pol-scope-type').value); if (!name) return;
  await populateTargets($('#pol-scope-type').value, $('#pol-scope-target')); toast('Created "' + name + '"');
};
$('#as-save').onclick = async () => {
  const policyId = $('#as-policy').value, targetId = $('#as-target').value;
  if (!policyId || !targetId) { $('#as-log').textContent = 'Pick a policy and a target first.'; return; }
  const body = {
    policy_type: $('#pol-type').value.toUpperCase(), // e.g. vpn_proxy → VPN_PROXY
    policy_id: Number(policyId),
    assignable_type: $('#as-type').value,
    assignable_id: Number(targetId),
  };
  if ($('#as-from').value) body.effective_from = $('#as-from').value;
  if ($('#as-to').value) body.effective_to = $('#as-to').value;
  try {
    await api('/policies/assign', { method: 'POST', body: JSON.stringify(body) });
    $('#as-log').textContent = '✓ Assigned "' + $('#as-policy').selectedOptions[0].textContent + '" to '
      + $('#as-type').value + ': ' + $('#as-target').selectedOptions[0].textContent + '. Agents apply it on next heartbeat (~30s).';
  } catch (e) { $('#as-log').textContent = '✕ ' + e.message; }
};

// ---- 8. biometric ----
function initBiometric() {
  if (!$('#bio-date').value) $('#bio-date').value = today();
  loadGatePolicy();
  $('#gate-save').onclick = saveGatePolicy;
  loadBioDevices();
  loadBiometric();
  loadMappings(); // Section 9: existing mappings + unmapped-ID picker
  employeesList().then((emps) => fillEmpPicker($('#bio-map-emp'), emps)).catch(() => {});
}
let GATE_COMPANY_ID = null;
async function loadGatePolicy() {
  try {
    const g = (await api('/gate/policy')).data;
    $('#gate-enabled').checked = !!g.gate_enabled;
    $('#gate-grace').value = g.gate_grace_minutes || 0;
    const me = ((await api('/companies')).data || [])[0];
    if (me) { GATE_COMPANY_ID = me.id; $('#gate-mode').value = me.biometric_gate || 'auto'; }
  } catch (e) { $('#gate-msg').textContent = e.message; }
}
async function saveGatePolicy() {
  try {
    await api('/gate/policy', { method: 'PUT', body: JSON.stringify({
      gate_enabled: $('#gate-enabled').checked,
      gate_grace_minutes: Math.max(0, parseInt($('#gate-grace').value || '0', 10)),
    }) });
    if (GATE_COMPANY_ID) {
      await api('/companies/' + GATE_COMPANY_ID, { method: 'PUT', body: JSON.stringify({ biometric_gate: $('#gate-mode').value }) });
    }
    toast('Gate policy saved');
  } catch (e) { $('#gate-msg').textContent = e.message; }
}
let bdEditId = null;
let bdDevices = [];
async function loadBioDevices() {
  try {
    const d = await api('/integrations/biometric/devices');
    bdDevices = d.data || [];
    $('#biodev-rows').innerHTML = bdDevices.map((v) => '<tr>'
      + '<td><span class="nm">' + esc(v.provider || v.name) + '</span></td>'
      + '<td class="mut" style="max-width:200px;overflow:hidden;text-overflow:ellipsis">' + esc(v.api_base_url ? (v.api_base_url + (v.api_endpoint ? '/' + v.api_endpoint : '')) : (v.integration_method || '—')) + '</td>'
      + '<td><span class="tag ' + (v.sync_mode === 'MANUAL' ? 't-off' : 't-ok') + '">'
        + (v.sync_mode === 'INTERVAL' ? ('EVERY ' + (v.sync_interval_minutes || 5) + 'M')
           : v.sync_mode === 'SCHEDULED' ? ('AT ' + ((v.sync_times || []).join(', ') || 'set times'))
           : 'MANUAL') + '</span>'
        + (v.next_sync_at ? '<div class="mut" style="font-size:10.5px">next ' + dt(v.next_sync_at) + '</div>' : '') + '</td>'
      + '<td><span class="tag ' + (v.status === 'ACTIVE' ? 't-ok' : 't-off') + '">' + esc(v.status) + '</span></td>'
      + '<td>' + (v.last_sync_ok_at ? dt(v.last_sync_ok_at) : (v.last_sync_at ? dt(v.last_sync_at) : '—'))
        + (v.last_sync_at && (!v.last_sync_ok_at || v.last_sync_at > v.last_sync_ok_at) ? '<div class="mut" style="font-size:10.5px">tried ' + dt(v.last_sync_at) + '</div>' : '') + '</td>'
      + '<td class="mut" style="max-width:220px">' + esc(v.last_sync_result || '—') + '</td>'
      + '<td>' + (v.logs_count ?? 0) + '</td>'
      + '<td><button class="btn" data-bd-edit="' + v.id + '">Edit</button> <button class="btn" data-bd-del="' + v.id + '">Delete</button></td></tr>').join('')
      || '<tr><td colspan="8" class="mut">No biometric device connected yet — fill the form below and press Save. Punches can also arrive via middleware push or CSV import.</td></tr>';
  } catch (e) {
    $('#biodev-rows').innerHTML = isDenied(e) ? deniedCard() : '<tr><td colspan="8" class="mut">' + esc(e.message) + '</td></tr>';
  }
}
function bdReset() {
  bdEditId = null;
  ['#bd-provider', '#bd-base', '#bd-endpoint', '#bd-corp', '#bd-user', '#bd-pass', '#bd-filter', '#bd-prefix', '#bd-inmc', '#bd-outmc', '#bd-times'].forEach((q) => { $(q).value = ''; });
  $('#bd-mode').value = 'INTERVAL'; $('#bd-interval').value = '5';
  $('#bd-pass').placeholder = '••••••••';
  $('#bd-save').textContent = 'Save';
  $('#bd-msg').textContent = '';
  $('#bd-test-out').innerHTML = '';
}
function bdCollect() {
  const gv = (q) => ($(q).value.trim() || null);
  const editing = bdEditId ? bdDevices.find((x) => x.id === bdEditId) : null;
  const body = {
    provider: gv('#bd-provider'),
    name: gv('#bd-provider'),
    sync_mode: $('#bd-mode').value,
    sync_interval_minutes: Math.max(1, parseInt($('#bd-interval').value, 10) || 5),
    sync_times: ($('#bd-times').value || '').split(',').map((s) => s.trim()).filter((s) => /^\d{1,2}:\d{2}$/.test(s)),
    api_base_url: gv('#bd-base'),
    api_endpoint: gv('#bd-endpoint'),
    corporate_id: gv('#bd-corp'),
    api_username: gv('#bd-user'),
    employee_code_filter: gv('#bd-filter') || 'ALL',
    employee_id_prefix: gv('#bd-prefix'),
    in_machine_id: gv('#bd-inmc'),
    out_machine_id: gv('#bd-outmc'),
    integration_method: 'DIRECT_PULL',
    status: (editing && editing.status) || 'ACTIVE',
  };
  const pw = $('#bd-pass').value;
  if (pw) body.api_password = pw;
  return body;
}
$('#bd-reset').onclick = bdReset;
$('#bd-save').onclick = async () => {
  const body = bdCollect();
  if (!body.provider) { $('#bd-msg').textContent = 'Provider is required.'; return; }
  if (!body.api_base_url || !body.api_endpoint) { $('#bd-msg').textContent = 'API base URL and endpoint are required.'; return; }
  try {
    if (bdEditId) await api('/integrations/biometric/devices/' + bdEditId, { method: 'PUT', body: JSON.stringify(body) });
    else await api('/integrations/biometric/devices', { method: 'POST', body: JSON.stringify(body) });
    bdReset();
    $('#bd-msg').textContent = '✓ Saved.' ;
    toast('Biometric device saved');
    loadBioDevices();
  } catch (e) { $('#bd-msg').textContent = '✕ ' + e.message; }
};
$('#bd-test').onclick = async () => {
  const body = bdCollect();
  if (bdEditId) body.device_id = bdEditId;
  $('#bd-msg').textContent = 'Testing connection…';
  $('#bd-test-out').innerHTML = '';
  try {
    const r = await api('/integrations/biometric/devices/test-connection', { method: 'POST', body: JSON.stringify(body) });
    $('#bd-msg').textContent = '✓ ' + r.message;
    if (r.raw) {
      $('#bd-test-out').innerHTML = '<div class="mut" style="font-size:11.5px">Raw provider response (for field-mapping check — punch count or MC numbers missing):</div>'
        + '<pre style="white-space:pre-wrap;word-break:break-all;font-size:11px;background:var(--bg);border:1px solid var(--line);border-radius:8px;padding:10px;max-height:220px;overflow:auto">' + esc(r.raw) + '</pre>';
    }
    if ((r.sample || []).length) {
      $('#bd-test-out').innerHTML += '<table><thead><tr><th>Emp code</th><th>Name (device)</th><th>Punch time</th><th>MC</th><th>Direction</th><th>Matched employee</th></tr></thead><tbody>'
        + r.sample.map((s) => '<tr><td>' + esc(s.code) + '</td><td>' + esc(s.name || '—') + '</td><td>' + esc(s.punched_at) + '</td><td>' + esc(s.mc ?? '—') + '</td><td>' + esc(s.direction) + '</td><td>'
          + (s.mapped ? '<span class="tag t-ok">MATCHED</span>' : '<span class="tag t-warn">NO MATCH</span>') + '</td></tr>').join('')
        + '</tbody></table>';
    }
  } catch (e) { $('#bd-msg').textContent = '✕ ' + e.message; }
};
$('#bd-syncnow').onclick = async () => {
  const id = bdEditId || (bdDevices.length === 1 ? bdDevices[0].id : null);
  if (!id) { $('#bd-msg').textContent = bdDevices.length ? 'Press Edit on the device you want to sync, then Sync now.' : 'Save the device first, then press Sync now.'; return; }
  $('#bd-msg').textContent = 'Syncing…';
  try {
    const r = await api('/integrations/biometric/devices/' + id + '/sync', { method: 'POST' });
    $('#bd-msg').textContent = '✓ ' + r.message;
    if ((r.unmatched_codes || []).length) {
      $('#bd-test-out').innerHTML = '<div class="mut" style="font-size:11.5px">Unmatched employee codes — map them under "Map biometric ID → employee" below and old punches back-fill automatically: <b>'
        + r.unmatched_codes.map(esc).join(', ') + '</b></div>';
    }
    toast('Sync finished');
    loadBioDevices();
    loadBiometric();
  } catch (e) { $('#bd-msg').textContent = '✕ ' + e.message; }
};
$('#biodev-rows').addEventListener('click', async (ev) => {
  const eBtn = ev.target.closest('[data-bd-edit]');
  const dBtn = ev.target.closest('[data-bd-del]');
  if (eBtn) {
    const v = bdDevices.find((x) => x.id === Number(eBtn.dataset.bdEdit));
    if (!v) return;
    bdEditId = v.id;
    $('#bd-provider').value = v.provider || v.name || '';
    $('#bd-base').value = v.api_base_url || '';
    $('#bd-endpoint').value = v.api_endpoint || '';
    $('#bd-corp').value = v.corporate_id || '';
    $('#bd-user').value = v.api_username || '';
    $('#bd-pass').value = '';
    $('#bd-pass').placeholder = v.has_password ? '•••••••• (saved — leave blank to keep)' : '••••••••';
    $('#bd-filter').value = v.employee_code_filter || '';
    $('#bd-prefix').value = v.employee_id_prefix || '';
    $('#bd-inmc').value = v.in_machine_id || '';
    $('#bd-outmc').value = v.out_machine_id || '';
    $('#bd-mode').value = v.sync_mode || (v.sync_enabled ? 'INTERVAL' : 'MANUAL');
    $('#bd-interval').value = v.sync_interval_minutes || 5;
    $('#bd-times').value = (v.sync_times || []).join(', ');
    $('#bd-save').textContent = 'Save changes';
    $('#bd-msg').textContent = 'Editing "' + (v.provider || v.name) + '" — change the fields and press Save changes.';
  }
  if (dBtn) {
    if (!window.confirm('Delete this biometric device? Its punch history is kept.')) return;
    try {
      await api('/integrations/biometric/devices/' + Number(dBtn.dataset.bdDel), { method: 'DELETE' });
      bdReset();
      loadBioDevices();
    } catch (e) { $('#bd-msg').textContent = '✕ ' + e.message; }
  }
});
async function loadBiometric() {
  const date = $('#bio-date').value || today();
  try {
    const d = await api('/integrations/biometric/logs?per_page=200&date=' + encodeURIComponent(date));
    const pc = { IN: 't-ok', OUT: 't-off', BREAK_IN: 't-warn', BREAK_OUT: 't-warn' };
    $('#bio-rows').innerHTML = (d.data || []).map((l) => '<tr>'
      + '<td>' + dt(l.punched_at) + '</td>'
      + '<td><span class="nm">' + esc(fullName(l.employee) || '—') + '</span>' + (l.employee ? '' : ' <span class="tag t-warn">UNMAPPED</span>') + '</td>'
      + '<td>' + esc(l.biometric_employee_id) + '</td>'
      + '<td><span class="tag ' + (pc[l.punch_type] || 't-off') + '">' + esc(l.punch_type || '—') + '</span></td>'
      + '<td>' + esc(l.verification_mode || '—') + '</td></tr>').join('')
      || '<tr><td colspan="5" class="mut">No punches for ' + esc(date) + '. Push logs from the device middleware or import a CSV below.</td></tr>';
  } catch (e) {
    $('#bio-rows').innerHTML = isDenied(e) ? deniedCard() : '<tr><td colspan="5" class="mut">' + esc(e.message) + '</td></tr>';
  }
  try {
    const m = await api('/reports/biometric-mismatch?date=' + encodeURIComponent(date));
    const badge = (s) => {
      const map = { MISMATCH: 't-danger', OK: 't-ok', NO_BIOMETRIC: 't-warn', NO_SYSTEM_LOGIN: 't-warn', UNKNOWN: 't-off' };
      return '<span class="tag ' + (map[s] || 't-off') + '">' + esc(s) + '</span>';
    };
    $('#bio-mm-rows').innerHTML = (m.data || []).map((r) => '<tr>'
      + '<td><span class="nm">' + esc(r.name || '#' + r.employee_id) + '</span></td>'
      + '<td>' + (r.biometric_in ? dt(r.biometric_in) : '—') + '</td>'
      + '<td>' + (r.system_login ? dt(r.system_login) : '—') + '</td>'
      + '<td>' + (r.diff_minutes ?? '—') + '</td><td>' + badge(r.status) + '</td></tr>').join('')
      || '<tr><td colspan="5" class="mut">Nothing to reconcile for ' + esc(date) + '.</td></tr>';
  } catch (e) {
    $('#bio-mm-rows').innerHTML = isDenied(e) ? deniedCard() : '<tr><td colspan="5" class="mut">' + esc(e.message) + '</td></tr>';
  }
}
$('#bio-load').onclick = loadBiometric;
$('#bio-date').addEventListener('change', loadBiometric);
$('#bio-import').onclick = async () => {
  const f = $('#bio-file').files[0];
  const msg = $('#bio-import-msg');
  if (!f) { msg.textContent = 'Choose a CSV file first.'; return; }
  const fd = new FormData();
  fd.append('file', f);
  try {
    const r = await api('/integrations/biometric/import', { method: 'POST', body: fd });
    msg.textContent = '✓ Imported ' + (r.stored ?? 0) + ' punches.';
    $('#bio-file').value = '';
    loadBiometric();
  } catch (e) { msg.textContent = '✕ ' + e.message; }
};
// Section 9: pick an unmapped ID from the dropdown → fills the manual field.
document.addEventListener('change', (e) => {
  if (e.target && e.target.id === 'bio-unmapped' && e.target.value) $('#bio-map-id').value = e.target.value;
});

async function mapBiometric(force) {
  const msg = $('#bio-map-msg');
  const bioId = $('#bio-map-id').value.trim(), empId = $('#bio-map-emp').value;
  if (!bioId || !empId) { msg.textContent = 'Enter the biometric ID and pick an employee.'; return; }
  try {
    const body = { biometric_employee_id: bioId, employee_id: Number(empId) };
    if (force) body.force = true;
    await api('/integrations/biometric/map-employee', { method: 'POST', body: JSON.stringify(body) });
    msg.textContent = '✓ Mapped. Existing unmapped punches for this ID were back-filled.';
    $('#bio-map-id').value = '';
    loadMappings(); loadBiometric();
  } catch (e) {
    // Section 9: one-employee-one-biometric warning → let the admin confirm a re-map.
    const errObj = e.body && (e.body.error || e.body);
    if (e.status === 409 && errObj && errObj.code === 'EMPLOYEE_ALREADY_MAPPED') {
      if (window.confirm((errObj.message || e.message) + '\n\nRe-map this employee to the new biometric ID?')) return mapBiometric(true);
      msg.textContent = 'Mapping cancelled.';
      return;
    }
    msg.textContent = '✕ ' + e.message;
  }
}
$('#bio-map-save').onclick = () => mapBiometric(false);

// Section 9: list existing mappings (with remove) + refresh the unmapped-ID picker.
async function loadMappings() {
  try {
    const r = await api('/integrations/biometric/mappings');
    $('#bio-map-rows').innerHTML = (r.data || []).map((m) => '<tr>'
      + '<td>' + esc(m.biometric_employee_id) + '</td>'
      + '<td><span class="nm">' + esc(m.employee_name || ('#' + m.employee_id)) + '</span></td>'
      + '<td style="text-align:right"><button class="btn ghost sm" data-map-del="' + m.id + '">Remove</button></td></tr>').join('')
      || '<tr><td colspan="3" class="mut">No mappings yet. Link an unmapped biometric ID to a person above.</td></tr>';
  } catch (e) {
    $('#bio-map-rows').innerHTML = '<tr><td colspan="3" class="mut">' + esc(e.message) + '</td></tr>';
  }
  try {
    const u = await api('/integrations/biometric/unmapped');
    const sel = $('#bio-unmapped');
    sel.innerHTML = '<option value="">— pick an unmapped ID —</option>'
      + (u.data || []).map((x) => '<option value="' + esc(x.biometric_employee_id) + '">' + esc(x.biometric_employee_id) + ' (' + x.punches + ' punch' + (x.punches === 1 ? '' : 'es') + ')</option>').join('');
  } catch (e) { /* keep the placeholder */ }
}
// Remove a mapping (event delegation so it survives table re-render).
document.addEventListener('click', async (e) => {
  const btn = e.target.closest && e.target.closest('[data-map-del]');
  if (!btn) return;
  if (!window.confirm('Remove this biometric mapping? Punch history is kept; new punches for this ID will be unmapped until re-linked.')) return;
  try {
    await api('/integrations/biometric/mappings/' + Number(btn.dataset.mapDel), { method: 'DELETE' });
    loadMappings(); loadBiometric();
  } catch (err) { $('#bio-map-msg').textContent = '✕ ' + err.message; }
});

// ---- 7b. meetings (Section 2) ----
let MTG_SEL = new Set();
let MTG_EMPS = [];
let MTG_EDIT_ID = null;

function initMeetings() {
  if (!window.MTG_INIT) {
    window.MTG_INIT = true;
    $('#mtg-reload').onclick = loadMeetings;
    $('#mtg-new').onclick = () => openMeetingModal(null);
    $('#mtg-close-btn').onclick = () => $('#mtg-ovl').classList.remove('open');
    $('#mtg-part-close').onclick = () => $('#mtg-part-ovl').classList.remove('open');
    $('#mtg-ovl').addEventListener('click', (e) => { if (e.target === $('#mtg-ovl')) $('#mtg-ovl').classList.remove('open'); });
    $('#mtg-part-ovl').addEventListener('click', (e) => { if (e.target === $('#mtg-part-ovl')) $('#mtg-part-ovl').classList.remove('open'); });
    $('#mtg-save').onclick = saveMeeting;
    ['#mtg-f-branch', '#mtg-f-dept', '#mtg-f-team'].forEach((q) => $(q).addEventListener('change', renderPartList));
    $('#mtg-f-search').addEventListener('input', renderPartList);
    $('#mtg-sel-all').onclick = (e) => { e.preventDefault(); shownEmps().forEach((x) => MTG_SEL.add(x.id)); renderPartList(); };
    $('#mtg-sel-none').onclick = (e) => { e.preventDefault(); MTG_SEL.clear(); renderPartList(); };
    $('#mtg-part-list').addEventListener('change', (e) => {
      const cb = e.target.closest('input[type=checkbox][data-emp]'); if (!cb) return;
      const id = Number(cb.dataset.emp);
      if (cb.checked) MTG_SEL.add(id); else MTG_SEL.delete(id);
      updatePartCount();
    });
  }
  loadMeetings();
}

const MTG_STATUS_TAG = { SCHEDULED: 't-warn', IN_PROGRESS: 't-ok', COMPLETED: 't-off', CANCELLED: 't-danger' };
async function loadMeetings() {
  const qs = new URLSearchParams();
  if ($('#mtg-filter-status').value) qs.set('status', $('#mtg-filter-status').value);
  if ($('#mtg-from').value) qs.set('from', $('#mtg-from').value);
  if ($('#mtg-to').value) qs.set('to', $('#mtg-to').value);
  try {
    const d = await api('/meetings' + (qs.toString() ? '?' + qs : ''));
    $('#mtg-rows').innerHTML = (d.data || []).map((m) => '<tr>'
      + '<td><span class="nm">' + esc(m.title) + '</span>' + (m.purpose ? '<div class="mut" style="font-size:11px">' + esc(m.purpose.slice(0, 60)) + '</div>' : '') + '</td>'
      + '<td>' + esc(m.meeting_date || '—') + '</td>'
      + '<td>' + (m.start_at ? dt(m.start_at) : '—') + (m.end_at ? ' – ' + new Date(m.end_at.replace(' ', 'T')).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '') + '</td>'
      + '<td>' + (m.participant_count ?? 0) + '</td>'
      + '<td><span class="tag ' + (MTG_STATUS_TAG[m.status] || 't-off') + '">' + esc((m.status || '').replace('_', ' ')) + '</span></td>'
      + '<td style="text-align:right;white-space:nowrap"><button class="btn" data-mtg-part="' + m.id + '">Participation</button> '
      + ((m.status === 'SCHEDULED' || m.status === 'IN_PROGRESS') ? '<button class="btn" data-mtg-edit="' + m.id + '">Edit</button> <button class="btn danger" data-mtg-cancel="' + m.id + '">Cancel</button>' : '')
      + '</td></tr>').join('')
      || '<tr><td colspan="6" class="mut">No meetings yet. Press "Schedule meeting" to create one.</td></tr>';
  } catch (e) {
    $('#mtg-rows').innerHTML = isDenied(e) ? deniedCard() : '<tr><td colspan="6" class="mut">' + esc(e.message) + '</td></tr>';
  }
}

// Row actions (event delegation so they survive re-render).
document.addEventListener('click', async (e) => {
  const ed = e.target.closest && e.target.closest('[data-mtg-edit]');
  const cn = e.target.closest && e.target.closest('[data-mtg-cancel]');
  const pt = e.target.closest && e.target.closest('[data-mtg-part]');
  if (ed) { openMeetingModal(Number(ed.dataset.mtgEdit)); }
  else if (cn) {
    if (!window.confirm('Cancel this meeting? Participants lose the Meeting option immediately.')) return;
    try { await api('/meetings/' + Number(cn.dataset.mtgCancel) + '/cancel', { method: 'POST' }); toast('Meeting cancelled'); loadMeetings(); }
    catch (err) { alert(err.message); }
  }
  else if (pt) { openParticipation(Number(pt.dataset.mtgPart)); }
});

function mtgToLocalInput(s) { return s ? s.replace(' ', 'T').slice(0, 16) : ''; }

async function openMeetingModal(id) {
  MTG_EDIT_ID = id;
  MTG_SEL = new Set();
  $('#mtg-err').textContent = '';
  $('#mtg-modal-title').textContent = id ? 'Edit meeting' : 'Schedule meeting';
  try {
    const org = await orgLists();
    fillSelect($('#mtg-f-branch'), org.branches || [], (b) => b.name, (b) => b.id, 'All branches');
    fillSelect($('#mtg-f-dept'), org.departments || [], (d) => d.name, (d) => d.id, 'All departments');
    fillSelect($('#mtg-f-team'), org.teams || [], (t) => t.name, (t) => t.id, 'All teams');
  } catch (e) { /* filters optional */ }
  MTG_EMPS = await employeesList().catch(() => []);
  if (id) {
    try {
      const m = (await api('/meetings/' + id)).data;
      $('#mtg-title').value = m.title || '';
      $('#mtg-purpose').value = m.purpose || '';
      $('#mtg-start').value = mtgToLocalInput(m.start_at);
      $('#mtg-end').value = mtgToLocalInput(m.end_at);
      $('#mtg-notes').value = m.notes || '';
      (m.participant_ids || []).forEach((x) => MTG_SEL.add(Number(x)));
    } catch (e) { $('#mtg-err').textContent = e.message; }
  } else {
    $('#mtg-title').value = ''; $('#mtg-purpose').value = ''; $('#mtg-notes').value = '';
    $('#mtg-start').value = ''; $('#mtg-end').value = '';
  }
  $('#mtg-f-search').value = '';
  ['#mtg-f-branch', '#mtg-f-dept', '#mtg-f-team'].forEach((q) => { $(q).selectedIndex = 0; });
  renderPartList();
  $('#mtg-ovl').classList.add('open');
}

function shownEmps() {
  const b = $('#mtg-f-branch').value, d = $('#mtg-f-dept').value, t = $('#mtg-f-team').value;
  const q = $('#mtg-f-search').value.trim().toLowerCase();
  return MTG_EMPS.filter((e) => {
    if (b && String(e.branch_id || '') !== b) return false;
    if (d && String(e.department_id || '') !== d) return false;
    if (t && String(e.team_id || '') !== t) return false;
    if (q && !((fullName(e) + ' ' + (e.employee_code || '')).toLowerCase().includes(q))) return false;
    return true;
  });
}
function renderPartList() {
  const list = shownEmps();
  $('#mtg-part-list').innerHTML = list.map((e) => '<label style="display:flex;align-items:center;gap:8px;padding:5px 4px;font-size:12.5px;cursor:pointer">'
    + '<input type="checkbox" data-emp="' + e.id + '"' + (MTG_SEL.has(e.id) ? ' checked' : '') + ' style="width:auto;margin:0">'
    + '<span>' + esc(fullName(e)) + ' <span class="mut">(' + esc(e.employee_code || ('#' + e.id)) + ')</span></span></label>').join('')
    || '<div class="mut" style="padding:8px">No employees match the filter.</div>';
  updatePartCount();
}
function updatePartCount() { $('#mtg-part-count').textContent = MTG_SEL.size + ' selected'; }

async function saveMeeting() {
  const body = {
    title: $('#mtg-title').value.trim(),
    purpose: $('#mtg-purpose').value.trim() || null,
    start_at: $('#mtg-start').value,
    end_at: $('#mtg-end').value,
    notes: $('#mtg-notes').value.trim() || null,
    participant_ids: [...MTG_SEL],
  };
  if (!body.title) { $('#mtg-err').textContent = 'Title is required.'; return; }
  if (!body.start_at || !body.end_at) { $('#mtg-err').textContent = 'Start and end time are required.'; return; }
  if (body.end_at <= body.start_at) { $('#mtg-err').textContent = 'End must be after start.'; return; }
  if (!body.participant_ids.length) { $('#mtg-err').textContent = 'Select at least one participant.'; return; }
  try {
    if (MTG_EDIT_ID) await api('/meetings/' + MTG_EDIT_ID, { method: 'PUT', body: JSON.stringify(body) });
    else await api('/meetings', { method: 'POST', body: JSON.stringify(body) });
    $('#mtg-ovl').classList.remove('open');
    toast('Meeting saved');
    loadMeetings();
  } catch (e) { $('#mtg-err').textContent = '✕ ' + e.message; }
}

const MTG_ATT_TAG = { ATTENDED: 't-ok', ABSENT: 't-danger', PENDING: 't-warn' };
async function openParticipation(id) {
  try {
    const d = (await api('/meetings/' + id + '/participation')).data;
    $('#mtg-part-title').textContent = 'Participation — ' + (d.meeting && d.meeting.title ? d.meeting.title : '');
    $('#mtg-part-rows').innerHTML = (d.rows || []).map((r) => '<tr>'
      + '<td><span class="nm">' + esc(r.name || ('#' + r.employee_id)) + '</span></td>'
      + '<td>' + (r.scheduled_start ? dt(r.scheduled_start) : '—') + '</td>'
      + '<td>' + (r.actual_start ? dt(r.actual_start) : '—') + '</td>'
      + '<td>' + (r.actual_end ? dt(r.actual_end) : '—') + '</td>'
      + '<td>' + mtgSecs(r.actual_seconds) + '</td>'
      + '<td><span class="tag ' + (MTG_ATT_TAG[r.attendance] || 't-off') + '">' + esc(r.attendance) + '</span></td></tr>').join('')
      || '<tr><td colspan="6" class="mut">No participants.</td></tr>';
    $('#mtg-part-ovl').classList.add('open');
  } catch (e) { alert(e.message); }
}
function mtgSecs(s) { s = s || 0; const h = Math.floor(s / 3600), m = Math.round((s % 3600) / 60); return h ? (h + 'h ' + m + 'm') : (m + 'm'); }

// ---- 9. reports & exports ----
// ---- Live productivity report (17-Jul) ----
let PROD_ROWS = [];
const hms = (s) => { s = Math.max(0, Math.round(s || 0)); const h = Math.floor(s/3600), m = Math.round((s%3600)/60); return h ? h + 'h ' + m + 'm' : m + 'm'; };
function prSetRange(from, to) { $('#pr-from').value = from; $('#pr-to').value = to; loadProductivity(); }
function isoDate(d) { return d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0'); }
async function loadProductivity() {
  const from = $('#pr-from').value || today(), to = $('#pr-to').value || today();
  $('#pr-rows').innerHTML = '<tr><td colspan="16" class="mut">Loading…</td></tr>';
  try {
    const r = await api('/reports/productivity?from=' + from + '&to=' + to);
    PROD_ROWS = r.data || [];
    $('#pr-rows').innerHTML = PROD_ROWS.length ? PROD_ROWS.map((x) =>
      '<tr>' +
      '<td>' + esc(x.work_date) + (x.live ? ' <span class="tag t-info" style="font-size:8px">LIVE</span>' : '') + '</td>' +
      '<td>' + esc(x.employee_code || '—') + '</td>' +
      '<td><b>' + esc(x.name) + '</b></td>' +
      '<td class="mut">' + esc(x.department || '—') + '</td>' +
      '<td>' + esc(x.first_in || '—') + '</td>' +
      '<td>' + esc(x.last_out || '—') + '</td>' +
      '<td data-sort="' + x.present_seconds + '">' + hms(x.present_seconds) + '</td>' +
      '<td data-sort="' + x.work_seconds + '"><b>' + hms(x.work_seconds) + '</b></td>' +
      '<td data-sort="' + x.idle_seconds + '">' + hms(x.idle_seconds) + '</td>' +
      '<td data-sort="' + x.break_count + '">' + x.break_count + '</td>' +
      '<td data-sort="' + x.break_seconds + '">' + hms(x.break_seconds) + '</td>' +
      '<td data-sort="' + (x.meeting_seconds || 0) + '">' + (x.meeting_seconds ? hms(x.meeting_seconds) : '—') + '</td>' +
      '<td data-sort="' + x.timeouts + '">' + x.timeouts + '</td>' +
      '<td data-sort="' + x.non_productive_seconds + '">' + hms(x.non_productive_seconds) + '</td>' +
      '<td data-sort="' + x.violations + '">' + (x.violations ? '<span class="tag t-danger">' + x.violations + '</span>' : '0') + '</td>' +
      '<td data-sort="' + x.productivity + '"><b>' + Number(x.productivity).toFixed(0) + '%</b></td></tr>'
    ).join('') : '<tr><td colspan="16" class="mut">No activity in this range.</td></tr>';
    $('#pr-note').textContent = PROD_ROWS.length + ' rows · ' + from + ' → ' + to + ' · working = active tracked time; present = in-office span.';
    attachTableFilter($('#pr-q'), '#pr-rows');
  } catch (e) { $('#pr-rows').innerHTML = '<tr><td colspan="16" class="mut">' + esc(e.message) + '</td></tr>'; }
}
// R4 item 6: extracted reports use hh:mm, not raw seconds/minutes.
const hhmm = (sec) => { const m = Math.max(0, Math.round((sec || 0) / 60)); return String(Math.floor(m / 60)).padStart(2, '0') + ':' + String(m % 60).padStart(2, '0'); };
function prCSV() {
  const head = ['Date','Code','Employee','Department','Team','Logged in','Logged out','Present (hh:mm)','Working (hh:mm)','Idle (hh:mm)','Breaks','Break time (hh:mm)','Time-outs','Non-productive (hh:mm)','Violations','Productivity%'];
  const rows = PROD_ROWS.map((x) => [x.work_date,x.employee_code,x.name,x.department,x.team,x.first_in,x.last_out,hhmm(x.present_seconds),hhmm(x.work_seconds),hhmm(x.idle_seconds),x.break_count,hhmm(x.break_seconds),x.timeouts,hhmm(x.non_productive_seconds),x.violations,x.productivity]);
  const csv = [head, ...rows].map((r) => r.map((c) => '"' + String(c==null?'':c).replace(/"/g,'""') + '"').join(',')).join('\n');
  const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([csv], {type:'text/csv'}));
  a.download = 'smartept-productivity-' + $('#pr-from').value + '_' + $('#pr-to').value + '.csv'; a.click(); URL.revokeObjectURL(a.href);
}
function prPDF() {
  const from = $('#pr-from').value, to = $('#pr-to').value;
  const rowsHtml = PROD_ROWS.map((x) => '<tr><td>' + esc(x.work_date) + '</td><td>' + esc(x.employee_code||'') + '</td><td>' + esc(x.name) + '</td><td>' + esc(x.department||'') + '</td><td>' + esc(x.first_in||'—') + '</td><td>' + esc(x.last_out||'—') + '</td><td>' + hhmm(x.present_seconds) + '</td><td>' + hhmm(x.work_seconds) + '</td><td>' + hhmm(x.idle_seconds) + '</td><td>' + x.break_count + '</td><td>' + hhmm(x.break_seconds) + '</td><td>' + x.timeouts + '</td><td>' + x.violations + '</td><td>' + Number(x.productivity).toFixed(0) + '%</td></tr>').join('');
  const co = ($('#company-name') ? $('#company-name').textContent : 'Company');
  const w = window.open('', '_blank');
  w.document.write('<html><head><title>SmartEPT Productivity ' + from + ' to ' + to + '</title><style>'
    + 'body{font-family:Inter,Segoe UI,sans-serif;color:#15171C;padding:22px;font-size:11px}'
    + 'h1{color:#0E7C8F;font-size:18px;margin:0}.sub{color:#878C99;font-size:11px;margin:2px 0 14px}'
    + 'table{width:100%;border-collapse:collapse}th{background:#E3F4F7;color:#0B6373;text-align:left;padding:6px;font-size:9px;text-transform:uppercase}'
    + 'td{padding:5px 6px;border-bottom:1px solid #EEF2F6}tr:nth-child(even) td{background:#FAFBFC}'
    + '.hd{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:3px solid #0E7C8F;padding-bottom:10px;margin-bottom:14px}'
    + '@media print{.np{display:none}}</style></head><body>'
    + '<div class="hd"><div><h1>Productivity Report</h1><div class="sub">' + esc(co) + ' · ' + from + ' → ' + to + ' · SmartEPT by Ametecs</div></div>'
    + '<button class="np" onclick="window.print()" style="padding:8px 14px;background:#0E7C8F;color:#fff;border:none;border-radius:7px;cursor:pointer">Print / Save PDF</button></div>'
    + '<table><thead><tr><th>Date</th><th>Code</th><th>Employee</th><th>Dept</th><th>In</th><th>Out</th><th>Present</th><th>Working</th><th>Idle</th><th>Breaks</th><th>Break time</th><th>Time-outs</th><th>Violations</th><th>Prod.%</th></tr></thead><tbody>'
    + (rowsHtml || '<tr><td colspan="14">No data</td></tr>') + '</tbody></table>'
    + '<p style="margin-top:14px;color:#878C99;font-size:10px">Generated ' + new Date().toLocaleString() + ' · SmartEPT — Employee Productivity Tracking & Intelligence</p>'
    + '</body></html>');
  w.document.close();
}

function initReports() {
  if (!$('#pr-from').value) { const d = new Date(); $('#pr-from').value = isoDate(new Date(d.getFullYear(), d.getMonth(), 1)); $('#pr-to').value = today(); }
  $('#pr-load').onclick = loadProductivity;
  $('#pr-today').onclick = () => prSetRange(today(), today());
  $('#pr-week').onclick = () => { const d = new Date(); const g = (d.getDay()+6)%7; const mon = new Date(d); mon.setDate(d.getDate()-g); prSetRange(isoDate(mon), today()); };
  $('#pr-month').onclick = () => { const d = new Date(); prSetRange(isoDate(new Date(d.getFullYear(), d.getMonth(), 1)), today()); };
  $('#pr-csv').onclick = prCSV;
  $('#pr-pdf').onclick = prPDF;
  loadProductivity();
  // Section 3 & 14: break + meeting reports.
  if (!$('#br-from').value) { $('#br-from').value = today(); $('#br-to').value = today(); }
  if (!$('#mr-from').value) { $('#mr-from').value = today(); $('#mr-to').value = today(); }
  $('#br-load').onclick = loadBreakReport;
  $('#br-csv').onclick = () => downloadCsv(breakReportQs() + '&format=csv', 'break_report_' + $('#br-from').value + '.csv');
  $('#mr-load').onclick = loadMeetingReport;
  loadBreakReport(); loadMeetingReport();
  const d = today(), m = d.slice(0, 7);
  [['#rp-att-from', d], ['#rp-att-to', d], ['#rp-prod-from', d], ['#rp-prod-to', d],
   ['#rp-comp-from', d], ['#rp-comp-to', d], ['#rp-sum-from', d], ['#rp-sum-to', d],
   ['#rp-reg-month', m], ['#rp-ms-month', m]]
    .forEach(([s, v]) => { if (!$(s).value) $(s).value = v; });
}
$('#rp-att').onclick = () => downloadCsv('/export/attendance?from=' + $('#rp-att-from').value + '&to=' + $('#rp-att-to').value, 'attendance_' + $('#rp-att-from').value + '.csv');
$('#rp-prod').onclick = () => downloadCsv('/export/productivity?from=' + $('#rp-prod-from').value + '&to=' + $('#rp-prod-to').value, 'productivity_' + $('#rp-prod-from').value + '_' + $('#rp-prod-to').value + '.csv');
$('#rp-comp').onclick = () => downloadCsv('/export/compliance?from=' + $('#rp-comp-from').value + '&to=' + $('#rp-comp-to').value, 'compliance_' + $('#rp-comp-from').value + '.csv');
$('#rp-sum').onclick = () => downloadCsv('/export/daily-summary?from=' + $('#rp-sum-from').value + '&to=' + $('#rp-sum-to').value, 'daily_summary_' + $('#rp-sum-from').value + '_' + $('#rp-sum-to').value + '.csv');
$('#rp-reg').onclick = () => {
  const m = $('#rp-reg-month').value || today().slice(0, 7);
  downloadCsv('/export/attendance-register?month=' + encodeURIComponent(m), 'attendance_register_' + m + '.csv');
};
async function loadMonthlySummary() {
  const m = $('#rp-ms-month').value || today().slice(0, 7);
  $('#ms-card').classList.remove('hide');
  $('#ms-title').firstChild.textContent = 'Monthly summary — ' + m + ' ';
  $('#ms-rows').innerHTML = '<tr><td colspan="9" class="mut">Loading…</td></tr>';
  try {
    const d = await api('/reports/monthly-summary?month=' + encodeURIComponent(m));
    $('#ms-rows').innerHTML = (d.data || []).map((r) => '<tr>'
      + '<td>' + esc(r.employee_code || '—') + '</td>'
      + '<td><span class="nm">' + esc(r.name) + '</span></td>'
      + '<td>' + (r.working_days_in_month ?? 0) + '</td>'
      + '<td><span class="tag t-ok">' + (r.present ?? 0) + '</span></td>'
      + '<td><span class="tag t-danger">' + (r.absent ?? 0) + '</span></td>'
      + '<td><span class="tag t-warn">' + (r.half_day ?? 0) + '</span></td>'
      + '<td><span class="tag t-info">' + (r.on_leave ?? 0) + '</span></td>'
      + '<td><b>' + (r.payable_days ?? 0) + '</b></td>'
      + '<td>' + (r.avg_productivity_score == null ? '—' : r.avg_productivity_score) + '</td></tr>').join('')
      || '<tr><td colspan="9" class="mut">No active employees for ' + esc(m) + '.</td></tr>';
  } catch (e) {
    $('#ms-rows').innerHTML = isDenied(e) ? deniedCard() : '<tr><td colspan="9" class="mut">' + esc(e.message) + '</td></tr>';
  }
}
$('#rp-ms').onclick = loadMonthlySummary;

// ---- Section 3 & 14: break report ----
function breakReportQs() {
  const qs = new URLSearchParams();
  qs.set('from', $('#br-from').value || today());
  qs.set('to', $('#br-to').value || today());
  if ($('#br-type').value) qs.set('break_type', $('#br-type').value);
  if ($('#br-exceeded').checked) qs.set('exceeded', '1');
  return '/reports/breaks?' + qs.toString();
}
const brMin = (s) => s == null ? '—' : (Math.round((s / 60) * 10) / 10) + 'm';
const BR_REV_TAG = { PENDING: 't-warn', REVIEWED: 't-ok', NONE: 't-off' };
async function loadBreakReport() {
  try {
    const d = await api(breakReportQs());
    $('#br-rows').innerHTML = (d.data || []).map((r) => '<tr>'
      + '<td>' + esc(r.date || '—') + '</td>'
      + '<td><span class="nm">' + esc(r.name || '—') + '</span></td>'
      + '<td>' + esc(r.break_type) + '</td>'
      + '<td>' + (r.start_at ? dt(r.start_at) : '—') + '</td>'
      + '<td>' + (r.end_at ? dt(r.end_at) : '—') + '</td>'
      + '<td>' + brMin(r.permitted_seconds) + '</td>'
      + '<td>' + brMin(r.actual_seconds) + '</td>'
      + '<td>' + (r.excess_seconds > 0 ? '<span class="tag t-danger">' + brMin(r.excess_seconds) + '</span>' : '—') + '</td>'
      + '<td class="mut" style="max-width:220px">' + esc(r.delay_reason || '—') + '</td>'
      + '<td><button class="btn" data-br-review="' + r.id + '" data-br-status="' + (r.review_status || 'NONE') + '"><span class="tag ' + (BR_REV_TAG[r.review_status] || 't-off') + '">' + esc(r.review_status || 'NONE') + '</span></button></td></tr>').join('')
      || '<tr><td colspan="10" class="mut">No breaks in this range.</td></tr>';
  } catch (e) {
    $('#br-rows').innerHTML = isDenied(e) ? deniedCard() : '<tr><td colspan="10" class="mut">' + esc(e.message) + '</td></tr>';
  }
}
// Review a break (Admin/HR): set remarks + mark reviewed.
document.addEventListener('click', async (e) => {
  const btn = e.target.closest && e.target.closest('[data-br-review]');
  if (!btn) return;
  const remarks = window.prompt('Reviewer remarks (optional) — saving marks this break REVIEWED:', '');
  if (remarks === null) return;
  try {
    await api('/reports/breaks/' + Number(btn.dataset.brReview) + '/review', { method: 'PUT', body: JSON.stringify({ review_status: 'REVIEWED', reviewer_remarks: remarks || null }) });
    toast('Break reviewed'); loadBreakReport();
  } catch (err) { alert(err.message); }
});

// ---- Section 14: meeting report ----
const MR_STATUS_TAG = { SCHEDULED: 't-warn', IN_PROGRESS: 't-ok', COMPLETED: 't-off', CANCELLED: 't-danger' };
async function loadMeetingReport() {
  const qs = new URLSearchParams();
  qs.set('from', $('#mr-from').value || today());
  qs.set('to', $('#mr-to').value || today());
  try {
    const d = await api('/reports/meetings?' + qs.toString());
    $('#mr-rows').innerHTML = (d.data || []).map((r) => '<tr>'
      + '<td><span class="nm">' + esc(r.title) + '</span></td>'
      + '<td>' + esc(r.date || '—') + '</td>'
      + '<td><span class="tag ' + (MR_STATUS_TAG[r.status] || 't-off') + '">' + esc((r.status || '').replace('_', ' ')) + '</span></td>'
      + '<td>' + (r.participants ?? 0) + '</td>'
      + '<td>' + (r.attended ?? 0) + '</td>'
      + '<td>' + brMin(r.scheduled_seconds) + '</td>'
      + '<td>' + brMin(r.actual_seconds) + '</td></tr>').join('')
      || '<tr><td colspan="7" class="mut">No meetings in this range.</td></tr>';
  } catch (e) {
    $('#mr-rows').innerHTML = isDenied(e) ? deniedCard() : '<tr><td colspan="7" class="mut">' + esc(e.message) + '</td></tr>';
  }
}
document.querySelectorAll('[data-export]').forEach((b) => b.addEventListener('click', () => {
  const kind = b.dataset.export;
  const qs = kind === 'productivity' ? '?date=' + today() : '?from=' + today() + '&to=' + today();
  downloadCsv('/export/' + kind + qs, kind + '_' + today() + '.csv');
}));

// ---- drawer / drill-down ----
let DID = null;
async function openEmployee(id, name) {
  DID = id;
  $('#d-name').textContent = name; $('#d-sub').textContent = 'Employee #' + id + ' · today';
  $('#drawer').classList.add('open');
  // Section 7: show the backdrop above the page and lock background scroll.
  const bd = $('#drawer-backdrop'); if (bd) bd.classList.add('open');
  document.body.classList.add('drawer-lock');
  $$('.tab').forEach((tb) => tb.classList.toggle('active', tb.dataset.tab === 'timeline'));
  loadTab('timeline');
}
function closeDrawer() {
  $('#drawer').classList.remove('open');
  const bd = $('#drawer-backdrop'); if (bd) bd.classList.remove('open');
  document.body.classList.remove('drawer-lock');
}
$('#drawer-x').onclick = closeDrawer;
// Section 7: clicking the backdrop (anywhere outside the panel) closes it; a click
// inside the drawer never reaches the backdrop, so it stays open. Esc also closes.
$('#drawer-backdrop').onclick = closeDrawer;
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape' && $('#drawer').classList.contains('open')) closeDrawer();
});
$$('.tab').forEach((tb) => tb.onclick = () => {
  $$('.tab').forEach((x) => x.classList.remove('active')); tb.classList.add('active'); loadTab(tb.dataset.tab);
});
async function loadTab(tab) {
  const body = $('#d-body'); body.innerHTML = '<div class="mut">Loading…</div>';
  try {
    if (tab === 'timeline') {
      const d = await api('/reports/employee/' + DID + '/timeline');
      body.innerHTML = d.timeline.length ? '<div class="tl">' + d.timeline.map((e) =>
        '<div class="ev"><span class="tm">' + t(e.time) + '</span>' + esc(e.label) + (e.detail ? ' <span class="mut">(' + esc(e.detail) + ')</span>' : '') + '</div>').join('') + '</div>'
        : '<div class="mut">No events today.</div>';
    } else if (tab === 'apps') {
      const d = await api('/reports/employee/' + DID + '/app-usage');
      body.innerHTML = tableFrom(d.data, ['app_name', 'category', 'seconds', 'status'], ['App', 'Category', 'Time', 'Status'], true);
    } else if (tab === 'sites') {
      const d = await api('/reports/employee/' + DID + '/website-usage');
      body.innerHTML = tableFrom(d.data, ['site', 'category', 'seconds', 'status'], ['Site', 'Category', 'Time', 'Status'], true);
    } else if (tab === 'compliance') {
      const d = await api('/reports/employee/' + DID + '/compliance');
      body.innerHTML = tableFrom(d.data, ['started_at', 'event_type', 'severity', 'detected_value'], ['Time', 'Type', 'Severity', 'Detected'], false);
    }
  } catch (e) { body.innerHTML = '<div class="mut">' + (isDenied(e) ? 'Your role cannot view this tab.' : esc(e.message)) + '</div>'; }
}
function tableFrom(rows, keys, heads, secs) {
  if (!rows || !rows.length) return '<div class="mut">No data.</div>';
  return '<table><thead><tr>' + heads.map((h) => '<th>' + esc(h) + '</th>').join('') + '</tr></thead><tbody>'
    + rows.map((r) => '<tr>' + keys.map((k) => {
      let v = r[k];
      if (k === 'seconds' && secs) v = secH(Number(v));
      if (k === 'started_at') v = t(v);
      return '<td>' + esc(String(v ?? '')) + '</td>';
    }).join('') + '</tr>').join('') + '</tbody></table>';
}

// ---- CSV download (with auth header) ----
async function downloadCsv(path, filename) {
  try {
    const blob = await apiBlob(path);
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a'); a.href = url; a.download = filename; a.click();
    URL.revokeObjectURL(url);
    $('#rp-msg') && ($('#rp-msg').textContent = '✓ ' + filename + ' downloaded.');
  } catch (e) {
    const m = e.status === 403 ? 'Your role cannot export data (export.data permission required).' : e.message;
    if (CURRENT === 'reports') $('#rp-msg').textContent = '✕ ' + m; else alert(m);
  }
}

// ---- 10. users (login accounts) ----
const ROLES = [
  ['SUPER_ADMIN', 'Super Admin'], ['COMPANY_ADMIN', 'Company Admin'], ['BRANCH_ADMIN', 'Branch Admin'],
  ['HR_ADMIN', 'HR Admin'], ['MANAGER', 'Manager'], ['TEAM_LEADER', 'Team Leader'],
  ['EMPLOYEE', 'Employee'], ['COMPLIANCE_OFFICER', 'Compliance Officer'], ['AUDITOR', 'Auditor'],
];
let USER_LIST = [], USER_EDIT_ID = null, USER_SEARCH_TIMER = null;
async function loadUsers() {
  const q = $('#u-q').value.trim();
  try {
    const d = await api('/users?per_page=200' + (q ? '&q=' + encodeURIComponent(q) : ''));
    USER_LIST = d.data || [];
    $('#u-rows').innerHTML = USER_LIST.map((u) => {
      const roleLabel = (ROLES.find((r) => r[0] === u.role) || [null, u.role_name || u.role || '—'])[1];
      return '<tr data-uid="' + u.id + '">'
        + '<td><span class="nm">' + esc(u.name) + '</span>'
        + (u.must_change_password ? ' <span class="tag t-warn" title="Still on a temporary password">TEMP PW</span>' : '') + '</td>'
        + '<td>' + esc(u.email) + '</td>'
        + '<td><span class="tag t-info">' + esc(roleLabel) + '</span></td>'
        + '<td>' + esc(u.employee ? u.employee.name + ' (' + (u.employee.code || '#' + u.employee.id) + ')' : '—') + '</td>'
        + '<td><span class="tag ' + (u.status === 'ACTIVE' ? 't-ok' : 't-off') + '">' + esc(u.status || '—') + '</span></td>'
        + '<td>' + (u.last_login_at ? dt(u.last_login_at) : '—') + '</td>'
        + '<td class="row" style="flex-wrap:nowrap">'
        + '<button class="btn" data-uact="edit">Edit</button>'
        + '<button class="btn" data-uact="reset">Reset password</button>'
        + (u.status === 'ACTIVE' ? '<button class="btn danger" data-uact="disable">Disable</button>' : '')
        + '</td></tr>';
    }).join('') || '<tr><td colspan="7" class="mut">No users' + (q ? ' matching "' + esc(q) + '"' : ' yet — add the first login account') + '.</td></tr>';
  } catch (e) {
    $('#u-rows').innerHTML = isDenied(e) ? deniedCard() : '<tr><td colspan="7" class="mut">' + esc(e.message) + '</td></tr>';
  }
}
$('#u-q').addEventListener('input', () => { clearTimeout(USER_SEARCH_TIMER); USER_SEARCH_TIMER = setTimeout(loadUsers, 300); });
$('#u-rows').addEventListener('click', async (e) => {
  const btn = e.target.closest('[data-uact]');
  if (!btn) return;
  const tr = btn.closest('tr[data-uid]');
  const u = USER_LIST.find((x) => x.id === Number(tr.dataset.uid));
  if (!u) return;
  if (btn.dataset.uact === 'edit') { openUserModal(u); return; }
  if (btn.dataset.uact === 'reset') {
    // R4 item 1: show a suggested password the admin can edit to a custom one.
    // Nothing is applied (and no sessions die) until they press Apply password.
    showCredentials(u.email, genPw(), u.id);
    return;
  }
  if (btn.dataset.uact === 'disable') {
    if (!confirm('Disable ' + u.email + '?\nThey are signed out everywhere immediately and can no longer log in.')) return;
    try { await api('/users/' + u.id, { method: 'DELETE' }); loadUsers(); }
    catch (err) { alert(err.message); }
  }
});
async function openUserModal(u) {
  USER_EDIT_ID = u ? u.id : null;
  $('#u-m-title').textContent = u ? 'Edit user' : 'Add user';
  $('#u-m-err').textContent = '';
  const roleSel = $('#uf-role');
  if (!roleSel.dataset.loaded) {
    // R4 item 5: include custom organisation roles in the assignment list.
    let list = ROLES.map((r) => ({ slug: r[0], name: r[1] }));
    try { const d = await api('/roles'); if ((d.data || []).length) list = d.data.map((r) => ({ slug: r.slug, name: r.name })); } catch (e) { /* non-admins keep the static list */ }
    fillSelect(roleSel, list, (r) => r.name, (r) => r.slug);
    roleSel.dataset.loaded = '1';
  }
  $('#uf-name').value = u ? (u.name || '') : '';
  $('#uf-email').value = u ? (u.email || '') : '';
  roleSel.value = (u && u.role) || 'EMPLOYEE';
  $('#uf-status').value = (u && u.status) || 'ACTIVE';
  $('#uf-status').disabled = !u; // new accounts always start ACTIVE
  const empSel = $('#uf-emp');
  if (u) {
    // The API only accepts the employee link at creation — show it read-only.
    fillSelect(empSel, u.employee ? [u.employee] : [], (r) => r.name + ' (' + (r.code || '#' + r.id) + ')', (r) => r.id, u.employee ? null : '— none —');
    empSel.disabled = true;
  } else {
    empSel.disabled = false;
    try {
      const emps = await employeesList();
      fillSelect(empSel, emps, (e) => fullName(e) + ' (' + (e.employee_code || '#' + e.id) + ')', (e) => e.id, '— none —');
    } catch (e) { fillSelect(empSel, [], (x) => x, (x) => x, '— none —'); }
  }
  $('#user-ovl').classList.add('open');
}
$('#u-add').onclick = () => openUserModal(null);
$('#u-m-save').onclick = async () => {
  $('#u-m-err').textContent = '';
  const body = {
    name: $('#uf-name').value.trim(),
    email: $('#uf-email').value.trim(),
    role: $('#uf-role').value,
  };
  if (!body.name || !body.email) { $('#u-m-err').textContent = 'Name and email are required.'; return; }
  try {
    if (USER_EDIT_ID) {
      body.status = $('#uf-status').value;
      await api('/users/' + USER_EDIT_ID, { method: 'PUT', body: JSON.stringify(body) });
      $('#user-ovl').classList.remove('open');
    } else {
      if ($('#uf-emp').value) body.employee_id = Number($('#uf-emp').value);
      const r = await api('/users', { method: 'POST', body: JSON.stringify(body) });
      $('#user-ovl').classList.remove('open');
      showCredentials(body.email, r.temp_password);
    }
    loadUsers();
  } catch (err) { $('#u-m-err').textContent = err.message; }
};

// ---- one-time credentials panel (user create / reset / employee auto-login) ----
let CRED_PENDING_UID = null;
const genPw = () => {
  const c = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789@#$%';
  return Array.from(crypto.getRandomValues(new Uint32Array(12))).map((x) => c[x % c.length]).join('');
};
function showCredentials(email, tempPassword, pendingUserId) {
  CRED_PENDING_UID = pendingUserId || null;
  $('#cred-email').value = email || '';
  $('#cred-pass').value = tempPassword || '';
  $('#cred-pass').readOnly = !CRED_PENDING_UID;
  $('#cred-apply-row').classList.toggle('hide', !CRED_PENDING_UID);
  $('#cred-msg').textContent = CRED_PENDING_UID
    ? 'Nothing has changed yet — the reset applies only when you press Apply password.'
    : '';
  $('#cred-ovl').classList.add('open');
}
$('#cred-apply').onclick = async () => {
  if (!CRED_PENDING_UID) return;
  const pw = $('#cred-pass').value.trim();
  if (pw.length < 8) { $('#cred-msg').textContent = '✕ The password must be at least 8 characters.'; return; }
  try {
    await api('/users/' + CRED_PENDING_UID + '/reset-password', { method: 'POST', body: JSON.stringify({ password: pw }) });
    $('#cred-msg').textContent = '✓ Password applied and emailed. All their old sessions are signed out; they must change it at first sign-in.';
    $('#cred-apply-row').classList.add('hide');
    CRED_PENDING_UID = null;
    loadUsers();
  } catch (e) { $('#cred-msg').textContent = '✕ ' + e.message; }
};
$('#cred-copy').onclick = async () => {
  const text = 'Login: ' + $('#cred-email').value + '\nTemporary password: ' + $('#cred-pass').value;
  try {
    await navigator.clipboard.writeText(text);
    $('#cred-msg').textContent = '✓ Copied to clipboard.';
  } catch (e) {
    $('#cred-pass').select();
    $('#cred-msg').textContent = 'Clipboard blocked by the browser — the password is selected, press Ctrl+C.';
  }
};

// ---- 11. attendance sheet + regularization ----
let ATT_LIST = [], ATT_EDIT_ID = null;
function initAttendance() {
  if (!$('#at-date').value) $('#at-date').value = today();
  const ySel = $('#hol-year');
  if (!ySel.options.length) {
    const y = new Date().getFullYear();
    fillSelect(ySel, [y - 1, y, y + 1], (v) => String(v), (v) => String(v));
    ySel.value = String(y);
    ySel.addEventListener('change', loadHolidays);
  }
  loadAttendance();
  loadHolidays();
}
async function loadAttendance() {
  const date = $('#at-date').value || today(), st = $('#at-status').value;
  // Notes accumulate one line per correction — show the latest, full text on hover.
  const lastNote = (s) => { const line = String(s || '').trim().split('\n').pop() || ''; return line.length > 70 ? line.slice(0, 70) + '…' : line; };
  try {
    const d = await api('/attendance?per_page=200&date=' + encodeURIComponent(date) + (st ? '&status=' + encodeURIComponent(st) : ''));
    ATT_LIST = d.data || [];
    const sc = { PRESENT: 't-ok', ABSENT: 't-danger', HALF_DAY: 't-warn', ON_LEAVE: 't-info', MISMATCH: 't-warn' };
    $('#at-rows').innerHTML = ATT_LIST.map((r) => '<tr>'
      + '<td><span class="nm">' + esc(r.employee_name || '#' + r.employee_id) + '</span> <span style="color:var(--ink-3)">' + esc(r.employee_code || '') + '</span></td>'
      + '<td><span class="tag ' + (sc[r.status] || 't-off') + '">' + esc(r.status || '—') + '</span></td>'
      + '<td>' + t(r.check_in_at) + '</td><td>' + t(r.check_out_at) + '</td>'
      + '<td>' + (r.late_minutes ?? 0) + '</td>'
      + '<td>' + esc(r.source || '—') + '</td>'
      + '<td title="' + esc(r.notes || '') + '">' + (esc(lastNote(r.notes)) || '—') + '</td>'
      + '<td><button class="btn" data-att-edit="' + r.id + '">Edit</button></td></tr>').join('')
      || '<tr><td colspan="8" class="mut">No attendance rows for ' + esc(date) + '. Rows appear from agent logins, biometric punches or the nightly marking job — use "+ Add missed day" to record one manually.</td></tr>';
  } catch (e) {
    $('#at-rows').innerHTML = isDenied(e) ? deniedCard() : '<tr><td colspan="8" class="mut">' + esc(e.message) + '</td></tr>';
  }
}
$('#at-load').onclick = loadAttendance;
$('#at-date').addEventListener('change', loadAttendance);
$('#at-status').addEventListener('change', loadAttendance);
$('#at-add').onclick = () => openAttModal(null);
$('#at-rows').addEventListener('click', (e) => {
  const btn = e.target.closest('[data-att-edit]');
  if (!btn) return;
  const r = ATT_LIST.find((x) => x.id === Number(btn.dataset.attEdit));
  if (r) openAttModal(r);
});
// "YYYY-MM-DD HH:MM:SS" ⇄ datetime-local's "YYYY-MM-DDTHH:MM"
const toLocalDt = (s) => s ? String(s).replace(' ', 'T').slice(0, 16) : '';
const fromLocalDt = (v) => v ? v.replace('T', ' ') + ':00' : null;
async function openAttModal(row) {
  ATT_EDIT_ID = row ? row.id : null;
  $('#att-m-title').textContent = row
    ? 'Regularize — ' + (row.employee_name || '#' + row.employee_id) + ' · ' + (row.work_date || '')
    : 'Add missed day';
  $('#att-m-err').textContent = '';
  const empSel = $('#af-emp');
  if (row) {
    fillSelect(empSel, [row], (r) => (r.employee_name || '#' + r.employee_id) + (r.employee_code ? ' (' + r.employee_code + ')' : ''), (r) => r.employee_id);
    empSel.disabled = true;
    $('#af-date').value = row.work_date || '';
    $('#af-date').disabled = true;
    $('#af-status').value = ['PRESENT', 'ABSENT', 'HALF_DAY', 'ON_LEAVE'].includes(row.status) ? row.status : 'PRESENT';
    $('#af-in').value = toLocalDt(row.check_in_at);
    $('#af-out').value = toLocalDt(row.check_out_at);
  } else {
    empSel.disabled = false;
    try { fillEmpPicker(empSel, await employeesList()); }
    catch (e) { fillSelect(empSel, [], (x) => x, (x) => x, isDenied(e) ? 'Your role cannot list employees' : e.message); }
    $('#af-date').disabled = false;
    $('#af-date').value = $('#at-date').value || today();
    $('#af-status').value = 'PRESENT';
    // Pre-fill sensible punch times (editable) so the admin records the ACTUAL
    // time, not just the date — 09:30 in / 18:00 out as a starting point.
    const d0 = $('#af-date').value;
    $('#af-in').value = d0 ? d0 + 'T09:30' : ''; $('#af-out').value = d0 ? d0 + 'T18:00' : '';
  }
  $('#af-reason').value = '';
  $('#att-ovl').classList.add('open');
}
$('#att-m-save').onclick = async () => {
  $('#att-m-err').textContent = '';
  const reason = $('#af-reason').value.trim();
  if (!reason) { $('#att-m-err').textContent = 'A reason is required — corrections feed payroll and are audited.'; return; }
  const body = {
    status: $('#af-status').value,
    check_in_at: fromLocalDt($('#af-in').value),
    check_out_at: fromLocalDt($('#af-out').value),
    reason,
  };
  try {
    if (ATT_EDIT_ID) {
      await api('/attendance/' + ATT_EDIT_ID, { method: 'PUT', body: JSON.stringify(body) });
    } else {
      if (!$('#af-emp').value) { $('#att-m-err').textContent = 'Pick an employee.'; return; }
      if (!$('#af-date').value) { $('#att-m-err').textContent = 'Pick a date.'; return; }
      body.employee_id = Number($('#af-emp').value);
      body.work_date = $('#af-date').value;
      await api('/attendance', { method: 'POST', body: JSON.stringify(body) });
    }
    $('#att-ovl').classList.remove('open');
    loadAttendance();
  } catch (err) { $('#att-m-err').textContent = err.message; }
};

// ---- holidays ----
async function loadHolidays() {
  const y = $('#hol-year').value;
  try {
    const d = await api('/holidays?year=' + encodeURIComponent(y));
    $('#hol-rows').innerHTML = (d.data || []).map((h) => '<tr>'
      + '<td><b>' + esc(h.holiday_date) + '</b></td>'
      + '<td>' + esc(h.name) + '</td>'
      + '<td><span class="tag ' + (h.type === 'PUBLIC' ? 't-info' : 't-idle') + '">' + esc(h.type || '—') + '</span></td>'
      + '<td style="text-align:right"><button class="btn danger" data-hol-del="' + h.id + '" data-hol-name="' + esc(h.name) + '">✕</button></td></tr>').join('')
      || '<tr><td colspan="4" class="mut">No holidays for ' + esc(y) + ' — add them below so nobody is marked late or absent on those days.</td></tr>';
  } catch (e) {
    $('#hol-rows').innerHTML = isDenied(e) ? deniedCard() : '<tr><td colspan="4" class="mut">' + esc(e.message) + '</td></tr>';
  }
}
$('#hol-add').onclick = async () => {
  const msg = $('#hol-msg');
  msg.textContent = '';
  const body = { holiday_date: $('#hol-date').value, name: $('#hol-name').value.trim(), type: $('#hol-type').value };
  if (!body.holiday_date || !body.name) { msg.textContent = 'Pick a date and enter a name.'; return; }
  try {
    await api('/holidays', { method: 'POST', body: JSON.stringify(body) });
    $('#hol-date').value = ''; $('#hol-name').value = '';
    msg.textContent = '✓ Added.';
    loadHolidays();
  } catch (e) { msg.textContent = '✕ ' + e.message; }
};
$('#hol-rows').addEventListener('click', async (e) => {
  const btn = e.target.closest('[data-hol-del]');
  if (!btn) return;
  if (!confirm('Remove holiday "' + btn.dataset.holName + '"?')) return;
  try { await api('/holidays/' + Number(btn.dataset.holDel), { method: 'DELETE' }); loadHolidays(); }
  catch (err) { $('#hol-msg').textContent = '✕ ' + err.message; }
});

// ---- forced password change (must_change_password) ----
function openForcedPwd() {
  ['#pw-cur', '#pw-new', '#pw-conf'].forEach((s) => { $(s).value = ''; });
  $('#pw-err').textContent = '';
  $('#pwd-ovl').classList.add('open'); // no ✕ and no click-outside — change it or sign out
}
$('#pw-save').onclick = async () => {
  $('#pw-err').textContent = '';
  const cur = $('#pw-cur').value, nw = $('#pw-new').value, cf = $('#pw-conf').value;
  if (!cur || !nw) { $('#pw-err').textContent = 'Enter your current and new password.'; return; }
  if (nw !== cf) { $('#pw-err').textContent = 'New password and confirmation do not match.'; return; }
  try {
    await api('/auth/change-password', {
      method: 'POST',
      body: JSON.stringify({ current_password: cur, new_password: nw, new_password_confirmation: cf }),
    });
    ME.must_change_password = false;
    $('#pwd-ovl').classList.remove('open');
  } catch (e) { $('#pw-err').textContent = e.message; }
};
$('#pw-signout').onclick = () => { $('#pwd-ovl').classList.remove('open'); $('#signout').click(); };

// ---- licence (R2-1) ----
async function loadLicense() {
  const box = $('#lic-status');
  try {
    const d = await api('/license');
    if ($('#lic-fp')) $('#lic-fp').value = d.machine_fingerprint || '';
    const pill = (txt, color) => `<span style="display:inline-block;padding:3px 10px;border-radius:999px;font-weight:700;font-size:12px;background:${color}22;color:${color}">${txt}</span>`;
    const STATUS_COLORS = { active: '#16A34A', expired: '#D97706', unconfigured: '#6B7B90' };
    const color = d.operational ? (STATUS_COLORS[d.status] || '#16A34A') : '#DC2626';
    if (!d.configured) {
      const left = d.evaluation_days_left;
      box.innerHTML = left > 0
        ? `${pill('EVALUATION', '#D97706')} <span class="hint">free evaluation — <b>${left} day${left === 1 ? '' : 's'} left</b> (ends ${d.evaluation_ends_at}). After that, monitoring stops until a licence key is entered below.</span>`
        : `${pill('EVALUATION ENDED', '#DC2626')} <span class="hint"><b>Monitoring is blocked.</b> The 7-day evaluation has ended — enter your licence key below to resume instantly. Get a key from the client portal or WhatsApp 90000 98877.</span>`;
      return;
    }
    const rows = [
      ['Status', `${pill(d.status.toUpperCase().replace(/_/g, ' '), color)} ${d.operational ? '' : ' — agent sync is blocked'}${d.status === 'expired' && d.within_grace ? ` (grace period: renew within ${d.grace_days} days of expiry)` : ''}`],
      ['Key', `<code>${d.key_masked || ''}</code>`],
      ['Company', d.company || '—'],
      ['Plan', (d.plan || '—') + (d.kind ? ' · ' + d.kind : '') + (d.deployment ? ' · ' + d.deployment.replace('_', '-') : '')],
      ['Device seats', d.device_limit != null ? `${d.devices_registered} registered / ${d.device_limit} licensed` : `${d.devices_registered} registered`],
      ['Expires', d.expires_at ? `${d.expires_at} (+${d.grace_days} grace days)` : 'never (perpetual)'],
      ['Last check', d.last_checked_at || 'never'],
      ['Source', d.source === 'file' ? 'Offline licence file (this PC)' : (d.central_url ? 'SmartEPT Central (online)' : '—')],
    ];
    box.innerHTML = '<table>' + rows.map(([k, v]) => `<tr><th style="text-align:left;white-space:nowrap;padding:6px 18px 6px 0">${k}</th><td>${v}</td></tr>`).join('') + '</table>'
      + (d.last_error ? `<div class="mut" style="color:#DC2626;margin-top:8px">Last error: ${d.last_error}</div>` : '');
  } catch (e) { box.innerHTML = `<span style="color:#DC2626">${e.message}</span>`; }
}
$('#lic-save').onclick = async () => {
  const key = $('#lic-key').value.trim().toUpperCase();
  if (!key) { $('#lic-msg').textContent = 'Enter the licence key first.'; return; }
  $('#lic-msg').textContent = 'Validating with SmartEPT Central…';
  try {
    const d = await api('/license', { method: 'POST', body: JSON.stringify({ key }) });
    $('#lic-msg').textContent = d.status === 'active' ? '✓ Licence activated.' : 'Central answered: ' + (d.last_error || d.status);
    $('#lic-key').value = '';
    loadLicense();
  } catch (e) { $('#lic-msg').textContent = e.message; }
};
$('#lic-fp-copy').onclick = () => {
  navigator.clipboard.writeText($('#lic-fp').value || '').then(() => {
    $('#lic-fp-copy').textContent = 'Copied'; setTimeout(() => $('#lic-fp-copy').textContent = 'Copy', 1200);
  });
};
$('#lic-import').onclick = async () => {
  const f = $('#lic-file').files[0];
  if (!f) { $('#lic-file-msg').textContent = 'Choose a .lic file first.'; return; }
  $('#lic-file-msg').textContent = 'Importing…';
  try {
    const token = (await f.text()).trim();
    const d = await api('/license/import', { method: 'POST', body: JSON.stringify({ token }) });
    $('#lic-file-msg').textContent = d.status === 'active'
      ? '✓ Licence activated from file — monitoring is licensed.'
      : ('File rejected: ' + (d.last_error || d.status) + (d.status === 'server_mismatch' ? ' (this file is locked to a different machine)' : ''));
    loadLicense();
  } catch (e) { $('#lic-file-msg').textContent = e.message; }
};
$('#lic-check').onclick = async () => {
  $('#lic-msg').textContent = 'Checking…';
  try {
    const d = await api('/license/validate', { method: 'POST' });
    $('#lic-msg').textContent = d.status === 'active' ? '✓ Valid — bundle refreshed.' : 'Central answered: ' + (d.last_error || d.status);
    loadLicense();
  } catch (e) { $('#lic-msg').textContent = e.message; }
};

// ---- audit & ops (R2-4) ----
async function loadOps() {
  loadAudit();
  loadRetention();
  loadStorageConfig();
  try {
    const s = await api('/ops/storage-usage');
    $('#ops-storage').innerHTML = (s.data || []).length
      ? '<table>' + s.data.map((r) => '<tr><td>' + esc(r.company) + '</td><td>' + r.files + ' files</td><td><b>' + esc(r.human) + '</b></td></tr>').join('') + '</table>'
      : 'No evidence files stored yet.';
  } catch (e) { $('#ops-storage').textContent = e.message; }
  try {
    const b = await api('/ops/backups');
    $('#ops-backups').innerHTML = (b.data || []).length
      ? '<table>' + b.data.slice(0, 5).map((r) => '<tr><td>' + esc(r.name) + '</td><td>' + esc(r.human) + '</td></tr>').join('') + '</table>'
      : 'No backups yet — the first one runs tonight at 01:30, or click "Back up now".';
  } catch (e) { $('#ops-backups').textContent = e.message; }
}
async function loadStorageConfig() {
  try {
    const c = await api('/ops/storage-config');
    $('#gcs-bucket').value = c.bucket || '';
    $('#gcs-project').value = c.project_id || '';
    $('#gcs-enabled').checked = !!c.enabled;
    if (c.has_key) $('#gcs-key').placeholder = 'A key is already saved — paste a new one only to replace it';
    const bits = ['Active store: <b>' + esc(c.active_disk === 'gcs' ? 'Google Cloud Storage' : 'This server (local disk)') + '</b>'];
    if (!c.sdk_installed) bits.push('<span style="color:#B45309">⚠ Cloud libraries not installed — IT runs <code>composer require google/cloud-storage league/flysystem-google-cloud-storage</code> once in the app folder to enable</span>');
    $('#gcs-status').innerHTML = bits.join(' · ');
    $('#loc-path').value = c.local_path || '';
    $('#loc-status').innerHTML = 'When Cloud Storage is off, new evidence is stored ' + (c.local_path ? 'in <b>' + esc(c.local_path) + '</b>' : 'on this server (default app storage)') + '.';
  } catch (e) { $('#gcs-status').textContent = e.message; }
}
$('#gcs-test').onclick = async () => {
  const msg = $('#gcs-msg'); msg.style.color = ''; msg.textContent = 'Testing…';
  try {
    const r = await api('/ops/storage-config/test', { method: 'POST', body: JSON.stringify({
      bucket: $('#gcs-bucket').value.trim(), key_json: $('#gcs-key').value.trim() || undefined }) });
    msg.textContent = r.message; msg.style.color = r.ok ? '#15803D' : '#B45309';
  } catch (e) { msg.textContent = e.message; msg.style.color = '#B91C1C'; }
};
$('#gcs-save').onclick = async () => {
  const msg = $('#gcs-msg'); msg.style.color = ''; msg.textContent = 'Saving…';
  try {
    await api('/ops/storage-config', { method: 'PUT', body: JSON.stringify({
      enabled: $('#gcs-enabled').checked, bucket: $('#gcs-bucket').value.trim(),
      project_id: $('#gcs-project').value.trim(), key_json: $('#gcs-key').value.trim() || undefined }) });
    $('#gcs-key').value = '';
    toast('Cloud storage settings saved');
    loadStorageConfig();
  } catch (e) { msg.style.color = '#B91C1C'; msg.textContent = e.message; }
};
$('#loc-test').onclick = async () => {
  const m = $('#loc-msg'); m.style.color = ''; m.textContent = 'Testing…';
  try { const r = await api('/ops/storage-local/test', { method: 'POST', body: JSON.stringify({ local_path: $('#loc-path').value.trim() }) });
    m.textContent = r.message; m.style.color = r.ok ? '#15803D' : '#B45309'; }
  catch (e) { m.textContent = e.message; m.style.color = '#B91C1C'; }
};
$('#loc-save').onclick = async () => {
  const m = $('#loc-msg'); m.style.color = ''; m.textContent = 'Saving…';
  try { await api('/ops/storage-local', { method: 'PUT', body: JSON.stringify({ local_path: $('#loc-path').value.trim() }) });
    toast('Local storage folder saved'); m.textContent = ''; loadStorageConfig(); }
  catch (e) { m.style.color = '#B91C1C'; m.textContent = e.message; }
};
// ---- storage cleanup (17-Jul) ----
$('#ops-cleanup').onclick = () => {
  const d = new Date(); d.setDate(d.getDate() - 30);
  $('#cl-from').value = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  $('#cl-to').value = today();
  $('#cl-confirm').value = ''; $('#cl-msg').textContent = '';
  $('#cleanup-ovl').classList.add('open');
};
$('#cleanup-x').onclick = $('#cleanup-cancel').onclick = () => $('#cleanup-ovl').classList.remove('open');
$('#cleanup-run').onclick = async () => {
  const msg = $('#cl-msg');
  const targets = [];
  if ($('#cl-shots').checked) targets.push('screenshots');
  if ($('#cl-activity').checked) targets.push('activity');
  if ($('#cl-apps').checked) targets.push('app_usage');
  if ($('#cl-sites').checked) targets.push('website_usage');
  if ($('#cl-presence').checked) targets.push('presence');
  if (!targets.length && !$('#cl-delviol').checked) { msg.textContent = 'Pick at least one thing to delete.'; return; }
  if ($('#cl-confirm').value.trim() !== 'DELETE') { msg.textContent = 'Type DELETE (capital letters) to confirm — this cannot be undone.'; return; }
  if (!$('#cl-from').value || !$('#cl-to').value) { msg.textContent = 'Pick both dates.'; return; }
  msg.textContent = 'Deleting…';
  try {
    const r = await api('/ops/storage-cleanup', { method: 'POST', body: {
      from_date: $('#cl-from').value, to_date: $('#cl-to').value, targets: targets,
      keep_violation_evidence: $('#cl-keepviol').checked,
      delete_violation_records: $('#cl-delviol').checked,
    }});
    const parts = Object.entries(r.result || {}).map(([k, v]) =>
      k + ': ' + (v.rows ?? 0) + ' rows' + (v.human ? ' (' + v.human + ' freed)' : ''));
    msg.textContent = '✓ Done — ' + (parts.join(' · ') || 'nothing matched that range.');
    $('#cl-confirm').value = '';
    loadOps();
  } catch (e) { msg.textContent = e.message; }
};

// ---- auto-cleanup retention (17-Jul) ----
async function loadRetention() {
  try {
    const r = (await api('/ops/retention')).data;
    $('#rt-enabled').checked = !!r.auto_cleanup_enabled;
    $('#rt-shots').value = r.retention_screenshots_days || '';
    $('#rt-activity').value = r.retention_activity_days || '';
    $('#rt-usage').value = r.retention_usage_days || '';
    $('#rt-viol').value = r.retention_violation_days || '';
    $('#rt-base').value = r.data_retention_days || 90;
    $('#rt-keepviol').checked = !!r.retention_keep_violation_evidence;
  } catch (e) { $('#rt-msg').textContent = e.message; }
}
async function saveRetention() {
  const numOr = (id) => { const v = $(id).value.trim(); return v === '' ? null : Math.max(1, parseInt(v, 10)); };
  const body = {
    auto_cleanup_enabled: $('#rt-enabled').checked,
    data_retention_days: numOr('#rt-base') || 90,
    retention_screenshots_days: numOr('#rt-shots'),
    retention_activity_days: numOr('#rt-activity'),
    retention_usage_days: numOr('#rt-usage'),
    retention_violation_days: numOr('#rt-viol'),
    retention_keep_violation_evidence: $('#rt-keepviol').checked,
  };
  try { await api('/ops/retention', { method: 'PUT', body: JSON.stringify(body) }); toast('Cleanup schedule saved'); }
  catch (e) { $('#rt-msg').textContent = e.message; }
}
async function previewPurge() {
  $('#rt-out').innerHTML = '<span class="mut">Checking…</span>';
  try {
    const r = await api('/ops/purge-run', { method: 'POST', body: JSON.stringify({ dry_run: true }) });
    $('#rt-out').innerHTML = r.lines.length
      ? '<b>Would delete (dry run — nothing removed):</b><br>' + r.lines.map(esc).join('<br>')
      : '<span class="mut">Nothing is past its retention window right now — disk is clean.</span>';
  } catch (e) { $('#rt-out').innerHTML = '<span style="color:var(--danger)">' + esc(e.message) + '</span>'; }
}
$('#rt-save').onclick = saveRetention;
$('#rt-preview').onclick = previewPurge;

async function loadAudit() {
  try {
    const p = new URLSearchParams();
    if ($('#au-action').value.trim()) p.set('action', $('#au-action').value.trim());
    if ($('#au-from').value) p.set('from', $('#au-from').value);
    if ($('#au-to').value) p.set('to', $('#au-to').value);
    const d = await api('/audit-logs?' + p.toString());
    $('#au-rows').innerHTML = (d.data || []).map((r) => '<tr>'
      + '<td>' + dt(r.created_at) + '</td>'
      + '<td>' + esc(r.user?.name || '—') + '</td>'
      + '<td><b>' + esc(r.action) + '</b></td>'
      + '<td>' + esc((r.subject_type || '').split('\\').pop() + (r.subject_id ? ' #' + r.subject_id : '')) + '</td>'
      + '<td class="mut" style="max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + esc(r.changes ? JSON.stringify(r.changes) : '') + '</td>'
      + '<td>' + esc(r.ip || '') + '</td></tr>').join('')
      || '<tr><td colspan="6" class="mut">No audit entries match.</td></tr>';
  } catch (e) {
    $('#au-rows').innerHTML = isDenied(e) ? deniedCard() : '<tr><td colspan="6" class="mut">' + esc(e.message) + '</td></tr>';
  }
}
$('#au-go').onclick = loadAudit;
$('#ops-backup-now').onclick = async () => {
  $('#ops-backup-msg').textContent = 'Backing up…';
  try {
    const r = await api('/ops/backup', { method: 'POST' });
    $('#ops-backup-msg').textContent = '✓ ' + (r.output || 'Done.');
    loadOps();
  } catch (e) { $('#ops-backup-msg').textContent = e.message; }
};

// ---- ⓘ help ----
const HELP = {
  dashboard: ['Live Dashboard', '<h5>What</h5>A real-time picture of the whole company: who is active, idle, on break or offline, plus today\'s violation and screenshot counts and the health of every agent below.<h5>Why</h5>One glance tells you whether the floor is working and whether the monitoring agents themselves are alive and syncing.<h5>How</h5>The table refreshes every 15 seconds automatically. Click any employee row to open their full day — timeline, apps, websites and compliance — in the side drawer.'],
  attendance: ['Attendance', '<h5>What</h5>The day\'s attendance sheet — status per employee (Present, Absent, Half-day, On leave) with check-in/out, late minutes and the source of each verdict — plus the company holiday calendar.<h5>Why</h5>This sheet feeds payroll, so it must be complete and correctable: a downed biometric reader or a forgotten leave application should not cost anyone a day\'s pay.<h5>How</h5>Pick a date and optionally a status filter. Edit any row to regularize it, or use "+ Add missed day" for a date with no record — both require a written reason that is stored on the record and audit-logged, and the row\'s source becomes MANUAL. Maintain holidays below: no late/absent marking happens on them and they appear as HD in the monthly register.'],
  screenshots: ['Screenshots', '<h5>What</h5>The screen captures the desktop agent uploaded for one employee on one day, with the app in focus and the reason each capture fired (interval, random or violation).<h5>Why</h5>Screenshots are the evidence layer: they turn a "13 minutes on YouTube" number into something you can verify before acting.<h5>How</h5>Pick an employee and a date, then click a tile for the full-size image. Captures only exist where the assigned screenshot policy enables them, and every image you open here is recorded in the audit log.'],
  usage: ['Usage & Compliance', '<h5>What</h5>Per-employee time by application and by website for a chosen day, alongside that day\'s compliance events.<h5>Why</h5>This is where productive vs unproductive time becomes concrete — which tools the employee actually used and where policy lines were crossed.<h5>How</h5>Pick an employee and date; categories (PRODUCTIVE, NEUTRAL, blocked) come from the application and website policies you define under Policies. Website names are read from the browser window title in this release.'],
  violations: ['Violations', '<h5>What</h5>The company-wide feed of compliance events: blocked apps and sites, category and severity, what the agent did about it, and a link to screenshot evidence when one was captured.<h5>Why</h5>Reviewing this daily keeps enforcement fair and consistent — the same event always produces the same recorded action.<h5>How</h5>Use "View evidence" to jump straight to that employee\'s screenshots for the day of the event. Export the full log as CSV for HR or audit.'],
  org: ['Organisation', '<h5>What</h5>Your company structure — Branches, Departments, Teams, Designations and Shifts — each on its own tab.<h5>Why</h5>Employees are placed into this structure, and policies, attendance and reports all roll up through it. Create these first so the pickers on the employee form have options.<h5>How</h5>Pick a tab, click <b>+ Add</b>, fill the name (and shift timings / parent branch etc. where relevant), Save. Edit or Delete any row. Deleting a unit does not delete employees — they simply lose that one link. Tip: the bulk employee import also creates any branch/team/department it sees by name, so you can build the whole structure just by importing your staff sheet.'],
  employees: ['Employees', '<h5>What</h5>The employee directory: code, contact, org placement (branch / department / team / shift), employment status and how many monitored devices each person has.<h5>Why</h5>Everything else in SmartEPT hangs off this record — policies resolve through the employee\'s team and department, and agents bind devices to it.<h5>How</h5>Search by name or code, add or edit with the form, and delete with confirmation. Create branches, teams and shifts first so the pickers here have options. When someone leaves, use <b>Relieve</b> (not Delete): one click disables their login, revokes every token, stops the agent on all their PCs and frees the licence seats — with the reason kept in the audit log. Their history stays for reports and payroll.'],
  users: ['Users', '<h5>What</h5>The login accounts for this console and the employee self-service — name, email, role, optional link to an employee record, status and last login.<h5>Why</h5>Accounts and employee records are different things: an auditor logs in but is not an employee, and an employee may exist without any login. Roles decide exactly what each account can see and do.<h5>How</h5>Add a user and SmartEPT generates a strong temporary password shown <b>exactly once</b> — copy it and hand it over; the user must change it at first sign-in. Reset password re-issues a one-time password and signs the user out everywhere. Disable blocks login immediately and kills active sessions; accounts are never hard-deleted because the audit trail references them.'],
  devices: ['Devices', '<h5>What</h5>Every PC where the SmartEPT agent is registered: hostname, OS, agent version, live status, compliance state, sync backlog and last heartbeat.<h5>Why</h5>A stopped or stale agent means a blind spot — this screen tells you whether the data you see elsewhere is complete.<h5>How</h5>Healthy agents heartbeat about every 30 seconds. A growing sync queue with an OFFLINE status usually just means the PC is off; DEGRADED or STOPPED health on an online device needs IT attention. <b>Unbind</b> a lost, replaced or misused PC to stop its agent instantly and free its licence seat — it stays blocked until you <b>Approve re-bind</b>, so nobody can quietly reconnect it.'],
  policies: ['Policies', '<h5>What</h5>The control room: 12 policy types (monitoring master switch, screenshots, webcam presence, app/site rules, network, USB, breaks, attendance, compliance scoring) with versioned edit forms and an assignment panel.<h5>Why</h5>Nothing is captured because the software can — everything is captured because a policy you wrote says so, and the version trail shows what applied when.<h5>How</h5>Pick a type, create or edit a policy (each save bumps the version; agents pick it up on the next heartbeat), then assign it to the company, a branch, department, team, employee or single device. More specific assignments win.'],
  biometric: ['Biometric', '<h5>What</h5>Door-punch integration: connect a cloud attendance API (eTimeOffice-style — punches import into Attendance &amp; payroll automatically every hour), plus the punch log, CSV import, biometric-ID-to-employee mapping, and a daily reconciliation of first punch vs first agent login.<h5>Why</h5>The gap between "in the office" and "at the system" is invisible to either source alone — the mismatch report exposes it in minutes per employee.<h5>How</h5>Fill the Biometric Device Setup form (provider, API base URL, endpoint, corporate ID, credentials), press Test connection to preview punches and their MC machine numbers, then Save with hourly sync enabled — or press Sync now anytime. Separate entry/exit readers? Enter their IN/OUT machine IDs and the machine number decides direction. Employee codes match automatically by employee code or biometric ID (use the prefix field when the feed drops a letter); anything unmatched appears under Map biometric ID → employee, and old punches back-fill once mapped. The mismatch report reads: OK, MISMATCH over 15 minutes, or NO_BIOMETRIC.'],
  integrations: ['API & Integrations', '<h5>What</h5>SmartEPT as an integration hub: API keys let external devices/apps push attendance IN and read it OUT; outbound targets push attendance to SmartPRS or other systems automatically.<h5>Why</h5>No manual CSV shuffling between your gate devices, SmartPRS and SmartEPT — secure API keys in, HMAC-signed pushes out.<h5>How</h5>Create a key (shown once), give it ingest/read scope. Add an outbound target with its URL + shared secret; Test push sends a day now, the nightly job ships the previous day. The Integration guide card has the exact URLs, JSON and signature check for the other side.'],
  license: ['Licence', '<h5>What</h5>This server\'s SmartEPT licence: the key, the plan and company it belongs to, how many device seats are licensed vs registered, the expiry date with its grace window, and when the server last confirmed all of this with SmartEPT Central. A server with no key runs a <b>7-day free evaluation</b>, then monitoring stops until a key is entered.<h5>Why</h5>The licence is what ties your installation to what you purchased — seats, plan features and validity. Only licence metadata travels to Central: screenshots, activity and camera data never leave this server. If a paid renewal is missed, agents keep working through the grace days so a busy week never stops monitoring mid-shift; trials stop the moment they end.<h5>How</h5>Paste the key from your order email or the client portal and click "Save & validate" — the server confirms it with Central instantly and then re-checks once a day on its own. "Validate now" forces a fresh check after a renewal or seat upgrade. If the status shows EXPIRED, renew from the client portal; the seats line tells you when you\'re close to the licensed device limit.'],
  ops: ['Audit & Ops', '<h5>What</h5>Three operational views in one place: the full audit trail (every admin action, export, screenshot view and licence event with who, when and from which IP), storage consumed by screenshot/webcam evidence per company, and the state of your database backups.<h5>Why</h5>Monitoring software must itself be accountable — when an employee questions an action, the audit trail shows exactly who did what. Storage growth and backups are the two quiet things that sink servers: full disks and "we never had a backup".<h5>How</h5>Filter the trail by action text or date range. Backups run automatically every night at 01:30 (newest 14 kept in storage/app/backups — copy them off this PC for real safety); "Back up now" runs one immediately before risky changes. If a company\'s evidence storage grows fast, tighten its screenshot policy, shorten retention, or use \'Free up storage\' to bulk-delete old screenshots and logs by date range — violation evidence is kept unless you explicitly say otherwise, and every cleanup is itself audit-logged.'],
  reports: ['Reports & Exports', '<h5>What</h5>CSV exports — attendance, productivity, compliance, daily-summary scores and the classic monthly attendance register — plus an on-screen monthly summary with payable days.<h5>Why</h5>These are the hand-off artifacts: payroll wants the register and payable days, managers want productivity, HR wants compliance, and the MD wants the one-page summary.<h5>How</h5>Set the date range (or month for the register and summary), click Export, and the file downloads ready to open in Excel. The monthly summary renders here on screen: working days, P/A/H/L counts, payable days (P + 0.5×H + L) and average productivity. Every export is recorded in the audit log with who ran it and for which dates.'],
};
$('#btn-help').onclick = () => {
  const h = HELP[CURRENT] || HELP.dashboard;
  $('#help-title').textContent = h[0];
  $('#help-body').innerHTML = h[1];
  $('#help-ovl').classList.add('open');
};
// Generic overlay close buttons + click-outside.
$$('[data-close]').forEach((b) => b.addEventListener('click', () => $('#' + b.dataset.close).classList.remove('open')));
// pwd-ovl (forced change) and cred-ovl (one-time password) deliberately have no
// click-outside close — one is mandatory, the other must not vanish on a stray click.
['help-ovl', 'emp-ovl', 'user-ovl', 'att-ovl'].forEach((id) => {
  const el = document.getElementById(id);
  el.addEventListener('click', (e) => { if (e.target === el) el.classList.remove('open'); });
});
</script>
@endverbatim
</body>
</html>
