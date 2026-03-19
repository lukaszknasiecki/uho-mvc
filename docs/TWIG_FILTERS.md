# Custom Twig Filters

Extra Twig filters registered by `_uho_view` in addition to Twig's built-in filters.

---

## `base64_encode`

Encodes a string to Base64.

```twig
{{ image_data | base64_encode }}
```

---

## `brackets2tag`

Replaces `[` and `]` characters in a string with HTML tags or custom strings.

```twig
{# Wrap with an HTML tag #}
{{ text | brackets2tag('strong') }}
{# "[Hello]" → "<strong>Hello</strong>" #}

{# Use custom open/close strings #}
{{ text | brackets2tag(['<span class="hl">', '</span>']) }}
```

---

## `date_PL`

Reformats a date string from `YYYY-MM-DD` to Polish dot notation `DD.MM.YYYY`.

```twig
{{ article.date | date_PL }}   {# "2024-03-19" → "19.03.2024" #}
```

---

## `declination`

Returns the correct grammatical form of a word based on the number (Polish declension rules). Accepts either:

- **Array form** – `words` key with three forms and optional `number` key (default `true`) to prepend the number.
- **Key form** – looks up `translate.<string>_<1|2|3>` from the template context.

```twig
{{ count | declination(['komentarz','komentarze','komentarzy']) }}
{{ count | declination({'words': ['komentarz','komentarze','komentarzy'], 'number': false}) }}
{{ count | declination('comment') }}   {# uses context.translate.comment_1/2/3 #}
```

Rules: form 1 for 1; form 2 for 2–4 (except 12–14); form 3 otherwise.

---

## `double_dashes`

Replaces every em-dash character (`—`) with two HTML `&mdash;` entities (`&mdash;&mdash;`).

```twig
{{ text | double_dashes }}
```

---

## `dozeruj`

Zero-pads a number to a given length. Delegates to `_uho_fx::dozeruj($string, $params)`.

```twig
{{ 7 | dozeruj(2) }}   {# outputs "07" #}
```

---

## `duration`

Formats a duration in **seconds** as a time string.

| Parameter | Output format | Example |
|-----------|--------------|---------|
| *(none)* | `HH:MM:SS` | `01:23:45` |
| `{short: true}` | `MM:SS` | `23:45` |
| `{type: 'hours_if_needed'}` | `MM:SS` or `HH:MM:SS` (hours only when > 3600 s) | `05:30` / `01:05:30` |

```twig
{{ seconds | duration }}
{{ seconds | duration({short: true}) }}
{{ seconds | duration({type: 'hours_if_needed'}) }}
```

---

## `filesize`

Converts a file size in **bytes** to a human-readable string (`KB` or `MB`).

```twig
{{ file.size | filesize }}   {# e.g. "512KB" or "1.4MB" #}
```

---

## `nospaces`

Replaces all regular spaces with non-breaking spaces (`&nbsp;`).

```twig
{{ label | nospaces }}
```

---

## `shuffle`

Randomly shuffles an array and returns it.

```twig
{% for item in items | shuffle %}
    ...
{% endfor %}
```

---

## `szewce`

Applies Polish typographic rules (*szewce* / *wdowy*) to prevent orphaned single-letter conjunctions and short words at line endings. Replaces eligible spaces with `&nbsp;` for:

- Single-letter conjunctions (a, i, o, w, z, …)
- Selected long conjunctions and abbreviations (II, Dr., ul., Le, La, El, …)
- Units and abbreviations (r., w., km, tys., mln, godz., …)
- The last character of a string
- Numbers (non-breaking space before a number)

```twig
{{ paragraph | szewce | raw }}
```

> Note: output contains HTML entities — pipe through `raw` to prevent double-escaping.

---

## `time`

Extracts the `HH:MM` portion from a datetime string (characters 12–16, i.e. `YYYY-MM-DD HH:MM:SS`).

```twig
{{ event.datetime | time }}   {# "2024-03-19 14:30:00" → "14:30" #}
```

---

## `uho_fx_date`

Converts a date value to a localised long or short string via `_uho_fx::convertSingleDate()`. Uses the view's current language setting. The optional `format` parameter defaults to `'long'`.

```twig
{{ article.date | uho_fx_date }}
{{ article.date | uho_fx_date('short') }}
```

---

## `ucwords`

Capitalizes the first letter of each word. When an optional truthy parameter is passed **and** the current language is `pl`, only the first letter of the whole string is capitalized (`ucfirst` behaviour).

```twig
{{ title | ucwords }}
{{ title | ucwords(true) }}   {# ucfirst when lang=pl #}
```
