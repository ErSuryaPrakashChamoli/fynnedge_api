# fynnedge-api

Laravel backend for the **FynnEdge** Flutter app.

Standalone. It shares no code, database, authentication or infrastructure
with fynn_on, FYNN-ON, the LMS, the CRM or any existing loan system, and it
must not be connected to them.

Two front doors onto one application:

```text
Flutter app ──► /api/v1/*  ──► Sanctum bearer token ──► users
                    │
              shared services, models, policies, database
                    │
FynnEdge staff ──► /admin  ──► session (admin guard)  ──► admin_users
```

The two authentication systems are separate by construction, not by a
check somebody has to remember: a customer token names a `User`, the admin
guard's provider only builds `AdminUser`, so there is nothing at `/admin`
for a customer token to be.

## Requirements

| | |
|---|---|
| PHP | 8.3+ (developed and tested on 8.5) |
| Composer | 2.x |
| Database | MySQL 8.x in production; SQLite for the test suite |
| Node | only to build the admin panel's assets — the API needs none |
| Extensions | `intl` (Filament), plus the Laravel defaults |

## Installation

```bash
composer install
cp .env.example .env
php artisan key:generate

# Create the schema. `migrate` is additive and safe to re-run;
# `migrate:fresh` drops everything and is not for a populated database.
php artisan migrate

# Baseline development data — one customer, matching the app's own mocks.
php artisan db:seed
```

Then, for development only:

```bash
php artisan db:seed --class=AdminUserSeeder      # a Super Admin for /admin
php artisan db:seed --class=SampleCatalogueSeeder # the six sample products
php artisan db:seed --class=DemoFixtureSeeder     # four invented customers
```

All three refuse to run when `APP_ENV=production`. The first behaves
differently there — see **The admin panel** below.

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

## The API

82 endpoints, all under `/api/v1`. Three are public — the two OTP endpoints
and the provider ingress — and every other one requires a Sanctum bearer
token.

**Signing in.** There are no passwords. A customer is a mobile number that
proves itself with a one-time code:

```bash
POST /api/v1/auth/send-otp     {"mobile":"9876543210"}
  → {"data":{"reference":"…","expires_in":30,"dev_hint":"123456"}}

POST /api/v1/auth/verify-otp   {"mobile":"…","reference":"…","code":"…","device_name":"Pixel 8"}
  → {"data":{"token":"…","is_new_user":false,"expires_at":"…","profile":{…}}}
```

`dev_hint` is the issued code, returned only when `FYNNEDGE_OTP_DEV_HINT` is
explicitly on **and** the app is not in production. Both conditions have to
hold; see **Environment**.

**Every other request** carries the token:

```http
Authorization: Bearer <token>
Accept: application/json
Content-Type: application/json
```

Tokens last 30 days (`SANCTUM_TOKEN_EXPIRATION`, in minutes), are stored only
as a SHA-256 hash, and are revoked per device — signing out on a phone leaves
a tablet signed in.

**Responses** are always enveloped:

```jsonc
{"data": {…}}                 // or  {"data": [...]}
{"data": [...], "meta": {…}}  // where a list paginates
{"message": "…"}                          // errors
{"message": "…", "errors": {"field": ["…"]}}  // validation
```

**Status codes** the app should expect:

| | |
|---|---|
| 200 / 201 / 204 | read · created · deleted |
| 401 | no token, or it is expired or revoked |
| 404 | not found **or not yours** — deliberately indistinguishable |
| 409 | idempotency conflict |
| 422 | validation, or the domain refusing |
| 429 | rate limited; honour `Retry-After` |
| 501 | the capability exists but no provider is connected |

**Ownership.** Every customer-scoped route resolves through the token
holder's own relation, so another customer's id answers **404 rather than
403** — an attacker cannot tell "not yours" from "does not exist".

**Uploads** go to `POST /api/v1/documents` as `multipart/form-data` with
`file`, `type` and `name`. `GET /api/v1/documents/limits` states what is
accepted (10 MB; PDF, JPEG, PNG, HEIC, WebP) so the app can refuse before a
round trip. Content is sniffed, not trusted — a renamed executable is
rejected. Files stream back through `GET /api/v1/documents/{id}/download`,
authenticated on every request; there is no public or signed URL.

**Rate limits** are per capability, not global: OTP sends 3/min per number,
credit checks and KYC 5/hour, lender submissions 3/hour, vault writes 20/min,
authenticated traffic 300/min. The full list is in
`AppServiceProvider::configureRateLimiting()`, each with the reason for its
figure.

The complete inventory:

```bash
php artisan route:list --path=api
```

## The admin panel

Filament 5 at `/admin`, for FynnEdge staff. It is not the API and the app
never touches it.

```bash
php artisan db:seed --class=AdminUserSeeder
# Development Super Admin: admin@fynnedge.test
# Password: <generated, printed once>
```

The password is never written down here. Set `FYNNEDGE_ADMIN_PASSWORD` to
choose one, or leave it unset and the seeder generates a password and prints
it to the console of whoever ran it — after which only the hash exists. An
administrator that already exists keeps the password it has; the seeder will
not silently reset it.

In production the same seeder creates nothing unless `FYNNEDGE_ADMIN_EMAIL`
and `FYNNEDGE_ADMIN_PASSWORD` (12 characters minimum) are both set, and it
refuses rather than inventing a default — a well-known credential on a panel
that reads every customer's finances is the whole breach.

**Six roles**, and what each may reach:

| Role | Staff accounts | Customer money | Identity & credit | Catalogue | Support |
|---|---|---|---|---|---|
| Super Admin | ✓ | ✓ | ✓ | ✓ | ✓ |
| Business Head | | ✓ | ✓ | ✓ | |
| Manager | | ✓ | ✓ | | ✓ |
| Team Leader | | | | | ✓ |
| Advisor | | | | | ✓ |
| Viewer | | | | | |

Every role can see customers and consents; staff cannot answer for the
product otherwise. `Advisor` is the application's own word — support messages
have carried `author_type = advisor` since Module 30.

**Almost everything is read-only.** Customer records are written by the
domain services, which also record *why*; a panel that could edit the row
directly would be a second writer with no such account of itself. The
exceptions are staff accounts, the catalogue, and replying to a support case
— and that reply goes through `SupportCaseService::addAdvisorMessage()`, the
same service an advisor API would call.

**Deactivate, never delete.** `is_active = false` ends a session already in
progress, checked on every request rather than only at login. Nothing in the
panel deletes a customer, an application or a member of staff, because those
records are the evidence behind decisions.

Sensitive fields are absent rather than masked — a masked figure still
confirms one exists. Document storage keys, bureau references, KYC identity
fields, file digests and every password or token hash are withheld from all
roles, and that is asserted against the rendered HTML in
`SensitiveExposureTest`.

## Database

**Migrations are the schema. Seeders are the data.** The application does not
depend on any pre-existing database file to rebuild itself:

```bash
php artisan migrate:fresh   # 41 migrations, from empty
php artisan db:seed         # baseline development data
```

41 migrations produce 46 tables, 502 columns, 41 foreign keys and 130
indexes. `php artisan migrate:fresh` is verified to work from nothing on both
MySQL and SQLite.

Development data comes from four seeders, each opt-in except the first:

| Seeder | Writes | In production |
|---|---|---|
| `DemoCustomerSeeder` | the one customer the app's mocks mirror | refuses |
| `AdminUserSeeder` | a Super Admin | requires env vars, or refuses |
| `SampleCatalogueSeeder` | six fictional products | refuses |
| `DemoFixtureSeeder` | four invented customers with applications, documents, cases | refuses |

Demo customers use the reserved `90000001xx` block and every record is
labelled — names begin "Demo", references begin `DEMO-`. Remove them with
`php artisan demo:purge`.

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
| Push notifications | `FYNNEDGE_PUSH_DRIVER` | `none` | **No live push provider connected** — no FCM, no APNs, no credentials, no device tokens |
| Advisor / support CRM | — | — | **Not connected.** No advisor system, ticketing system, CRM or chat provider. Support cases live in FynnEdge's own tables and are read by whoever looks at them |
| Language model / predictive model | — | — | **Not connected, and not required.** The intelligence layer is deterministic domain rules; FynnAI reads it rather than producing it |
| Analytics platform | — | — | **Not connected.** No warehouse, event stream or feature store. Financial observations are written at meaningful change only |
| Email / SMS / WhatsApp notifications | — | — | **Not implemented.** No channel exists and none is stubbed |
| Marketing communication | — | — | **Not implemented.** Nothing composes, schedules or sends one |
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

### Notifications

Two rules shape the whole layer.

**An internal event is not a customer notification.** FynnEdge records far
more than a customer needs to read — every submission attempt, every
provider event including the ones it ignores, every profile edit. A
notification for each would be a log, and a log is not communication. So
`NotificationPolicy` is the only thing that decides, it returns a decision
or `null`, and `null` is the ordinary answer.

**Creating a notification is not delivering a push.** They are separate
tables for that reason. A push that fails, a provider that is not
configured, a customer with no device and a customer who turned push off
all end the same way for the notification: it is in the notification
centre, unchanged, waiting to be read.

```
Existing domain event  →  NotificationPolicy  →  notifications row
                                              →  DeliverNotification job
                                              →  NotificationDeliveryProvider
```

`NotificationDecision` is the canonical object every producer must build,
and it takes two things away from callers entirely: the **category** and
whether the notification is **transactional** are both derived from the
type. A caller cannot file a document problem under goals, and cannot
classify "action required" as optional. It also refuses a link the router
cannot open, and copy containing anything shaped like a PAN, a long number,
a storage key, a digest or a token.

**Idempotency** is a unique index on `(user_id, idempotency_key)`, reached
through `insertOrIgnore`. Not an `if exists` check — provider redelivery,
queue retries and a double-tapped button all aim at the window between the
read and the write. Eight forked processes recording one event produce one
row, and exactly one of them believes it created it, which is what stops a
customer being pushed eight times. Provider-event idempotency is separate
and stays in Module 28.

**Ordering** is inherited, not reimplemented. Provider-event notifications
are created inside `SubmissionReconciliationService`'s transaction on the
applied branch only, so an event Module 28 ignored as stale cannot announce
"under review" after a disbursal.

**Attribution.** A provider's decision is the provider's. FynnEdge never
approves, declines or disburses anything, and the attribution is in the
title so it survives a truncated push.

**Read state** is server-side and idempotent: the first `read_at` stands, so
reading twice does not move when they first saw it. `read-all` is safe to
repeat and returns zero the second time.

**Preferences** change how FynnEdge reaches out, never what it records.
Turning push off does not empty the notification centre. Only categories in
which *every* type is non-transactional can be muted — derived from the
types themselves, so adding a transactional type closes a category
automatically. Today that is `financial_health` and `goal`; applications
and documents cannot be muted at all. Marketing consent is a separate thing
in a separate place and never gates service communication.

**Deep links** are one contract, shared by `App\Domain\Notifications\DeepLink`
and Flutter's `DeepLinks`: `fynnedge://applications/{id}`,
`fynnedge://vault/{id}`, `fynnedge://fynn-score`, `fynnedge://goals`,
`fynnedge://support`. There is deliberately no per-goal link, because there
is no per-goal screen. A link the app does not recognise opens nothing.

**Retention is unset.** Notification history is kept. No period has been
decided by product or legal, and `fynn:retention` does not delete
notifications — inventing a period to make the module feel finished would
be deleting a customer's own record of what they were told.

### Decision history and explainability

**What is recorded, and what deliberately is not.** Of the decision contexts
a customer could be said to explore, only **two** reach the server as an
action: a FynnTwin simulation and a product comparison. Both are recorded.

Nothing else is. `product_detail_review` and `fynntrust_review` would be
records of a GET — page-view tracking however they are labelled — and
FynnTrust and FynnMirror have **no API endpoint at all**, so recording them
would mean inventing a tracking endpoint to build a history of what somebody
looked at. Opening a product is not wanting it.

**Applications are read, not copied.** `loan_applications` has held an
immutable snapshot of exactly what the customer was shown since Module 9 —
amount, tenure, rate, EMI, fees and the catalogue version behind them. A
second copy could disagree with the first, and the customer would be shown
whichever the code happened to read. There is also **no `application_id` on
a decision**: FynnEdge cannot know a simulation led to an application
without inferring it from "same product, later", and a column that could
only be filled by a guess will be.

**Historical integrity.** An explanation is built from figures recorded at
the time, never recomputed. A decision from six months ago explains itself
with the rate it was actually shown — verified live by dropping a customer's
income from ₹1,50,000 to ₹40,000 and re-reading the decision, which still
says ₹1,50,000, noted "as it was at the time".

**Provenance on every figure.** `customer_provided`, `calculated`,
`catalogue_published`, `provider_derived`, `simulated`,
`historical_snapshot`. There is no `estimated` and no `predicted`: FynnEdge
holds a figure, was given one, or worked one out.

**What was not assessed is said out loud.** An affordability explanation
carries "Not assessed: whether a lender would approve this" rather than
leaving a gap — a gap where approval would be is a gap a customer reads as
a pass.

**`DecisionSnapshot` is the canonical boundary**, with an allowlist of
figures and a value check on top: an allowed key carrying a PAN is the same
disclosure as a key called `pan`. A comparison records no winner, because
there was none.

**Deduplication:** the type, figures, product and day. A slider that lands
back on the same figures is one exploration; anything different is its own.
Proved with eight forked processes.

**Retention is unset.** A decision history is a customer's own record of
what they were shown. No period has been decided and nothing deletes it.

### Financial intelligence

**The finding this module is built on:** FynnEdge kept **no financial
history at all**. `financial_profiles` is a single mutable row, so editing
an income destroyed the previous one irrecoverably, and no FynnScore had
ever been remembered.

That makes a whole class of claim impossible rather than merely
unimplemented — "your income increased 12%", "your expenses are falling",
"your score improved by 6", "this goal has stalled". So FynnEdge starts
observing, and **starts empty**: no row in `financial_observations` is
reconstructed from today's profile, so no existing customer acquires a
history they did not have. Until a customer has been observed twice, the
honest answer to "what has changed?" is that FynnEdge does not know, and
`history.has_comparable_history` says so explicitly rather than leaving a
client to infer it from an empty list.

Observations are written at **meaningful change only** — a profile edit, a
goal change, an application milestone. Never a page view: an app open is not
a financial event, and a table that grew on refreshes would be a record of
somebody's attention rather than their money.

```
Domain event  ≠  Financial Moment  ≠  Notification
```

A **moment** is one of the handful of events a customer would recognise as
part of their own story. Moments are **historical facts and are never
rewritten**: a moment saying a score rose from 64 to 71 keeps saying that
after the score falls to 55. That is why the sentence is written at the time
and stored — deriving it on read would mean deriving it from today's state,
which is exactly the rewriting the table exists to prevent. Duplicates are
prevented by a unique index on `(user_id, idempotency_key)`, proved with
eight forked processes.

**Insights** are the opposite: derived on every read and never stored, so a
recommendation cannot outlive its condition. A score change is deliberately
**not** an insight — it is something that happened, so it lives in the
timeline.

**The action plan** is that ladder's full output, capped at three. The
single next action is its first item, so Home's card and the plan beneath it
come from one engine and cannot suggest different things. It is allowed to
be short or empty: a customer whose finances are in order is told so rather
than handed an invented task.

Two rules exist to keep it a planner rather than a funnel. A customer whose
recorded outgoings exceed their income is offered a review of their own
figures and is **never** pointed at borrowing — that one was found by
probing, because the three-item cap had hidden it from the test written for
it. And a strong FynnScore produces no improvement action at all.

**Next best action** is a single answer from a deterministic ladder:
blocked → missing input → active decision → goal deadline → getting started
→ nothing needed. The first rung exists because of a failure it prevents:
Home used to answer *"Set your first goal"* to a customer who had been told
not to resubmit an application FynnEdge could not confirm. Home now consumes
these rules rather than running its own, so the two cannot disagree.

**Thresholds** are existing product definitions — the 45% affordability
reference and the six-month emergency target, both FinancialEngine's. Module
31 introduces one of its own, on `ScoreChangePolicy`, with the reasoning
beside it.

**Explainability.** Every insight, next action and moment records the rule
that produced it and the values it fired on. None of that reaches the
customer — it describes the system — and a test fails if anything ships
without it.

**Retention is unset.** Moments and observations are a customer's own record
of their financial life. No period has been decided, nothing deletes them,
and `fynn:retention` does not touch them.

### The financial review

The customer asking to be shown where they stand. **There is no review
table, no `/financial-review` endpoint and no review engine** — the review
is the `/intelligence` read, and every section of it already existed
somewhere: the position is `FinancialPosition`, the score is
`FynnScoreEngine`'s, the goals are the Goals module's own metrics, the
changes are Module 35's observations and the next step is Module 33's
ladder.

`/intelligence` therefore carries two things it did not before — `score` and
`goals` — both from the state the request already built, at no extra query.
That is what makes the review one coherent moment: a goal edited between two
requests could otherwise let the goal section contradict the next step
beside it.

**Asking for a review changes nothing.** No observation, no moment, no
decision, no notification. Because nothing is recorded, FynnEdge has no
honest way to say when the last review was or when the next is due, and the
screen says so by not saying it: no schedule, no reminder, no streak.

**What it deliberately does not have** is a verdict. No review score, no
completeness percentage, no health grade. FynnScore already has bands, and a
second opinion about somebody's finances is exactly what a review must not
be. `test/product_language_test.dart` scans both codebases for the grading
and scheduling vocabulary.

**One score, one set of goal figures.** `FynnScore::toSummaryArray()` is the
only place a band is turned into a label and a sentence, so the review and
the FynnScore screen cannot phrase one differently. The goals come from the
metrics `FinancialEngine` already produced for the snapshot, bounded at ten
with the true count beside them.

### Financial progress

What FynnEdge has actually observed changing, and nothing else. Every item
on the progress feed comes from a pair of consecutive rows in
`financial_observations` — **today's figures are never one end of a
comparison**, because they are what is true now rather than something
FynnEdge wrote down, and pairing the two would invent the second half of
every entry.

`FinancialProgress` reads the observations and returns the metrics that
moved. It is silent by default, and each silence has a reason:

| Situation | Why nothing is said |
|---|---|
| One observation | A baseline, not a trend |
| Identical values | Not a change, however much else moved |
| A score arriving | An arrival — Module 31 records it as a moment |
| A score across model versions | A different measurement; subtracting is meaningless |
| A move under `ScoreChangePolicy::POINTS` | The threshold the product already uses |
| Either end unmeasured | Zero and absent are different facts |
| A buffer with no outgo | No denominator, so no figure that can have changed |

**A score change explains itself from what was true at the time.** The model
reads three ratios; both observations stored them; so each component is
reported as the value it had when each score was calculated, scored by
FynnScore's own published curves. Nothing is recomputed from the current
profile, which is what keeps a historical explanation still true tomorrow.
It is never a cause: several figures move between two observations and the
model averages three of them.

**Deduplication is enforced by the database.** `financial_observations`
carries a `state_digest` and a `follows_id`, unique together per customer,
reached through `insertOrIgnore`. An observation identical to the one it
follows is not a new observation; a state a customer returns to later is,
because the row before it is different. The recorder also compares before
writing — that comparison handles the sequential case and the index handles
the concurrent one, which `ObservationConcurrencyTest` proves with eight
forked processes.

**What has no history.** Goal amounts are not observed, so FynnEdge cannot
say "you saved ₹25,000 more" — the previous value was replaced by the edit.
Page views, Twin simulations and product comparisons write nothing here:
Module 32 records explorations as decisions, in its own table, and an
attention record is not a financial fact.

### The financial plan

A plan is a reading, not a record. **There is no `financial_plans` table, no
`/financial-plan` endpoint and no plan engine.** It is three things read once
and rendered together: where the customer's figures stand, the goals they
created, and the one step the existing action ladder produced.

**`FinancialPosition`** is the factual half. It reads a `FinancialSnapshot`
and divides nothing — no ratio, no threshold and no arithmetic of its own.
What it adds is the sentence: three states (income above outgo, income
matching outgo, outgo above income) and an honest fourth for a customer who
has recorded no income at all. The same sentences reach the plan header and
the buffer rung of the ladder, because copy that describes somebody's money
is as much a source of truth as the arithmetic behind it, and two versions
of it drift.

**The buffer question can have no answer.** `Ratio::timesOver` returns zero
for a zero denominator, and reading that as a fact told a customer with
nothing recorded going out that their savings would cover less than a month.
`bufferIsMeasurable()` separates a low figure from an absent one, and the
rung stays silent for the second.

**Completion is derived, never a status.** `FinancialGoal.status` stays
`active` for a goal whose two amounts have met; `is_complete` is
`saved >= target` at every read. A status would be a second answer that could
disagree with the figures printed beside it.

**`monthly_required` is a calculated reference, not a commitment.** It is
`remaining / months_remaining`, recomputed on every read, and there is no
commitment model for it to be a commitment against. The goal card used to
read "Set aside ₹9,474 a month for 19 months to get there" — an instruction
the customer never agreed to, followed by a promise about where it leads. It
now says what the arithmetic gives at the target and date they chose.

**Nothing predicts.** No "on track", no projected completion, no month by
which anything will happen. `test/product_language_test.dart` scans both
codebases for the phrasings, allowing a mention only where the sentence is
refusing to make it.

**Two rungs the planner was reaching wrongly.** `exploreOptions` is
documented as `goals.any and applications.empty` and its sentence reads "see
what suits the goal you set" — but it checked only the applications, so a
customer with no goal, and a customer whose income FynnEdge did not have,
were both pointed at loan discovery. Both now match the stated rule. The
negative-surplus guard from Module 33 is unchanged and still the one that
matters most.

### Support

**NO ADVISOR SYSTEM, SUPPORT CRM, TICKETING SYSTEM OR CHAT PROVIDER IS
CONNECTED.** A support case is written to FynnEdge's own tables and read by
whoever looks at them. Nothing is forwarded anywhere, nothing replies, and
no case advances on a timer — a status describing work nobody did is a lie
a customer would act on.

That is also why there is no advisor endpoint. `author_type = advisor`
exists in the schema with no write path anywhere in the codebase: the model
is ready for an advisor interface, and inventing an endpoint for one would
be inventing the advisor.

**Contextual, not generic.** The point of the module is that support knows
what the customer was doing. `[Get help]` on an application whose submission
was never confirmed opens a request already carrying that application, and
the server raises it to **high priority because of the application's state**
— never because a request said so. `SupportContextType` is an allowlist
(never a model class name) and `ContextResolver` looks every id up through
`$user->applications()`, so another customer's id answers exactly as an id
that never existed.

What a context carries is a reference, a status sentence and a product name.
The status sentence is `CustomerStatus`'s own — support and the application
screen cannot say two different things about the same application.

**FynnEdge decides status and priority.** Neither is fillable and neither is
read from a request. Transitions are checked against
`SupportCaseStatus::allowedNext()` before anything is written, and every
move leaves an event saying who made it. A resolved case cannot be reopened.

**References** are `FE-SUP-YYYY-NNNNNN`, allocated by
`SequentialReference` — the insert is attempted and a unique violation means
take the next number. Read-then-write was measured losing six of eight
concurrent requests.

**Rate limits:** 5 cases and 20 messages per customer per hour, both
deliberately generous. A limiter that stops somebody asking for help is a
worse failure than a duplicate, and duplicates are already prevented by the
idempotency key.

**Notifications** reuse Module 29 entirely — two types, `support_resolved`
and `support_waiting_for_customer`, and nothing else. A case being
acknowledged is FynnEdge confirming something the customer watched happen.

**Privacy.** A subject, a description and a message body are the customer's
own words about their own money. They are never logged, never sent to
FynnAI, and never put in a notification. Logs carry the reference, category,
status and priority.

**Retention is unset.** Support history is a customer's own record of what
they asked and what they were told. No period has been decided, nothing
deletes it, and `fynn:retention` does not touch it.

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

Several suites are deliberately written to fail when something is *added*
rather than when something breaks, because the failure mode being guarded
against is an absent decision rather than a wrong one:

- `OwnershipCoverageTest` reads the route table. Every authenticated route
  with a `{parameter}` must be probed as the wrong customer or carry a
  written reason for needing no probe.
- `RoleMatrixTest` holds the whole admin access matrix — 14 resources × 6
  roles — and fails if a resource exists that the matrix does not mention.
- `SensitiveExposureTest` plants sentinel values in withheld fields and
  searches the rendered HTML for them, so the guarantee is about the
  response rather than the resource definition.
- `PanelRendersTest` renders every panel page **with fixtures loaded**; an
  empty table renders almost anything.
- `VaultIsolationTest` fails if any new route serves a download.

Three tests skip where `pcntl` is unavailable. They prove concurrency
behaviour rather than simulate it, and skipping is the honest outcome; the
same races were verified manually with parallel processes.

## Operations

```bash
php artisan fynn:health                 # anything stuck that needs a person
                                        # (includes urgent help requests nobody has picked up)
php artisan fynn:health --json          # same, for a monitor
php artisan fynn:retention              # what is held, and how old
php artisan fynn:provider-events:retry  # re-attempt events that reached nothing
php artisan queue:work                  # document reading, when QUEUE_CONNECTION=database

php artisan database:verify             # this database against the original SQLite
php artisan database:verify --allow-extra  # …tolerating rows added since
php artisan demo:purge --admin          # remove the demo customers and dev admin
```

`database:verify` answers one question: did the conversion lose anything.
It compares row counts and column shape table by table and never prints a
record's contents — it will one day be pointed at a database holding real
customers. Once demo fixtures are loaded the database legitimately holds
more than the original, which is what `--allow-extra` is for: gains are
reported as `EXTRA`, losses still fail.

`demo:purge` removes two populations: the customers `DemoFixtureSeeder`
writes, and the ones the Flutter contract test leaves behind after signing
in against a running backend. Both sets are named individually in
`App\Domain\Demo\ReservedIdentities` — never matched by prefix, so a real
customer cannot be caught by a rule getting broader. It shows what will
cascade and asks before deleting; `--dry-run` inspects without changing
anything, which is the only way to see the effect when `--force` is used
from a script. The baseline development customer is deliberately not
included: it is the one the Flutter app's mocks mirror.

`fynn:health` exits non-zero only when a customer is **blocked and cannot
resolve it themselves** — today that means a submission whose outcome was
never confirmed, where the app tells them to contact support. Everything
else is reported as attention and exits clean.

There is no monitoring stack configured in this project and none is
pretended: the exit code and `--json` are the integration points for
whatever a deployment actually has.

## Environment

`.env.example` documents every variable and why it holds the value it does.
The ones that must change before a deployment faces real customers:

| Variable | Development | Production | Because |
|---|---|---|---|
| `APP_ENV` | `local` | `production` | Several seeders and the log OTP sender key off it |
| `APP_DEBUG` | `true` | **`false`** | With debug on, every error carries file paths and a stack trace |
| `APP_KEY` | generated | generated | Never share one between environments |
| `LOG_LEVEL` | `debug` | `info` | Debug is noisy and likelier to capture something that should not be kept |
| `DB_CONNECTION` | `mysql` | `mysql` | SQLite is for the test suite |
| `DB_TIMEZONE` | `+00:00` | `+00:00` | MySQL converts `TIMESTAMP` from the session zone; unpinned, every stored time shifts |
| `SESSION_ENCRYPT` | `false` | **`true`** | Sessions are stored in the database, and backups copy that table |
| `SESSION_SECURE_COOKIE` | unset | **`true`** | Otherwise an admin session travels in clear text |
| `FYNNEDGE_OTP_DRIVER` | `log` | a real SMS provider | The log sender throws in production |
| `FYNNEDGE_OTP_DEV_HINT` | `true` | **unset** | On, the API returns the code it just issued |
| `TRUSTED_PROXIES` | empty | your load balancer | Untrusted, every customer presents as the proxy and the OTP limits collapse into one bucket |
| `FYNNEDGE_ADMIN_EMAIL` / `_PASSWORD` | unused | set, or no admin | There is no default production password |

No secret belongs in the repository. `.env` is gitignored; `.env.example`
carries names and explanations, never values.

## Notes for going live

- **The production checklist is the Environment table above.** None of it is
  set for you, and two of the entries — `APP_DEBUG` and
  `FYNNEDGE_OTP_DEV_HINT` — disclose information if left at their
  development values.
- **Remove the demo data.** `php artisan demo:purge --admin` takes out the
  four invented customers and the development administrator. A database that
  has been used for development otherwise carries invented loan applications
  into every report run afterwards.
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
