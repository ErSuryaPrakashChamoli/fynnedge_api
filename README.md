# fynnedge-api

Laravel backend for the **FynnEdge** Flutter app.

Standalone. It shares no code, database, authentication or infrastructure
with fynn_on, FYNN-ON, the LMS, the CRM or any existing loan system, and it
must not be connected to them.

## Running

```bash
php artisan migrate
php artisan serve --port=8123

# Document reading runs on the queue. With QUEUE_CONNECTION=sync (the
# default) it runs inline and no worker is needed; with `database`, run:
php artisan queue:work
```

All routes are under `/api/v1` (set in `bootstrap/app.php`).

```bash
php artisan route:list --path=api
```

## What is connected, and what is not

FynnEdge is deliberately explicit about which capabilities are real. Each of
these defaults to `none` or to sample data, and each says so to the customer
rather than simulating the real thing.

| Capability | Setting | Default | Status |
|---|---|---|---|
| Product catalogue | `FYNNEDGE_CATALOGUE_DRIVER` | `sample` | Six invented products, labelled as sample data |
| Provider ingestion | `FYNNEDGE_PROVIDER_DRIVER` | `fixture` | **No live provider connected** |
| Credit bureau | `FYNNEDGE_CREDIT_DRIVER` | `none` | **No live bureau connected** |
| FynnScan (document understanding) | `FYNNEDGE_SCAN_DRIVER` | `none` | **No live OCR/document provider connected** |
| KYC (identity verification) | `FYNNEDGE_KYC_DRIVER` | `none` | **No live KYC provider connected** |
| Lender submission | `FYNNEDGE_LENDER_DRIVER` | `none` | **No live submission provider connected** — applications are recorded and sent to nobody |
| Provider events | `FYNNEDGE_PROVIDER_EVENTS_DRIVER` | `none` | **No live provider event source connected** — the ingress allowlist is empty |
| FynnAI | `FYNN_AI_PROVIDER` | `deterministic` | Answers from the customer's own saved figures; not a language model |
| OTP delivery | `FYNNEDGE_OTP_PROVIDER` | development | Returns a `dev_hint` instead of sending an SMS |

`.env.example` documents every variable, including what has to be settled
before any of these can go live.

## Architecture

**Authentication** is Sanctum. Everything except `/auth/send-otp` and
`/auth/verify-otp` sits behind `auth:sanctum`. Ownership always resolves
through `$request->user()->relation()->find($id)` — never route-model
binding — so another customer's id returns the same 404 as one that does not
exist.

**Money** is integer paise throughout (`App\Domain\Money`). No float touches
a rupee figure.

**Mirrored engines.** The financial, score, match and twin engines exist in
both PHP and Dart and are proven identical by committed parity fixtures:

```bash
php artisan fynn:parity-fixture   # regenerate after changing an engine
```

**Catalogue.** `Provider → CatalogueProduct → ProductVersion → LoanProduct`
behind `ProductRepository`. Published versions are immutable; applications
snapshot their pricing, so republishing a product changes only what new
customers see. Administered by `php artisan fynn:catalogue` — there are no
admin HTTP endpoints, deliberately.

**Credit.** `Consent → CreditBureauAdapter → normalizer → CreditProfile →
credit criterion → FynnMatch`. FynnEdge stores a score, its model, its scale
and when it was obtained — never the report. A bureau score is not a
FynnScore and nothing converts one into the other.

**FynnScan.** `Vault → consent → transfer gate → queue → worker →
DocumentUnderstandingProvider → normalizer → canonical extraction → customer
review → confirmation → financial profile`.

Reading happens on the queue, not in the customer's request. The worker
re-checks everything the request checked — the document still exists, its
bytes are unchanged, and consent still stands — because by the time it runs
any of those may have changed. Set `QUEUE_CONNECTION=database` and run
`php artisan queue:work` in production; the `sync` default runs it inline.
Extraction is evidence, not the customer's data: nothing read from a document
reaches their profile until they confirm that specific field. FynnScan reads
income documents only.

**Lender submission.** `Application → consent → LenderApplicationMapper →
LenderSubmissionProvider → canonical outcome`. A FynnEdge application is not
a lender application: creating `FE-2026-000001` means the customer completed
an application here, and only a provider's own acknowledgement can mean an
external party has it. The two live on separate axes — `ApplicationStatus`
for what the customer did, `SubmissionOutcome` for what a provider did — and
`provider_received` is the single field that means an external party holds
it. A 200 from this API never sets it.

Every field that leaves is listed in `App\Domain\Lender\SubmissionFieldMap`
with its purpose, and everything refused is listed there with the reason:
FynnScore, FynnTrust, Twin scenarios, credit data, scan extractions, KYC
results, documents, savings, goals and AI conversations do not go.

**Provider events.** `Ingress → provider authentication → normalizer →
canonical event → idempotency → reconciliation → submission and application
state → timeline`. Deliberately outside `auth:sanctum`: a customer's token
authenticates nothing there, and a callback carries no customer session.

Three states stay separate. `ApplicationStatus` is what happened inside
FynnEdge; `SubmissionOutcome` is what FynnEdge knows about the delivery;
a provider event is what a provider said. Only a valid provider event can
write `under_review`, `approved`, `declined` or `disbursed`, and only a
provider event can resolve a submission whose outcome is `unknown`.

An event that arrives before FynnEdge has stored the reference it quotes is
recorded as failed rather than lost; `php artisan fynn:provider-events:retry`
re-attempts those through the same transition policy, updating the original
row rather than creating a second. It is deliberately not scheduled — it
mutates customer application state, and running it unattended is a
deployment decision to take on purpose.

Events are idempotent on a provider event id, or on a deterministic
fingerprint where a provider issues none. Ordering is by event meaning —
rank must strictly increase — so a late event cannot undo a later one and two
contradictory decisions do not flip a customer's outcome on delivery order.
Nothing of a callback's body is stored beyond a hash.

**KYC.** `Vault → consent → KycVerificationProvider → normalizer → canonical
verification → customer review → optional profile update`. A separate
capability from FynnScan on the same vault, with its own consent, because
reading a document and checking an identity are different questions.

Two findings are carried apart and never merged: what the service's own
checks said about the document, and whether the identity on it matched the
customer's profile. A verified result is not approval and changes nothing on
a profile by itself. Only `identity` documents are checked; the only field
compared is name; and only a masked tail of a document number is stored —
never a full PAN, never an Aadhaar number, never a date of birth or address.
`App\Domain\Kyc\IdentityFieldPurpose` records why each field is kept or
refused, and `App\Domain\Kyc\AadhaarBoundary` records why FynnEdge does not
touch Aadhaar authentication.

## Consents

Nine types, in `App\Domain\Consent\ConsentType`. A consent is offered only
when the capability behind it exists — `credit_bureau_check`,
`document_processing`, `identity_verification` and `lender_data_sharing`
become grantable exactly when their driver is configured, and are otherwise
shown as unavailable with a reason. The ledger is append-only: a change of mind adds a record, it never
edits one.

`document_processing` and `identity_verification` are deliberately separate.
One permits sending a document somewhere to be **read**; the other permits
having an identity **checked**. A customer can reasonably want the first and
not the second.

## Tests

```bash
php artisan test           # the whole suite
./vendor/bin/pint --test   # formatting
```

The JSON shape is the contract. The Flutter models parse these keys directly
and there is a test on the app side that proves it, run against a live
server:

```bash
php artisan serve --port=8123
cd ../fynnedge && flutter test test/api_contract_test.dart \
  --dart-define=FYNN_API_BASE=http://127.0.0.1:8123
```

Run that after changing any response shape.

Standing probe suites worth extending rather than replacing: `IdorMatrixTest`
(every resource × every verb as the wrong customer), `TamperingTest` (forged
pricing, scores, extractions, consent versions), `FinancialIntegrityTest`,
`ProductionConfigTest` (what the app refuses when it believes it is in
production).

## Operations

```bash
php artisan fynn:health                 # anything stuck that needs a person
php artisan fynn:health --json          # same, for a monitor
php artisan fynn:retention              # what is held, and how old
php artisan fynn:provider-events:retry  # re-attempt events that reached nothing
php artisan queue:work                  # document reading, when QUEUE_CONNECTION=database
```

`fynn:health` exits non-zero only when a customer is **blocked and cannot
resolve it themselves** — today that means a submission whose outcome was
never confirmed, where the app tells them to contact support. Everything
else is reported as attention and exits clean.

There is no monitoring stack configured in this project and none is
pretended: the exit code and `--json` are the integration points for
whatever a deployment actually has.

## Notes for going live

- **Empty maps**: return `(object) []`, not `[]`. PHP encodes an empty
  associative array as a JSON array, which breaks strict parsers.
- **Retention periods are unset, and unset means keep.** FynnEdge does not
  choose one — how long a credit report, extraction, verification or
  provider event may be kept while consent stands is a product and legal
  decision. The mechanism exists: `php artisan fynn:retention` reports what
  is held and how old it is, and `--apply` enforces whatever periods are
  configured. Consent withdrawal is separate and already deletes
  immediately.
- **No customer document leaves FynnEdge unscanned.** `ExternalTransferGate`
  refuses to hand a file to a real external provider unless a malware
  scanner has passed it. No scanner is connected, so today that means a real
  provider adapter cannot ship the hole with it. Simulated providers send
  the bytes nowhere and are unaffected.
- **The simulated submission provider refuses to run in production.** It
  reports itself unavailable when `APP_ENV=production`, deliberately without
  an escape hatch: a customer shown a delivered application that was never
  sent is the worst failure this product could produce.
- **No verification expiry** is defined. `expires_at` exists and is always
  null: a period nobody chose would make results look stale on an arbitrary
  date.
- **Virus scanning** is not implemented. It is a prerequisite before any
  customer document is sent to an external provider.
