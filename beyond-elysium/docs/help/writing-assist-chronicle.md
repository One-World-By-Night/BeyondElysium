# AI Assist Settings (Chronicle)

Turns on the AI Assist button for this chronicle's own long-form fields, and optionally gives the chronicle its own AI provider and key instead of the site's.

## Who can use this

Storytellers (HST and AST). A Narrator, the chronicle's Harpy, and a player never see this tab - and even a Storyteller who turns this on here only sees the AI Assist button itself on fields they can already manage. See [AI Writing Assist](writing-assist.md) for who that is, field by field.

## How to get there

wp-admin sidebar → Beyond Elysium → Chronicle Setup → AI Assist tab.

## The screen

- **Chronicle** - a dropdown of every chronicle on the install.
- A note that any key entered here must be a real API key from OpenAI's or Anthropic's own developer console - never a ChatGPT Plus or Claude Pro login - and that leaving the key blank uses the site-wide key instead.
- **Enable AI Assist for this chronicle** - a checkbox. Nothing below shows until it's checked.
- Once enabled:
  - **Provider** - a dropdown: **OpenAI (ChatGPT)**, **Claude**, or **Self-Hosted
    (OpenAI-compatible)**. Only the selected option's own fields show below, and picking one
    saves immediately.
  - **OpenAI** or **Claude** shows one field set: **This chronicle's own API key** (a
    password box, showing a "configured" placeholder once one exists), a **Clear** button
    once one exists, and **Test Connection**.
  - **Self-Hosted (OpenAI-compatible)** shows a note about compatible servers (Ollama, LM
    Studio, vLLM, LocalAI, and similar), then **API base URL**, **Model**, **This chronicle's
    own API key**, **Clear**, and **Test Connection**.
  - **Save**.
- A "Saved." line appears after a successful save. **Test Connection** reports its result inline, under whichever provider's fields are showing.

## Common tasks

### Turn AI Assist on for this chronicle

1. Check **Enable AI Assist for this chronicle**.
2. Pick a **Provider**.
3. Click **Save**.

### Give the chronicle its own key

1. Pick the **Provider** the key is for.
2. Type the key into **This chronicle's own API key**.
3. Click **Test Connection** to check it.
4. Click **Save**.

### Switch provider

1. Pick a different option from **Provider** - this saves right away.
2. Fill in that provider's own fields and click **Save**.

### Remove the chronicle's own key

1. Click **Clear** next to that provider's key field.

### Point the chronicle at a self-hosted server

1. Set **Provider** to **Self-Hosted (OpenAI-compatible)**.
2. Fill in **API base URL** and **Model**.
3. Type the server's key, if it needs one.
4. Click **Test Connection**, then **Save**.

## Things to know

- **A saved key is never shown again.** The field reads as "configured" once one exists; typing something new replaces it, **Clear** removes it, and testing a saved key means retyping it first - a stored key is never sent back to this page.
- **Leaving the key blank uses the site-wide key** - and, for Self-Hosted, the site-wide server too. The chronicle inherits whatever a site administrator has set up unless you override it here.
- **Provider saves the moment you pick it**, before you touch Save - only the key, URL, and model fields wait for Save.
- **This has to be a real developer API key, billed per request** - not a ChatGPT Plus or Claude Pro login, which can't be used here at all.
- **Self-Hosted isn't a fourth provider.** It's the same OpenAI request shape, pointed at your own server's address and model instead.
- **This only affects this chronicle's own character, plot, rumor, and world-object fields.** Catalog-wide fields, like a shared trait's description, always use the site-wide setting.

## Troubleshooting

- **"Type the key above first - a saved key is never sent back to this page, so it has to be re-entered to test it."** Type the key (or, for Self-Hosted, the URL and model) before clicking Test Connection.
- **"Could not connect with these settings."** The key, URL, or model is wrong - check them with whoever issued the key. This screen never reports a more specific reason than that.
- **I don't see this tab.** You need a Storyteller role in the chronicle currently selected.
- **I turned this on but nobody sees an AI Assist button.** The button is gated per field on the fuller management role for that content - a Narrator, Harpy, or player never sees it, even here. See [AI Writing Assist](writing-assist.md).
- **I don't see the fields I expect.** Only the selected **Provider**'s own fields show - switch it to see the others.

## Related

- [AI Writing Assist](writing-assist.md)
- [Chronicle Setup](chronicle-setup.md)
- [Action & Rumor Settings](apr-settings.md)
- [AI Assist Settings (Site)](writing-assist-site.md)
- [Admin Guide](../admin-guide.md#configuring-it)
