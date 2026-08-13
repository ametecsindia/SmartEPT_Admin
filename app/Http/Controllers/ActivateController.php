<?php

namespace App\Http\Controllers;

use App\Models\InstallationLicense;
use App\Services\LicenseFile;
use Illuminate\Http\Request;

/**
 * PRE-LOGIN licence activation (SmartPRS2 AS-DL pattern, 13-Aug-2026).
 *
 * On an on-prem client install, /activate shows the machine fingerprint and
 * accepts the .lic file BEFORE anyone signs in — so a fresh install can be
 * activated without console credentials. Safe unauthenticated: only a file
 * validly SIGNED by Central AND fingerprint-locked to THIS machine activates
 * anything; everything else is rejected by LicenseFile::verify().
 *
 * On the Ametecs-hosted cloud console (SMARTEPT_ONPREM unset) the upload form
 * is hidden — cloud licences are managed server-side, per the PRS2 rule.
 */
class ActivateController extends Controller
{
    public function __construct(private LicenseFile $file)
    {
    }

    public function show()
    {
        return view('activate', $this->state());
    }

    public function store(Request $request)
    {
        abort_unless(config('smartept.onprem'), 404);

        $request->validate(['licence_file' => ['required', 'file', 'max:64']]); // a .lic is ~1 KB

        $license = $this->file->import((string) file_get_contents($request->file('licence_file')->getRealPath()));

        if ($license->status === 'active') {
            return redirect('/activate')->with('lic_ok',
                'Licence activated' . ($license->companyName() ? ' for ' . $license->companyName() : '') . ' — sign in to continue.');
        }

        return redirect('/activate')->with('lic_err',
            'That licence file was not accepted (' . ($license->last_error ?: $license->status) . '). '
            . 'Check it is the .lic issued for THIS machine\'s fingerprint, or contact Ametecs.');
    }

    private function state(): array
    {
        $license = InstallationLicense::current();

        return [
            'onprem'      => (bool) config('smartept.onprem'),
            'activated'   => $license->configured() && $license->status === 'active',
            'company'     => $license->companyName(),
            'expires'     => optional($license->expiresAt())->toDateString(),
            'fingerprint' => $this->file->machineFingerprint(),
            'eval_left'   => $license->configured() ? null : $license->evaluationDaysLeft(),
        ];
    }
}
