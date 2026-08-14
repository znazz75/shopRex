<?php

/**
 * Storefront route table. Registering a new page is one line here - see
 * Core\Router's docblock. Clean URLs only - no legacy .php-shaped aliases
 * (dropped in the v2.00 cutover; the old procedural pages themselves are
 * deleted, and every internal link generator - theme templates, emails,
 * redirects - has been updated to point at these paths directly, so
 * nothing in the app still needs the old shape).
 */

use ShopRex\Controllers\Storefront\AccountController;
use ShopRex\Controllers\Storefront\AuthController;
use ShopRex\Controllers\Storefront\CartController;
use ShopRex\Controllers\Storefront\CatalogController;
use ShopRex\Controllers\Storefront\CheckoutController;
use ShopRex\Controllers\Storefront\ContactController;
use ShopRex\Controllers\Storefront\LegalDocumentController;
use ShopRex\Controllers\Storefront\OrderController;
use ShopRex\Controllers\Storefront\PageController;
use ShopRex\Controllers\Storefront\ProductController;
use ShopRex\Controllers\Storefront\RmaController;
use ShopRex\Controllers\Storefront\SearchController;
use ShopRex\Controllers\Storefront\WithdrawalController;
use ShopRex\Core\Router;
use ShopRex\Core\Container;

return function (Router $router, Container $container): void {
    $router->get('/', [CatalogController::class, 'home']);
    $router->get('/category/{slug}', [CatalogController::class, 'category']);

    $router->get('/product/{slug}', [ProductController::class, 'show']);

    $router->get('/search', [SearchController::class, 'index']);

    $router->get('/page/{slug}', [PageController::class, 'show']);

    $router->get('/cart', [CartController::class, 'index']);
    // Single POST endpoint, action=add|update|remove.
    $router->post('/cart/add', [CartController::class, 'handleAction']);
    $router->post('/cart/update', [CartController::class, 'handleAction']);
    $router->post('/cart/remove', [CartController::class, 'handleAction']);

    $router->get('/checkout', [CheckoutController::class, 'index']);
    $router->post('/checkout', [CheckoutController::class, 'process']);
    // PayPal/CreditCard gateways' own return_url/success_url point here.
    $router->get('/checkout/capture', [CheckoutController::class, 'capture']);

    $router->get('/order/{orderNumber}', [OrderController::class, 'confirmation']);
    $router->get('/order/{orderNumber}/invoice', [OrderController::class, 'downloadInvoice']);

    $router->get('/login', [AuthController::class, 'showLogin']);
    $router->post('/login', [AuthController::class, 'login']);
    $router->get('/register', [AuthController::class, 'showRegister']);
    $router->post('/register', [AuthController::class, 'register']);
    $router->get('/logout', [AuthController::class, 'logout']);
    $router->get('/forgot-password', [AuthController::class, 'showForgotPassword']);
    $router->post('/forgot-password', [AuthController::class, 'forgotPassword']);
    $router->get('/reset-password', [AuthController::class, 'showResetPassword']);
    $router->post('/reset-password', [AuthController::class, 'resetPassword']);

    $router->get('/account', [AccountController::class, 'index']);
    $router->get('/account/export', [AccountController::class, 'export']);
    $router->get('/account/delete', [AccountController::class, 'confirmDelete']);
    $router->post('/account/delete', [AccountController::class, 'delete']);

    $router->get('/contact', [ContactController::class, 'show']);
    $router->post('/contact', [ContactController::class, 'submit']);

    $router->get('/account/orders/{orderNumber}/withdrawal', [WithdrawalController::class, 'show']);
    $router->post('/account/orders/{orderNumber}/withdrawal', [WithdrawalController::class, 'submit']);
    $router->get('/account/orders/{orderNumber}/rma', [RmaController::class, 'show']);
    $router->post('/account/orders/{orderNumber}/rma', [RmaController::class, 'submit']);

    $router->get('/legal/{type}', [LegalDocumentController::class, 'download']);
};
