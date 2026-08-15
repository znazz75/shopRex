<?php

namespace ShopRex\Controllers\Admin;

use ShopRex\Core\Container;
use ShopRex\Core\Request;
use ShopRex\Core\Response;
use ShopRex\Services\I18n;

/**
 * Lets a Super Admin customize the wording of every outgoing transactional
 * email (order confirmations, status updates, password resets, ...), per
 * language, overriding the built-in defaults. Direct port of
 * admin/email_templates.php. Exists as its own controller because email
 * template editing is a distinct concern from settings/pages - it has its
 * own storage table (email_templates) and its own per-key/per-language
 * addressing scheme (see templateKeysMeta()) rather than fitting the
 * simple key/value shape of Services\SettingsRepository.
 */
final class EmailTemplateAdminController extends AdminCrudController
{
    // Shared PDO connection used for the raw email_templates queries below.
    private readonly \PDO $pdo;

    public function __construct(Request $request, Container $container)
    {
        parent::__construct($request, $container);
        $this->pdo = $container->make(\PDO::class);
    }

    /**
     * Template keys the UI knows about, with a human label and the tokens
     * each one's body/subject can use (shown as a hint while editing).
     */
    private function templateKeysMeta(): array
    {
        return [
            '_header'                  => ['label' => __('admin.email_templates.key_header'), 'tokens' => ['shop_name'], 'has_subject' => false],
            '_footer'                  => ['label' => __('admin.email_templates.key_footer'), 'tokens' => ['shop_name'], 'has_subject' => false],
            'order_confirmation'       => ['label' => __('admin.email_templates.key_order_confirmation'), 'tokens' => ['shop_name', 'customer_name', 'order_number', 'order_items_table', 'bank_transfer_details'], 'has_subject' => true],
            'order_status_update'      => ['label' => __('admin.email_templates.key_order_status_update'), 'tokens' => ['shop_name', 'order_number', 'status', 'admin_notes'], 'has_subject' => true],
            'registration_welcome'     => ['label' => __('admin.email_templates.key_registration_welcome'), 'tokens' => ['shop_name', 'customer_name', 'account_url'], 'has_subject' => true],
            'password_reset'           => ['label' => __('admin.email_templates.key_password_reset'), 'tokens' => ['shop_name', 'customer_name', 'reset_link'], 'has_subject' => true],
            'account_deletion_warning' => ['label' => __('admin.email_templates.key_account_deletion_warning'), 'tokens' => ['shop_name', 'customer_name', 'deletion_date', 'login_url'], 'has_subject' => true],
        ];
    }

    /** Shows the template list, and - if a specific template key was requested via ?key= - loads that template/language's current saved override (or blank defaults) into the edit form. */
    public function index(Request $request): Response
    {
        // Only languages the shop currently has enabled are offered here (see
        // CLAUDE.md's i18n section on enabledLanguages() vs availableLanguages())
        // - editing a template for a disabled language wouldn't be useful.
        $availableLangs = I18n::enabledLanguages();
        $lang = (string)$request->get('lang', getSetting('default_language', 'en'));
        if (!array_key_exists($lang, $availableLangs)) {
            $lang = 'en';
        }

        $templateKeys = $this->templateKeysMeta();

        $editKey = $request->get('key');
        if ($editKey && !array_key_exists($editKey, $templateKeys)) {
            // Someone navigated to an unrecognized ?key= (typo, stale link, or
            // tampered URL) - bounce back rather than trying to render an edit
            // form for a template that doesn't exist.
            $this->flash('error', __('admin.email_templates.unknown_template'));
            return $this->redirect('/admin/email-templates');
        }

        $current = null;
        if ($editKey) {
            $stmt = $this->pdo->prepare('SELECT * FROM email_templates WHERE template_key = ? AND language = ?');
            $stmt->execute([$editKey, $lang]);
            // If no row exists yet, this key/language combo has never been
            // customized - fall back to an empty shell so the edit form still
            // has something to bind to (the actual default wording lives
            // elsewhere, e.g. Mailer's built-in templates, and is used at send
            // time when no override row exists).
            $current = $stmt->fetch() ?: ['template_key' => $editKey, 'language' => $lang, 'subject' => '', 'body_html' => ''];
        }

        // Builds a [template_key][language] => true lookup so the list view can
        // show which languages already have a custom override saved, without
        // running a query per cell.
        $existing = [];
        foreach ($this->pdo->query('SELECT template_key, language FROM email_templates')->fetchAll() as $row) {
            $existing[$row['template_key']][$row['language']] = true;
        }

        return $this->render('email_templates/index', [
            'lang'           => $lang,
            'availableLangs' => $availableLangs,
            'templateKeys'   => $templateKeys,
            'editKey'        => $editKey,
            'current'        => $current,
            'existing'       => $existing,
            'errors'         => [],
            'pageTitle'      => __('admin.email_templates'),
        ]);
    }

    /** Saves (inserts or overwrites) one template's subject/body for a specific language. */
    public function save(Request $request): Response
    {
        // Blocks a forged template-edit submission (CSRF) - see Controller::requireCsrf().
        if ($csrfFailure = $this->requireCsrf()) {
            return $csrfFailure;
        }

        $availableLangs = I18n::enabledLanguages();
        $templateKeys = $this->templateKeysMeta();

        $key = (string)$request->post('template_key', '');
        $postLang = (string)$request->post('language', 'en');
        // Whitelist check: both the template key and the language must be ones
        // this installation actually knows about/has enabled - stops a
        // tampered form from writing rows for a made-up template key or a
        // disabled language.
        if (!array_key_exists($key, $templateKeys) || !array_key_exists($postLang, $availableLangs)) {
            $this->flash('error', __('admin.email_templates.invalid_template_or_lang'));
            return $this->redirect('/admin/email-templates');
        }

        $subject = trim((string)$request->post('subject', ''));
        $body = (string)$request->post('body_html', '');
        // "Upsert" - one query either creates the first override for this
        // key/language pair or overwrites the existing one, keyed on
        // (template_key, language) presumably being a unique constraint in
        // the schema.
        $stmt = $this->pdo->prepare(
            'INSERT INTO email_templates (template_key, language, subject, body_html) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE subject = VALUES(subject), body_html = VALUES(body_html)'
        );
        $stmt->execute([$key, $postLang, $subject, $body]);

        $this->flash('success', __('admin.email_templates.flash_saved'));
        // Redirects back to the list with the just-edited language pre-selected
        // (?lang=...) so the admin lands back where they were instead of
        // resetting to the default language.
        return Response::redirect(rtrim(SITE_URL, '/') . '/admin/email-templates?lang=' . urlencode($postLang));
    }
}
