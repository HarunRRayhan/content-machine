# Telegram bot

The Telegram bot is a shared capture inbox for a workspace: forward a link, a
photo, or a voice note, or just type, and it lands in that workspace's
Scratch Pad. One bot per workspace, shared by every team member who links
their own Telegram account to it.

## Connecting the bot (admin)

1. Message [@BotFather](https://t.me/BotFather) on Telegram, create a bot,
   and copy the token it gives you.
2. In the dashboard, go to **Telegram** and paste the token into **Bot
   token**, then **Connect**.

Connecting validates the token, registers the app's webhook with Telegram,
and registers the bot's command menu. Disconnecting clears the token but
keeps the webhook identity and every member's existing link, so
reconnecting later doesn't force everyone to re-link.

## Linking your own account

Connecting the bot doesn't mean it will answer you: the bot only answers
Telegram accounts that have been linked to a workspace member. Each team
member links their own account, once, from the **Telegram** page:

1. Click **Get link code**. A short-lived code appears (`/link AB12CD34`).
2. Send that exact message to the bot on Telegram.
3. The bot replies confirming who you're linked as. The dashboard also
   lists every linked member.

A code expires after 15 minutes and can only be used once. Requesting a new
one invalidates any of your own codes that haven't been used yet.

Once linked, use **Send test message** on the same page to confirm the bot
can actually reach you.

## Commands

| Command | What it does |
|---|---|
| `/start` | A welcome message and, if you're not linked yet, how to link. |
| `/help` | Lists everything below. |
| `/me` | Shows which account you're linked as. |
| `/link CODE` | Links your Telegram account using a code from the dashboard. |
| `/videos` | Your workspace's most recent videos. |
| `/posts` | Your workspace's most recent posts. |
| `/notes` | Your workspace's most recent Scratch Pad captures (text, links, photos, voice). |
| `/note <text>` | Saves a Scratch Pad text note. |

Commands work the same for every linked member, with or without an AI
provider configured.

## Capturing without a command

Anything that isn't a command is captured straight to the Scratch Pad:

- A message that's just a URL is captured as a link.
- A photo (with an optional caption) is captured as a photo.
- A voice note is captured, then transcribed in the background. The
  transcript arrives as a follow-up message once it's ready.
- Any other text is captured as a note (unless **AI chat** is on, see below).

Every message gets a real reply: capture confirmation, or an honest error
if something couldn't be captured.

## AI chat

Off by default. When a workspace has an AI provider configured (see
**AI Providers**), the **Telegram** page has an **AI chat** toggle. Turning
it on changes what a plain text message does: instead of being captured as
a note, it gets a conversational reply.

Everything else is unaffected: a link, a photo, and a voice note still
always capture, and `/note <text>` still always saves a note even with AI
chat on.

Before a plain text message is treated as free chat, it's checked against
the bot's own read-only commands: something like "show my notes" or "what
videos do I have" runs `/notes` or `/videos` and replies with exactly what
that command would have shown, no paraphrasing. This never does anything a
command couldn't already do by hand. Anything that doesn't clearly match one
of `/me`, `/videos`, `/posts`, or `/notes` falls through to a normal
conversational reply: the AI can talk, but still has no access to this
app's data beyond those specific lookups, can't take any other action, and
doesn't remember earlier messages between replies. Broader capabilities are
a separate, later, explicit addition, not something this toggle grants on
its own.

If AI chat is on but the workspace's provider fails or isn't reachable, the
bot says so, then still captures the message as a note instead of dropping
it.
