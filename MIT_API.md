# API - what this build adds

This build keeps ITFlow's REST API (`/api/v1/...`) as it is, so tools written for stock ITFlow work unchanged. This page covers only
what is different here:
- endpoints for our own features: service boards, timesheets and payroll, ticket parts, usage counts;
- our fields added to some of ITFlow's own reads;
- more ways to send the key;
- a few rules that protect our features' data.

For the API itself (endpoints, fields, roles on keys) see ITFlow's documentation at [itflow.org](https://itflow.org) and Admin > API
Keys on your own install.

**Contents**
- [Sending the API key](#sending-the-api-key)
- [How our endpoints answer](#how-our-endpoints-answer)
- [Service boards - `/api/v1/mit_boards/`](#service-boards---apiv1mit_boards)
- [Timesheets and payroll](#timesheets-and-payroll) - `mit_timesheets`, `mit_payroll`, `mit_charge_codes`, `mit_leave`, `mit_reimbursements`
- [Ticket parts - `/api/v1/mit_ticket_parts/`](#ticket-parts---apiv1mit_ticket_parts)
- [Usage counts - `/api/v1/mit_usage/`](#usage-counts---apiv1mit_usage)
- [Our fields on ITFlow's reads](#our-fields-on-itflows-reads)
- [API / service accounts](#api--service-accounts)
- [Rules that differ from stock ITFlow](#rules-that-differ-from-stock-itflow)
- [What the API never gives out](#what-the-api-never-gives-out)

## Sending the API key

Any of these work:

| Way | Example |
|---|---|
| `X-API-Key` header | `curl -H "X-API-Key: <key>" https://itflow.example.com/api/v1/clients/read.php` |
| `Authorization: Bearer` header | `curl -H "Authorization: Bearer <key>" ...` |
| `api_key` in the address (ITFlow's way) | `.../api/v1/clients/read.php?api_key=<key>` |
| `api_key` in the JSON or form body of a POST (ITFlow's way) | `{"api_key": "<key>", ...}` |

Use a header where you can: a key in the address ends up in web server and proxy logs, a key in a header does not.

- **Paging:** `limit` is held between 1 and 1000 (default 50), and `offset` at 0 or more, so a bad value no longer gives an error.
- **A body that is not JSON** is read as having no fields, rather than failing.

## How our endpoints answer

An API key acts as its ITFlow login, and every endpoint below runs with that login's rights. Each one uses the same function as its
page in ITFlow, so you get the same checks, ticket history, internal notes, notifications and audit log lines as if someone had done
it on screen. The role permission each endpoint needs is shown with it. Client limits apply everywhere: a key whose login is limited
to some clients only sees and changes those clients' records.

Every answer is JSON:

| | |
|---|---|
| A read | `{"success": "True", "count": <rows on this page>, "total": <rows in all>, "data": [...]}`. An empty result is `success: "True"` with `count: 0` (ITFlow's own reads answer `"False"` there). Pages follow `limit` / `offset`. |
| A change that worked | `{"success": "True", "message": "...", "count": 1, "data": [<the record>]}`. The message is the same one the page shows. |
| A refusal | `{"success": "False", "message": "<why>"}` with an HTTP status: `400` for a bad or missing field, `403` when the login's role or client limits don't allow it, `404` when the record isn't there or is out of reach, `409` when the record's state doesn't allow it (closed ticket, part already on a PO), `503` when the feature is switched off or its database update hasn't run. |

Hours are decimal hours with two places (`7.5` = 7 h 30 min). Dates are `YYYY-MM-DD`; times are the company's clock.

## Service boards - `/api/v1/mit_boards/`

Service boards are who works a ticket: Service Desk, Tier 1, Tier 2 and so on. **Needs:** Support (view to read, edit to move).

### List boards - `GET /api/v1/mit_boards/read.php`

Each board comes with:
- `board_id`, `board_name`, `board_description`;
- `board_is_default`: where new tickets land when no rule places them;
- `board_auto_assign`: whether round-robin is on;
- `open_tickets`: counted among the clients the key can see;
- `members`: a list, each with `user_id`, `user_name`, and `notify` (whether they are told about tickets arriving).

| Parameter | |
|---|---|
| `board_id` | Only this board |
| `ticket_id` | Instead of the list: this ticket's `board_id` / `board_name`, and every `moves` it has made (`from_board_id`, `to_board_id`, `how` - routed / created / moved / escalated / placed / archived - `by_user_name`, `at`) |
| `archived` | `1` = archived boards too |

The ticket's board is also on ITFlow's own `tickets/read.php` (see [Our fields on ITFlow's reads](#our-fields-on-itflows-reads)).

### Move or escalate a ticket - `POST /api/v1/mit_boards/move.php`

This does the same as the ticket page's Board picker, or **Escalate to another board** when `escalate` is `1`. The ticket must be
open. Afterwards:
- the move is in the ticket's history, with an internal note;
- the new board's members who asked to be told get a notification and an email;
- if the new board has auto-assign, an unassigned ticket goes to its next member.

| Field | | |
|---|---|---|
| `ticket_id` | required | The ticket |
| `board_id` | required | The board to move it to (an active board) |
| `escalate` | optional | `1` = escalate. A reason is then required |
| `reason` | optional | Why. Kept as an internal note. Up to 2,000 characters |
| `unassign` | optional | `1` = hand the ticket back to nobody, so the new board picks it up |
| `client_id` | optional | The ticket's client. Send it when the key's login is limited to certain clients |

```bash
curl -X POST https://itflow.example.com/api/v1/mit_boards/move.php \
  -H "X-API-Key: <key>" -H "Content-Type: application/json" \
  -d '{"ticket_id": 4821, "board_id": 3, "escalate": 1, "reason": "Needs a server person", "unassign": 1}'
```

The answer's `data` has the ticket's new `board_id` / `board_name` and its `ticket_assigned_to` afterwards.

## Timesheets and payroll

These endpoints only read. Submitting, approving and correcting timesheets stay on the timesheet pages. They need Timesheets
switched on (Admin > Settings > Modules).

**Who you can see:** a key's login always sees its own timesheets, plus the people it approves (Admin > Timesheets > People). With
**Timesheets** view on its role, it sees everyone. Asking for anyone else with `user_id` gives `403`.

**Hours** are worked out the same way as on the Timesheets & Payroll report. An approved pay period keeps the figures it was approved
with, even if settings change later.

### Timesheets - `GET /api/v1/mit_timesheets/read.php`

One row per person per pay period:
- who: `user_id`, `user_name`, `user_email`, `employee_id`, `pay_type` (hourly / salary / contractor);
- the timesheet: `timesheet_id`, `period_start`, `period_end`, `status` (open / submitted / returned / approved), `submitted_at`,
  `decided_by_user_id`, `decided_by_name`, `decided_at`, `decision_note`;
- the period's hours (listed in the next table).

| Hours field | |
|---|---|
| `billable_ticket_hours`, `other_ticket_hours` | Time on ticket notes, on billable / other tickets |
| `code_hours` | `[{code_id, code_name, hours}]` - hours on each charge code |
| `unaccounted_hours` | Normal hours nothing accounted for (counted as lost time, if that is switched on) |
| `not_on_a_code_hours`, `not_clocked_hours` | California rules: clocked time on no code; ticket / code time outside the clocked times |
| `paid_break_hours`, `worked_hours`, `leave_hours`, `unpaid_hours`, `paid_hours`, `total_hours` | As the report |
| `regular_hours`, `overtime_hours`, `double_time_hours` | The split payroll pays on |
| `meal_premium_hours`, `rest_premium_hours` | California: premium hours owed for missed meal periods / rest breaks |
| `billable_percent`, `california_rules` | |

| Parameter | |
|---|---|
| `from`, `to` | Every pay period touching these dates (default: the last finished pay period). At most 400 days |
| `period` | Any date in a pay period - that period only |
| `user_id` | Only this person |
| `status` | Only timesheets in this status |

### Payroll summary - `GET /api/v1/mit_payroll/read.php`

The Timesheets & Payroll report's summary, as data for a payroll provider: one row per person over the dates. Each row has:
- the same hours fields as above;
- `periods` and `periods_approved`: how many of the pay periods are approved;
- `leave_balances`: `[{bank_id, bank_name, balance_hours}]`, at the end of the dates;
- mileage and purchases to pay back: `reimburse_miles`, `reimburse_mileage`, `reimburse_purchases`, `reimburse_total`, and
  `reimburse_paid_elsewhere` (paid back in ITFlow outside payroll);
- `reimburse_through`: `payroll` or `itflow`.

**Needs:** Timesheets view, as the report does. Parameters: `from`, `to`, `period`, `user_id` (as above). People with nothing in the
dates are left out.

```bash
curl -H "X-API-Key: <key>" "https://itflow.example.com/api/v1/mit_payroll/read.php?period=2026-11-15"
```

### Charge codes - `GET /api/v1/mit_charge_codes/read.php`

The codes hours go on. Use them to match each `code_hours` entry to an earning code in your payroll provider. Each code has:
- `code_id`, `code_name`;
- `code_kind`: work, lost, leave or break;
- `code_paid`, `code_color`;
- `role_ids`: the roles that may use it (empty = everyone);
- `note_required`, `daily_max_hours`, `fills_closure_days`, `code_archived_at`.

`archived=1` includes archived codes. Any key can read them.

### Leave balances - `GET /api/v1/mit_leave/read.php`

Each person's balance on each leave bank (vacation, sick and so on) at the end of a date. Each row has `user_id`, `user_name`,
`employee_id`, `bank_id`, `bank_name`, `on` and `balance_hours`.

| Parameter | |
|---|---|
| `on` | The date (default today) |
| `user_id` | Only this person |
| `bank_id` | Only this bank |

### Mileage and purchases - `GET /api/v1/mit_reimbursements/read.php`

What people paid for themselves in the dates and are owed: own-vehicle trips (mileage) and purchases. These are the same items as
the timesheet's "Mileage and purchases" box. Each row has:
- `user_id`, `user_name`, `date`;
- `kind`: mileage or purchase;
- `what`, `where`, `ticket_id`, `miles`, `amount`, `trip_id`, `expense_id`;
- `owed`: whether it still counts towards what is owed;
- `paid_on` and `paid_through` (payroll / itflow);
- `state`: the same words the timesheet shows, e.g. "Goes to payroll" or "Paid through payroll Nov 30".

For a ticket of a client the key can't see, only its number is shown. Parameters: `from`, `to`, `period`, `user_id`.

## Ticket parts - `/api/v1/mit_ticket_parts/`

Parts used on tickets, the same as the ticket page's Parts box. A part's `source` is one of:
- `stock`: from stock, taken off the product's stock at once;
- `order`: to order, put on Purchasing > To Order;
- `other`: already on hand, so nothing is taken or ordered.

**Needs:** Purchasing switched on, and Support (view to read, edit to change).

### List parts - `GET /api/v1/mit_ticket_parts/read.php`

Each part has:
- `part_id`, `ticket_id`, `client_id`;
- `name`, `description`, `quantity`, `unit_price`, `tax_id`, `product_id`, `asset_id`, `source`;
- `status`: from stock, on hand, waiting to order, ordered, partly received, received, or not ordered;
- the order: `order_item_id`, `po_id`, `po_number`, `received_quantity`;
- billing: `invoice_id`, `invoice_number`, `billed_at`;
- `created_by`, `created_at`.

| Parameter | |
|---|---|
| `ticket_id` | Only this ticket's parts |
| `client_id` | Only this client's |
| `unbilled` | `1` = only parts not billed yet (what To Bill lists) |

### Add a part - `POST /api/v1/mit_ticket_parts/create.php`

The ticket must not be closed. An internal note and the ticket's history record the part.

| Field | | |
|---|---|---|
| `ticket_id` | required | The ticket |
| `name` | required | What it is, up to 200 characters |
| `quantity` | required | More than 0 |
| `source` | optional | `stock` (default), `order` or `other` |
| `product_id` | optional | The product it is. Stock is taken from it |
| `unit_price` | optional | What the client is charged for one (default 0) |
| `unit_cost` | optional | What one costs you. Only kept when the key's login may see costs. Never read back |
| `description`, `tax_id`, `asset_id` | optional | `asset_id` must be one of the client's own assets |
| `vendor_id` | optional | Where to order it from (for `order`) |
| `client_id` | optional | The ticket's client. Send it when the key's login is limited to certain clients |

```bash
curl -X POST https://itflow.example.com/api/v1/mit_ticket_parts/create.php \
  -H "X-API-Key: <key>" -H "Content-Type: application/json" \
  -d '{"ticket_id": 4821, "name": "1 TB NVMe drive", "quantity": 1, "source": "order", "product_id": 77, "unit_price": 129}'
```

### Change a part - `POST /api/v1/mit_ticket_parts/update.php`

Send `part_id` and the whole part (the same fields as create). A field you leave out is read as empty, just as the page's form would
send it. Stock follows the change: only the difference in quantity moves. Refused when:
- the part is billed;
- the part is on a purchase order and the quantity would change (change it on the PO instead).

### Remove a part - `POST /api/v1/mit_ticket_parts/remove.php`

Send `part_id` (and `client_id` when the key's login is limited to certain clients). The part must not be billed yet:
- a stock part goes back on the shelf;
- a part still waiting to order comes off To Order;
- a part already on a purchase order must come off the PO first.

This endpoint is `remove.php` rather than `delete.php` because ITFlow's API holds every `delete.php` to Full access, while the Parts
box only needs Support edit.

## Usage counts - `/api/v1/mit_usage/`

Usage billing lets a recurring invoice line take its quantity from a count: pick **Counted by an integration** under "Quantity from"
in the line's Edit Line window. NinjaOne, Bitwarden and Ingram Micro report their counts on their own (Admin > Integrations > Usage
connectors). Anything else can report a count through this API.

**Needs:** Sales (edit to create), and access to the client.

### Report a count - `POST /api/v1/mit_usage/create.php`

| Field | | |
|---|---|---|
| `client_id` | required | The client |
| `metric` | required | The usage name picked on the recurring line, e.g. `workstations` or `m365_business_premium` |
| `quantity` | required | The count, 0 or more |
| `as_of` | optional | The date counted, `YYYY-MM-DD`, not in the future (default today) |
| `note` | optional | Up to 255 characters kept with the count (the key's name is added in front) |

```bash
curl -X POST https://itflow.example.com/api/v1/mit_usage/create.php \
  -H "X-API-Key: <key>" -H "Content-Type: application/json" \
  -d '{"client_id": 12, "metric": "workstations", "quantity": 37, "note": "RMM export"}'
```

A refused count answers `400`, with a `message` saying why:
- the client is unknown;
- there is no usage name, or the name starts with `tag:` (kept for the daily tag counts);
- the quantity is below 0;
- the date is not real, or is in the future.

Every count sent is kept. When the invoice is made, the line uses the client's latest count: the newest `as_of`, then the last one sent.

### List what to report - `GET /api/v1/mit_usage/read.php`

The recurring lines billed by a count, for the clients the key can see, with the latest count of each. Each row has `client_id`,
`client_name`, `metric`, `source`, `item_name`, `recurring_invoice_id`, `latest_quantity` and `latest_as_of`.

| Parameter | |
|---|---|
| `client_id` | Only this client |
| `source` | Only lines counted this way: `usage` (ITFlow's "Counted by an integration"), or `manual` / `api` / `tag` (lines left from the old Usage billing card) |

## Our fields on ITFlow's reads

Some of ITFlow's own read endpoints carry extra fields here. Each one is named `mit_...`, so it can never clash with one of ITFlow's.
Tools that don't know them can ignore them.

| Endpoint | Added |
|---|---|
| `tickets/read` | `mit_board_id`, `mit_board_name` - the ticket's service board |
| `invoices/read` | `mit_discount_percent` and `mit_discount_product_id` (a percentage discount - ITFlow's `invoice_discount_amount` holds the dollars it comes to now); `mit_minimum_lines` (`[{item_id, minimum}]`, minimum-commit lines); `mit_quickbooks` when QuickBooks Online is connected (`approval`: none / approved / changed, `batch_entry`: the batch status, `entered_by_hand`) |
| `quotes/read`, `recurring_invoices/read` | `mit_discount_percent`, `mit_discount_product_id`, `mit_minimum_lines` |
| `contracts/read` | `mit_unlimited_hours` - true for unlimited support hours (ITFlow's `contract_monthly_hours` then holds 99999) |

## API / service accounts

Because an API key acts as an ITFlow agent, it needs a login. In Admin > Users > New / Edit, switch on **API / service account** for
that login. It then:
- keeps its role, so its keys keep working with the same rights and client limits;
- cannot sign in from a browser;
- is left out of every list of people to pick from: Whose on the calendar, Dispatch, Assign to, @mentions, boards, timesheets and the
  rest.

Such logins are listed in their own section under Admin > Users.

## Rules that differ from stock ITFlow

| Endpoint | Here |
|---|---|
| `products/read` | Leaves out what a product costs you (cost and markup). Prices are still there. |
| `invoice_items/create` | Refused (as not found) for an invoice in a QuickBooks Online batch. Change it in QuickBooks, or take it out of the batch first. |
| `clients/delete` | Refused while the client's invoices are in a QuickBooks batch, or while its ticket time is on a submitted / approved timesheet. Otherwise it also removes our quote line pictures and puts the client's unbilled stock parts back on the shelf. |
| `ticket_replies/create` | A closed ticket takes no replies, and `ticket_status` must be a working status or Resolved (not New or Closed). Taking a Resolved ticket back to work clears its resolved time. |
| `tickets/update` | A priority change works the SLA due times out again, as the ticket page does. |

## What the API never gives out

The API never gives out any of these:
- what things cost you: product cost and markup, quote line cost, parts' unit cost, agents' labor costs;
- the AI helper, its library or its conversations;
- integration secrets and settings (Microsoft 365, QuickBooks, NinjaOne and the rest);
- per-agent preferences such as look and layout.

ITFlow's own Credentials endpoint is ITFlow's, under its own permission.

**Moving back to stock ITFlow:** the `mit_*` endpoints go (callers get a 404) and so do the `mit_...` fields. The data stays in the
database, and the rules above become ITFlow's own again.
