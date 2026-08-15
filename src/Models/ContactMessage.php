<?php

namespace ShopRex\Models;

use ShopRex\Core\Model;

/**
 * One submission of the storefront contact form (Admin -> Contact Messages).
 * Exists as its own model/table (rather than e.g. just emailing the admin
 * and discarding it) so an admin has a persistent inbox to work through,
 * reply to, and track the status of. Submission is rate-limited separately
 * (see Services\RateLimiter, bound to the contact_message_attempts table)
 * to stop the form being used to spam an admin's inbox.
 */
class ContactMessage extends Model
{
    protected static string $table = 'contact_messages';

    public string $name = '';
    public string $email = '';
    public ?string $subject = null;
    public string $message = '';
    // Lets a customer reference an existing order in their message (e.g. "my
    // order SR20260101-ABC123 hasn't arrived") - free text, not a foreign key,
    // since a guest may not have an account to link to.
    public ?string $orderNumber = null;
    // Set only if the sender was logged in when they submitted the form -
    // null for anonymous/guest submissions.
    public ?int $customerId = null;
    // Sender's IP at submission time - used for the rate limiter and as a
    // spam-triage signal for admins, not shown to the customer.
    public ?string $ipAddress = null;
    // Workflow state for the admin inbox (e.g. new/replied/closed).
    public string $status = 'new';
    // Private notes an admin can attach - never shown to the customer.
    public ?string $adminNotes = null;
    public ?string $createdAt = null;
    public ?string $updatedAt = null;

    /** Inserts a new contact-form submission and returns it hydrated as a model - the storefront controller calls this after validating and rate-limit-checking the incoming POST. */
    public static function submit(array $data, \PDO $pdo): self
    {
        $stmt = $pdo->prepare(
            'INSERT INTO contact_messages (name, email, subject, message, order_number, customer_id, ip_address)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        // Empty-string optional fields (subject/order_number) are normalized
        // to NULL rather than stored as empty strings, so "not provided" is
        // unambiguous in the database.
        $stmt->execute([
            $data['name'], $data['email'], $data['subject'] ?: null, $data['message'],
            $data['order_number'] ?: null, $data['customer_id'] ?? null, $data['ip_address'] ?? null,
        ]);
        return self::find((int)$pdo->lastInsertId());
    }
}
