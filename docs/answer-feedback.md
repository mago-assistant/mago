# Answer feedback

Under every answer in the chat panel sit a thumbs up and a thumbs down. An administrator who gets a
wrong, odd or unhelpful answer gives it a thumbs down; one that got exactly what they needed gives
it a thumbs up. Either way the turn is kept, and *Mago Assistant → Answer Feedback* is where those
turns are read back and exported as debug material for an issue.

Internally a piece of feedback is still called a flag (`mago_flag`, `FlagRepository`, the
`mago/flags/*` routes): a thumbs down is exactly what a flag used to be, and a thumbs up is the same
row with `rating = up`. Flags made before the rating existed read as a thumbs down.

## What a flag is

A flag is a copy, not a pointer. At the moment it is made, the turn is serialised into the flag row:

| In the snapshot | Where it comes from |
| --- | --- |
| The flagged answer, with its tool calls | `mago_message` |
| The six messages before it | `mago_message` |
| Provider, model, tokens and skills used, over every provider call of the turn | `mago_usage_log` |
| The request and response payloads of each call | `mago_usage_log`, **only when debug logging was on** |
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

### Which usage rows belong to the turn

`mago_usage_log` rows carry a conversation and a timestamp, not a message. A turn runs from the
message that started it (the question, or the answer that was waiting for a confirmation) to the
flagged answer, and every usage row in that window is copied. A tool-using turn logs one row per
provider call, so tokens are summed and the skills of all calls are merged. A slash command makes no
provider call and gets no usage at all, rather than the previous turn's.

### Privacy

Message text is stored tokenised (see privacy mode) and the snapshot keeps it that way, so the JSON
bundle carries tokens, not the values behind them. The Answer Feedback screen rehydrates them through
the conversation's vault while the conversation exists; after that they read as `[earlier record]`.

### Retention

A flag outlives deleting its conversation by hand, but not the history retention window
(`mago/privacy/history_retention_days`). The window is counted from when the flag was made, so
flagging an answer in an old conversation still keeps it for the full window. With retention off,
flags are kept until someone deletes them.

### Size

With debug logging on, every provider call of a turn brings its own payloads, and a long tool-heavy
turn can outgrow one database row. `SnapshotSizeLimit` then leaves payloads out, oldest call first,
so the call that produced the answer is kept longest, and the flag says it was trimmed.

## In the panel

The thumbs sit under an assistant answer and appear on hover or keyboard focus; once the answer is
rated they stay visible and the chosen thumb takes the accent colour. Each is a toggle button with
`aria-pressed`, inside a group labelled *Rate this answer*.

- A click saves the rating straight away. A card then asks for an optional note: what was wrong
  (with the usual reasons as chips) for a thumbs down, what was helpful for a thumbs up. Sending it
  adds the note to the same row; leaving it empty or pressing Escape keeps the rating without one.
- Clicking the other thumb changes the rating of the same row and drops the note, which was about
  the other thumb; the snapshot is not taken again.
- Clicking the chosen thumb again removes the feedback.

Changing or removing feedback is only for the admin who gave it, and only while it is still open.
After someone resolved it, the panel shows the rating that was kept, and removing it is a delete
under Answer Feedback.

The message id it needs is already there: the `done` SSE event carries `message_id` for every
answer, and `Chat\Load` gives the messages of a reloaded conversation the `rating` they already carry.
`Chat\Flag` checks ownership through `getMessageForUser()`, so a flag cannot become a way to read
someone else's conversation.

## In the admin

*Mago Assistant → Answer Feedback* lists them with status, rating, a preview of the answer, the
note, who gave it and which model answered. Filter on *Rating* to see only the thumbs down. Opening one shows the answer, the messages leading to it, and
the payloads when they were captured — and says so plainly when they were not.

**Download JSON** gives the whole snapshot as a file to attach to an issue. It is sent from memory,
so no copy is left in `var/`.

> The bundle is the conversation as it was stored, so it can contain store and customer data. The
> screen says so above the button; read it before attaching it to a public issue.

## ACL

| Resource | Allows |
| --- | --- |
| `MagoAssistant_Mago::flags` | See the screen, open a flag, resolve or reopen it |
| `MagoAssistant_Mago::flags_export` | Download the JSON bundle |
| `MagoAssistant_Mago::flags_delete` | Delete flags |

A flag is a copy of another admin's conversation, so every Answer Feedback action also needs
`MagoAssistant_Mago::conversations`, the resource the Conversations screen is behind.

Rating itself needs `MagoAssistant_Mago::assistant_read` — anyone who can use the panel can rate
what it answers.
