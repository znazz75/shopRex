<?php

namespace ShopRex\Core;

use ShopRex\Core\Auth\AdminAuth;

/**
 * Base for every admin controller. Per-route capability checks already
 * happen in Router::dispatch() (via Route::capability()) before a
 * controller is even instantiated, but requireLogin() runs again here too
 * as defense-in-depth - matches the old requireAdminLogin()+
 * requireAdminPermission() double-checking nothing, since the intent was
 * always "every admin page independently refuses to run for a logged-out
 * visitor", not "trust the router".
 *
 * In plain terms: every admin controller (ProductAdminController,
 * OrderAdminController, ...) extends this instead of the plain Controller
 * base, which automatically gets it two things every admin page needs: a
 * guaranteed "you must be logged in" check, and easy access to who's
 * logged in via $this->admin.
 */
abstract class AdminController extends Controller
{
    /** The logged-in admin's DB row (id, username, role, ...) - guaranteed non-null by the time a subclass's methods run, since the constructor below calls requireLogin() first. */
    protected readonly array $admin;

    public function __construct(Request $request, Container $container)
    {
        parent::__construct($request, $container);
        // Belt-and-braces check: even though the Router should already have
        // blocked unauthenticated requests before this controller was ever
        // instantiated, this repeats the check so an admin controller is
        // never reachable while logged out, even if a route was registered
        // without a ->capability() call by mistake.
        AdminAuth::requireLogin();
        $this->admin = AdminAuth::current();
    }
}
