# API - what this build changes

This build keeps ITFlow's REST API (`/api/v1/...`) as it is - tools written for stock ITFlow work unchanged. This page lists only
what is different here: one new endpoint group, a few extra ways to send the key, and some rules that keep our features' data
safe. For the API itself (endpoints, fields, roles on keys) see ITFlow's documentation at [itflow.org](https://itflow.org) and
Admin > API Keys on your own install.

## Sending the API key

Any of these work:

| Way | Example |
|---|---|
| `X-API-Key` header | `curl -H "X-API-Key: <key>" https://itflow.example.com/api/v1/clients/read.php` |
| `Authorization: Bearer` header | `curl -H "Authorization: Bearer <key>" ...` |
| `api_key` in the address (ITFlow's way) | `.../api/v1/clients/read.php?api_key=<key>` |
| `api_key` in the JSON / form body of a POST (ITFlow's way) | `{"api_key": "<key>", ...}` |

The headers are the better choice - an address ends up in web server and proxy logs, a header does not.

- **Paging:** `limit` is held between 1 and 1000 (default 50) and `offset` at 0 or more, so a bad value no longer gives an error.
- **A body that is not JSON** is read as no fields rather than failing.

## API / service accounts

An API key with a role acts as an ITFlow agent, so it needs a login. In Admin > Users > New / Edit, switch on **API / service
account** for that login: it keeps its role (so its keys keep working with the same rights and client limits), but it cannot
sign in from a browser and is left out of every list of people to pick - Whose on the calendar, Dispatch, Assign to, @mentions,
boards, timesheets and the rest. Such logins are listed in their own section under Admin > Users.

## Usage counts - `/api/v1/mit_usage/` (ours)

Usage billing: a recurring invoice line can take its quantity from a count - pick **Counted by an integration** under
"Quantity from" in the line's Edit Line window. NinjaOne, Bitwarden and Ingram Micro report their counts on their own (Admin >
Integrations > Usage connectors); anything else can report one through this API. The key's login needs **Sales** (edit for
create) and access to the client.

### Report a count - `POST /api/v1/mit_usage/create.php`

| Field | | |
|---|---|---|
| `client_id` | required | The client |
| `metric` | required | The usage name - the one picked on the recurring line, e.g. `workstations`, `m365_business_premium` |
| `quantity` | required | The count, 0 or more |
| `as_of` | optional | The date counted, `YYYY-MM-DD`, not in the future (default today) |
| `note` | optional | Up to 255 characters kept with the count (the key's name is added in front) |

```bash
curl -X POST https://itflow.example.com/api/v1/mit_usage/create.php \
  -H "X-API-Key: <key>" -H "Content-Type: application/json" \
  -d '{"client_id": 12, "metric": "workstations", "quantity": 37, "note": "RMM export"}'
```

A refused count answers `400` with `success: "False"` and a `message` saying why: unknown client, no usage name, a name starting
with `tag:` (kept for the daily tag counts), a quantity below 0, or a date that is not real or is in the future. Every count sent is
kept; the line uses the client's latest one (newest `as_of`, then the last sent) when the invoice is made.

### List what to report - `GET /api/v1/mit_usage/read.php`

The recurring lines billed by a count, for the clients the key can see, with the latest count of each:
`client_id`, `client_name`, `metric`, `source`, `item_name`, `recurring_invoice_id`, `latest_quantity`, `latest_as_of`.

| Parameter | |
|---|---|
| `client_id` | Only this client |
| `source` | Only lines counted this way: `usage` (ITFlow's "Counted by an integration"), or `manual` / `api` / `tag` (lines left from the old Usage billing card) |

## Rules that differ from stock ITFlow

| Endpoint | Here |
|---|---|
| `products/read` | Leaves out what a product costs us (cost and markup). Prices are still there. |
| `invoice_items/create` | Refused (as not found) for an invoice in a QuickBooks Online batch - change it in QuickBooks, or take it out of the batch first. |
| `clients/delete` | Refused while the client's invoices are in a QuickBooks batch, or its ticket time is on a submitted / approved timesheet. Otherwise it also removes our quote line pictures and puts the client's unbilled stock parts back on the shelf. |
| `ticket_replies/create` | A closed ticket takes no replies, and `ticket_status` must be a working status or Resolved (not New or Closed). Taking a Resolved ticket back to work clears its resolved time. |
| `tickets/update` | A priority change works the SLA due times out again, as the ticket page does. |

Moving back to stock ITFlow: `mit_usage` goes (the counts stay in the database) and the rules above become ITFlow's own again.
