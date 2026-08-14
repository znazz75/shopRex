<?php
/**
 * Mailer with editable, bilingual templates.
 *
 * Every email is: {{_header}} + body_html (from email_templates, with
 * {{token}} substitution) + {{_footer}}. Header/footer and every
 * template's subject/body are editable in Admin -> Email Templates
 * (admin/email_templates.php) per language, with English as the fallback
 * whenever a language has no override for a given key.
 *
 * Uses PHP's built-in mail() out of the box so the framework has zero
 * required dependencies. For real-world delivery (Gmail/SendGrid/Mailgun/
 * etc.) swap the transport in deliver() for SMTP - see README.md. Every
 * attempt (success or failure) is written to the email_log table.
 */

class Mailer
{
    /**
     * Raw send + log. Prefer the send*() convenience methods below, which
     * render a template first; this is also used directly by anything
     * that already has a fully-built subject/HTML body.
     */
    public static function send(string $to, string $subject, string $htmlBody, string $template, ?int $orderId = null, ?string $attachmentPath = null, ?string $attachmentName = null): bool
    {
        [$success, $error] = self::deliver($to, $subject, $htmlBody, $attachmentPath, $attachmentName);

        $stmt = db()->prepare(
            'INSERT INTO email_log (recipient, subject, template, order_id, status, error_message)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$to, $subject, $template, $orderId, $success ? 'sent' : 'failed', $error]);

        return $success;
    }

    private static function deliver(string $to, string $subject, string $htmlBody, ?string $attachmentPath, ?string $attachmentName): array
    {
        $from = 'From: ' . MAIL_FROM_NAME . ' <' . MAIL_FROM . '>';

        try {
            if ($attachmentPath && is_file($attachmentPath)) {
                // Build a minimal multipart/mixed message by hand (no
                // Composer dependency) so the invoice PDF can ride along.
                $boundary = 'shopRex-' . bin2hex(random_bytes(12));
                $headers = [
                    'MIME-Version: 1.0',
                    'Content-Type: multipart/mixed; boundary="' . $boundary . '"',
                    $from,
                ];
                $body = "--$boundary\r\n"
                    . "Content-Type: text/html; charset=UTF-8\r\n"
                    . "Content-Transfer-Encoding: 8bit\r\n\r\n"
                    . $htmlBody . "\r\n\r\n"
                    . "--$boundary\r\n"
                    . 'Content-Type: application/pdf; name="' . ($attachmentName ?: 'invoice.pdf') . "\"\r\n"
                    . "Content-Transfer-Encoding: base64\r\n"
                    . 'Content-Disposition: attachment; filename="' . ($attachmentName ?: 'invoice.pdf') . "\"\r\n\r\n"
                    . chunk_split(base64_encode(file_get_contents($attachmentPath)))
                    . "--$boundary--";
                $success = @mail($to, $subject, $body, implode("\r\n", $headers));
            } else {
                $headers = ['MIME-Version: 1.0', 'Content-Type: text/html; charset=UTF-8', $from];
                $success = @mail($to, $subject, $htmlBody, implode("\r\n", $headers));
            }
            $error = $success ? null : 'mail() returned false (no local MTA configured?)';
        } catch (Throwable $e) {
            $success = false;
            $error = $e->getMessage();
        }

        return [$success, $error];
    }

    /**
     * Fetch a template row for $key, preferring $language and falling back
     * to English, then to an empty stand-in so a missing template never
     * fatals - it just sends a mostly-blank (still logged) email.
     */
    private static function getTemplate(string $key, string $language): array
    {
        $stmt = db()->prepare('SELECT * FROM email_templates WHERE template_key = ? AND language = ?');
        $stmt->execute([$key, $language]);
        $row = $stmt->fetch();
        if ($row) {
            return $row;
        }
        if ($language !== 'en') {
            $stmt->execute([$key, 'en']);
            $row = $stmt->fetch();
            if ($row) {
                return $row;
            }
        }
        return ['subject' => '', 'body_html' => ''];
    }

    private static function applyTokens(string $text, array $vars): string
    {
        foreach ($vars as $name => $value) {
            $text = str_replace('{{' . $name . '}}', (string)$value, $text);
        }
        return $text;
    }

    /**
     * Render $key in $language: header + body (with tokens applied to both
     * subject and body) + footer. Any {{token}} left over after $vars is
     * applied (e.g. an admin typo) is simply removed rather than shown raw.
     */
    public static function render(string $key, string $language, array $vars = []): array
    {
        $vars = array_merge(['shop_name' => getSetting('shop_name', SITE_NAME)], $vars);

        $template = self::getTemplate($key, $language);
        $header = self::getTemplate('_header', $language)['body_html'] ?? '';
        $footer = self::getTemplate('_footer', $language)['body_html'] ?? '';

        $subject = self::applyTokens($template['subject'] ?? '', $vars);
        $body = self::applyTokens($header, $vars)
            . self::applyTokens($template['body_html'] ?? '', $vars)
            . self::applyTokens($footer, $vars);

        // Strip any placeholder no $vars entry covered.
        $body = preg_replace('/\{\{[a-z0-9_]+\}\}/i', '', $body);
        $subject = preg_replace('/\{\{[a-z0-9_]+\}\}/i', '', $subject);

        $html = '<!doctype html><html><body style="font-family: Arial, sans-serif; color:#222; background:#f5f5f5; padding:24px;">'
            . '<div style="max-width:600px;margin:0 auto;background:#fff;border-radius:8px;overflow:hidden;">' . $body . '</div>'
            . '</body></html>';

        return ['subject' => $subject, 'html' => $html];
    }

    private static function renderOrderItemsTable(array $order, array $items): string
    {
        $rows = '';
        foreach ($items as $item) {
            $option = $item['option_summary'] ? '<br><small style="color:#666;">' . e($item['option_summary']) . '</small>' : '';
            $rows .= '<tr style="border-bottom:1px solid #e5e7eb;">'
                . '<td style="padding:8px 4px;">' . e($item['product_name']) . $option . '</td>'
                . '<td style="padding:8px 4px;">' . (int)$item['quantity'] . '</td>'
                . '<td style="padding:8px 4px;text-align:right;">' . formatPrice((float)$item['total_price']) . '</td>'
                . '</tr>';
        }

        return '<table style="width:100%;border-collapse:collapse;margin-top:16px;">'
            . '<thead><tr style="text-align:left;border-bottom:2px solid #e5e7eb;"><th style="padding:8px 4px;">Item</th><th style="padding:8px 4px;">Qty</th><th style="padding:8px 4px;text-align:right;">Price</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table>'
            . '<table style="width:100%;margin-top:12px;">'
            . '<tr><td>Subtotal</td><td style="text-align:right;">' . formatPrice((float)$order['subtotal']) . '</td></tr>'
            . '<tr><td>Shipping' . (!empty($order['shipping_method_name']) ? ' (' . e($order['shipping_method_name']) . ')' : '') . '</td><td style="text-align:right;">' . formatPrice((float)$order['shipping_cost']) . '</td></tr>'
            . '<tr><td>Tax</td><td style="text-align:right;">' . formatPrice((float)$order['tax_total']) . '</td></tr>'
            . '<tr style="font-weight:bold;font-size:16px;"><td style="padding-top:8px;">Total</td><td style="text-align:right;padding-top:8px;">' . formatPrice((float)$order['total']) . '</td></tr>'
            . '</table>';
    }

    public static function sendOrderConfirmation(array $order, array $items): bool
    {
        $lang = $order['language'] ?? 'en';
        // Reuses the existing {{bank_transfer_details}} template token for
        // both payment methods that get no gateway redirect (bank transfer
        // and invoice) - avoids needing a second token in every language's
        // order_confirmation template row (see sql/schema.sql email_templates seed).
        $bankDetails = '';
        if ($order['payment_method'] === 'bank_transfer') {
            $bankDetails = '<div style="background:#f3f4f6;border-radius:6px;padding:16px;margin-top:12px;">'
                . '<p style="margin:0 0 8px;font-weight:bold;">Please transfer the total amount to:</p>'
                . '<p style="margin:0;">Account holder: ' . e(getSetting('bank_account_holder', BANK_ACCOUNT_HOLDER)) . '<br>IBAN: ' . e(getSetting('bank_iban', BANK_IBAN))
                . '<br>BIC: ' . e(getSetting('bank_bic', BANK_BIC)) . '<br>Bank: ' . e(getSetting('bank_name', BANK_NAME)) . '<br>Reference: ' . e($order['order_number']) . '</p></div>';
        } elseif ($order['payment_method'] === 'invoice') {
            $bankDetails = '<div style="background:#f3f4f6;border-radius:6px;padding:16px;margin-top:12px;">'
                . '<p style="margin:0;">You will receive an invoice with your shipment - please pay within the stated term.</p></div>';
        }

        $rendered = self::render('order_confirmation', $lang, [
            'customer_name'         => trim($order['shipping_name'] ?? ''),
            'order_number'          => $order['order_number'],
            'order_items_table'     => self::renderOrderItemsTable($order, $items),
            'bank_transfer_details' => $bankDetails,
        ]);

        $invoicePath = null;
        $invoiceName = null;
        try {
            $stmt = db()->prepare('SELECT * FROM invoices WHERE order_id = ? LIMIT 1');
            $stmt->execute([$order['id']]);
            $invoice = $stmt->fetch();
            if ($invoice && is_file($invoice['pdf_path'])) {
                $invoicePath = $invoice['pdf_path'];
                $invoiceName = $invoice['invoice_number'] . '.pdf';
            }
        } catch (Throwable $e) {
            // invoices table/file not available - send without an attachment.
        }

        return self::send($order['customer_email'], $rendered['subject'], $rendered['html'], 'order_confirmation', (int)$order['id'], $invoicePath, $invoiceName);
    }

    public static function sendOrderStatusUpdate(array $order): bool
    {
        $lang = $order['language'] ?? 'en';
        $notes = !empty($order['admin_notes']) ? '<p>' . nl2br(e($order['admin_notes'])) . '</p>' : '';
        $rendered = self::render('order_status_update', $lang, [
            'order_number' => $order['order_number'],
            'status'       => e($order['status']),
            'admin_notes'  => $notes,
        ]);
        return self::send($order['customer_email'], $rendered['subject'], $rendered['html'], 'order_status_update', (int)$order['id']);
    }

    public static function sendRegistrationWelcome(int $customerId): bool
    {
        $stmt = db()->prepare('SELECT * FROM customers WHERE id = ?');
        $stmt->execute([$customerId]);
        $customer = $stmt->fetch();
        if (!$customer) {
            return false;
        }

        $rendered = self::render('registration_welcome', $customer['language'] ?? 'en', [
            'customer_name' => $customer['first_name'],
            'account_url'   => rtrim(SITE_URL, '/') . '/account.php',
        ]);
        return self::send($customer['email'], $rendered['subject'], $rendered['html'], 'registration_welcome');
    }

    public static function sendPasswordReset(array $customer, string $token): bool
    {
        $resetLink = rtrim(SITE_URL, '/') . '/reset_password.php?token=' . urlencode($token);
        $rendered = self::render('password_reset', $customer['language'] ?? 'en', [
            'customer_name' => $customer['first_name'],
            'reset_link'    => $resetLink,
        ]);
        return self::send($customer['email'], $rendered['subject'], $rendered['html'], 'password_reset');
    }

    public static function sendAccountDeletionWarning(array $customer, string $deletionDate): bool
    {
        $rendered = self::render('account_deletion_warning', $customer['language'] ?? 'en', [
            'customer_name' => $customer['first_name'],
            'deletion_date' => $deletionDate,
            'login_url'     => rtrim(SITE_URL, '/') . '/login.php',
        ]);
        return self::send($customer['email'], $rendered['subject'], $rendered['html'], 'account_deletion_warning');
    }
}
