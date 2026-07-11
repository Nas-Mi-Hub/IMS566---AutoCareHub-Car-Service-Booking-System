<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
$user = requireRole('admin');
$db = getDB();
ensureSchemaUpdates();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf(baseUrl('admin/contact.php'));
    $action = $_POST['action'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);

    if ($action === 'status' && $id > 0) {
        $status = $_POST['status'] ?? 'read';
        if (!in_array($status, ['new', 'read', 'replied'], true)) {
            $status = 'read';
        }
        $db->prepare('UPDATE contact_submissions SET status=? WHERE id=?')->execute([$status, $id]);
        flash('success', 'Inquiry updated to: ' . $status);
    } elseif ($action === 'delete' && $id > 0) {
        $db->prepare('DELETE FROM contact_submissions WHERE id=?')->execute([$id]);
        flash('success', 'Inquiry deleted.');
    } elseif ($action === 'seed_demo') {
        // Sample inquiries if empty (presentation)
        $count = (int) $db->query('SELECT COUNT(*) FROM contact_submissions')->fetchColumn();
        if ($count === 0) {
            $samples = [
                ['Ahmad Faiz', 'ahmad@email.com', '0123456789', 'Service package inquiry', 'Hi, do you offer fleet maintenance packages for 5 company cars? Looking for monthly servicing.'],
                ['Siti Nurhaliza', 'siti@email.com', '0198765432', 'Booking reschedule', 'I need to reschedule my appointment next week. Can the workshop call me?'],
                ['Lim Wei Jie', 'limwj@email.com', '0162233445', 'Quote for brake pads', 'My Myvi 2019 needs front brake pads. Rough estimate please before I book.'],
                ['Priya Kumar', 'priya@email.com', null, 'Workshop hours', 'Are you open on Saturday afternoon for walk-in diagnostics?'],
            ];
            $ins = $db->prepare('INSERT INTO contact_submissions (name,email,phone,subject,message,status) VALUES (?,?,?,?,?,?)');
            foreach ($samples as $i => $s) {
                $ins->execute([$s[0], $s[1], $s[2], $s[3], $s[4], $i === 0 ? 'new' : ($i === 1 ? 'read' : 'new')]);
            }
            flash('success', 'Sample inquiries loaded for demo.');
        } else {
            flash('info', 'Inquiries already exist — sample seed skipped.');
        }
    }
    redirect(baseUrl('admin/contact.php'));
}

$filter = $_GET['filter'] ?? 'all';
$sql = 'SELECT * FROM contact_submissions';
$params = [];
if (in_array($filter, ['new', 'read', 'replied'], true)) {
    $sql .= ' WHERE status=?';
    $params[] = $filter;
}
$sql .= ' ORDER BY FIELD(status,"new","read","replied"), created_at DESC';
$stmt = $db->prepare($sql);
$stmt->execute($params);
$submissions = $stmt->fetchAll();

$counts = [
    'all'     => (int) $db->query('SELECT COUNT(*) FROM contact_submissions')->fetchColumn(),
    'new'     => (int) $db->query("SELECT COUNT(*) FROM contact_submissions WHERE status='new'")->fetchColumn(),
    'read'    => (int) $db->query("SELECT COUNT(*) FROM contact_submissions WHERE status='read'")->fetchColumn(),
    'replied' => (int) $db->query("SELECT COUNT(*) FROM contact_submissions WHERE status='replied'")->fetchColumn(),
];

$workshopPhone = getWorkshopSetting('workshop_phone', '03-1234 5678');
$workshopEmail = getWorkshopSetting('workshop_email', 'support@autocarehub.my');

renderHeader('Contact Inquiries', $user, 'contact');
?>
<h2 class="section-title">Contact Inquiries</h2>
<p class="section-subtitle">Public contact form messages · Reply by Call / WhatsApp / Email · Track status to replied</p>

<div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
  <div class="stat-card text-center"><p class="text-2xl font-bold text-white"><?= $counts['all'] ?></p><p class="text-xs text-slate-400">Total</p></div>
  <div class="stat-card text-center"><p class="text-2xl font-bold text-amber-400"><?= $counts['new'] ?></p><p class="text-xs text-slate-400">New</p></div>
  <div class="stat-card text-center"><p class="text-2xl font-bold text-sky-400"><?= $counts['read'] ?></p><p class="text-xs text-slate-400">Read</p></div>
  <div class="stat-card text-center"><p class="text-2xl font-bold text-emerald-400"><?= $counts['replied'] ?></p><p class="text-xs text-slate-400">Replied</p></div>
</div>

<div class="panel-card p-4 mb-4 flex flex-wrap gap-2 items-center justify-between">
  <div class="flex flex-wrap gap-2">
    <?php foreach (['all' => 'All', 'new' => 'New', 'read' => 'Read', 'replied' => 'Replied'] as $k => $label): ?>
    <a href="?filter=<?= $k ?>" class="<?= $filter === $k ? 'btn-primary' : 'btn-secondary' ?>" style="padding:.35rem .75rem;font-size:.75rem"><?= $label ?> (<?= $counts[$k] ?? 0 ?>)</a>
    <?php endforeach; ?>
  </div>
  <?php if ($counts['all'] === 0): ?>
  <form method="POST">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="seed_demo">
    <button class="btn-secondary" style="font-size:.75rem">Load sample inquiries</button>
  </form>
  <?php endif; ?>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
  <div class="panel-card p-5 lg:col-span-1">
    <h3 class="font-semibold app-heading mb-3">Workshop contact tips</h3>
    <ul class="text-sm text-slate-400 space-y-2">
      <li>• Use <strong class="app-heading">WhatsApp</strong> for quick quotes &amp; reschedules</li>
      <li>• Use <strong class="app-heading">Call</strong> for urgent issues</li>
      <li>• Mark <strong class="app-heading">Replied</strong> after you respond</li>
      <li>• Public form: <a class="text-accent" href="<?= baseUrl('contact.php') ?>" target="_blank">contact.php</a></li>
    </ul>
    <div class="mt-4 text-xs app-muted space-y-1">
      <p>Workshop phone: <?= e($workshopPhone) ?></p>
      <p>Workshop email: <?= e($workshopEmail) ?></p>
    </div>
  </div>
  <div class="lg:col-span-2 space-y-3">
  <?php if (empty($submissions)): ?>
    <div class="panel-card p-8 text-center empty-state">
      No inquiries yet. When customers submit the public contact form, they appear here.
      <form method="POST" class="mt-4">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="seed_demo">
        <button class="btn-primary" style="width:auto">Load sample inquiries for demo</button>
      </form>
    </div>
  <?php endif; ?>

  <?php foreach ($submissions as $s):
    $phone = $s['phone'] ?? '';
    $waText = "Hi " . $s['name'] . ", this is AutoCare Hub regarding your inquiry: \"" . $s['subject'] . "\". ";
    $mailHref = 'mailto:' . rawurlencode($s['email']) . '?subject=' . rawurlencode('Re: ' . $s['subject']) . '&body=' . rawurlencode("Hi " . $s['name'] . ",\n\nThank you for contacting AutoCare Hub regarding: " . $s['subject'] . "\n\n");
    $badge = $s['status'] === 'new' ? 'pending' : ($s['status'] === 'replied' ? 'approved' : 'completed');
  ?>
  <div class="panel-card p-5 <?= $s['status'] === 'new' ? 'notif-alert' : '' ?>">
    <div class="flex flex-wrap justify-between items-start gap-3">
      <div class="flex-1 min-w-0">
        <div class="flex flex-wrap items-center gap-2 mb-1">
          <p class="font-semibold app-heading"><?= e($s['subject']) ?></p>
          <span class="badge badge-<?= $badge ?>"><?= e($s['status']) ?></span>
        </div>
        <p class="text-sm app-muted">
          <strong class="app-heading"><?= e($s['name']) ?></strong>
          · <a class="text-accent" href="mailto:<?= e($s['email']) ?>"><?= e($s['email']) ?></a>
        </p>
        <p class="text-sm mt-2" style="color:var(--text-body)"><?= nl2br(e($s['message'])) ?></p>
        <p class="text-xs app-muted mt-2"><?= e($s['created_at']) ?></p>
      </div>
    </div>

    <div class="mt-4 flex flex-wrap gap-2 items-center">
      <?= contactPhoneButtons($phone, $waText) ?>
      <a class="contact-btn contact-btn-call contact-btn-sm" href="<?= e($mailHref) ?>">✉ Email</a>
    </div>

    <div class="mt-3 flex flex-wrap gap-2">
      <?php if ($s['status'] !== 'read'): ?>
      <form method="POST" class="inline">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="status">
        <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
        <input type="hidden" name="status" value="read">
        <button class="btn-secondary">Mark Read</button>
      </form>
      <?php endif; ?>
      <?php if ($s['status'] !== 'replied'): ?>
      <form method="POST" class="inline">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="status">
        <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
        <input type="hidden" name="status" value="replied">
        <button class="btn-success">Mark Replied</button>
      </form>
      <?php endif; ?>
      <?php if ($s['status'] !== 'new'): ?>
      <form method="POST" class="inline">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="status">
        <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
        <input type="hidden" name="status" value="new">
        <button class="btn-warning">Reopen</button>
      </form>
      <?php endif; ?>
      <form method="POST" class="inline" onsubmit="return confirm('Delete this inquiry?')">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
        <button class="btn-danger">Delete</button>
      </form>
    </div>
  </div>
  <?php endforeach; ?>
  </div>
</div>
<?php renderFooter(); ?>
