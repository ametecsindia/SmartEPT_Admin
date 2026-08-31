<?php

namespace App\Services\Biometric;

use App\Models\BiometricDevice;
use RuntimeException;

/**
 * The list of biometric providers SmartEPT can pull punches from.
 *
 * To add a vendor: write a PunchProvider and add one line to PROVIDERS. The console
 * dropdown, the validation rule and the sync engine all read from here, so there is
 * exactly one place to edit.
 */
class ProviderRegistry
{
    /** What a device with no explicit provider_key is assumed to be (every pre-28-Aug row). */
    public const DEFAULT_KEY = 'ETIMEOFFICE';

    /** @var array<int, class-string<PunchProvider>> */
    private const PROVIDERS = [
        ETimeOfficeProvider::class,
        EsslProvider::class,
    ];

    /** @return array<string, PunchProvider> keyed by provider key */
    public function all(): array
    {
        $out = [];
        foreach (self::PROVIDERS as $class) {
            /** @var PunchProvider $p */
            $p = app($class);
            $out[$p->key()] = $p;
        }

        return $out;
    }

    /** @return array<int, string> */
    public function keys(): array
    {
        return array_keys($this->all());
    }

    /** @return array<int, array{key:string, label:string}> — for the console dropdown. */
    public function options(): array
    {
        return array_values(array_map(
            fn (PunchProvider $p) => ['key' => $p->key(), 'label' => $p->label()],
            $this->all()
        ));
    }

    public function get(string $key): PunchProvider
    {
        $all = $this->all();
        $key = strtoupper(trim($key));

        if (! isset($all[$key])) {
            throw new RuntimeException('Unknown biometric provider "' . $key . '".');
        }

        return $all[$key];
    }

    /** The provider a device talks to. */
    public function for(BiometricDevice $device): PunchProvider
    {
        return $this->get($this->keyFor($device));
    }

    /**
     * Resolve a device's provider key. provider_key wins; if it is blank (a row saved
     * before 28-Aug, or a hand-edited record) fall back to sniffing the free-text
     * provider name, and finally to eTimeOffice — which is what every such row is.
     */
    public function keyFor(BiometricDevice $device): string
    {
        $key = strtoupper(trim((string) $device->provider_key));
        if ($key !== '' && isset($this->all()[$key])) {
            return $key;
        }

        $name = strtolower((string) $device->provider . ' ' . (string) $device->vendor);
        if (str_contains($name, 'essl') || str_contains($name, 'etimetracklite')) {
            return EsslProvider::KEY;
        }

        return self::DEFAULT_KEY;
    }
}
