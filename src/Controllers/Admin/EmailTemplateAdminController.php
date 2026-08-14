<?php

namespace ShopRex\Controllers\Admin;

use ShopRex\Core\Container;
use ShopRex\Core\Request;
use ShopRex\Core\Response;
use ShopRex\Services\I18n;

/** Direct port of admin/email_templates.php. */
final class EmailTemplateAdminController extends AdminCrudController
{
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

    public function index(Request $request): Response
    {
        $availableLangs = I18n::enabledLanguages();
        $lang = (string)$request->get('lang', getSetting('default_language', 'en'));
        if (!array_key_exists($lang, $availableLangs)) {
            $lang = 'en';
        }

        $templateKeys = $this->templateKeysMeta();

        $editKey = $request->get('key');
        if ($editKey && !array_key_exists($editKey, $templateKeys)) {
            $this->flash('error', __('admin.email_templates.unknown_template'));
            return $this->redirect('/admin/email-templates');
        }

        $current = null;
        if ($editKey) {
            $stmt = $this->pdo->prepare('SELECT * FROM email_templates WHERE template_key = ? AND language = ?');
            $stmt->execute([$editKey, $lang]);
            $current = $stmt->fetch() ?: ['template_key' => $editKey, 'language' => $lang, 'subject' => '', 'body_html' => ''];
        }

        // Which languages already have a saved override for each key, for the list view.
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

    public function save(Request $request): Response
    {
        if ($csrfFailure = $this->requireCsrf()) {
            return $csrfFailure;
        }

        $availableLangs = I18n::enabledLanguages();
        $templateKeys = $this->templateKeysMeta();

        $key = (string)$request->post('template_key', '');
        $postLang = (string)$request->post('language', 'en');
        if (!array_key_exists($key, $templateKeys) || !array_key_exists($postLang, $availableLangs)) {
            $this->flash('error', __('admin.email_templates.invalid_template_or_lang'));
            return $this->redirect('/admin/email-templates');
        }

        $subject = trim((string)$request->post('subject', ''));
        $body = (string)$request->post('body_html', '');
        $stmt = $this->pdo->prepare(
            'INSERT INTO email_templates (template_key, language, subject, body_html) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE subject = VALUES(subject), body_html = VALUES(body_html)'
        );
        $stmt->execute([$key, $postLang, $subject, $body]);

        $this->flash('success', __('admin.email_templates.flash_saved'));
        return Response::redirect(rtrim(SITE_URL, '/') . '/admin/email-templates?lang=' . urlencode($postLang));
    }
}
