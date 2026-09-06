<?php

declare(strict_types=1);

namespace App\Signature;

/**
 * What Contracts needs from an e-signature vendor.
 *
 * Deliberately small. Contracts owns the signature *record* — who must sign,
 * in what order, what happened and when — and a vendor owns only the delivery
 * of the envelope and the evidence certificate that comes back. Keeping the
 * interface to those five verbs is what makes the shipped ManualProvider a real
 * implementation rather than a stub: a company with no vendor still gets the
 * whole record, minus the emails.
 *
 * No method here is allowed to invent a state transition. `send()` and
 * `parseWebhook()` report what the vendor said; SignatureService decides what
 * that means for the contract, because a provider that could move a contract to
 * active by itself would put an unauthenticated callback in charge of the most
 * consequential status in the product.
 */
interface SignatureProvider
{
    /** The slug this provider is addressed by, in `SIGNATURE_PROVIDER` and in the webhook URL. */
    public function name(): string;

    /**
     * Whether this deployment has everything the provider needs to work.
     *
     * False means "do not attempt to send", not "hide the feature": the caller
     * turns it into an honest 503 naming what is missing.
     */
    public function isConfigured(): bool;

    /**
     * Hand the envelope to the vendor.
     *
     * @param  array<string,mixed>       $request the signature_requests row
     * @param  list<array<string,mixed>> $signers the signature_signers rows, in signing order
     * @return array{provider_reference: ?string, status: string, delivered: bool, detail: string,
     *               signers?: array<int|string,string>}
     *         `delivered` is the honest bit: false means nothing was actually
     *         sent to anybody, so the UI can say so instead of implying an
     *         email is in flight. `signers` maps signer id to the vendor's own
     *         reference, when it issues one.
     */
    public function send(array $request, array $signers): array;

    /** Recall an envelope. True when the vendor accepted the recall or had nothing to recall. */
    public function cancel(string $providerRef): bool;

    /**
     * Whether this delivery really came from the vendor.
     *
     * Takes the raw body rather than the decoded payload because every vendor
     * signs the bytes it sent; re-encoding the decoded array changes key order
     * and whitespace, and the HMAC then never matches.
     *
     * @param array<string,string> $headers
     */
    public function verifyWebhook(string $rawBody, array $headers): bool;

    /**
     * Normalise one delivery into the shape SignatureService acts on.
     *
     * `event_id` is the vendor's own id for the delivery and is what makes a
     * retry a no-op — a provider that does not supply one gets a deterministic
     * hash of the payload instead, which is the next best thing.
     *
     * @param  array<string,mixed> $payload
     * @return array{event_id: string, event_type: string, provider_reference: ?string,
     *               signer_reference: ?string, signer_email: ?string, occurred_at: ?string,
     *               decline_reason: ?string}
     */
    public function parseWebhook(array $payload): array;
}
