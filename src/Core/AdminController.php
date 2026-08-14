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
 */
abstract class AdminController extends Controller
{
    protected readonly array $admin;

    public function __construct(Request $request, Container $container)
    {
        parent::__construct($request, $container);
        AdminAuth::requireLogin();
        $this->admin = AdminAuth::current();
    }
}
