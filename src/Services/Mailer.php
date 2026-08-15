<?php

namespace ShopRex\Services;

/**
 * Mailer with editable, multi-language templates.
 *
 * Every email is: {{_header}} + body_html (from email_templates, with
 * {{token}} substitution) + {{_footer}}. Header/footer and every
 * template's subject/body are editable in Admin -> Email Templates
 * per language, with English as the fallback whenever a language has no
 * override for a given key.
 *
 * Uses PHP's built-in mail() out of the box so the framework has zero
 * required dependencies. For real-world delivery (Gmail/SendGrid/Mailgun/
 * etc.) swap the transport in deliver() for SMTP - see README.md. Every
 * attempt (success or failure) is written to the email_log table.
 *
 * Static-methods-only by design (no per-request state, same as
 * Support\Slugger/Pagination) - CheckoutService and every controller that
 * sends an email call these send*()/render() entry points directly.
 */
final class Mailer
{
    /**
     * Raw send + log. Prefer the send*() convenience methods below, which
     * render a template first; this is also used directly by anything
     * that already has a fully-built subject/HTML body.
     */
    public static function send(string $to, string $subject, string $htmlBody, string $template, ?int $orderId = null, ?string $attachmentPath = null, ?string $attachmentName = null): bool
    {
        [$success, $error] = self::deliver($to, $subject, $htmlBody, $attachmentPath, $attachmentName);

        // Every attempt is logged, success or failure - $template/$orderId
        // are just metadata for the admin's email log screen, not part of
        // the message itself.
        $stmt = db()->prepare(
            'INSERT INTO email_log (recipient, subject, template, order_id, status, error_message)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$to, $subject, $template, $orderId, $success ? 'sent' : 'failed', $error]);

        return $success;
    }

    /**
     * The actual transport call - wraps PHP's built-in mail() and returns
     * [success, error message or null]. Kept separate from send() so
     * send() only has to worry about logging, not the mechanics of
     * building a plain vs. multipart message.
     */
    private static function deliver(string $to, string $subject, string $htmlBody, ?string $attachmentPath, ?string $attachmentName): array
    {
        $from = 'From: ' . MAIL_FROM_NAME . ' <' . MAIL_FROM . '>';

        try {
            if ($attachmentPath && is_file($attachmentPath)) {
                // Build a minimal multipart/mixed message by hand (no
                // Composer dependency) so the invoice PDF can ride along.
                // A random boundary string separates the HTML body part
                // from the attachment part - it must not appear anywhere
                // inside either part's own content, which is why it's
                // randomly generated rather than a fixed string.
                $boundary = 'shopRex-' . bin2hex(random_bytes(12));
                $headers = [
                    'MIME-Version: 1.0',
                    'Content-Type: multipart/mixed; boundary="' . $boundary . '"',
                    $from,
                ];
                // First part: the HTML email body. Second part: the PDF
                // attachment, base64-encoded (chunk_split wraps it at the
                // standard 76-character line length MIME expects) and
                // marked as a downloadable attachment with its filename.
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
                // @ suppresses PHP's own warning on failure - the return
                // value (false) is checked explicitly right below instead,
                // and every outcome is already logged by send() regardless.
                $success = @mail($to, $subject, $body, implode("\r\n", $headers));
            } else {
                // No attachment - a plain single-part HTML email.
                $headers = ['MIME-Version: 1.0', 'Content-Type: text/html; charset=UTF-8', $from];
                $success = @mail($to, $subject, $htmlBody, implode("\r\n", $headers));
            }
            $error = $success ? null : 'mail() returned false (no local MTA configured?)';
        } catch (\Throwable $e) {
            // mail() itself doesn't normally throw, but file_get_contents()
            // on a missing/unreadable attachment could - catch broadly so a
            // bad attachment never turns into an uncaught fatal error.
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
        // Requested language has no override for this template - fall back
        // to the English row (every template is expected to have one).
        if ($language !== 'en') {
            $stmt->execute([$key, 'en']);
            $row = $stmt->fetch();
            if ($row) {
                return $row;
            }
        }
        // Neither the requested language nor English has this template at
        // all - return a blank stand-in rather than null/throwing, so
        // render() below can still produce a (mostly empty) email instead
        // of a fatal error.
        return ['subject' => '', 'body_html' => ''];
    }

    /** Replaces every {{name}} placeholder in $text with its matching value from $vars, leaving unmatched placeholders untouched (render() strips those afterwards). */
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
        // Every template can reference {{shop_name}} without every caller
        // having to remember to pass it in - merged in first so an
        // explicit entry in $vars (if any) would still win via array_merge's
        // "later array wins" rule, though no caller currently overrides it.
        $vars = array_merge(['shop_name' => getSetting('shop_name', SITE_NAME)], $vars);

        $template = self::getTemplate($key, $language);
        // '_header'/'_footer' are special template_key rows (not a real
        // email on their own) shared by every message - editable once in
        // Admin -> Email Templates rather than duplicated into each template.
        $header = self::getTemplate('_header', $language)['body_html'] ?? '';
        $footer = self::getTemplate('_footer', $language)['body_html'] ?? '';

        $subject = self::applyTokens($template['subject'] ?? '', $vars);
        // Final email body is always header + this template's body + footer,
        // each with {{token}} substitution applied independently.
        $body = self::applyTokens($header, $vars)
            . self::applyTokens($template['body_html'] ?? '', $vars)
            . self::applyTokens($footer, $vars);

        // Strip any placeholder no $vars entry covered (e.g. an admin typo
        // like {{customr_name}}) - shows nothing rather than leaking the
        // raw "{{...}}" syntax into the sent email.
        $body = preg_replace('/\{\{[a-z0-9_]+\}\}/i', '', $body);
        $subject = preg_replace('/\{\{[a-z0-9_]+\}\}/i', '', $subject);

        // Wrap the assembled body in a minimal, inline-styled HTML shell -
        // inline CSS is used throughout because most email clients strip
        // <style> blocks / external stylesheets.
        $html = '<!doctype html><html><body style="font-family: Arial, sans-serif; color:#222; background:#f5f5f5; padding:24px;">'
            . '<div style="max-width:600px;margin:0 auto;background:#fff;border-radius:8px;overflow:hidden;">' . $body . '</div>'
            . '</body></html>';

        return ['subject' => $subject, 'html' => $html];
    }

    /**
     * Builds the HTML order-items table + totals summary that fills the
     * {{order_items_table}} token in the order_confirmation template.
     * Column headers/labels reuse the exact same translation keys as the
     * storefront order confirmation page (src/Views/storefront/order/
     * confirmation.php), via the global __() helper, so the wording a
     * customer sees on-screen right after checkout matches what lands in
     * their inbox a moment later. __() renders in Services\I18n::current()'s
     * language rather than $order['language'] explicitly - safe here only
     * because sendOrderConfirmation() is exclusively called synchronously
     * from CheckoutService, mid-checkout-request, when the two are always
     * the same value; a future "resend this email" admin action would need
     * to switch the active language first rather than assuming that.
     */
    private static function renderOrderItemsTable(array $order, array $items): string
    {
        $rows = '';
        foreach ($items as $item) {
            // Chosen option combination (if any) shown as a small grey
            // subtitle under the product name, same idea as the invoice PDF.
            $option = $item['option_summary'] ? '<br><small style="color:#666;">' . e($item['option_summary']) . '</small>' : '';
            $rows .= '<tr style="border-bottom:1px solid #e5e7eb;">'
                . '<td style="padding:8px 4px;">' . e($item['product_name']) . $option . '</td>'
                . '<td style="padding:8px 4px;">' . (int)$item['quantity'] . '</td>'
                . '<td style="padding:8px 4px;text-align:right;">' . formatPrice((float)$item['total_price']) . '</td>'
                . '</tr>';
        }

        // Same "Shipping (Method Name)" pattern as the storefront cart/
        // checkout pages - only appends the method name when one's known.
        $shippingLabel = e(__('common.shipping')) . (!empty($order['shipping_method_name']) ? ' (' . e($order['shipping_method_name']) . ')' : '');

        return '<table style="width:100%;border-collapse:collapse;margin-top:16px;">'
            . '<thead><tr style="text-align:left;border-bottom:2px solid #e5e7eb;"><th style="padding:8px 4px;">' . e(__('order.item')) . '</th><th style="padding:8px 4px;">' . e(__('common.quantity')) . '</th><th style="padding:8px 4px;text-align:right;">' . e(__('common.price')) . '</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table>'
            . '<table style="width:100%;margin-top:12px;">'
            . '<tr><td>' . e(__('common.subtotal')) . '</td><td style="text-align:right;">' . formatPrice((float)$order['subtotal']) . '</td></tr>'
            . '<tr><td>' . $shippingLabel . '</td><td style="text-align:right;">' . formatPrice((float)$order['shipping_cost']) . '</td></tr>'
            . '<tr><td>' . e(__('common.tax')) . '</td><td style="text-align:right;">' . formatPrice((float)$order['tax_total']) . '</td></tr>'
            . '<tr style="font-weight:bold;font-size:16px;"><td style="padding-top:8px;">' . e(__('common.total')) . '</td><td style="text-align:right;padding-top:8px;">' . formatPrice((float)$order['total']) . '</td></tr>'
            . '</table>';
    }

    /**
     * Sends the order confirmation email right after checkout completes,
     * with the PDF invoice attached when one already exists for this
     * order. Bank-transfer/invoice payment methods get their payment
     * instructions inlined into the email since there's no external
     * payment page to send the customer to.
     */
    public static function sendOrderConfirmation(array $order, array $items): bool
    {
        $lang = $order['language'] ?? 'en';
        // Reuses the existing {{bank_transfer_details}} template token for
        // both payment methods that get no gateway redirect (bank transfer
        // and invoice) - avoids needing a second token in every language's
        // order_confirmation template row (see sql/schema.sql email_templates seed).
        // Same translation keys (and the same {amount}-token phrasing) as
        // the equivalent block on the storefront order confirmation page
        // (src/Views/storefront/order/confirmation.php) - see
        // renderOrderItemsTable()'s docblock for why __() is safe to use
        // here despite not being passed $order['language'] explicitly.
        $bankDetails = '';
        if ($order['payment_method'] === 'bank_transfer') {
            $bankDetails = '<div style="background:#f3f4f6;border-radius:6px;padding:16px;margin-top:12px;">'
                . '<p style="margin:0 0 8px;font-weight:bold;">' . e(__('order.bank_transfer_instructions', ['amount' => formatPrice((float)$order['total'])])) . '</p>'
                . '<p style="margin:0;">' . e(__('order.bank_account_holder')) . ': ' . e(getSetting('bank_account_holder', BANK_ACCOUNT_HOLDER)) . '<br>' . e(__('order.bank_iban')) . ': ' . e(getSetting('bank_iban', BANK_IBAN))
                . '<br>' . e(__('order.bank_bic')) . ': ' . e(getSetting('bank_bic', BANK_BIC)) . '<br>' . e(__('order.bank_name')) . ': ' . e(getSetting('bank_name', BANK_NAME)) . '<br>' . e(__('order.bank_reference')) . ': ' . e($order['order_number']) . '<br><br>' . e(__('order.will_ship_on_payment')) . '</p></div>';
        } elseif ($order['payment_method'] === 'invoice') {
            $bankDetails = '<div style="background:#f3f4f6;border-radius:6px;padding:16px;margin-top:12px;">'
                . '<p style="margin:0;">' . e(__('order.invoice_instructions')) . '</p></div>';
        }

        $rendered = self::render('order_confirmation', $lang, [
            'customer_name'         => trim($order['shipping_name'] ?? ''),
            'order_number'          => $order['order_number'],
            'order_items_table'     => self::renderOrderItemsTable($order, $items),
            'bank_transfer_details' => $bankDetails,
        ]);

        // Attach the order's invoice PDF if one has already been generated
        // (InvoiceGenerator runs before this in the checkout flow) and the
        // file still exists on disk - silently sends without an attachment
        // otherwise, since a missing invoice shouldn't block the order
        // confirmation from going out at all.
        $invoicePath = null;
        $invoiceName = null;
        $stmt = db()->prepare('SELECT * FROM invoices WHERE order_id = ? LIMIT 1');
        $stmt->execute([$order['id']]);
        $invoice = $stmt->fetch();
        // No matching row (e.g. InvoiceGenerator failed earlier and was
        // already error_log()'d by CheckoutService) or the file's gone
        // missing from disk - either way, a missing invoice shouldn't
        // block the order confirmation itself from going out.
        if ($invoice && is_file($invoice['pdf_path'])) {
            $invoicePath = $invoice['pdf_path'];
            $invoiceName = $invoice['invoice_number'] . '.pdf';
        }

        return self::send($order['customer_email'], $rendered['subject'], $rendered['html'], 'order_confirmation', (int)$order['id'], $invoicePath, $invoiceName);
    }

    /** Notifies a customer that their order's status changed (e.g. "shipped") - includes any admin-written note verbatim, HTML-escaped with line breaks preserved. */
    public static function sendOrderStatusUpdate(array $order): bool
    {
        $lang = $order['language'] ?? 'en';
        // nl2br() turns the admin's plain-text newlines into <br> tags so
        // multi-line notes still look right in HTML email; e() escapes the
        // note's actual text first so an admin's note can't inject markup.
        $notes = !empty($order['admin_notes']) ? '<p>' . nl2br(e($order['admin_notes'])) . '</p>' : '';
        $rendered = self::render('order_status_update', $lang, [
            'order_number' => $order['order_number'],
            'status'       => e($order['status']),
            'admin_notes'  => $notes,
        ]);
        return self::send($order['customer_email'], $rendered['subject'], $rendered['html'], 'order_status_update', (int)$order['id']);
    }

    /** Sends the "welcome, here's your account" email right after a new customer registers. Returns false without sending if the customer id doesn't exist. */
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
            'account_url'   => rtrim(SITE_URL, '/') . '/account',
        ]);
        return self::send($customer['email'], $rendered['subject'], $rendered['html'], 'registration_welcome');
    }

    /** Sends a "click here to reset your password" email containing a one-time reset link built from $token (already generated/stored by the caller). */
    public static function sendPasswordReset(array $customer, string $token): bool
    {
        // urlencode() so a token containing URL-unsafe characters can't
        // break the query string it's embedded in.
        $resetLink = rtrim(SITE_URL, '/') . '/reset-password?token=' . urlencode($token);
        $rendered = self::render('password_reset', $customer['language'] ?? 'en', [
            'customer_name' => $customer['first_name'],
            'reset_link'    => $resetLink,
        ]);
        return self::send($customer['email'], $rendered['subject'], $rendered['html'], 'password_reset');
    }

    /** Sends the GDPR inactivity-deletion warning email (see Services\GdprService::runInactivityCleanup()) telling a customer their account will be erased on $deletionDate unless they log back in. */
    public static function sendAccountDeletionWarning(array $customer, string $deletionDate): bool
    {
        $rendered = self::render('account_deletion_warning', $customer['language'] ?? 'en', [
            'customer_name' => $customer['first_name'],
            'deletion_date' => $deletionDate,
            'login_url'     => rtrim(SITE_URL, '/') . '/login',
        ]);
        return self::send($customer['email'], $rendered['subject'], $rendered['html'], 'account_deletion_warning');
    }
}
