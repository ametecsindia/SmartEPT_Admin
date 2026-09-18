<?php

/**
 * The protection capability matrix.
 *
 * Requirement, verbatim from the client brief: "do not mark an application as
 * supported merely because the UI option exists." Different applications
 * implement file, image and camera access in genuinely different ways, and an
 * option that renders but enforces nothing is worse than no option at all —
 * the console reports a control the auditor will ask about and the endpoint
 * does nothing.
 *
 * So this file is the single place that says, per item, whether SmartEPT can
 * actually enforce a protection and by what mechanism. The console reads it to
 * decide which checkboxes to offer, which to disable, and what warning to show.
 * It is ADVISORY ONLY: the truth about what happened on a given PC is whatever
 * the endpoint reports back in `action_taken`, which includes explicit
 * NOT_ENFORCEABLE values. This file must never be the thing that convinces
 * anyone a block occurred.
 *
 * Statuses
 *   SUPPORTED     a documented mechanism exists and applies to this item alone.
 *   BROWSER_WIDE  enforced, but wider than the item: the only lever Chromium
 *                 and Firefox expose is browser-level, so switching it on for
 *                 one site affects every site in that browser. Offered, with
 *                 the consequence stated on screen.
 *   UNVERIFIED    the mechanism exists but has not been proven against THIS
 *                 application on a real Windows machine. Offered with a warning
 *                 and reported honestly by the endpoint if it fails.
 *   UNSUPPORTED   no mechanism. Never offered.
 *
 * Moving an entry from UNVERIFIED to SUPPORTED requires evidence from the VM
 * runbook, not optimism.
 *
 * TWO SEPARATE VIDEO-BLOCKING MECHANISMS EXIST (16-Sep-2026) — do not confuse
 * them when reading video_cdn_block below:
 *
 *   1. THIS file's `video` protection / video_cdn_block. Per-rule, company-wide
 *      only, and only actually blocks the two sites in video_cdn_domains
 *      below — everywhere else the tick is recorded but not enforced, exactly
 *      as this file already says.
 *
 *   2. The Media Control local proxy (smartept-enforcer's internal/mediaproxy),
 *      switched on by the company-wide "Block media streaming" toggle in
 *      Device Control, AND, per employee, by that employee's media_block_mode
 *      (EmployeeController, EnforcerSyncController::mediaControlForEmployee).
 *      This one decrypts the browser's own HTTPS in Chrome/Edge and drops
 *      responses that look like video by Content-Type, so it is not limited
 *      to a fixed domain list — it is what actually reaches Instagram,
 *      Facebook, TikTok and any other site, not just YouTube/X. It does NOT
 *      reach Firefox, Safari, or a desktop app that isn't a browser.
 *
 * The two are independent and both currently run: a company or an employee
 * can have #2 armed while #1's per-row CDN blocks (or lack of one) do their
 * own separate, narrower thing on top. They have not been merged — ticking
 * `video` on a Rules-screen row does not, today, arm #2. See the project's
 * media-streaming-control-status doc for the reasoning and the option to
 * unify them later.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | What each protection means
    |--------------------------------------------------------------------------
    */
    'catalogue' => [
        'file' => [
            'label'   => 'File Sharing Block',
            'summary' => 'The application keeps working — chat, calls, normal use. Sending or uploading documents from it is prevented.',
        ],
        'image' => [
            'label'   => 'Image Sharing Block',
            'summary' => 'The application keeps working. Sending or uploading photos and images from it is prevented.',
        ],
        'camera' => [
            'label'   => 'Camera Block',
            'summary' => 'The application keeps working, including files it already has. It cannot open the camera to take a photo or video.',
        ],
        'video' => [
            'label'   => 'Video / Media Streaming Block',
            'summary' => 'The application or site keeps opening — chat, browsing, everything else works. Video/reel playback inside it is what this targets. EXPERIMENTAL: offered everywhere at Ejaz\'s request (16-Sep-2026) even where no mechanism exists yet, so the console is honest about which rows are actually enforced today — see the note on each row.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | How each protection is enforced, and what it cannot reach
    |--------------------------------------------------------------------------
    |
    | Written down here because these limits belong in the product, not in a
    | salesperson's memory. Every one of them is a question a bank's auditor
    | asks.
    */
    'mechanisms' => [
        'desktop_file_dialog' => [
            'where'  => 'SmartEPT agent, in the signed-in employee session',
            'how'    => 'Watches for the Windows file dialog opened by the protected application, closes it before a file can be chosen, and records the attempt. The application is never terminated.',
            'blind_spots' => [
                'A file dragged and dropped onto the application never opens a dialog and is not seen.',
                'An image pasted from the clipboard never opens a dialog and is not seen.',
                'An application that draws its own picker instead of using the Windows dialog is not seen. Microsoft Store apps often do.',
            ],
        ],
        'camera_consent_store' => [
            'where'  => 'SmartEPT agent, in the signed-in employee session',
            'how'    => "Sets Windows' own per-application camera permission to Deny for that application, in the signed-in user's profile only. Removed the moment the employee signs out, so the next employee starts from their own policy.",
            'blind_spots' => [
                'Applications that reach the camera through the legacy DirectShow path rather than the Windows camera service are not covered. Windows 10 1903 or later is required for desktop applications.',
            ],
        ],
        'browser_file_dialog_switch' => [
            'where'  => 'SmartEPT enforcement service',
            'how'    => 'Turns off the file picker in Chrome, Edge and Brave through Windows policy.',
            'blind_spots' => [
                'Browsers expose no per-site upload control, so this applies to the whole browser: every site loses uploads, and Save As stops working too. It is offered because it is honest and it works, not because it is precise.',
                'The policy removes the file picker only. A file dragged onto a page or an image pasted into it is caught by the agent\'s drag/paste guard while the browser is in front - best effort, not a driver.',
            ],
        ],
        'browser_camera_policy' => [
            'where'  => 'SmartEPT enforcement service',
            'how'    => 'Turns the camera off in Chrome, Edge, Brave and Firefox through Windows policy, keeping the sites marked Allowed on the Rules screen as exceptions.',
            'blind_spots' => [
                'Browsers expose an allow list, not a block list, so blocking the camera on one site means blocking it everywhere except the sites you have explicitly allowed.',
            ],
        ],
        'media_server_block' => [
            'where'  => 'SmartEPT enforcement service',
            'how'    => "Refuses to resolve the application's media server on the PC (a Windows DNS policy rule), so chat keeps working but every file or image it tries to send has nowhere to go — attach button, paste, drag-and-drop, Share menu and forward alike. Removed the moment the box is unticked.",
            'blind_spots' => [
                'Receiving files and images in that application stops as well. None of these services separate sending from receiving.',
                'Only applications whose media server is separate from their chat server can be blocked this way. Telegram, Discord, Teams and Outlook cannot; use Full Block & Close for those.',
            ],
        ],
        'anydesk_config' => [
            'where'  => 'SmartEPT enforcement service',
            'how'    => "Turns off AnyDesk's file manager in its own configuration. Remote control keeps working; file transfer does not.",
            'blind_spots' => [
                'AnyDesk does not distinguish an image from any other file, so Image Sharing Block adds nothing to File Sharing Block here.',
            ],
        ],
        'video_cdn_block' => [
            'where'  => 'SmartEPT enforcement service',
            'how'    => "Some sites stream actual video from a CDN domain that is separate from the page you browse (YouTube's page is youtube.com; the video itself streams from googlevideo.com). Where that split is real, only the video-CDN domain is added to the machine's site blocklist — the page domain is left off it — so the site opens, but playback fails. See config('protections.video_cdn_domains') for the exact domains this is wired for.",
            'blind_spots' => [
                'Only works on sites where the split above is real and known. Most social apps (Instagram, Facebook, TikTok) serve video from the same CDN hostnames as photos and everything else, so there is no domain to block without also breaking the rest of the app — ticking this box there records the request but SmartEPT does not act on it yet.',
                'No mechanism exists for a desktop application (Discord, Steam, Zoom, etc.) at all — video inside an app, not a browser, would need a different approach entirely (a managed browser extension or similar), not yet built.',
                'Verified only in theory (documented by third-party filtering vendors for YouTube, and observed for X/Twitter). Never yet run against a real Windows PC with SmartEPT\'s own blocklist mechanism — do not promise this to a client until it is.',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Video-CDN domains for video_cdn_block
    |--------------------------------------------------------------------------
    |
    | The ONLY sites this mechanism can actually do something for: item (as
    | policy_rules stores it) => the CDN domain(s) added to the site blocklist
    | when that item's `video` protection is ticked. The item's own domain is
    | deliberately NOT added here — it must stay reachable.
    |
    | Adding an entry here is a claim about how the platform's CDN is shaped,
    | not a claim that SmartEPT has verified it end to end. Move to a "verified"
    | note only with a dated VM test, same rule as the rest of this file.
    */
    'video_cdn_domains' => [
        'youtube.com'  => ['googlevideo.com'],
        'x.com'        => ['video.twimg.com'],
        'twitter.com'  => ['video.twimg.com'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Defaults by rule type
    |--------------------------------------------------------------------------
    |
    | Used for any item not named below — a free-text rule an admin typed.
    */
    'defaults' => [
        'APPLICATION' => [
            // Not offered for desktop applications in general: closing the file
            // dialog, clearing the clipboard and cancelling a drag is a guard,
            // not a block, and the client's own test walked straight around it.
            // The honest control for an application that must not send files is
            // Full Block & Close. Only an application with its OWN transfer
            // switch (AnyDesk) is listed as supported below.
            'file'   => ['status' => 'UNSUPPORTED', 'mechanism' => null,
                         'note' => 'SmartEPT cannot stop this application sending files while it keeps running - copy-paste, drag-and-drop and Share menus all bypass a dialog guard. Use Full Block & Close.'],
            'image'  => ['status' => 'UNSUPPORTED', 'mechanism' => null,
                         'note' => 'Same as File Sharing Block: not enforceable while the application runs. Use Full Block & Close, or Camera Block for photos taken in-app.'],
            'camera' => ['status' => 'UNVERIFIED', 'mechanism' => 'camera_consent_store'],
            // Offered everywhere at Ejaz's request (16-Sep-2026) even though no
            // mechanism exists for a desktop application yet — UNVERIFIED, not
            // UNSUPPORTED, is the honest word for "checkable, not enforced".
            'video'  => ['status' => 'UNVERIFIED', 'mechanism' => null,
                         'note' => 'No verified mechanism for video inside a desktop application yet. The tick is recorded; nothing on the PC currently acts on it.'],
        ],
        'WEBSITE' => [
            'file'   => ['status' => 'BROWSER_WIDE', 'mechanism' => 'browser_file_dialog_switch'],
            'image'  => ['status' => 'BROWSER_WIDE', 'mechanism' => 'browser_file_dialog_switch',
                         'note' => 'A browser cannot tell an image upload from any other upload, so this does the same thing as File Sharing Block.'],
            'camera' => ['status' => 'BROWSER_WIDE', 'mechanism' => 'browser_camera_policy'],
            'video'  => ['status' => 'UNVERIFIED', 'mechanism' => 'video_cdn_block',
                         'note' => 'Only enforced on sites listed in protections.video_cdn_domains (currently YouTube and X/Twitter). On every other site the tick is recorded but not currently enforced — most sites serve video from the same place as everything else, with nothing to block separately.'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Per-item capability
    |--------------------------------------------------------------------------
    |
    | Keys are the normalised item exactly as policy_rules stores it: lower
    | case, no .exe, no scheme, no www.
    */
    'items' => [

        'whatsapp' => [
            // Verified 05-Sep-2026 on a real PC: chat works, attach / paste /
            // drag all fail to send.
            'file'   => ['status' => 'SUPPORTED', 'mechanism' => 'media_server_block',
                         'note' => 'WhatsApp keeps chatting; sending AND receiving files and images stops, however the file is attached.'],
            'image'  => ['status' => 'SUPPORTED', 'mechanism' => 'media_server_block',
                         'note' => 'Same media server as files, so this does the same thing as File Sharing Block.'],
            'camera' => ['status' => 'SUPPORTED', 'mechanism' => 'camera_consent_store',
                         'note' => 'WhatsApp Desktop is a Store package, which is the case Windows per-application camera permission covers best.'],
        ],

        'telegram' => [
            'camera' => ['status' => 'UNVERIFIED', 'mechanism' => 'camera_consent_store'],
        ],

        'signal' => [
            'file'   => ['status' => 'UNVERIFIED', 'mechanism' => 'media_server_block',
                         'note' => 'Signal keeps chatting; sending AND receiving files and images stops, however the file is attached. Verify once on a real PC before promising it to a client.'],
            'image'  => ['status' => 'UNVERIFIED', 'mechanism' => 'media_server_block',
                         'note' => 'Same media server as files, so this does the same thing as File Sharing Block.'],
            'camera' => ['status' => 'UNVERIFIED', 'mechanism' => 'camera_consent_store'],
        ],

        'anydesk' => [
            'file'   => ['status' => 'SUPPORTED', 'mechanism' => 'anydesk_config',
                         'note' => 'Remote control keeps working. File transfer and the file manager are turned off in AnyDesk itself.'],
            'image'  => ['status' => 'UNSUPPORTED', 'mechanism' => 'anydesk_config',
                         'note' => 'AnyDesk transfers images as ordinary files and offers no separate control. Use File Sharing Block.'],
            'camera' => ['status' => 'UNVERIFIED', 'mechanism' => 'camera_consent_store'],
        ],

        'discord' => [
            'file'   => ['status' => 'UNVERIFIED', 'mechanism' => 'media_server_block',
                         'note' => 'Discord keeps chatting; sending AND receiving files and images stops, however the file is attached. Verify once on a real PC before promising it to a client.'],
            'image'  => ['status' => 'UNVERIFIED', 'mechanism' => 'media_server_block',
                         'note' => 'Same media server as files, so this does the same thing as File Sharing Block.'],
            'camera' => ['status' => 'UNVERIFIED', 'mechanism' => 'camera_consent_store'],
        ],

        'messenger' => [
            'file'   => ['status' => 'UNVERIFIED', 'mechanism' => 'media_server_block',
                         'note' => 'Messenger keeps chatting; sending AND receiving files and images stops, however the file is attached. Verify once on a real PC before promising it to a client.'],
            'image'  => ['status' => 'UNVERIFIED', 'mechanism' => 'media_server_block',
                         'note' => 'Same media server as files, so this does the same thing as File Sharing Block.'],
            'camera' => ['status' => 'UNVERIFIED', 'mechanism' => 'camera_consent_store'],
        ],

        'slack' => [
            'file'   => ['status' => 'UNVERIFIED', 'mechanism' => 'media_server_block',
                         'note' => 'Slack keeps chatting; sending AND receiving files and images stops, however the file is attached. Verify once on a real PC before promising it to a client.'],
            'image'  => ['status' => 'UNVERIFIED', 'mechanism' => 'media_server_block',
                         'note' => 'Same media server as files, so this does the same thing as File Sharing Block.'],
            'camera' => ['status' => 'UNVERIFIED', 'mechanism' => 'camera_consent_store'],
        ],

        'ultraviewer' => [
            'camera' => ['status' => 'UNSUPPORTED', 'mechanism' => null,
                         'note' => 'UltraViewer does not use the camera.'],
        ],

        'teamviewer' => [
            'camera' => ['status' => 'UNVERIFIED', 'mechanism' => 'camera_consent_store'],
        ],

        'outlook' => [
            'camera' => ['status' => 'UNSUPPORTED', 'mechanism' => null],
        ],

        // Websites. These are the browser-wide levers; the note on each is what
        // the console shows before the admin ticks the box.
        'mail.google.com'   => ['_inherit' => 'WEBSITE'],
        'drive.google.com'  => ['_inherit' => 'WEBSITE'],
        'web.whatsapp.com'  => ['_inherit' => 'WEBSITE'],
        'web.telegram.org'  => ['_inherit' => 'WEBSITE'],
        'outlook.office.com' => ['_inherit' => 'WEBSITE'],
        'onedrive.live.com' => ['_inherit' => 'WEBSITE'],
        'dropbox.com'       => ['_inherit' => 'WEBSITE'],

        // Video streaming — the sites researched 16-Sep-2026. Two carry a real,
        // documented CDN split (video_cdn_domains above); the rest are named
        // here specifically so the console tells the truth about each one
        // instead of one generic WEBSITE note covering platforms that behave
        // very differently underneath.
        'youtube.com' => [
            'video' => ['status' => 'UNVERIFIED', 'mechanism' => 'video_cdn_block',
                        'note' => 'YouTube streams video from a separate domain (googlevideo.com) from the page itself. Blocking just that domain should leave the site browsable with video failing to play. Documented technique elsewhere; never yet run on a SmartEPT-managed PC — verify on the enforcer VM before promising it to a client.'],
        ],
        'x.com' => [
            'video' => ['status' => 'UNVERIFIED', 'mechanism' => 'video_cdn_block',
                        'note' => 'X/Twitter streams video from video.twimg.com, separate from the images domain (pbs.twimg.com) and the site itself. Same unverified-on-our-stack caveat as YouTube.'],
        ],
        'twitter.com' => [
            'video' => ['status' => 'UNVERIFIED', 'mechanism' => 'video_cdn_block',
                        'note' => 'Same as x.com — the two hostnames are the same product.'],
        ],
        'instagram.com' => [
            'video' => ['status' => 'UNVERIFIED', 'mechanism' => null,
                        'note' => 'Best available evidence says Instagram serves photos, Stories, feed video and Reels from the SAME CDN hostnames — no separate video domain the way YouTube has. Ticking this is recorded but very likely cannot be enforced without also breaking the photo feed. Needs a real traffic capture before this can move past "recorded only".'],
        ],
        'facebook.com' => [
            'video' => ['status' => 'UNVERIFIED', 'mechanism' => null,
                        'note' => 'A possible video-only subdomain (video-*.fbcdn.net, separate from photos on scontent-*.fbcdn.net) is reported by one unverified source — not confirmed by SmartEPT. Needs a real traffic capture before this can be enforced. The tick is recorded, not acted on, until then.'],
        ],
        'tiktok.com' => [
            'video' => ['status' => 'UNVERIFIED', 'mechanism' => null,
                        'note' => 'TikTok IS short video — there is no "app open, video off" state to aim for the way there is for YouTube. Ticking this is recorded but cannot be enforced as a partial block; the only real lever for TikTok is blocking it outright.'],
        ],
    ],
];
