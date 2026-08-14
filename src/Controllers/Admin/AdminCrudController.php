<?php

namespace ShopRex\Controllers\Admin;

use ShopRex\Core\AdminController;

/**
 * Base for the admin pages that fit the "list + edit form + delete, single
 * entity" shape (Categories/Menus/Pages/Shipping/Tax Rates/Admins - see the
 * architecture plan's classification of which admin pages are near-
 * boilerplate CRUD vs genuinely bespoke). Deliberately thin: each entity's
 * validation, delete-guard invariants, and child-collection handling
 * (shipping's weight tiers, categories' re-parenting) differ enough that a
 * fully generic list()/create()/update()/delete() would either be too
 * rigid or hide real logic behind indirection - matching Core\Model's own
 * "deliberately conservative, no generic query builder" stance. What's
 * actually shared (capability-gated construction, CSRF/flash/redirect
 * helpers) already lives on AdminController/Controller; concrete
 * controllers below just follow a consistent index()+save() shape by
 * convention, one GET+POST route pair per entity - same as the original
 * single-file list+form pages they replace.
 */
abstract class AdminCrudController extends AdminController
{
}
