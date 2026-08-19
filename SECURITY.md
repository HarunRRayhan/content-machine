# Security Policy

## Supported versions

This project is pre-1.0 and pre-alpha. Only `main` is supported. There are no
tagged releases yet, and no version of this software should be considered
production-hardened.

## Reporting a vulnerability

Please don't open a public issue for a security problem. Use GitHub's private
reporting instead:

1. Go to the **Security** tab on this repository.
2. Click **Report a vulnerability**.
3. Describe the issue, how you found it, and, if you can, how to reproduce it.

That opens a private advisory only maintainers can see, so it can get fixed
before anyone else finds out how.

No bug bounty, no fixed SLA at this stage, but reports will be read and
acknowledged.

## Prompt injection

This project ingests arbitrary URLs and files through a Telegram bot and hands
some of that content to AI models. Anything that comes from a third party
(a forwarded link, a page's text, a document's contents, a transcript) is
treated strictly as data to summarize or analyze, never as instructions for
the bot or the underlying model to follow. If you find a way to make the bot
or any AI call in this project act on injected content, whether that's
running a command, changing what it saves, or changing how it behaves, that
counts as a security bug and is in scope for this policy. Report it the same
way as above.

This section is a placeholder. It will get more specific once the bot itself
ships; for now it states the standing principle so we're accountable to it
from day one.
