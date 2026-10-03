<?php

namespace App\Services;

use App\Models\MailLog;
use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

/**
 * 30-Sep-2026 (Ejaz): WhatsApp delivery of the scheduled Productivity report snapshot, through
 * Meta's official WhatsApp Business Platform (Cloud API) — the only route that does not get a
 * number banned. Per company: phone number ID + access token (stored encrypted) + the approved
 * template's name/language. The image is uploaded as media, then sent in that template's
 * IMAGE header; body {{1}} = report period, {{2}} = one-line summary.
 * Every attempt is recorded in mail_logs (kind whatsapp_report, "to" = the number).
 */
class WhatsAppSender
{
    public const KIND = 'whatsapp_report';
    public const DEFAULT_TEMPLATE = 'smartept_daily_report';

    public static function key(int $companyId): string
    {
        return 'whatsapp:company:' . $companyId;
    }

    /** Saved config; the token is decrypted only for sending (never returned to the console). */
    public static function config(int $companyId): array
    {
        $c = json_decode((string) Setting::get(self::key($companyId), ''), true) ?: [];

        return [
            'phone_number_id' => (string) ($c['phone_number_id'] ?? ''),
            'token'           => (string) ($c['token'] ?? ''),
            'template'        => (string) (($c['template'] ?? '') ?: self::DEFAULT_TEMPLATE),
            'language'        => (string) (($c['language'] ?? '') ?: 'en'),
            'api_version'     => (string) (($c['api_version'] ?? '') ?: 'v21.0'),
        ];
    }

    public static function connected(int $companyId): bool
    {
        $c = self::config($companyId);

        return $c['phone_number_id'] !== '' && $c['token'] !== '';
    }

    /** Digits only; a 10-digit Indian mobile gets 91 in front. null = not a usable number. */
    public static function normalise(string $n): ?string
    {
        $d = preg_replace('/\D+/', '', $n);
        if (strlen($d) === 11 && $d[0] === '0') {
            $d = substr($d, 1);
        }
        if (strlen($d) === 10) {
            $d = '91' . $d;
        }

        return (strlen($d) >= 11 && strlen($d) <= 15) ? $d : null;
    }

    /** @return string sent | failed | skipped */
    public function sendImage(int $companyId, string $to, string $png, string $period, string $summary): string
    {
        $c = self::config($companyId);
        $num = self::normalise($to);
        $status = 'sent';
        $error = null;

        if (! $num) {
            [$status, $error] = ['skipped', 'Not a valid mobile number'];
        } elseif ($c['phone_number_id'] === '' || $c['token'] === '') {
            [$status, $error] = ['skipped', 'WhatsApp is not connected (Reports → Schedule Report → WhatsApp connection)'];
        } else {
            try {
                $token = Crypt::decryptString($c['token']);
                $base = 'https://graph.facebook.com/' . $c['api_version'] . '/' . $c['phone_number_id'];

                $up = Http::withToken($token)->timeout(30)
                    ->attach('file', $png, 'productivity-report.png', ['Content-Type' => 'image/png'])
                    ->post($base . '/media', ['messaging_product' => 'whatsapp', 'type' => 'image/png']);
                $mediaId = $up->json('id');
                if (! $up->successful() || ! $mediaId) {
                    throw new \RuntimeException('Upload refused: ' . ($up->json('error.message') ?: $up->status()));
                }

                $r = Http::withToken($token)->timeout(30)->post($base . '/messages', [
                    'messaging_product' => 'whatsapp',
                    'to'                => $num,
                    'type'              => 'template',
                    'template'          => [
                        'name'       => $c['template'],
                        'language'   => ['code' => $c['language']],
                        'components' => [
                            ['type' => 'header', 'parameters' => [['type' => 'image', 'image' => ['id' => $mediaId]]]],
                            ['type' => 'body', 'parameters' => [
                                ['type' => 'text', 'text' => mb_substr($period, 0, 200)],
                                ['type' => 'text', 'text' => mb_substr($summary, 0, 500)],
                            ]],
                        ],
                    ],
                ]);
                if (! $r->successful()) {
                    throw new \RuntimeException('Send refused: ' . ($r->json('error.message') ?: $r->status()));
                }
            } catch (\Throwable $e) {
                [$status, $error] = ['failed', mb_substr($e->getMessage(), 0, 1000)];
            }
        }

        MailLog::create([
            'company_id' => $companyId, 'to' => $num ?: $to, 'subject' => 'WhatsApp report — ' . $period,
            'kind' => self::KIND, 'status' => $status, 'error' => $error,
        ]);

        return $status;
    }
}
