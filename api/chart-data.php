<?php
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');

$user = currentUser();
if (!$user) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit; }

$db = getDB();
$role = $user['role'];
$uid = (int) $user['id'];

$userFilter = $role === 'customer' ? " AND user_id=$uid" : '';
$apptFilter = $role === 'customer' ? " AND user_id=$uid" : ($role === 'mechanic' ? ' AND paid_at IS NOT NULL' : '');

// Spending / income by month (last 6 months)
$incomeLabels = [];
$incomeData = [];
$commissionData = [];
for ($i = 5; $i >= 0; $i--) {
    $m = date('Y-m', strtotime("-$i months"));
    $incomeLabels[] = date('M Y', strtotime("-$i months"));

    if ($role === 'mechanic') {
        $stmt = $db->query("SELECT COALESCE(SUM(gross_payment),0) FROM service_history WHERE DATE_FORMAT(completion_date,'%Y-%m')='$m' AND status='Settled' AND mechanic_id=$uid");
        $incomeData[] = (float) $stmt->fetchColumn();
        $cstmt = $db->query("SELECT COALESCE(SUM(commission_amount),0) FROM service_history WHERE DATE_FORMAT(completion_date,'%Y-%m')='$m' AND status='Settled' AND mechanic_id=$uid");
        $commissionData[] = (float) $cstmt->fetchColumn();
    } else {
        $stmt = $db->query("SELECT COALESCE(SUM(gross_payment),0) FROM service_history WHERE DATE_FORMAT(completion_date,'%Y-%m')='$m' AND status='Settled' $userFilter");
        $incomeData[] = (float) $stmt->fetchColumn();
    }
}

// Chart label depends on role
$incomeLabel = match ($role) {
    'customer' => 'Total Spent (RM)',
    'mechanic' => 'Job Value (RM)',
    default    => 'Income (RM)',
};

// Appointment status breakdown
$statusRows = $db->query("SELECT status, COUNT(*) as cnt FROM appointments WHERE status != 'Cancelled' $apptFilter GROUP BY status")->fetchAll();
$statusLabels = []; $statusData = [];
foreach ($statusRows as $r) { $statusLabels[] = $r['status']; $statusData[] = (int)$r['cnt']; }
if (empty($statusLabels)) { $statusLabels = ['No Data']; $statusData = [0]; }

// Top services
$svcRows = $db->query("
    SELECT sp.name, COUNT(*) as cnt FROM appointments a
    JOIN service_packages sp ON sp.id = a.package_id
    WHERE a.status != 'Cancelled' $apptFilter
    GROUP BY a.package_id ORDER BY cnt DESC LIMIT 5
")->fetchAll();
$svcLabels = []; $svcData = [];
foreach ($svcRows as $r) { $svcLabels[] = $r['name']; $svcData[] = (int)$r['cnt']; }
if (empty($svcLabels)) { $svcLabels = ['—']; $svcData = [0]; }

$stats = getDashboardStats($role !== 'admin' ? $uid : null, $role !== 'admin' ? $role : null);
if ($role === 'admin') $stats = getDashboardStats();

$statsOut = [
    'totalClients' => $stats['totalClients'],
    'bookings'     => $stats['bookings'],
    'grossIncome'  => formatRM($stats['grossIncome']),
    'todayAppts'   => $stats['todayAppts'],
];
if ($role === 'mechanic') {
    $statsOut['commissionTotal'] = formatRM($stats['commissionTotal'] ?? 0);
}

// Admin P&L: revenue, parts cost, commissions (expense), profit
$plRevenue = [];
$plCost = [];
$plCommission = [];
$plProfit = [];
if ($role === 'admin') {
    for ($i = 5; $i >= 0; $i--) {
        $m = date('Y-m', strtotime("-$i months"));
        $rev = (float) $db->query("SELECT COALESCE(SUM(gross_payment),0) FROM service_history WHERE DATE_FORMAT(completion_date,'%Y-%m')='$m' AND status='Settled'")->fetchColumn();
        $comm = (float) $db->query("SELECT COALESCE(SUM(commission_amount),0) FROM service_history WHERE DATE_FORMAT(completion_date,'%Y-%m')='$m' AND status='Settled'")->fetchColumn();
        // Parts cost from job_parts joined to completion month
        $partsCost = 0.0;
        try {
            $partsCost = (float) $db->query("
                SELECT COALESCE(SUM(jp.qty * p.cost_price),0)
                FROM job_parts jp
                JOIN parts p ON p.id = jp.part_id
                JOIN appointments a ON a.id = jp.appointment_id
                WHERE a.status='Completed' AND DATE_FORMAT(a.updated_at,'%Y-%m')='$m'
            ")->fetchColumn();
        } catch (Throwable $e) {
            $partsCost = 0.0;
        }
        // Refunds (approx: paid refunds on cancelled)
        $refunds = 0.0;
        try {
            $refunds = (float) $db->query("
                SELECT COALESCE(SUM(COALESCE(quote_amount,0)),0) FROM appointments
                WHERE refund_status='paid' AND DATE_FORMAT(COALESCE(refund_requested_at, updated_at),'%Y-%m')='$m'
            ")->fetchColumn();
        } catch (Throwable $e) { /* ignore */ }

        $cost = $partsCost + $comm + $refunds;
        $plRevenue[] = $rev;
        $plCost[] = $cost;
        $plCommission[] = $comm;
        $plProfit[] = round($rev - $cost, 2);
    }
    $statsOut['monthProfit'] = formatRM(end($plProfit) ?: 0);
    $statsOut['monthCost'] = formatRM(end($plCost) ?: 0);
}

// Customer total spent already in grossIncome; also paid-this-month
if ($role === 'customer') {
    $tm = $db->prepare("SELECT COALESCE(SUM(gross_payment),0) FROM service_history WHERE user_id=? AND status='Settled' AND DATE_FORMAT(completion_date,'%Y-%m')=DATE_FORMAT(CURDATE(),'%Y-%m')");
    $tm->execute([$uid]);
    $statsOut['monthSpent'] = formatRM((float) $tm->fetchColumn());
}

echo json_encode([
    'income'   => [
        'labels' => $incomeLabels,
        'data'   => $incomeData,
        'label'  => $incomeLabel,
        'commission_data' => $commissionData,
        'commission_label' => 'Commission (RM)',
    ],
    'pnl' => $role === 'admin' ? [
        'labels' => $incomeLabels,
        'revenue' => $plRevenue,
        'cost' => $plCost,
        'profit' => $plProfit,
        'commission' => $plCommission,
    ] : null,
    'status'   => ['labels' => $statusLabels, 'data' => $statusData],
    'services' => ['labels' => $svcLabels, 'data' => $svcData],
    'capacity' => ['mech' => $stats['mechPct'], 'elec' => $stats['elecPct']],
    'stats'    => $statsOut,
    'role'     => $role,
]);
