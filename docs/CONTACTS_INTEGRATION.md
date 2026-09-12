# Contacts integration

Counterparties come from AICOUNTLY Contacts. Contracts does **not** duplicate the
contacts database — with one deliberate exception, described below, which is the
most legally important thing in this document.

## Lookup

```
GET /api/counterparties/search?q=acme&limit=20
```

Proxied to Contacts with the caller's own `ses_key`, so a user sees exactly the
contacts they are allowed to see. Debounced client-side, and the picker shows the
organisation and email alongside the name because two "Acme" rows are common and
a name alone does not disambiguate them.

`ContactsClient::normalise()` flattens whatever Contacts returns into a stable
shape:

```
{ id, display_name, legal_name, trading_name, type, email, phone,
  registered_address, gstin, pan, cin,
  contact_persons: [{ name, designation, email, phone }] }
```

## Degrading

Contacts being unreachable returns an empty result and reports the integration as
unavailable. The counterparty picker then accepts a **free-text name**, so a
contract can still be created.

Blocking contract creation because a lookup service is down would be the wrong
trade — the contract exists whether or not the directory is answering.

## Parties

`contract_parties` holds one row per party: the role, whether it is the primary
counterparty, the signatory details, and `contact_ref_id` pointing back into
Contacts.

The live contact is read for display. Contracts stores the reference, not a copy.

`contracts.counterparty_name` is denormalised from the primary counterparty so
the repository list renders without a join or a Contacts round trip per row.

## The legal snapshot

This is the exception, and it is the reason this integration is not simply "call
Contacts".

**The Contacts master is a living record. A contract is not.**

A company renames itself, moves office, changes its GST registration. Contacts
correctly reflects the change. But the agreement signed in March 2024 was signed
by a company with a particular legal name at a particular registered address, and
that fact does not change because someone edited a record two years later.

So at execution, `contract_party_snapshots` captures:

```
legal_name · trading_name · registered_address
gstin · pan · cin
email · phone
authorised_representative · representative_designation
raw_payload (the whole record, as it was)
captured_reason · captured_by · captured_at
```

**Snapshots are append-only.** A correction is a new snapshot, never an
`UPDATE`. The history is the point: "who were we contracting with, as recorded at
the time" must stay answerable.

The company's own details are snapshotted too, from Manage, in the same act —
the company that signed can rename itself just as easily as the counterparty.

### When

- `POST /api/parties/{id}/snapshot` — explicitly, at any time
- `POST /api/contracts/{id}/parties/snapshot-all` — every party at once
- automatically at execution, via `captureAllForExecution()`

## Party roles

`company`, `counterparty`, `customer`, `vendor`, `supplier`, `partner`,
`employee`, `consultant`, `landlord`, `tenant`, `licensor`, `licensee`,
`guarantor`, `witness`, `other` — a `CHECK` constraint, not free text, because
"which contracts have us as licensee" is a question the repository has to answer.

## What Contracts deliberately does not do

- No contact CRUD. Creating or editing a contact happens in Contacts.
- No contact list. There is a picker, not a directory.
- No sync. There is nothing to keep in sync, because nothing is copied.
- No cached contact table. The snapshot is a legal record of a moment, not a
  cache — it is never refreshed, and refreshing it would defeat its purpose.

## Configuration

```env
# Derived from the hostname when unset: a *.gh.aicountly.com host resolves to
# contacts.gh.aicountly.com.
# CONTACTS_API_BASE=https://contacts.aicountly.com
```
