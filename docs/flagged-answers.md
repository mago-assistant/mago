# Flagged answers

An administrator who gets a wrong, odd or unhelpful answer can flag it from the chat panel. The flag
keeps the turn, and *Mago Assistant → Flagged Answers* is where those turns are read back and
exported as debug material for an issue.

## What a flag is

A flag is a copy, not a pointer. At the moment it is made, the turn is serialised into the flag row:

| In the snapshot | Where it comes from |
| --- | --- |
| The flagged answer, with its tool calls | `mago_message` |
| The six messages before it | `mago_message` |
| Provider, model, tokens and skills used | `mago_usage_log` |
| The request and response payloads | `mago_usage_log`, **only when debug logging was on** |
| Module version, Magento version, PHP version | runtime |

### Why a copy

Two things would otherwise empty a flag exactly when someone opens it:

- `UsageLogger` writes `request_payload` and `response_payload` only while
  *Stores → Configuration → Mago Assistant → Debug* is on. On a store where it is off, there is
  nothing to point at.
- `UsageLogCleaner` nulls those columns again after `mago/debug/payload_retention_days` (30 by
  default), and deleting a conversation cascades its messages away.

So `mago_flag.snapshot` holds its own copy, and `message_id` / `conversation_id` are foreign keys
with `ON DELETE SET NULL`: delete the conversation and the flag still reads. The integration test
`FlagRepositoryTest::itKeepsTheSnapshotWhenTheConversationIsDeleted` pins that.

A flag is never refreshed. It is what the answer looked like when someone thought it was wrong.

## In the panel

The flag button sits under an assistant answer and appears on hover; once the answer is flagged it
stays visible and takes the accent colour. Clicking it again removes the flag.

The message id it needs is already there: the `done` SSE event carries `message_id` for every
answer, and `Chat\Load` marks the messages of a reloaded conversation that already carry a flag.
`Chat\Flag` checks ownership through `getMessageForUser()`, so a flag cannot become a way to read
someone else's conversation.

## In the admin

*Mago Assistant → Flagged Answers* lists them with status, a preview of the answer, the note, who
flagged it and which model answered. Opening one shows the answer, the messages leading to it, and
the payloads when they were captured — and says so plainly when they were not.

**Download JSON** gives the whole snapshot as a file to attach to an issue.

> The bundle is the conversation as it was stored, so it can contain store and customer data. The
> screen says so above the button; read it before attaching it to a public issue.

## ACL

| Resource | Allows |
| --- | --- |
| `MagoAssistant_Mago::flags` | See the screen, open a flag, resolve or reopen it |
| `MagoAssistant_Mago::flags_export` | Download the JSON bundle |
| `MagoAssistant_Mago::flags_delete` | Delete flags |

Flagging itself needs `MagoAssistant_Mago::assistant_read` — anyone who can use the panel can flag
what it answers.
