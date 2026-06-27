<?php
/**
 * _expenses_widget.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Include this block inside any admin page (walkin.php, or a dashboard) so
 * both owners and cashiers can log today's business expenses quickly.
 *
 * Usage: <?php require_once 'expenses_widget.php'; ?>
 *
 * Requires: $conn (DB), session with user_id, config.php already loaded.
 * ─────────────────────────────────────────────────────────────────────────────
 */

// ── Handle ADD business expense ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['widget_add_expense'])) {
    // Guard: block saves when called from a locked daily report
    if (!empty($GLOBALS['daily_report_locked'])) {
        $GLOBALS['widget_msg']      = '🔒 Report is locked. Expense not saved.';
        $GLOBALS['widget_msg_type'] = 'warning';
        goto skip_save_expense;
    }
    $category = sanitize_input($_POST['we_category'] ?? 'misc');
    $label    = sanitize_input($_POST['we_label']    ?? '');
    $amount   = floatval($_POST['we_amount']         ?? 0);
    $notes    = sanitize_input($_POST['we_notes']    ?? '');
    $added_by = $_SESSION['user_id'] ?? null;

    // Identify who entered it (by PIN if cashier, by user_id if owner)
    $pin_used = null;
    if (isset($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'cashier') {
        $entered_pin = trim($_POST['we_pin'] ?? '');
        // Verify PIN matches this cashier
        $pin_check = $conn->prepare("SELECT id FROM users WHERE id=? AND cashier_pin=?");
        $pin_check->bind_param("is", $added_by, $entered_pin);
        $pin_check->execute();
        if ($pin_check->get_result()->num_rows > 0) {
            $pin_used = $entered_pin;
        } else {
            $GLOBALS['widget_msg']      = '⚠️ Incorrect PIN. Expense not saved.';
            $GLOBALS['widget_msg_type'] = 'danger';
            goto skip_save_expense;
        }
        $pin_check->close();
    }

    if ($amount > 0 && !empty($label)) {
        $stmt = $conn->prepare("
            INSERT INTO business_expenses
                (expense_date, category, label, amount, notes, added_by, verified_by_pin)
            VALUES (CURDATE(), ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("ssdssi", $category, $label, $amount, $notes, $added_by, $pin_used);
        $ok = $stmt->execute(); $stmt->close();
        $GLOBALS['widget_msg']      = $ok ? "✅ Expense recorded." : "DB error.";
        $GLOBALS['widget_msg_type'] = $ok ? "success" : "danger";
    } else {
        $GLOBALS['widget_msg']      = "Amount and description required.";
        $GLOBALS['widget_msg_type'] = "danger";
    }
    skip_save_expense:;
}

// ── Handle DELETE business expense ────────────────────────────────────────────
if (isset($_GET['del_expense']) && is_owner()) {
    $did  = intval($_GET['del_expense']);
    $stmt = $conn->prepare("DELETE FROM business_expenses WHERE id=? AND expense_date=CURDATE()");
    $stmt->bind_param("i", $did); $stmt->execute(); $stmt->close();
    // Redirect back to same page
    header("Location: " . strtok($_SERVER['REQUEST_URI'], '?')); exit();
}

// ── Fetch today's business expenses ──────────────────────────────────────────
$today_expenses = $conn->query("
    SELECT be.*, u.full_name AS by_name, u.admin_role AS by_role
    FROM business_expenses be
    LEFT JOIN users u ON be.added_by = u.id
    WHERE be.expense_date = CURDATE()
    ORDER BY be.created_at DESC
")->fetch_all(MYSQLI_ASSOC);
$today_exp_total = array_sum(array_column($today_expenses, 'amount'));

$biz_categories = ['water','laundry','supplies','utilities','food','transport','maintenance','misc'];
$cat_icons      = ['water'=>'💧','laundry'=>'🧺','supplies'=>'🛒','utilities'=>'💡',
                   'food'=>'🍱','transport'=>'🚗','maintenance'=>'🔧','misc'=>'📦'];
?>

<!-- ── BUSINESS EXPENSES WIDGET ───────────────────────────────────────────── -->
<div class="panel" style="margin-top:1.5rem;" id="expenses-widget">

    <div class="panel-header">
        <span class="panel-title">🧾 Today's Business Expenses</span>
        <span style="background:var(--rust);color:#fff;font-size:0.72rem;padding:0.2rem 0.65rem;border-radius:20px;font-weight:700;">
            ₱<?php echo number_format($today_exp_total, 2); ?> today
        </span>
    </div>

    <?php if (!empty($GLOBALS['widget_msg'])): ?>
    <div class="alert alert-<?php echo $GLOBALS['widget_msg_type']; ?>"
         style="margin:0.75rem 1rem 0;"><?php echo $GLOBALS['widget_msg']; ?></div>
    <?php endif; ?>

    <div class="panel-body" style="padding:1rem;">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;">

            <!-- Add form -->
            <div>
                <div style="font-size:0.78rem;font-weight:700;color:var(--brown);margin-bottom:0.65rem;">
                    ➕ Log an Expense
                </div>
                <form method="POST">
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.5rem;margin-bottom:0.5rem;">
                        <div>
                            <label style="font-size:0.72rem;color:var(--gray);display:block;margin-bottom:3px;">Category</label>
                            <select name="we_category"
                                    style="width:100%;padding:0.4rem 0.6rem;border:1px solid var(--border2);border-radius:7px;background:var(--bg3);color:var(--brown);font-size:0.82rem;">
                                <?php foreach ($biz_categories as $cat): ?>
                                <option value="<?php echo $cat; ?>"><?php echo ($cat_icons[$cat]??'📦').' '.ucfirst($cat); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label style="font-size:0.72rem;color:var(--gray);display:block;margin-bottom:3px;">Amount (₱) *</label>
                            <input type="number" name="we_amount" step="0.01" min="0.01" required placeholder="0.00"
                                   style="width:100%;padding:0.4rem 0.6rem;border:1px solid var(--border2);border-radius:7px;background:var(--bg3);color:var(--brown);font-size:0.82rem;box-sizing:border-box;">
                        </div>
                    </div>
                    <div style="margin-bottom:0.5rem;">
                        <label style="font-size:0.72rem;color:var(--gray);display:block;margin-bottom:3px;">Description *</label>
                        <input type="text" name="we_label" required placeholder="e.g. 1 water jug refill, laundry load"
                               style="width:100%;padding:0.4rem 0.6rem;border:1px solid var(--border2);border-radius:7px;background:var(--bg3);color:var(--brown);font-size:0.82rem;box-sizing:border-box;">
                    </div>
                    <div style="margin-bottom:0.5rem;">
                        <label style="font-size:0.72rem;color:var(--gray);display:block;margin-bottom:3px;">Notes</label>
                        <input type="text" name="we_notes" placeholder="Optional"
                               style="width:100%;padding:0.4rem 0.6rem;border:1px solid var(--border2);border-radius:7px;background:var(--bg3);color:var(--brown);font-size:0.82rem;box-sizing:border-box;">
                    </div>

                    <?php if (isset($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'cashier'): ?>
                    <!-- PIN confirmation for cashier accountability -->
                    <div style="margin-bottom:0.75rem;padding:0.65rem;background:rgba(0,112,243,0.07);
                                border:1px solid rgba(0,112,243,0.2);border-radius:8px;">
                        <label style="font-size:0.72rem;color:#0070f3;font-weight:700;display:block;margin-bottom:4px;">
                            🔐 Enter your 4-digit PIN to confirm
                        </label>
                        <input type="password" name="we_pin" maxlength="4" pattern="\d{4}" required
                               inputmode="numeric" placeholder="••••"
                               style="width:80px;padding:0.4rem 0.6rem;border:1px solid rgba(0,112,243,0.3);border-radius:7px;
                                      background:var(--bg3);color:var(--brown);font-size:1rem;text-align:center;letter-spacing:0.25em;">
                        <div style="font-size:0.68rem;color:var(--gray);margin-top:3px;">Your PIN identifies you as the person who recorded this expense.</div>
                    </div>
                    <?php endif; ?>

                    <button type="submit" name="widget_add_expense" class="btn btn-primary btn-sm" style="width:100%;font-size:0.82rem;">
                        ➕ Add Expense
                    </button>
                </form>
            </div>

            <!-- Today's list -->
            <div>
                <div style="font-size:0.78rem;font-weight:700;color:var(--brown);margin-bottom:0.65rem;">
                    📋 Today's Log (<?php echo date('M d, Y'); ?>)
                </div>
                <?php if (empty($today_expenses)): ?>
                <div style="text-align:center;padding:1.5rem;color:var(--gray);font-size:0.82rem;
                            background:var(--bg3);border-radius:8px;border:1px solid var(--border2);">
                    <div style="font-size:1.5rem;margin-bottom:0.3rem;">🧾</div>No expenses yet today.
                </div>
                <?php else: ?>
                <div style="display:flex;flex-direction:column;gap:0.35rem;max-height:260px;overflow-y:auto;">
                    <?php foreach ($today_expenses as $e): ?>
                    <div style="display:flex;align-items:center;gap:0.5rem;padding:0.4rem 0.6rem;
                                background:var(--bg3);border-radius:7px;border:1px solid var(--border2);">
                        <span style="font-size:0.9rem;flex-shrink:0;"><?php echo $cat_icons[$e['category']] ?? '📦'; ?></span>
                        <div style="flex:1;min-width:0;">
                            <div style="font-size:0.82rem;font-weight:600;color:var(--brown);
                                        white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                <?php echo htmlspecialchars($e['label']); ?>
                            </div>
                            <div style="font-size:0.68rem;color:var(--gray);">
                                <?php echo ucfirst($e['category']); ?>
                                &nbsp;·&nbsp;by <?php echo htmlspecialchars($e['by_name'] ?? '?'); ?>
                                <?php if ($e['verified_by_pin']): ?>
                                <span style="color:#0070f3;">🔐 PIN verified</span>
                                <?php endif; ?>
                                &nbsp;·&nbsp;<?php echo date('h:i A', strtotime($e['created_at'])); ?>
                            </div>
                        </div>
                        <span style="font-weight:700;color:var(--rust);font-size:0.85rem;white-space:nowrap;">
                            ₱<?php echo number_format($e['amount'],2); ?>
                        </span>
                        <?php if (is_owner()): ?>
                        <a href="?del_expense=<?php echo $e['id']; ?>"
                           style="color:var(--red);font-size:0.75rem;text-decoration:none;padding:0.1rem 0.3rem;
                                  border-radius:4px;border:1px solid var(--red);flex-shrink:0;"
                           onclick="return confirm('Delete this expense?')">✕</a>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div style="margin-top:0.5rem;text-align:right;font-size:0.82rem;font-weight:700;color:var(--rust);">
                    Total: ₱<?php echo number_format($today_exp_total, 2); ?>
                </div>
                <?php endif; ?>
            </div>

        </div>
    </div>
</div>