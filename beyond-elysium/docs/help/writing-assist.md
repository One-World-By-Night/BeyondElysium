# AI Writing Assist

A button that asks an AI to draft or polish the text in a long-form field, without leaving the field it sits next to.

## Who can use this

Storytellers (HST and AST) see it next to their own chronicle's long-form fields: a character's Background and Notes, a Storyteller-only NPC's roleplaying-notes fields, a plot's overview and cliffhanger, a plot entry, a rumor's description, and an item or location's description, limitations, and other text properties. A site admin sees the same button in a few sitewide screens too - the shared trait and power catalog's reference, description, and source notes, an approval rule's reason, a chronicle's own description on the Games screen, and the site's Credits text. A Narrator, the chronicle's Harpy, and a player never see it, even next to a field they can otherwise edit themselves - it's always gated on the fuller management capability for that content, never on plain permission to edit the field.

## How to get there

There's no page of its own - look for an **AI Assist** button next to any long-form field you can already manage.

## The screen

- **AI Assist** - the button itself, next to the field.
- Clicking it opens a modal titled **AI Assist**:
  - If the field already has text: a note that this will improve the existing text, keeping
    its meaning and details, with **Cancel** and **Polish this**.
  - If the field is empty: a **What should this be about?** box for a short line of
    direction, with **Cancel** and **Generate**.
  - While it's working, the button reads **Generating…**.
  - Once a suggestion comes back: the suggestion text, a reminder that nothing is saved yet,
    and **Back**, **Regenerate**, and **Accept**.
- **Accept** drops the suggestion into the field and closes the modal - it doesn't save anything by itself.
- **Back** returns to the instruction or polish step without closing the modal; **Regenerate** asks again from there.
- A failure shows as a plain sentence inside the modal in place of a suggestion.

## Common tasks

### Fill in an empty field

1. Click **AI Assist** next to the field.
2. Type a short line under **What should this be about?**
3. Click **Generate**.
4. Read the suggestion, then click **Accept** to drop it in - or **Regenerate** to try again, or **Back**/**Cancel** to back out.
5. Save the field the normal way - **Accept** fills it in, it doesn't save it.

### Polish something you've already written

1. Type your own draft into the field first.
2. Click **AI Assist**.
3. Click **Polish this**.
4. Review the result, then **Accept**, **Regenerate**, or **Back**.
5. Save the field as usual.

## Things to know

- **Nothing is saved by this modal itself.** Accept only fills the field - you still have to click that field's own Save or Submit.
- **Your chronicle has to turn this on first.** Even with a key configured for the whole site, a chronicle-scoped field (character, plot, rumor, item, location, approval reason) does nothing until an HST or AST enables AI Assist for that chronicle - see the [Admin Guide](../admin-guide.md#ai-writing-assist).
- **Limited to 20 suggestions a minute, per person.** Asking faster than that gets a wait-and-retry message instead of a result.
- **It never invents specific game rules.** It's told to write prose, not a ruling - treat any numbers or mechanics it writes as something to check yourself.
- **Long text is trimmed before it's sent.** A very long field is cut down before it goes out, and the short direction you type for an empty field has its own, shorter limit.
- **Which provider answers depends on what's configured** - OpenAI, Claude, or a compatible self-hosted server. A chronicle's own custom endpoint has to be a public address; a site administrator's may be a local one. This screen doesn't show you which is active.

## Troubleshooting

- **I don't see an AI Assist button at all.** Either you don't hold the fuller management capability for this field, or it's a field this tool doesn't cover.
- **"AI assist is not configured yet - ask whoever manages this chronicle (or the site) to add an API key."** Nobody's set up a key yet, or your chronicle hasn't turned the feature on - ask an HST, AST, or your site admin.
- **"Too many suggestions in the last minute - wait a moment and try again."** Wait about a minute and try again.
- **"Could not reach the AI provider. Try again in a moment." / "The AI provider returned an error. Check that the configured API key is still valid."** The configured key or connection is failing - tell whoever manages it.
- **"The AI provider returned an empty response."** Click **Regenerate**, or try a more specific instruction.
- **I clicked Accept but my change didn't stick.** You likely navigated away before saving the field itself - Accept only fills it in, saving is still a separate step.

## Related

- [Character Editor](character-editor.md)
- [Plots & Rumors](plot-manager.md)
- [Rumors](rumors.md)
- [Approval Rules](approval-rules.md)
- [AI Assist Settings (Chronicle)](writing-assist-chronicle.md)
- [AI Assist Settings (Site)](writing-assist-site.md)
- [Admin Guide](../admin-guide.md#ai-writing-assist)
