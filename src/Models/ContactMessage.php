<?php

namespace ShopRex\Models;

use ShopRex\Core\Model;

class ContactMessage extends Model
{
    protected static string $table = 'contact_messages';

    public string $name = '';
    public string $email = '';
    public ?string $subject = null;
    public string $message = '';
    public ?string $orderNumber = null;
    public ?int $customerId = null;
    public ?string $ipAddress = null;
    public string $status = 'new';
    public ?string $adminNotes = null;
    public ?string $createdAt = null;
    public ?string $updatedAt = null;

    public static function submit(array $data, \PDO $pdo): self
    {
        $stmt = $pdo->prepare(
            'INSERT INTO contact_messages (name, email, subject, message, order_number, customer_id, ip_address)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['name'], $data['email'], $data['subject'] ?: null, $data['message'],
            $data['order_number'] ?: null, $data['customer_id'] ?? null, $data['ip_address'] ?? null,
        ]);
        return self::find((int)$pdo->lastInsertId());
    }
}
