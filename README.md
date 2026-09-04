# WorkLog'd

![PHPStan badge](https://img.shields.io/badge/PHPStan-Level_7-blue?style=flat-square&color=blue)

WorkLog'd is a modern time tracker for freelancers and agencies, with first-class submission of
your tracked time to Jira.

It is a fork of [solidtime](https://github.com/solidtime-io/solidtime), which it tracks for
upstream fixes and features. Everything solidtime does, this does; see
[what this fork adds](#what-this-fork-adds) for the rest.

## Features

 - Time tracking: Track your time with a modern and easy-to-use interface
 - Projects: Create and manage projects and assign project members
 - Tasks: Create and manage tasks and assign tasks to projects
 - Clients: Create and manage clients and assign clients to projects
 - Billable rates: Set billable rates for projects, project members, organization members and organizations 
 - Multiple organizations: Create and manage multiple organizations with one account
 - Roles and permissions: Create and manage organizations
 - Import: Import your time tracking data from other time tracking applications (Supported: Toggl, Clockify, Timeentry CSV)

## What this fork adds

Everything else behaves as upstream solidtime.

### Integrations

- **Jira**: log your time entries to Jira issues. Put a ticket key like `PROJ-123` in a description
  and that time is logged against the issue. Entries on the same ticket, with the same description,
  on the same day are combined into a single worklog. A preview shows exactly what will be created,
  updated or removed before anything is sent. See [Jira integration](#jira-integration-optional).
- **Google Calendar**: show your calendar events next to your time entries and copy an event into a
  time entry with one click. See [Google Calendar integration](#google-calendar-integration-optional).

### Calendar

- **Zoom**: `-` and `+` controls step the vertical scale by one visible hour at a time, from a
  single hour up to the whole configured day. Upstream is fixed at 100px per hour, which fits about
  eight hours on screen.
- **Days shown**: choose how many days the week view shows, counting from your start of the week.
  Links to a day outside that range open in the day view rather than landing on nothing.
- **Scroll position is kept** across page reloads and when paging between weeks, instead of jumping
  back to the current time.
- **Hover popup** with the full description, time range and duration, for entries too short to show
  their own text.
- **Live duration and range** while dragging out a new entry, so you can see what you are creating
  before you release.
- **Escape cancels** a drag, resize or selection in progress.
- **Readable event layout**: the duration sits bottom right at the same size as the rest of the
  inset, so a column can be scanned down.
- **Missing ticket hints**: optionally mark work entries whose description contains no ticket key
  with a red dot, in the calendar, the time list and the timesheet. Off by default.

### Reporting and tables

- **Description filter** on the reporting page. The other filters pick from a list, which does not
  suit free text, so this one matches on the text of the entry itself.
- **Pagination controls**: paginated tables show which rows you are looking at out of how many, and
  let you change the page size. The detailed report was previously fixed at 15 rows with no total.

### Import

- **Assign imported entries to one member**. CSV importers take each row's owner from the file, so
  importing your own export usually creates placeholder users. You can now put everything on a
  single member instead.

### Projects and appearance

- **Alpha-capable colour picker** for projects, replacing the 19 fixed presets - any organization
  with more than 19 projects necessarily had duplicates.
- **Per-user colour for time without a project**, instead of a hardcoded grey.

### Removals

- **No phone home.** Upstream schedules two twice-daily POSTs to `app.solidtime.io`: one sending
  usage counts (users, organizations, projects, clients, tasks, time entries, audit rows) and one
  sending this installation's version and `APP_URL` to check for a newer release. Both were on by
  default. The commands, the service behind them and their scheduling flags are gone, so no part
  of this fork contacts solidtime's servers. The admin panel consequently shows the running
  version and build but no "update available" notice.

## Self Hosting

This fork ships its own example stack, built around the images published to
`ghcr.io/technowhizz/worklogd` on every push to `main`.

```bash
cp docker-compose.prod.example.yml docker-compose.prod.yml
./generate-secrets.sh                        # writes worklogd.prod.env
# edit docker-compose.prod.yml: APP_URL, SUPER_ADMINS and the mail settings
docker compose -f docker-compose.prod.yml up -d
docker compose -f docker-compose.prod.yml exec app \
    php artisan admin:user:create "Your Name" you@example.com --verify-email
```

**There is no setup wizard, and the first user has to be created from the command line.** The
example stack ships with `APP_ENABLE_REGISTRATION=false`, which removes public sign-up entirely -
`/register` returns 404 and the login screen offers no link to it - so a fresh database has no
accounts and no way to make one through the browser. `admin:user:create` is the way in: it creates
the user together with their personal organization and prints a generated password, or pass
`--ask-for-password` to choose one. Use the address you put in `SUPER_ADMINS` and keep
`--verify-email` - admin panel access needs the email both listed there and verified, and
`admin:user:verify <email>` does the latter afterwards if you forget. See
[Admin portal](#admin-portal) for what that gets you and how to hand the access to anyone else.

Everyone after that joins by invitation, so registration can stay off. Someone invited to an
organization still has to create an account before they can accept, so an invitation link opens the
sign-up screen for the person holding it - and only for the address the invitation was sent to.

`generate-secrets.sh` produces every value that is yours to invent - `APP_KEY`, the database
password and the Passport signing keys. Keep that env file: `APP_KEY` decrypts the stored Jira and
Google tokens, and the Passport keys sign every API token in circulation, so regenerating them is
not recoverable.

Nothing else needs bootstrapping by hand. On boot the app container creates the `storage`
directories (a bind-mounted `storage/` starts empty and masks the image's copy, unlike a named
volume), migrates the database, and creates the personal access client that the API token screen
mints tokens through. All three are idempotent, so they simply pass on every later boot.

Note the **queue worker is not optional here**. A Jira sync runs as a queued job, so without one the
sync dialog waits forever. That also means the cache must be shared between containers rather than
the default `file` driver.

solidtime's [self-hosting guides](https://docs.solidtime.io/self-hosting/intro) still apply to
everything this fork has not changed.

### Admin portal

`/admin` is the portal for whoever runs the instance, built from the same Vue components and the
same stylesheet as the rest of the app. It reaches across every organization: edit, export, import
and delete organizations and see their members and pending invitations; edit users, impersonate
them, and hand out or take away admin access; record what each organization is billed; and read
the audit log, the failed jobs, the API tokens and the outstanding invitations.

Access is not a role inside an organization. A user gets it either from the `SUPER_ADMINS`
environment variable or from the **Super admin** switch on their record in the portal, and in both
cases their email address has to be verified. The environment variable is the bootstrap and the way
back in: admins named there cannot have the access taken away from inside the portal, and nobody can
revoke their own. From the command line:

```bash
php artisan admin:user:super-admin you@example.com            # grant
php artisan admin:user:super-admin them@example.com --revoke  # revoke
```

#### Billing

Each organization has one subscription record under **Billing → Subscriptions**: a plan, a status, a
trial end, seats, a price and interval, and a reference for whatever system actually takes the
money. The dashboard totals monthly revenue, trials about to end, lapsed plans and organizations
with nothing recorded, and the subscriptions table flags any organization with more members than the
seats it paid for.

#### Connecting Stripe

Payments run through Stripe, via `laravel/cashier`. The organization is the customer, not the
user - a person in three organizations is a customer of none of them personally.

1. In Stripe, create one **Professional** product with two recurring **per-seat** prices, monthly
   and yearly, in the currency you set as `BILLING_CURRENCY`.
2. Put the keys and both price IDs in the environment:

```bash
STRIPE_KEY=pk_live_...
STRIPE_SECRET=sk_live_...
STRIPE_WEBHOOK_SECRET=whsec_...
CASHIER_CURRENCY=gbp
STRIPE_PRICE_PROFESSIONAL_MONTHLY=price_...
STRIPE_PRICE_PROFESSIONAL_YEARLY=price_...
```

3. Add a webhook endpoint in Stripe pointing at `https://<your-host>/stripe/webhook`, subscribed
   to the `customer.subscription.*` and `invoice.*` events. **The webhook is the source of truth** -
   the redirect back from Checkout is not trusted for anything, because a customer can close the
   tab before it happens.

Without the price IDs the billing page says there is nothing to buy rather than breaking, so a
self-hosted instance that never wants payments can simply leave them empty.

Seats follow membership: adding a member raises the Stripe quantity and Stripe prorates the current
period; removing one lowers it at the next renewal. That happens on a queued job, so inviting
somebody never fails because Stripe was briefly unreachable - **the queue worker is required for
billing to stay correct**, not just for Jira.

Enterprise has no self-serve price on purpose. Those deals are negotiated and then recorded by hand
in the admin portal, which carries a custom price, seat count, interval and an invoice reference.

**Recording a subscription does not restrict anybody.** With `BILLING_ENFORCE=false`, the default,
every organization keeps full access however its record reads, which lets you get the records right
before they matter. Setting `BILLING_ENFORCE=true` then makes them real: an organization with more
than one member and no active paid plan or running trial is blocked from the API until its billing
is sorted out. `BILLING_TRIAL_DAYS` sets how long a trial started from the portal runs for, and
`BILLING_CURRENCY` sets the currency new subscriptions default to and the one the revenue total is
reported in.

#### What each plan gets

| | Free | Professional |
|---|---|---|
| Members | 1 | unlimited, billed per seat |
| Jira worklogs synced | 5 per week | unlimited |
| Google Calendar | no | yes |
| Estimates, rounding, shared and PDF reports | no | yes |

The Jira allowance counts worklogs *created* in Jira and resets on Monday. Correcting or removing a
worklog already pushed does not spend it - otherwise fixing a typo would be a reason to run out.
Running out never touches stored credentials: a lapsed organization keeps its Google refresh token
and its record of what is already in Jira, because revoking the first cannot be undone and deleting
the second would re-push every worklog as a duplicate on the way back up.

The count comes from `jira_worklog_creations`, an append-only ledger, rather than from the live
worklog rows. Those get deleted when a worklog is removed from Jira, which would make the allowance
refundable - sync five, delete them, sync five more, forever. A creation is a fact about the past.
Syncs are also locked one-at-a-time per organization, since two running side by side would each
read the full allowance before either had spent any of it.

One limit worth knowing: the allowance is per organization, and organizations are free to create.
Somebody determined to dodge it could spread a week's work across several free organizations, at
the cost of separate clients, projects, reporting and Jira configuration in each. That is a
deliberate trade rather than an oversight - the organization is the billing unit, so it is also the
unit the allowance belongs to.

### Google Calendar integration (optional)

The Google Calendar integration is disabled until you provide an OAuth client. Without one, nothing
about it is shown to your users.

1. In the [Google Cloud Console](https://console.cloud.google.com/), create a project and enable the
   **Google Calendar API**.
2. Create an OAuth client of type **Web application** and add
   `https://<your-host>/integrations/google-calendar/callback` as an authorised redirect URI.
3. Set `GOOGLE_CLIENT_ID` and `GOOGLE_CLIENT_SECRET` in your `.env`.

Users then connect their own Google account under *Profile Settings*. WorkLog'd requests read-only
access to calendar events plus the account's email address, and stores only the OAuth tokens
(encrypted) - event titles and times are fetched from Google per request and never persisted.

Note that `calendar.events.readonly` is a *sensitive* scope. Google requires app verification for
published apps, with exemptions for testing, internal use within a Google Workspace organization,
and apps under 100 users.

### Jira integration (optional)

Jira uses **OAuth 2.0 (3LO)**, so it needs an Atlassian app the same way Google Calendar needs a
Google client. Without one the integration stays hidden.

1. At [developer.atlassian.com](https://developer.atlassian.com/console/myapps/) create an
   **OAuth 2.0 integration** with **resource-level** access. Resource level restricts the grant to
   the one site the person picks, rather than every site in their Atlassian account.
2. Under *Permissions* add the **Jira platform REST API** with `read:jira-work` and
   `write:jira-work`, and the **User identity API** with `read:me`. `offline_access` is not a
   console permission - it is added to the authorization request, which the app does for you, and
   without it Atlassian issues no refresh token at all.
3. Under *Authorization* set the callback to `https://<your-host>/integrations/jira/callback`.
4. Under *Distribution* turn sharing on, or only your own Atlassian organization can authorise it.
5. Put the credentials in the environment:

```bash
JIRA_CLIENT_ID=...
JIRA_CLIENT_SECRET=...
```

Then an owner or administrator sets the **Jira site URL** (`https://your-org.atlassian.net`) under
*Organization Settings*, and each member connects their own Atlassian account under *Profile
Settings*. If somebody picks the wrong site on Atlassian's consent screen the connection is
refused with the site it expected, rather than quietly logging their time to another Jira.

Access is personal on purpose, so worklogs are attributed to the person who did the work rather
than to a shared account. Access tokens last about an hour and refresh tokens rotate on every use,
so a stolen credential is worth far less than a token that stays valid until somebody notices. A
refresh token unused for 90 days expires, and the settings card then asks for a reconnection.

**No Atlassian personal data is stored.** The connection row holds tokens and a cloud id, nothing
else - the connected account's name and email are fetched from `/me` when the settings card renders
and cached for well under a day. That is what places the app outside Atlassian's Personal Data
Reporting API, and it is why the app can answer "no" to their personal data declaration. Keep it
that way: adding an `account_id` or `email` column back would make that declaration false.

Optional settings:

- **Project keys**: restrict detection to your own keys, ex. `PROJ, OPS`. Without it anything shaped
  like an issue key is picked up, which also matches things like `UTF-8` and `COVID-19`.
- **Only sync work from**: a date before which work is treated as already logged. Set it after
  importing history from another tracker so time your old process already sent is not logged twice.

Syncing is always manual - the **Jira** button in the calendar toolbar, then a preview you confirm.
Nothing is written to Jira without that. The preview reports what would be created, updated,
removed and skipped, and warns before deleting anything. Only worklogs this app created are ever
touched; worklogs logged by hand in Jira are left alone.

Breaks, running timers and totals under a minute are never synced. A sync runs as a queued job, so
set `QUEUE_CONNECTION` to `database` or `redis` and run a worker for it to happen in the background;
with the default `sync` it runs inline and blocks the request until it finishes.

## Issues & Feature Requests

Please [open an issue](https://github.com/technowhizz/solidtime/issues/new) on this repository.

Bugs that also reproduce on upstream solidtime are better reported
[there](https://github.com/solidtime-io/solidtime/issues/new) so everyone benefits - this fork
will pick up the fix when it merges upstream.

## Security

Looking to report a vulnerability? Please refer our [SECURITY.md](./SECURITY.md) file.

## License

This project is open-source and available under the GNU Affero General Public License v3.0 (AGPL v3). Please see the [license file](LICENSE.md) for more information.

It is derived from [solidtime](https://github.com/solidtime-io/solidtime), copyright the solidtime
authors, and remains under the same licence. The WorkLog'd name and marks are not part of that
grant.
