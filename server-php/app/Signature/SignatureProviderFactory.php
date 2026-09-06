<?php

declare(strict_types=1);

namespace App\Signature;

use App\Core\Env;
use App\Support\DomainException;

/**
 * Resolves `SIGNATURE_PROVIDER` to something that implements the interface.
 *
 * Only `manual` ships today. That is deliberate rather than unfinished: a
 * vendor integration that nobody has credentials to test against would be
 * untested code standing between a company and its contracts, and the manual
 * path is what every deployment falls back to anyway.
 *
 * An unrecognised value is refused rather than quietly downgraded to manual. A
 * deployment that set `SIGNATURE_PROVIDER=docusign` believes envelopes are
 * being emailed; silently giving it the provider that emails nothing would turn
 * a one-line configuration error into contracts that sit unsigned for weeks
 * with no visible fault anywhere.
 */
final class SignatureProviderFactory
{
    /** @var array<string, class-string<SignatureProvider>> */
    private const PROVIDERS = [
        ManualProvider::NAME => ManualProvider::class,
    ];

    /** The slug this deployment is configured for. */
    public static function configuredName(): string
    {
        $name = strtolower(trim(Env::get('SIGNATURE_PROVIDER')));

        return $name === '' ? ManualProvider::NAME : $name;
    }

    /**
     * The provider this deployment sends through.
     *
     * @throws DomainException when configured for a provider this build has no client for
     */
    public static function default(): SignatureProvider
    {
        $name     = self::configuredName();
        $provider = self::for($name);

        if ($provider === null) {
            throw DomainException::unavailable(
                sprintf('SIGNATURE_PROVIDER is set to "%s", which this build has no client for.', $name),
                'SIGNATURE_PROVIDER_UNKNOWN'
            );
        }

        return $provider;
    }

    /**
     * A provider by name, or null when the name is not one we implement.
     *
     * Null rather than a throw because the webhook route reaches this with a
     * URL segment supplied by whoever called it, and an unknown segment there
     * is a 404, not a server misconfiguration.
     */
    public static function for(string $name): ?SignatureProvider
    {
        $class = self::PROVIDERS[strtolower(trim($name))] ?? null;

        return $class === null ? null : new $class();
    }

    /** @return list<string> */
    public static function available(): array
    {
        return array_keys(self::PROVIDERS);
    }

    /**
     * What the settings screen shows about signing.
     *
     * `delivers` separates "signatures work" from "we email the counterparty",
     * which are different answers and are read by different people.
     *
     * @return array{provider: string, configured: bool, known: bool, delivers: bool, detail: string}
     */
    public static function status(): array
    {
        $name     = self::configuredName();
        $provider = self::for($name);

        if ($provider === null) {
            return [
                'provider'   => $name,
                'configured' => false,
                'known'      => false,
                'delivers'   => false,
                'detail'     => sprintf('This build has no client for the "%s" signature provider.', $name),
            ];
        }

        $manual = $provider->name() === ManualProvider::NAME;

        return [
            'provider'   => $provider->name(),
            'configured' => $provider->isConfigured(),
            'known'      => true,
            'delivers'   => ! $manual && $provider->isConfigured(),
            'detail'     => $manual
                ? 'Signatures are tracked in Contracts and circulated outside it; upload the signed copy to record execution.'
                : 'Envelopes are sent through ' . $provider->name() . '.',
        ];
    }
}
