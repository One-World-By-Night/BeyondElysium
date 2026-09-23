# AI Assist Settings (Site)

The site-wide AI provider and key: used directly for catalog-level AI Assist (Schema Block descriptions, Credits text), and as the fallback for any chronicle that turns AI Assist on without supplying a key of its own.

## Who can use this

Site admin only - the WordPress Administrator role. No chronicle role reaches this screen, including HST and AST: a chronicle's own AI Assist provider and key are set on [AI Assist Settings (Chronicle)](writing-assist-chronicle.md) instead, which any Storyteller can reach for their own chronicle.

## How to get there

wp-admin sidebar → Beyond Elysium → System Config → AI Assist tab.

## The screen

- A note that any key entered here must be a real API key from OpenAI's or Anthropic's own developer console - never a ChatGPT Plus or Claude Pro login.
- **Provider** - a dropdown: **OpenAI (ChatGPT)**, **Claude**, or **Self-Hosted (OpenAI-compatible)**. Only the selected option's own fields show below.
- **OpenAI** or **Claude**, whichever is selected, shows: **API key** (a password box, reading "•••••••• (configured - leave blank to keep)" once one exists), **Clear** once one exists, and **Test Connection**.
- **Self-Hosted (OpenAI-compatible)** shows a note about compatible servers (Ollama, LM Studio, vLLM, LocalAI, and similar), then **API base URL**, **Model**, **API key**, **Clear**, and **Test Connection**.
- **Save**.

A "Saved." line appears after a successful save. **Test Connection** reports its result inline, under whichever provider's fields are showing.

## Common tasks

### Turn on the site's AI provider

1. Pick a **Provider**.
2. Type an **API key**.
3. Click **Test Connection** to check it.
4. Click **Save**.

### Switch provider

1. Pick a different option from **Provider**.
2. Fill in that provider's own fields.
3. Click **Save**.

### Point the site at a self-hosted server

1. Set **Provider** to **Self-Hosted (OpenAI-compatible)**.
2. Fill in **API base URL** and **Model**.
3. Type the server's key, if it needs one.
4. Click **Test Connection**, then **Save**.

### Remove the stored key

1. Click **Clear** next to the key field.

## Things to know

- **Picking a Provider doesn't save it by itself.** Unlike the chronicle version of this screen, switching **Provider** here only changes what you're looking at - nothing is written until you click **Save**, which saves whichever Provider is currently selected along with any key, URL, or model changes below it.
- **A saved key is never shown again.** The field reads as "configured" once one exists; typing something new replaces it, **Clear** removes it, and testing a saved key means retyping it first - a stored key is never sent back to this page.
- **This is the fallback, not a chronicle's own setting.** Any chronicle that turns on AI Assist without its own key uses this one; a chronicle with its own key ignores this screen entirely for its own fields. See [AI Assist Settings (Chronicle)](writing-assist-chronicle.md).
- **More than the chronicle button depends on this key.** The Schema Blocks catalog description editor and the Credits text field are site-wide, not chronicle-scoped, so they always use this key, never a chronicle's own.
- **This has to be a real developer API key, billed per request** - not a ChatGPT Plus or Claude Pro login, which can't be used here at all.
- **Self-Hosted isn't a fourth provider.** It's the same OpenAI request shape, pointed at your own server's address and model instead - there's no self-hosted equivalent offered for Claude's own API shape.

## Troubleshooting

- **"Type the key above first - a saved key is never sent back to this page, so it has to be re-entered to test it."** Retype the key (or, for Self-Hosted, the URL and model) before clicking Test Connection.
- **"Could not connect with these settings."** The key, URL, or model is wrong - check them with whoever issued the key. This screen never reports a more specific reason than that.
- **I don't see this tab.** You need the site's Administrator role - an Editor, even one who manages a chronicle's own AI Assist settings, doesn't reach this screen.
- **A chronicle's AI Assist still doesn't work after I set this up.** Confirm the chronicle itself has turned AI Assist on - this screen only supplies the fallback key, it doesn't enable the feature for any chronicle. See [AI Assist Settings (Chronicle)](writing-assist-chronicle.md).
- **I picked a different Provider and it doesn't seem to have changed anything.** Click **Save** - picking a Provider here only changes what's shown on screen until you do.
- **AI Assist stopped working after the site's security keys changed.** A saved key is encrypted with the site's own WordPress secret keys, so changing them (or moving the site to a new configuration file without them) leaves it unreadable. Enter the key again and click **Save**.

## Related

- [AI Assist Settings (Chronicle)](writing-assist-chronicle.md)
- [AI Writing Assist](writing-assist.md)
- [Schema Blocks](schema-blocks.md)
- [Credits](credits.md)
- [Games](games.md)
- [Admin Guide](../admin-guide.md#configuring-it)
