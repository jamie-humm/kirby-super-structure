# Kirby Super Structure

A structure field for multi-language sites that supports `translate: false` on
its **nested fields** and gives every row a stable `_uuid`.

> **Beta.** This plugin has had limited testing. Please test it thoroughly
> with your own content flows before using it in production, and report issues.
>
> **Not recommended for existing content.** Don't convert an existing
> `structure` field that already holds content to `superstructure`. Existing rows
> have no `_uuid`, so they can't be matched across languages, and syncing is
> skipped until the default language is saved once. Use it for new fields.

## Installation

```
composer require jamie-humm/kirby-super-structure:^1.0@beta
```

Or copy this folder to `site/plugins/kirby-super-structure`.

Requires Kirby 5 and PHP 8.2+.

> This is an **additional** field type. It does not replace or modify Kirby's
> core `structure` field; blueprints using `type: structure` behave exactly as before.

## Usage

```yaml
fields:
  items:
    type: superstructure
    appearance: structure   # 'pages' (default) or 'structure'
    fields:
      title:
        type: text
      image:
        type: files
        translate: false
```

## How it works

- The default language is the single source of truth for which rows exist, their
  order, and all `translate: false` fields.
- Secondary languages can't add, remove, duplicate or reorder rows. This is
  intentional. They only own the translatable fields of existing rows.
- Synced fields are disabled in secondary languages in the Panel.
- Rows are matched across languages by `_uuid`.
- A row that is new to a language starts as a copy of the default language row; `translate: false` fields are always overwritten from the default.
- Changes to the default language are pushed to existing translations
  (`latest` and `changes` versions) via `*.update:after` hooks.

## Referencing entries by UUID

Every row has a stable `_uuid` that is identical in all languages, so it can be
stored as a reference from other fields.

**Blueprint:** a select field listing the rows and saving their UUID:

```yaml
client:
  type: select
  translate: false
  options: query
  query:
    fetch: site.clients.toStructure
    text: "{{ structureItem.company }}"
    value: "{{ structureItem._uuid }}"
```

**Template:** find the row by its UUID:

```php
<?php
$clientPool = $site->content()->clients()->toStructure();
if ($client = $clientPool->findBy('_uuid', $page->client()->value())):
    if ($image = $client->logo()->toFile()): ?>
        <img src="<?= $image->resize(400)->url() ?>" alt="<?= $client->company() ?> Logo">
    <?php endif ?>
<?php endif ?>
```

Note that the key is `_uuid`, with a leading underscore.


## Panel behavior

- Rows are styled like the pages field's list items (no table header,
  separate rounded rows). Everything is scoped to `.k-field-type-superstructure`. Because of this, it's better to limit `columns` to one or two entries. You can set `appearance: structure` to get the core look.
- In non-default languages the Panel hides add, batch edit, delete all,
  duplicate, delete and sorting controls (`index.css`). This is cosmetic;
  the server discards structural changes anyway.



## Limitations

- Existing rows without a `_uuid` are not synced until the default language is saved once.
- Only top-level nested fields are supported (not fields inside nested structures).

## License

MIT © Jamie Hunter
