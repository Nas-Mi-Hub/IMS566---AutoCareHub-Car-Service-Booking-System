<?php
/**
 * PaymentService — manual proof upload + optional gateway stub
 */
class PaymentService
{
    public static function config(): array
    {
        static $cfg = null;
        if ($cfg === null) {
            $path = __DIR__ . '/../../config/payment.php';
            $cfg = is_file($path) ? require $path : [
                'gateway_enabled' => false,
                'provider' => 'none',
                'sst_enabled' => false,
                'sst_percent' => 6,
            ];
        }
        return $cfg;
    }

    public static function isGatewayEnabled(): bool
    {
        $c = self::config();
        return !empty($c['gateway_enabled']) && ($c['provider'] ?? 'none') !== 'none';
    }

    public static function sstEnabled(): bool
    {
        return !empty(self::config()['sst_enabled']);
    }

    public static function sstPercent(): float
    {
        return (float) (self::config()['sst_percent'] ?? 6);
    }

    /**
     * Calculate labor + parts + optional SST.
     *
     * @return array{subtotal:float,sst:float,total:float,sst_percent:float}
     */
    public static function calcTotals(float $labor, float $parts = 0): array
    {
        $subtotal = round($labor + $parts, 2);
        $sst = 0.0;
        $pct = self::sstPercent();
        if (self::sstEnabled() && $pct > 0) {
            $sst = round($subtotal * ($pct / 100), 2);
        }
        return [
            'subtotal'    => $subtotal,
            'sst'         => $sst,
            'total'       => round($subtotal + $sst, 2),
            'sst_percent' => $pct,
        ];
    }

    public static function pay(
        int $appointmentId,
        int $userId,
        string $paymentMethod,
        ?string $paymentProofPath = null
    ): array {
        $result = payForAppointment($appointmentId, $userId, $paymentMethod, $paymentProofPath);
        // Email/SMS for critical payment event (in-app already sent by payForAppointment)
        if (!empty($result['ok'])) {
            try {
                $db = getDB();
                $stmt = $db->prepare('SELECT email, full_name, contact_no FROM users WHERE id=?');
                $stmt->execute([$userId]);
                $u = $stmt->fetch();
                if ($u) {
                    $msg = 'Payment of ' . formatRM($result['amount'] ?? 0) . ' received. Receipt ' . ($result['receipt_no'] ?? '') . ' is ready.';
                    if (!empty($u['email'])) {
                        sendEmail($u['email'], '[' . APP_NAME . '] Payment Confirmed', $msg, (string) ($u['full_name'] ?? ''));
                    }
                    if (!empty($u['contact_no'])) {
                        sendSms((string) $u['contact_no'], 'Payment confirmed: ' . formatRM($result['amount'] ?? 0));
                    }
                }
            } catch (Throwable $e) {
                // non-fatal
            }
        }
        return $result;
    }

    /**
     * Stub: build a Billplz/SenangPay redirect URL when gateway is configured.
     * Returns null when gateway is disabled (use manual payment UI).
     */
    public static function createGatewayCharge(int $appointmentId, float $amount, string $description, string $callbackUrl): ?array
    {
        if (!self::isGatewayEnabled()) {
            return null;
        }
        $c = self::config();
        $provider = $c['provider'] ?? 'none';

        // Demo/stub response — wire real API when credentials are present
        return [
            'provider'    => $provider,
            'status'      => 'stub',
            'message'     => 'Gateway path is configured but requires live API keys. Use manual payment proof for now.',
            'amount'      => $amount,
            'description' => $description,
            'callback'    => $callbackUrl,
            'redirect_url'=> null,
        ];
    }
}
