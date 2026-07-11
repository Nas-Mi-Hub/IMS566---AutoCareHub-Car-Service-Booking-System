<?php
/**
 * NotificationService — in-app + email + SMS fan-out
 */
class NotificationService
{
    public static function send(
        int $userId,
        string $title,
        string $message,
        string $type = 'info',
        ?string $link = null,
        bool $withEmail = false,
        bool $withSms = false
    ): void {
        notify($userId, $title, $message, $type, $link);

        if (!$withEmail && !$withSms) {
            return;
        }

        $db = getDB();
        $stmt = $db->prepare('SELECT email, full_name, contact_no FROM users WHERE id=?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if (!$user) {
            return;
        }

        if ($withEmail && !empty($user['email'])) {
            sendEmail($user['email'], '[' . APP_NAME . '] ' . $title, $message, $user['full_name'] ?? '');
        }
        if ($withSms && !empty($user['contact_no'])) {
            sendSms($user['contact_no'], $title . ': ' . mb_substr(strip_tags($message), 0, 140));
        }
    }

    public static function critical(
        int $userId,
        string $title,
        string $message,
        string $type = 'appointment',
        ?string $link = null
    ): void {
        self::send($userId, $title, $message, $type, $link, true, true);
    }
}
