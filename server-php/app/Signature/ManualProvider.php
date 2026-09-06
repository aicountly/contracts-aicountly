<?php

declare(strict_types=1);

namespace App\Signature;

/**
 * The shipped default: no vendor at all.
 *
 * Most companies that buy a CLM already sign somewhere else — a vendor portal,
 * a printer and a scanner, a counterparty's own DocuSign account. This provider
 * is for them, and it is honest about what it does: it sends nothing. What it
 * gives them is the part Contracts is actually for — the list of who still has
 * to sign, tracked against the contract — and execution is recorded when the
 * signed copy is uploaded.
 *
 * The alternative would be to hide signatures entirely without a vendor, which
 * pushes the one record anybody audits back into a spreadsheet.
 */
final class ManualProvider implements SignatureProvider
{
    public const NAME = 'manual';

    public function name(): string
    {
        return self::NAME;
    }

    /**
     * Always true: there is nothing to configure.
     *
     * This is why signatures work on day one of a deployment, before anyone has
     * bought an e-signature subscription.
     */
    public function isConfigured(): bool
    {
        return true;
    }

    /**
     * Mark the envelope as out for signature without transmitting anything.
     *
     * `delivered` is false and the detail says so in words a user will read on
     * the screen. Reporting a send that did not happen is how a contract sits
     * for three weeks waiting for an email nobody was ever sent.
     *
     * @param  array<string,mixed>       $request
     * @param  list<array<string,mixed>> $signers
     * @return array{provider_reference: ?string, status: string, delivered: bool, detail: string}
     */
    public function send(array $request, array $signers): array
    {
        return [
            // The reference is this deployment's own, not a vendor's, so that
            // every request has a stable handle to quote in correspondence even
            // when no vendor was involved.
            'provider_reference' => 'MANUAL-' . strtoupper(substr((string) ($request['uuid'] ?? ''), 0, 8)),
            'status'             => 'sent',
            'delivered'          => false,
            'detail'             => sprintf(
                'No signature provider is configured, so nothing was emailed. %d signatory(ies) are now tracked as outstanding; '
                . 'circulate the document yourself and upload the signed copy to record execution.',
                count($signers)
            ),
        ];
    }

    /** Nothing was ever sent, so there is nothing to recall and the recall always succeeds. */
    public function cancel(string $providerRef): bool
    {
        return true;
    }

    /**
     * Never valid.
     *
     * This provider has no vendor and therefore no callbacks. Accepting one
     * would mean an unauthenticated POST could complete a signature request and
     * move a contract to active, which is the single most damaging thing an
     * anonymous caller could be allowed to do here.
     *
     * @param array<string,string> $headers
     */
    public function verifyWebhook(string $rawBody, array $headers): bool
    {
        return false;
    }

    /**
     * @param  array<string,mixed> $payload
     * @return array{event_id: string, event_type: string, provider_reference: ?string,
     *               signer_reference: ?string, signer_email: ?string, occurred_at: ?string,
     *               decline_reason: ?string}
     */
    public function parseWebhook(array $payload): array
    {
        return [
            'event_id'           => '',
            'event_type'         => 'unsupported',
            'provider_reference' => null,
            'signer_reference'   => null,
            'signer_email'       => null,
            'occurred_at'        => null,
            'decline_reason'     => null,
        ];
    }
}
