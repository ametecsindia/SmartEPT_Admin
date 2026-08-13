<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Activate SmartEPT</title>
<style>
    * { box-sizing: border-box; margin: 0; }
    body { font-family: 'Segoe UI', system-ui, sans-serif; background: linear-gradient(160deg, #06333d 0%, #0E7C8F 60%, #06333d 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px; }
    .card { background: #fff; border-radius: 18px; max-width: 560px; width: 100%; padding: 38px 40px; box-shadow: 0 24px 70px rgba(0,0,0,.45); }
    h1 { font-size: 24px; color: #06333d; margin-bottom: 4px; }
    .brand { font-weight: 800; color: #0E7C8F; letter-spacing: .5px; margin-bottom: 18px; font-size: 13px; }
    p { font-size: 14px; color: #334155; line-height: 1.6; margin-bottom: 16px; }
    .btn { margin-top: 14px; display: inline-flex; align-items: center; gap: 8px; border: none; border-radius: 11px; padding: 13px 26px; font-size: 15px; font-weight: 700; cursor: pointer; background: #0E7C8F; color: #fff; }
    .btn:hover { background: #0a5f6e; }
    .err { background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; border-radius: 10px; padding: 11px 14px; font-size: 13px; margin-bottom: 14px; }
    .ok { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; border-radius: 10px; padding: 11px 14px; font-size: 13px; margin-bottom: 14px; }
    .note { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 11px; padding: 12px 15px; font-size: 12.5px; color: #64748b; line-height: 1.6; margin-top: 18px; }
    .fp { font-family: Consolas, monospace; word-break: break-all; background: #f1f5f9; border-radius: 7px; padding: 6px 9px; display: block; margin-top: 5px; }
    input[type=file] { font-size: 14px; width: 100%; padding: 14px; border: 1.5px dashed #94a3b8; border-radius: 11px; background: #f8fafc; }
</style>
</head>
<body>
<div class="card">
    <h1>Activate SmartEPT</h1>
    <div class="brand">EMPLOYEE PRODUCTIVITY TRACKING · ON-PREMISES</div>

    @if (session('lic_err')) <div class="err">{{ session('lic_err') }}</div> @endif
    @if (session('lic_ok'))  <div class="ok">{{ session('lic_ok') }}</div>  @endif

    @if (! $onprem)
        <p>This SmartEPT <strong>Cloud</strong> console is managed by Ametecs — there is no licence file to upload here. Your access renews automatically with your subscription.</p>
    @else
        @if ($activated)
            <div class="ok">This installation is activated{{ $company ? ' for ' . $company : '' }}{{ $expires ? ' (valid to ' . $expires . ')' : '' }}. Upload a new .lic only if Ametecs has released this licence for a server move.</div>
        @else
            <p>Upload the <strong>.lic</strong> licence file Ametecs sent you. One licence file activates one server — activation is instant and fully offline. After this, your team simply signs in and works.</p>
            @if ($eval_left !== null)
                <p style="color:#7A5614;font-weight:700">Evaluation mode: {{ $eval_left }} day(s) left. Enter your licence before it ends to keep monitoring running.</p>
            @endif
        @endif

        <form method="POST" action="/activate" enctype="multipart/form-data">
            @csrf
            <label style="display:block;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.6px;margin-bottom:7px">Licence file (.lic)</label>
            <input type="file" name="licence_file" accept=".lic,text/plain" required>
            <button class="btn" type="submit">Activate</button>
        </form>

        <div class="note">
            <strong style="color:#334155">This server — share this with Ametecs to receive your licence file:</strong>
            <span class="fp">{{ $fingerprint }}</span>
        </div>
    @endif

    <div class="note">Need help? Ametecs India Private Limited · sales@ametecsindia.com · WhatsApp 90000 98877.<br>© 2026 SmartEPT. All rights reserved.</div>
</div>
</body>
</html>
