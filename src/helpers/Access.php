<?php

declare(strict_types=1);

namespace justinholtweb\reportr\helpers;

use Craft;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\Tag;
use craft\elements\User;
use craft\services\ElementSources;
use justinholtweb\reportr\models\Column;
use justinholtweb\reportr\models\QuerySpec;

/**
 * What someone who is not an admin may build a report over.
 *
 * A report is a way to read content out of Craft in bulk, so building one should take what reading
 * that content in the control panel takes. Before this, "Manage reports" was enough to export every
 * user's email, every order's address, or the entries of a section the author could not open — and
 * a `twig:` column was a way to run any Twig at all, against `craft.app` included.
 *
 * Admins may report on anything. Others may only choose an element type and source they can open in
 * the control panel, and may not add or change a `twig:` column. What an admin already built stays
 * as it is: someone may edit a report's title or schedule without the report being re-checked
 * against a source they could never have picked.
 *
 * Running a report is a separate permission, and runs what its builder chose.
 */
abstract class Access
{
    /**
     * Element types a non-admin may report on, and the permission each needs beyond the source
     * check. Craft filters entry, category and asset sources by the user's own permissions; the
     * others are checked here. Anything not listed — addresses, a plugin's own elements — is for
     * admins, because there is no telling what its sources hide.
     */
    private const TYPES = [
        Entry::class => null,
        Category::class => null,
        Asset::class => null,
        Tag::class => null,
        User::class => 'viewUsers',
        'craft\\commerce\\elements\\Order' => 'commerce-manageOrders',
        'craft\\commerce\\elements\\Product' => 'commerce-viewProductType:{uid}',
        'craft\\commerce\\elements\\Variant' => 'commerce-viewProductType:{uid}',
    ];

    /**
     * Element types whose "all sources" a non-admin may pick: those whose permission covers every
     * source. For the rest, "all" would reach the sections they can't see.
     */
    private const ALL_SOURCES_OK = [Tag::class, User::class, 'craft\\commerce\\elements\\Order'];

    /**
     * Why `$user` may not build a report over this element type and source, or null if they may.
     */
    public static function whyNotSource(QuerySpec $spec, \craft\elements\User $user): ?string
    {
        if ($user->admin) {
            return null;
        }

        $type = $spec->elementType;

        if (!array_key_exists($type, self::TYPES)) {
            return Craft::t('reportr', 'Only an admin can report on {type}.', ['type' => self::typeName($type)]);
        }

        $permission = self::TYPES[$type];

        if ($permission !== null && !str_contains($permission, '{uid}') && !$user->can($permission)) {
            return Craft::t('reportr', 'You can’t view {type} in the control panel, so you can’t report on them.', ['type' => self::typeName($type)]);
        }

        if ($spec->source === '' || $spec->source === '*') {
            return in_array($type, self::ALL_SOURCES_OK, true)
                ? null
                : Craft::t('reportr', 'Choose a source. “All” would include ones only an admin can see.');
        }

        // Craft builds index sources for the signed-in user, leaving out what they can't view.
        $keys = [];

        try {
            foreach (Craft::$app->getElementSources()->getSources($type, ElementSources::CONTEXT_INDEX) as $source) {
                if (isset($source['key'])) {
                    $keys[] = (string)$source['key'];
                }
            }
        } catch (\Throwable) {
        }

        if (!in_array($spec->source, $keys, true)) {
            return Craft::t('reportr', 'You can’t view that source in the control panel, so you can’t report on it.');
        }

        // Commerce does not filter product sources by permission, so the product type's own
        // permission is checked here.
        if ($permission !== null && str_contains($permission, '{uid}')) {
            $uid = preg_match('/^productType:(.+)$/', $spec->source, $m) ? $m[1] : null;

            if ($uid === null || !$user->can(str_replace('{uid}', $uid, $permission))) {
                return Craft::t('reportr', 'You can’t view that product type, so you can’t report on it.');
            }
        }

        return null;
    }

    /**
     * The `twig:` column keys in a spec — the parts of a report that run code.
     *
     * @return string[]
     */
    public static function twigColumns(QuerySpec $spec): array
    {
        $keys = [];

        foreach ($spec->columns as $column) {
            if ($column->parse()[0] === Column::PREFIX_TWIG) {
                $keys[] = $column->key;
            }
        }

        return $keys;
    }

    /**
     * Path segments that lead from content to a person: `attr:author.email`,
     * `attr:uploader.fullName`, `field:relatedEntries.authors.email`. Lower case.
     */
    private const USER_SEGMENTS = [
        'author', 'authors', 'creator', 'uploader', 'user', 'users', 'owner', 'primaryowner',
        'customer', 'editor', 'addresses', 'address', 'draftcreator', 'revisioncreator',
    ];

    /**
     * The `attr:`/`field:` column keys that reach a user — their email, name, addresses — by a
     * built-in relation or a Users/Addresses field anywhere along the path.
     *
     * Reporting on users takes permission to view them; without this, a report over a section
     * someone can see was a way to read the people attached to it. A path is checked statically,
     * when it is added, because by the time it runs on a schedule there is nobody to ask.
     *
     * @return string[]
     */
    public static function userColumns(QuerySpec $spec): array
    {
        $userFields = [];

        foreach (Craft::$app->getFields()->getAllFields() as $field) {
            if ($field instanceof \craft\fields\Users || $field instanceof \craft\fields\Addresses) {
                $userFields[] = strtolower($field->handle);
            }
        }

        $keys = [];

        foreach ($spec->columns as $column) {
            [$prefix, $path] = $column->parse();

            if ($prefix === Column::PREFIX_TWIG) {
                continue;
            }

            foreach (explode('.', strtolower($path)) as $segment) {
                if (in_array($segment, self::USER_SEGMENTS, true) || in_array($segment, $userFields, true)) {
                    $keys[] = $column->key;

                    break;
                }
            }
        }

        return $keys;
    }

    private static function typeName(string $type): string
    {
        return class_exists($type) && method_exists($type, 'pluralLowerDisplayName') ? $type::pluralLowerDisplayName() : $type;
    }
}
