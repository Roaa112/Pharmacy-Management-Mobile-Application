# Notifications and contact messages

Code: `app/Notifications` (`HealthReminder`, `ContactMessageReplied`, `Push/PushChannel`,
`Push/FcmPushGateway`, `Push/LogPushGateway`), `app/Modules/HealthService/DeviceTokenController`,
`app/Modules/ContactMessage`, `app/Jobs/DeliverContactReply`, `app/Mail`.
Dashboard: **Operations › Contact messages**, **Health data › Reminders sent**.

## 1. Notifications

### What exists

| Channel | Used for |
|---|---|
| Push (FCM or log) + database notification | Health reminders (vaccines, medication doses); pharmacy replies to contact messages |
| E-mail | Verification / reset codes; "your password was changed" notice |

**There are no push or e-mail notifications for order, payment, shipment or refund events.**
Customers see those by polling the order endpoints.

### Devices

The app registers its push token with `POST /api/user/devices` (`token`, `platform`
`android|ios|web`, `locale` `ar|en`); a token is stored hashed for lookup (`token_hash` unique) and
moves to the latest account that registers it. `DELETE /api/user/devices` removes it (e.g. on
logout). Tokens Firebase reports as unregistered are deleted automatically.

### History

`GET /api/user/notifications` lists the customer's database notifications (paginated). There is no
read/unread endpoint.

### Configuration

`PUSH_DRIVER=log` (default: pushes are only logged) or `fcm` with `FCM_CREDENTIALS` (path to a
Firebase service-account JSON). **FCM is implemented but not verified against a real Firebase
project.**

## 2. Contact messages

A conversation between a customer and the pharmacy.

```mermaid
sequenceDiagram
  participant App
  participant API
  participant Staff as Dashboard
  App->>API: POST /api/user/contact-messages (name, email, message)
  Note over API: status = open
  Staff->>API: reply (contact_messages.reply)
  Note over API: status = replied; DeliverContactReply after response
  API-->>App: database notification + push
  App->>API: POST /api/user/contact-messages/{id}/replies
  Note over API: status = open again
```

| Actor | Capability | Rules |
|---|---|---|
| Customer | Create a message (`name`, optional `email`, `message` ≤ 5000); list own conversations (newest activity first); view one; reply (≤ 5000, 10/min) | Own conversations only; staff names are never shown (`from: pharmacy`) |
| Admin | List with filter `open|replied` and open count (sidebar badge); reply (≤ 2000, 30/min); **resend** a reply whose delivery failed; delete a conversation | `contact_messages.view`, `.reply`, `.delete` |

Delivery of a pharmacy reply: `DeliverContactReply` runs after the HTTP response (no queue worker
needed), claims the reply once (`sent_at`), writes the database notification (in the language of the
customer's device) and pushes to every device; the number of devices reached or the error is stored
on the reply and shown in the dashboard. "Resend" clears the claim. Audited:
`contact_message.replied`, `contact_message.reply_resent`.

## 3. Data

`device_tokens`, `notifications`, `notification_deliveries`, `contact_messages`,
`contact_message_replies`.

## 4. Tests

`Admin/ContactMessageRepliesTest`, `Health/ReminderDeliveryTest`, `HealthRemindersTest`.
