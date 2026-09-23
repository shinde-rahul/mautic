# External contact activity API

Record an external application event against an existing contact:

```http
POST /api/contacts/{id}/activity/new
Content-Type: application/json

{
  "type": "purchase",
  "data": {
    "product": "Aivie Pro",
    "order_id": "order-123",
    "quantity": 1
  }
}
```

Enable the Mautic API and authenticate using a supported API authentication
method. The authenticated user needs permission to edit the target contact
(`editown` or `editother`, according to contact ownership).

* `id`: the existing contact's ID in the URL. This endpoint does not identify or
  create contacts.
* `type`: required, 1–64 ASCII letters, numbers, dots, underscores or hyphens,
  starting with a letter or number. Event names are case-sensitive.
* `data`: required object of named properties. Nested JSON values are supported.
  An empty object or empty array is accepted for events without details.

A successful request returns HTTP 201:

```json
{
  "activity": {
    "id": 42,
    "contactId": 123,
    "type": "purchase",
    "data": {
      "product": "Aivie Pro",
      "order_id": "order-123",
      "quantity": 1
    },
    "timestamp": "2026-09-23T10:00:00+00:00"
  }
}
```

The timestamp is assigned by Mautic when the event is received. Invalid fields
return HTTP 400, insufficient contact permissions return HTTP 403, and a missing
contact returns HTTP 404. Every successful request creates a new event; clients
must account for possible duplicates when retrying requests.

Events appear in **Contacts → select the contact → History**, under the
**External event** type. The supplied event name is the row label; expanding the
row displays escaped JSON details.

The existing `GET /api/contacts/{id}/activity` and `GET /api/contacts/activity`
endpoints include these entries with `event: "lead.external"` and
`details: {"type": "purchase", "data": {...}}`. Use
`filters[includeEvents][]=lead.external` to select external events, or
`filters[excludeEvents][]=lead.external` to exclude them. Existing search and
pagination parameters apply. Custom names share this single timeline category.

These entries record history only. Names such as `pageview`, `email.sent`, or
`created_contact` do not execute native tracking, email, or contact operations.
This feature does not add segment filters, campaign decisions/triggers, bulk
submission, event updates/deletion, backdated timestamps, or a sending integration
for an external store. It reuses the contact event log and requires no schema
migration.

## Manual validation

1. Enable the API in **Settings → Configuration → API Settings**. Use an API user
   with contact edit/view permissions and an existing test contact.
2. Clear cache with `ddev exec php bin/console cache:clear` if needed.
3. Submit the example JSON to the endpoint using an authenticated API client.
   Expect HTTP 201 and the matching contact ID, event name, details, and timestamp.
4. Open that contact's History. Select **External event** and expand the entry.
   Confirm the purchase and product details are visible.
5. GET the contact activity endpoint with the external-event filter. Confirm
   the new entry appears. Search for `Aivie Pro` and check pagination after
   creating several entries. Confirm another contact does not show this entry.
6. Submit `"type": "<script>"`, omit `data`, and supply a nonexistent contact ID.
   Expect HTTP 400, HTTP 400, and HTTP 404 respectively, with no new events.
7. Submit an event with HTML in a data value. Confirm it displays as text and
   does not execute. Confirm nested values, Unicode, booleans and numbers display.
8. Repeat with a view-only user and with an edit-own user targeting someone else's
   contact. Expect HTTP 403. Confirm an edit-own user can add to their own contact.
9. Confirm existing native timeline entries still appear, and that a custom event
   named `pageview` creates no native page hit or campaign action.

Automated coverage:

```bash
ddev exec php bin/phpunit -c app/phpunit.xml.dist app/bundles/LeadBundle/Tests/Controller/Api/ExternalActivityFunctionalTest.php
```
