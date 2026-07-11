<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
$user = requireRole('admin');
$db = getDB();
ensureSchemaUpdates();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf(baseUrl('admin/inventory.php'));
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id = (int) ($_POST['id'] ?? 0) ?: null;
        $result = InventoryService::savePart([
            'name'       => $_POST['name'] ?? '',
            'sku'        => $_POST['sku'] ?? '',
            'category'   => $_POST['category'] ?? 'General',
            'unit_price' => $_POST['unit_price'] ?? 0,
            'cost_price' => $_POST['cost_price'] ?? 0,
            'stock_qty'  => $_POST['stock_qty'] ?? 0,
            'min_stock'  => $_POST['min_stock'] ?? 5,
            'supplier'   => $_POST['supplier'] ?? '',
            'is_active'  => isset($_POST['is_active']) ? 1 : 0,
        ], $id);
        flash($result['ok'] ? 'success' : 'error', $result['ok']
            ? 'Part saved. / Alat ganti disimpan.'
            : ($result['error'] ?? 'Save failed.'));
    } elseif ($action === 'adjust') {
        $partId = (int) ($_POST['part_id'] ?? 0);
        $delta = (int) ($_POST['delta'] ?? 0);
        $reason = trim($_POST['reason'] ?? 'Manual adjustment');
        $type = $delta >= 0 ? 'in' : 'out';
        if ($delta === 0) {
            flash('error', 'Adjustment quantity cannot be zero. / Kuantiti pelarasan tidak boleh sifar.');
        } else {
            $result = InventoryService::adjustStock($partId, $delta, $reason, (int) $user['id'], $type === 'in' ? 'in' : 'out');
            flash($result['ok'] ? 'success' : 'error', $result['ok']
                ? 'Stock updated. / Stok dikemas kini.'
                : ($result['error'] ?? 'Failed.'));
        }
    } elseif ($action === 'toggle') {
        $id = (int) ($_POST['id'] ?? 0);
        $part = InventoryService::getPart($id);
        if ($part) {
            InventoryService::savePart(array_merge($part, ['is_active' => $part['is_active'] ? 0 : 1]), $id);
            flash('success', 'Part status updated. / Status dikemas kini.');
        }
    }
    redirect(baseUrl('admin/inventory.php'));
}

$parts = InventoryService::listParts(false);
$lowStock = InventoryService::lowStockParts();
$margin = InventoryService::partsMarginSummary(date('Y-m-01'), date('Y-m-t'));
$editId = (int) ($_GET['edit'] ?? 0);
$edit = $editId ? InventoryService::getPart($editId) : null;

renderHeader('Parts & Inventory', $user, 'inventory');
?>
<h2 class="section-title">Parts & Inventory / Inventori Alat Ganti</h2>
<p class="section-subtitle">Track stock, costs, and markup — critical workshop revenue / Jejak stok, kos dan markup</p>

<?php if (!empty($lowStock)): ?>
<div class="mb-4 p-3 rounded-lg flash-error text-sm">
  <strong>Low stock alert / Amaran stok rendah:</strong>
  <?= e(implode(', ', array_map(fn($p) => $p['name'] . ' (' . $p['stock_qty'] . ')', $lowStock))) ?>
</div>
<?php endif; ?>

<div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
  <div class="stat-card">
    <p class="text-xs app-muted">Parts SKUs</p>
    <p class="text-2xl font-bold text-white"><?= count($parts) ?></p>
  </div>
  <div class="stat-card">
    <p class="text-xs app-muted">Parts revenue (month)</p>
    <p class="text-2xl font-bold text-emerald-400"><?= formatRM($margin['parts_revenue']) ?></p>
  </div>
  <div class="stat-card">
    <p class="text-xs app-muted">Parts margin (month)</p>
    <p class="text-2xl font-bold text-accent"><?= formatRM($margin['parts_margin']) ?></p>
  </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
  <div class="panel-card p-5 lg:col-span-1">
    <h3 class="font-semibold mb-3"><?= $edit ? 'Edit Part' : 'Add Part' ?> / <?= $edit ? 'Sunting' : 'Tambah' ?></h3>
    <form method="POST" class="space-y-3">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="save">
      <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>
      <div><label class="text-xs text-slate-400">Name *</label>
        <input class="form-input" name="name" required value="<?= e($edit['name'] ?? '') ?>"></div>
      <div><label class="text-xs text-slate-400">SKU</label>
        <input class="form-input" name="sku" value="<?= e($edit['sku'] ?? '') ?>"></div>
      <div><label class="text-xs text-slate-400">Category</label>
        <input class="form-input" name="category" value="<?= e($edit['category'] ?? 'General') ?>"></div>
      <div class="grid grid-cols-2 gap-2">
        <div><label class="text-xs text-slate-400">Sell price (RM)</label>
          <input class="form-input" type="number" step="0.01" min="0" name="unit_price" value="<?= e((string)($edit['unit_price'] ?? '0')) ?>"></div>
        <div><label class="text-xs text-slate-400">Cost (RM)</label>
          <input class="form-input" type="number" step="0.01" min="0" name="cost_price" value="<?= e((string)($edit['cost_price'] ?? '0')) ?>"></div>
      </div>
      <div class="grid grid-cols-2 gap-2">
        <div><label class="text-xs text-slate-400">Stock qty</label>
          <input class="form-input" type="number" name="stock_qty" value="<?= e((string)($edit['stock_qty'] ?? '0')) ?>"></div>
        <div><label class="text-xs text-slate-400">Min stock</label>
          <input class="form-input" type="number" name="min_stock" value="<?= e((string)($edit['min_stock'] ?? '5')) ?>"></div>
      </div>
      <div><label class="text-xs text-slate-400">Supplier</label>
        <input class="form-input" name="supplier" value="<?= e($edit['supplier'] ?? '') ?>"></div>
      <label class="flex items-center gap-2 text-sm">
        <input type="checkbox" name="is_active" value="1" <?= !$edit || !empty($edit['is_active']) ? 'checked' : '' ?>>
        Active / Aktif
      </label>
      <button type="submit" class="btn-primary w-full"><?= $edit ? 'Update' : 'Add Part' ?></button>
      <?php if ($edit): ?>
      <a href="<?= baseUrl('admin/inventory.php') ?>" class="btn-secondary w-full text-center block">Cancel edit</a>
      <?php endif; ?>
    </form>

    <hr class="my-5 border-slate-700">
    <h3 class="font-semibold mb-3">Adjust Stock / Laras Stok</h3>
    <form method="POST" class="space-y-3">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="adjust">
      <div><label class="text-xs text-slate-400">Part</label>
        <select class="form-input" name="part_id" required>
          <?php foreach ($parts as $p): if (!$p['is_active']) continue; ?>
          <option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?> (<?= (int)$p['stock_qty'] ?>)</option>
          <?php endforeach; ?>
        </select></div>
      <div><label class="text-xs text-slate-400">Qty (+ in / − out)</label>
        <input class="form-input" type="number" name="delta" required placeholder="e.g. 10 or -2"></div>
      <div><label class="text-xs text-slate-400">Reason</label>
        <input class="form-input" name="reason" placeholder="Restock / damaged / etc."></div>
      <button type="submit" class="btn-secondary w-full">Apply Adjustment</button>
    </form>
  </div>

  <div class="panel-card p-5 lg:col-span-2">
    <div class="table-scroll">
      <table class="data-table">
        <thead>
          <tr>
            <th>Part</th><th>SKU</th><th>Category</th><th>Sell</th><th>Cost</th><th>Stock</th><th>Margin</th><th>Status</th><th></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($parts as $p):
          $marginUnit = (float)$p['unit_price'] - (float)$p['cost_price'];
          $low = (int)$p['stock_qty'] <= (int)$p['min_stock'];
        ?>
          <tr class="<?= $low ? 'row-alert' : '' ?>">
            <td><strong><?= e($p['name']) ?></strong>
              <?php if ($p['supplier']): ?><br><span class="text-xs text-slate-500"><?= e($p['supplier']) ?></span><?php endif; ?>
            </td>
            <td class="text-xs"><?= e($p['sku'] ?? '—') ?></td>
            <td><?= e($p['category']) ?></td>
            <td class="text-emerald-400"><?= formatRM($p['unit_price']) ?></td>
            <td><?= formatRM($p['cost_price']) ?></td>
            <td>
              <strong class="<?= $low ? 'text-red-400' : '' ?>"><?= (int)$p['stock_qty'] ?></strong>
              <span class="text-xs text-slate-500">/ min <?= (int)$p['min_stock'] ?></span>
            </td>
            <td class="text-accent text-xs"><?= formatRM($marginUnit) ?></td>
            <td><?= $p['is_active'] ? '<span class="badge badge-approved">Active</span>' : '<span class="badge badge-cancelled">Off</span>' ?></td>
            <td class="whitespace-nowrap">
              <a href="?edit=<?= (int)$p['id'] ?>" class="btn-secondary" style="padding:.25rem .5rem;font-size:.7rem">Edit</a>
              <form method="POST" class="inline">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                <button class="btn-secondary" style="padding:.25rem .5rem;font-size:.7rem"><?= $p['is_active'] ? 'Disable' : 'Enable' ?></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($parts)): ?>
          <tr><td colspan="9" class="empty-state">No parts yet. Add your first SKU.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php renderFooter(); ?>
