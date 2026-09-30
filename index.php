<?php

namespace JamieHumm\SuperStructure;

use Kirby\Cms\App;
use Kirby\Cms\ModelWithContent;
use Kirby\Data\Data;
use Kirby\Form\Form;

/**
 * Structure field that supports `translate: false` on its nested fields.
 *
 * Model:
 * - the default language is the single source of truth for row existence,
 *   row order and all fields marked `translate: false`
 * - secondary languages only own the translatable fields of existing rows
 * - rows are matched across languages by a stable `_uuid`
 *
 * Alignment happens in two places:
 * 1. save(): a secondary language is aligned against the default language
 *    before it is stored (works for `changes` versions too)
 * 2. *.update:after hooks: a change to the default language is pushed to
 *    every existing secondary translation (latest + changes versions)
 */
final class SuperStructure
{
    /**
     * Names of nested fields flagged with `translate: false`
     */
    public static function syncedNames(array $fields): array
    {
        $names = [];

        foreach ($fields as $name => $field) {
            if (($field['translate'] ?? true) === false) {
                $names[] = strtolower($name);
            }
        }

        return $names;
    }

    /**
     * Strips `translate` so Kirby's form doesn't handle it natively,
     * and locks synced fields outside the default language.
     */
    public static function normalizeFields(array $fields): array
    {
        $kirby = App::instance();
        $lock  = $kirby->multilang() && $kirby->language()->isDefault() === false;

        foreach ($fields as $name => $field) {
            if (($field['translate'] ?? true) === false) {
                unset($field['translate']);

                if ($lock) {
                    $field['disabled'] = true;
                }
            }

            $fields[$name] = $field;
        }

        return $fields;
    }

    public static function newUuid(): string
    {
        return bin2hex(random_bytes(8));
    }

    /**
     * Rows of the default language (latest version), or null if unusable
     * (missing translation or legacy rows without a `_uuid`).
     */
    public static function sourceRows(ModelWithContent $model, string $name): array|null
    {
        $code    = App::instance()->defaultLanguage()->code();
        $version = $model->version('latest');

        if ($version->exists($code) === false) {
            return null;
        }

        $rows = $version->content($code)->get($name)->yaml();

        $seen = [];

        foreach ($rows as $row) {
            $uuid = $row['_uuid'] ?? null;

            // legacy rows or duplicated uuids can't be matched reliably,
            // so don't sync at all rather than mix rows up
            if (empty($uuid) || isset($seen[$uuid])) {
                return null;
            }

            $seen[$uuid] = true;
        }

        return $rows;
    }

    /**
     * Rebuilds $rows so that they mirror $source (existence, order, synced
     * fields), keeping the translatable values of rows that already exist.
     */
    public static function apply(array $rows, array $source, array $synced): array
    {
        $existing = [];

        foreach ($rows as $row) {
            if (isset($row['_uuid'])) {
                $existing[$row['_uuid']] = $row;
            }
        }

        $result = [];

        foreach ($source as $src) {
            $uuid = $src['_uuid'];
            $row  = $existing[$uuid] ?? $src;

            foreach ($synced as $name) {
                $row[$name] = $src[$name] ?? null;
            }

            $result[] = $row;
        }

        return $result;
    }

    /**
     * Used by the field's save(): only touches secondary languages
     */
    public static function align(array $rows, ModelWithContent $model, string $name, array $fields): array
    {
        $kirby  = App::instance();
        $synced = static::syncedNames($fields);

        if (
            $synced === [] ||
            $kirby->multilang() === false ||
            $kirby->language()->isDefault()
        ) {
            return $rows;
        }

        $source = static::sourceRows($model, $name);

        if ($source === null) {
            return $rows;
        }

        return static::apply($rows, $source, $synced);
    }

    /**
     * Used by the update hooks: push default language -> all other languages
     */
    public static function propagate(ModelWithContent $model): void
    {
        $kirby = App::instance();

        if ($kirby->multilang() === false) {
            return;
        }

        foreach ($model->blueprint()->fields() as $name => $props) {
            if (($props['type'] ?? null) !== 'superstructure') {
                continue;
            }

            $synced = static::syncedNames($props['fields'] ?? []);

            if ($synced === []) {
                continue;
            }

            $source = static::sourceRows($model, $name);

            if ($source === null) {
                continue;
            }

            foreach ($kirby->languages() as $language) {
                if ($language->isDefault()) {
                    continue;
                }

                $code = $language->code();

                // each version is read and written separately so unsaved
                // translations in `changes` are never overwritten by `latest`
                foreach (['latest', 'changes'] as $versionId) {
                    $version = $model->version($versionId);

                    if ($version->exists($code) === false) {
                        continue;
                    }

                    $rows    = $version->content($code)->get($name)->yaml();
                    $aligned = static::apply($rows, $source, $synced);

                    if ($aligned === $rows) {
                        continue;
                    }

                    // Version::update() doesn't trigger model hooks,
                    // so this can't loop
                    $version->update(
                        [$name => Data::encode($aligned, 'yaml')],
                        $code
                    );
                }
            }
        }
    }
}

App::plugin('jamie-humm/kirby-super-structure', [
    'fields' => [
        'superstructure' => [
            'extends' => 'structure',
            'methods' => [
                'form' => function () {
                    $this->form ??= new Form(
                        fields: SuperStructure::normalizeFields($this->attrs['fields'] ?? []),
                        model: $this->model(),
                        language: 'current'
                    );

                    return $this->form->reset();
                },
            ],
            'save' => function ($value) {
                $form     = $this->form();
                $defaults = $form->defaults();
                $rows     = [];
                $seen     = [];

                foreach ($value as $row) {
                    $row = $form
                        ->reset()
                        ->fill(input: $defaults)
                        ->submit(input: $row, passthrough: true)
                        ->toStoredValues();

                    // remove frontend helper id
                    unset($row['_id']);

                    // the Panel's "duplicate" action clones hidden keys too,
                    // so make sure every uuid is present and unique
                    if (empty($row['_uuid']) || isset($seen[$row['_uuid']])) {
                        $row['_uuid'] = SuperStructure::newUuid();
                    }

                    $seen[$row['_uuid']] = true;

                    $rows[] = $row;
                }

                return SuperStructure::align(
                    $rows,
                    $this->model(),
                    $this->name(),
                    $this->attrs['fields'] ?? []
                );
            },
            'props' => [
                /**
                 * Visual style of the rows: 'pages' (default) or 'structure' (core look)
                 */
                'appearance' => function (string $appearance = 'pages') {
                    return $appearance === 'structure' ? 'structure' : 'pages';
                },
            ],
        ],
    ],
    'hooks' => [
        'page.update:after' => fn ($newPage) => SuperStructure::propagate($newPage),
        'site.update:after' => fn ($newSite) => SuperStructure::propagate($newSite),
        'file.update:after' => fn ($newFile) => SuperStructure::propagate($newFile),
        'user.update:after' => fn ($newUser) => SuperStructure::propagate($newUser),
    ],
]);
