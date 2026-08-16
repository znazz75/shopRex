<?php

/**
 * Admin route table - see src/routes/web.php's docblock for the general
 * pattern. Clean URLs only - no legacy .php-shaped aliases (dropped in
 * the v2.00 cutover along with the physical admin/*.php files; the
 * sidebar nav in src/Views/admin/layout/header.php only ever generates
 * these clean paths).
 *
 * In plain terms: this is the same idea as src/routes/web.php, but for
 * every /admin/... URL. The big difference from the storefront table is
 * that almost every route here is chained with ->capability('...') - see
 * Core\Auth\AdminAuth::CAPABILITIES for what each capability string
 * actually allows, and Core\Router::dispatch() for where that check
 * actually happens (before the controller is even built).
 */

use ShopRex\Controllers\Admin\AdminAuthController;
use ShopRex\Controllers\Admin\AdminUserAdminController;
use ShopRex\Controllers\Admin\CategoryAdminController;
use ShopRex\Controllers\Admin\ContactAdminController;
use ShopRex\Controllers\Admin\CustomerAdminController;
use ShopRex\Controllers\Admin\DashboardController;
use ShopRex\Controllers\Admin\EmailTemplateAdminController;
use ShopRex\Controllers\Admin\FinanceAdminController;
use ShopRex\Controllers\Admin\ImageCropController;
use ShopRex\Controllers\Admin\InventoryAdminController;
use ShopRex\Controllers\Admin\LegalDocumentAdminController;
use ShopRex\Controllers\Admin\MenuAdminController;
use ShopRex\Controllers\Admin\NumberingAdminController;
use ShopRex\Controllers\Admin\OrderAdminController;
use ShopRex\Controllers\Admin\PageAdminController;
use ShopRex\Controllers\Admin\ProductAdminController;
use ShopRex\Controllers\Admin\ProductEditController;
use ShopRex\Controllers\Admin\ProductImageController;
use ShopRex\Controllers\Admin\RmaAdminController;
use ShopRex\Controllers\Admin\SettingsAdminController;
use ShopRex\Controllers\Admin\ShippingAdminController;
use ShopRex\Controllers\Admin\TaxRateAdminController;
use ShopRex\Controllers\Admin\WithdrawalAdminController;
use ShopRex\Core\Router;
use ShopRex\Core\Container;

// Same "returns a closure, called by admin/index.php with its own
// $router/$container" pattern as src/routes/web.php - see that file's
// comment on the signature for why $container is accepted even though it's
// unused by any route registered below.
return function (Router $router, Container $container): void {
    // Not capability-gated (there's no admin session yet to check).
    // Registered first since a broken login route would take the whole
    // admin panel down with it.
    $router->get('/admin/login', [AdminAuthController::class, 'showLogin']);
    $router->post('/admin/login', [AdminAuthController::class, 'login']);
    $router->get('/admin/logout', [AdminAuthController::class, 'logout']);

    $router->get('/admin/categories', [CategoryAdminController::class, 'index'])->capability('categories');
    $router->post('/admin/categories', [CategoryAdminController::class, 'save'])->capability('categories');

    $router->get('/admin/tax-rates', [TaxRateAdminController::class, 'index'])->capability('settings');
    $router->post('/admin/tax-rates', [TaxRateAdminController::class, 'save'])->capability('settings');

    $router->get('/admin/numbering', [NumberingAdminController::class, 'index'])->capability('settings');
    $router->post('/admin/numbering', [NumberingAdminController::class, 'save'])->capability('settings');

    $router->get('/admin/admins', [AdminUserAdminController::class, 'index'])->capability('admins');
    $router->post('/admin/admins', [AdminUserAdminController::class, 'save'])->capability('admins');

    $router->get('/admin/shipping', [ShippingAdminController::class, 'index'])->capability('shipping');
    $router->post('/admin/shipping', [ShippingAdminController::class, 'save'])->capability('shipping');

    $router->get('/admin/menus', [MenuAdminController::class, 'index'])->capability('menus');
    $router->post('/admin/menus', [MenuAdminController::class, 'save'])->capability('menus');
    // Not capability-gated at the route level - see MenuAdminController::reorder()'s
    // docblock (it does its own inline check, matching the original AJAX
    // endpoint's JSON-403 behavior instead of the router's redirect-based denial).
    $router->post('/admin/menus/reorder', [MenuAdminController::class, 'reorder']);

    $router->get('/admin/pages', [PageAdminController::class, 'index'])->capability('pages');
    $router->post('/admin/pages', [PageAdminController::class, 'save'])->capability('pages');

    $router->get('/admin', [DashboardController::class, 'index'])->capability('dashboard');

    $router->get('/admin/finance', [FinanceAdminController::class, 'index'])->capability('finance');
    $router->get('/admin/finance/annual-report', [FinanceAdminController::class, 'annualReport'])->capability('finance');

    $router->get('/admin/orders', [OrderAdminController::class, 'index'])->capability('orders');
    // /create must be registered before the {id} routes below - routes
    // match in registration order (Core\Router's docblock), and {id}
    // matches any path segment, so a later /create route would never be
    // reached (a request for it would hit show()/save() with id="create" -
    // i.e. id=0 - first).
    $router->get('/admin/orders/create', [OrderAdminController::class, 'create'])->capability('orders');
    $router->post('/admin/orders/create', [OrderAdminController::class, 'store'])->capability('orders');
    $router->get('/admin/orders/{id}', [OrderAdminController::class, 'show'])->capability('orders');
    $router->post('/admin/orders/{id}', [OrderAdminController::class, 'save'])->capability('orders');
    $router->post('/admin/orders/{id}/items', [OrderAdminController::class, 'saveItems'])->capability('orders');
    $router->post('/admin/orders/{id}/resend-invoice', [OrderAdminController::class, 'resendInvoice'])->capability('orders');
    // The one route in this whole feature NOT gated by the plain 'orders'
    // capability - cancelling (this project's "delete an order") is Super
    // Admin only, see AdminAuth::CAPABILITIES.
    $router->post('/admin/orders/{id}/cancel', [OrderAdminController::class, 'cancel'])->capability('orders_delete');

    $router->get('/admin/inventory', [InventoryAdminController::class, 'index'])->capability('inventory');
    $router->post('/admin/inventory/adjust', [InventoryAdminController::class, 'adjust'])->capability('inventory');

    // Customer management, including the test-account tooling used to place
    // orders that never touch real stock/finance figures - see CLAUDE.md's
    // "Test accounts" section.
    $router->get('/admin/customers', [CustomerAdminController::class, 'index'])->capability('customers');
    $router->post('/admin/customers/create-test-user', [CustomerAdminController::class, 'createTestUser'])->capability('customers');
    $router->get('/admin/customers/{id}', [CustomerAdminController::class, 'show'])->capability('customers');
    $router->get('/admin/customers/{id}/export', [CustomerAdminController::class, 'export'])->capability('customers');
    $router->post('/admin/customers/{id}/status', [CustomerAdminController::class, 'saveStatus'])->capability('customers');
    $router->post('/admin/customers/{id}/delete-test-account', [CustomerAdminController::class, 'deleteTestAccount'])->capability('customers');
    $router->post('/admin/customers/{id}/payment', [CustomerAdminController::class, 'savePayment'])->capability('customers');
    $router->post('/admin/customers/{id}/gdpr-delete', [CustomerAdminController::class, 'gdprDelete'])->capability('customers');

    $router->get('/admin/products', [ProductAdminController::class, 'index'])->capability('products');
    $router->post('/admin/products/delete', [ProductAdminController::class, 'delete'])->capability('products');

    $router->get('/admin/products/create', [ProductEditController::class, 'create'])->capability('products');
    $router->post('/admin/products/create', [ProductEditController::class, 'save'])->capability('products');
    $router->get('/admin/products/{id}/edit', [ProductEditController::class, 'edit'])->capability('products');
    $router->post('/admin/products/{id}/edit', [ProductEditController::class, 'save'])->capability('products');

    $router->get('/admin/products/{id}/images', [ProductImageController::class, 'index'])->capability('products');
    $router->post('/admin/products/{id}/images', [ProductImageController::class, 'action'])->capability('products');
    // Not capability-gated at the route level - ProductImageController::reorder()
    // does its own inline check, matching the original AJAX endpoint's
    // JSON-403 behavior (same pattern as MenuAdminController::reorder()).
    $router->post('/admin/products/{id}/images/reorder', [ProductImageController::class, 'reorder']);

    $router->get('/admin/images/{id}/crop', [ImageCropController::class, 'edit'])->capability('products');
    $router->post('/admin/images/{id}/crop', [ImageCropController::class, 'save'])->capability('products');

    $router->get('/admin/settings', [SettingsAdminController::class, 'index'])->capability('settings');
    $router->post('/admin/settings', [SettingsAdminController::class, 'save'])->capability('settings');
    $router->post('/admin/settings/site-url', [SettingsAdminController::class, 'saveSiteUrl'])->capability('settings');
    $router->post('/admin/settings/gdpr-cleanup', [SettingsAdminController::class, 'runGdprCleanup'])->capability('settings');

    $router->get('/admin/email-templates', [EmailTemplateAdminController::class, 'index'])->capability('settings');
    $router->post('/admin/email-templates', [EmailTemplateAdminController::class, 'save'])->capability('settings');

    // v2.00 - admin side of the legal/compliance domain (Phase 6).
    $router->get('/admin/contact-messages', [ContactAdminController::class, 'index'])->capability('contact_messages');
    $router->get('/admin/contact-messages/{id}', [ContactAdminController::class, 'show'])->capability('contact_messages');
    $router->post('/admin/contact-messages/{id}', [ContactAdminController::class, 'save'])->capability('contact_messages');

    $router->get('/admin/withdrawals', [WithdrawalAdminController::class, 'index'])->capability('withdrawals');
    $router->get('/admin/withdrawals/{id}', [WithdrawalAdminController::class, 'show'])->capability('withdrawals');
    $router->post('/admin/withdrawals/{id}', [WithdrawalAdminController::class, 'save'])->capability('withdrawals');

    $router->get('/admin/rma-tickets', [RmaAdminController::class, 'index'])->capability('rma_tickets');
    $router->get('/admin/rma-tickets/{id}', [RmaAdminController::class, 'show'])->capability('rma_tickets');
    $router->post('/admin/rma-tickets/{id}', [RmaAdminController::class, 'save'])->capability('rma_tickets');

    $router->get('/admin/legal-documents', [LegalDocumentAdminController::class, 'index'])->capability('legal_documents');
    $router->post('/admin/legal-documents', [LegalDocumentAdminController::class, 'save'])->capability('legal_documents');
    $router->post('/admin/legal-documents/delete', [LegalDocumentAdminController::class, 'delete'])->capability('legal_documents');
};
