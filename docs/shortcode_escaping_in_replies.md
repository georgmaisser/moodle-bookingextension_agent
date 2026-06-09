# Shortcode escaping in agent replies

When the agent answers a question by reproducing **shortcodes** — anything in square
brackets such as `[bookingoptions ...]` or a closing `[/bookingoptions]` — it must
escape the opening bracket so the shortcode is shown **literally** instead of being
executed. This matters most when the user asks about the *Shortcodes* documentation
chapter, where the literal tags are the whole point of the answer.

## The problem

The chat message the agent writes back is rendered through Moodle's `format_text()`.
If the `filter_shortcodes` filter is enabled, it scans the message for a literal `[`,
matches a registered tag name, and replaces the tag with its **rendered output**.

So a reply that contains the raw text `[bookingoptions ...]` does not show the user the
shortcode they asked about — it runs it and shows a list of booking options instead.

There is **no backslash-escape** in `filter_shortcodes` (see
`filter/shortcodes/lib/helpers.php`): the parser only ever looks for the character `[`.
The reliable fix is therefore to make sure that character is never emitted as a literal
`[` when we mean it literally.

## The rule

Escape the **opening** bracket as the HTML entity `&#91;`:

| You mean to show          | Write in the reply         | Renders as                 |
| ------------------------- | -------------------------- | -------------------------- |
| `[bookingoptions id=1]`   | `&#91;bookingoptions id=1]` | `[bookingoptions id=1]`    |
| `[/bookingoptions]`       | `&#91;/bookingoptions]`     | `[/bookingoptions]`        |

Only the opening `[` needs escaping. The shortcodes parser keys off `[`, so a lone
closing `]` is harmless and can stay as-is.

### Do not escape Markdown links

`format_text` processing aside, the reply is still Markdown. A Markdown link uses the
same square brackets — `[label](url)` — and must **not** be escaped, or the link breaks.
The rule applies only to shortcode-shaped tokens (`[name ...]` / `[/name]`), never to
`[text](url)`.

## Where this lives

This behavior is steered entirely from the skill, not from any core renderer or global
`format_text` configuration. The instruction is a guidance line in the
`core.explain_docs` skill:

`mod/booking/bookingextension/agent/classes/local/wbagent/core/skills/explain_docs_skill.php`
→ `get_contextual_prompt_packs()`

Keeping it skill-local means only the documentation-explaining flow carries the
escaping instruction; other skills and the rest of the engine are untouched.

## Note on the doc preview

The inline **doc preview** (`doc_markdown_preview_renderer` →
`ai_get_doc_content::markdown_to_html`) is unaffected: it is a custom Markdown renderer
that `htmlspecialchars`-escapes all content and never calls `format_text`, so shortcodes
can never fire there. The escaping rule above is purely about the **chat reply text**.
