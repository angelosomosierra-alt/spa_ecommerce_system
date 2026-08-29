<?php
require_once '../config.php';
require_once __DIR__ . '/admin_access.php';
enforce_page_access();

$page_title  = 'Help / Guide';
$page_icon   = '❓';
$active_page = 'help';
require_once __DIR__ . '/admin_header.php';
?>

<style>
/* ── Help page layout ──────────────────────────────────────────────────── */
.help-wrap {
    max-width: 860px;
    margin: 0 auto;
}

/* ── Search bar ─────────────────────────────────────────────────────────── */
.help-search-wrap {
    position: relative;
    margin-bottom: 1.75rem;
}
.help-search {
    width: 100%;
    padding: 0.78rem 1rem 0.78rem 2.6rem;
    border: 2px solid var(--border2);
    border-radius: 10px;
    font-size: 0.9rem;
    background: var(--bg3);
    color: var(--brown);
    box-sizing: border-box;
    font-family: inherit;
    transition: border-color 0.18s, box-shadow 0.18s;
}
.help-search:focus {
    outline: none;
    border-color: var(--gold);
    box-shadow: 0 0 0 3px rgba(201,106,44,0.12);
}
.help-search-icon {
    position: absolute;
    left: 0.8rem;
    top: 50%;
    transform: translateY(-50%);
    font-size: 1rem;
    pointer-events: none;
    opacity: 0.5;
}

/* ── Section dividers ───────────────────────────────────────────────────── */
.help-section-label {
    display: flex;
    align-items: center;
    gap: 0.65rem;
    font-size: 0.68rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.12em;
    color: var(--gold);
    margin: 1.75rem 0 0.6rem;
    padding-bottom: 0.4rem;
    border-bottom: 2px solid var(--border2);
}
.help-section-label:first-child { margin-top: 0; }
.help-section-label span { font-size: 1rem; }

/* ── Accordion topics ───────────────────────────────────────────────────── */
.help-topic {
    border: 1px solid var(--border2);
    border-radius: 10px;
    margin-bottom: 0.45rem;
    overflow: hidden;
    background: var(--white);
    transition: box-shadow 0.2s, border-color 0.2s;
}
.help-topic:hover { box-shadow: 0 2px 12px rgba(59,42,26,0.07); }
.help-topic.open  {
    border-color: rgba(201,106,44,0.5);
    box-shadow: 0 3px 18px rgba(201,106,44,0.1);
}

.help-topic-header {
    display: flex;
    align-items: center;
    gap: 0.85rem;
    padding: 0.8rem 1.1rem;
    cursor: pointer;
    user-select: none;
    background: var(--bg3);
    transition: background 0.15s;
}
.help-topic-header:hover { background: #ede0d2; }
.help-topic.open .help-topic-header {
    background: linear-gradient(135deg, #3B2A1A 0%, #5a4030 100%);
}

.help-topic-num {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 24px;
    height: 24px;
    border-radius: 50%;
    background: var(--gold);
    color: #fff;
    font-size: 0.7rem;
    font-weight: 800;
    flex-shrink: 0;
    line-height: 1;
}
.help-topic.open .help-topic-num {
    background: rgba(255,255,255,0.22);
}

.help-topic-title {
    flex: 1;
    font-size: 0.88rem;
    font-weight: 700;
    color: var(--brown);
}
.help-topic.open .help-topic-title { color: #FAF3E8; }

.help-topic-arrow {
    font-size: 0.72rem;
    color: var(--gray);
    transition: transform 0.25s cubic-bezier(0.4,0,0.2,1);
    flex-shrink: 0;
}
.help-topic.open .help-topic-arrow {
    transform: rotate(90deg);
    color: rgba(255,255,255,0.65);
}

/* ── Collapsible body ───────────────────────────────────────────────────── */
.help-topic-body {
    max-height: 0;
    overflow: hidden;
    transition: max-height 0.38s cubic-bezier(0.4,0,0.2,1);
}
.help-topic.open .help-topic-body { max-height: 3000px; }

.help-body-inner {
    padding: 1.1rem 1.3rem 1.3rem;
    border-top: 1px solid var(--border2);
    font-size: 0.85rem;
    line-height: 1.75;
    color: #3a3028;
}
.help-body-inner p { margin: 0 0 0.7rem; }
.help-body-inner p:last-child { margin-bottom: 0; }
.help-body-inner ul {
    margin: 0.25rem 0 0.75rem 1.25rem;
    padding: 0;
}
.help-body-inner li { margin-bottom: 0.28rem; }
.help-body-inner strong { color: var(--brown); }

/* ── Tip / callout boxes ────────────────────────────────────────────────── */
.help-tip {
    background: #fff8f0;
    border: 1px solid rgba(201,106,44,0.22);
    border-left: 3px solid var(--gold);
    border-radius: 7px;
    padding: 0.6rem 0.9rem;
    margin: 0.65rem 0;
    font-size: 0.82rem;
    color: #5a3318;
    line-height: 1.6;
}
.help-tip strong { color: #8B4513; }

.help-note {
    background: #f0f6ff;
    border: 1px solid #c0d4f0;
    border-left: 3px solid #4a90d9;
    border-radius: 7px;
    padding: 0.6rem 0.9rem;
    margin: 0.65rem 0;
    font-size: 0.82rem;
    color: #1a3a5c;
    line-height: 1.6;
}

/* ── Status pills ───────────────────────────────────────────────────────── */
.hs { display:inline-block; padding:0.08rem 0.5rem; border-radius:20px; font-size:0.72rem; font-weight:700; }
.hs-pending   { background:#fef9f0; color:#92400e; border:1px solid #f59e0b55; }
.hs-assigned  { background:#f0f4ff; color:#1d4ed8; border:1px solid #3b82f655; }
.hs-approved  { background:#f0fff4; color:#166534; border:1px solid #16a34a55; }
.hs-completed { background:#f3f4f6; color:#374151; border:1px solid #9ca3af55; }
.hs-declined  { background:#fff0f0; color:#991b1b; border:1px solid #ef444455; }
.hs-cancelled { background:#fafafa; color:#6b7280; border:1px solid #d1d5db; }
.hs-checkin   { background:#fffbeb; color:#92400e; border:1px solid #d97706; }

/* ── Sub-heading inside body ────────────────────────────────────────────── */
.help-sub {
    font-size: 0.78rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.07em;
    color: var(--gold);
    margin: 1rem 0 0.35rem;
}
.help-sub:first-child { margin-top: 0; }

/* ── No results ─────────────────────────────────────────────────────────── */
#helpNoResults {
    display: none;
    text-align: center;
    padding: 3rem 1rem;
    color: var(--gray);
    font-size: 0.9rem;
}
</style>

<div class="help-wrap">

    <!-- Page header -->
    <div class="page-header" style="margin-bottom:1.5rem;">
        <div>
            <h1 style="font-size:1.4rem;font-weight:800;color:var(--brown);margin:0 0 0.2rem;">❓ Help & Admin Guide</h1>
            <p style="font-size:0.85rem;color:var(--gray);margin:0;">
                Everything you need to know about using the Recovery Iloilo admin panel.
                Search below or click any topic to expand it.
            </p>
        </div>
    </div>

    <!-- Search -->
    <div class="help-search-wrap">
        <span class="help-search-icon">🔍</span>
        <input type="text" class="help-search" id="helpSearch"
               placeholder="Search topics… (e.g. commission, walk-in, daily report)"
               oninput="filterHelp(this.value)"
               autocomplete="off">
    </div>

    <!-- No results -->
    <div id="helpNoResults">
        <div style="font-size:2rem;margin-bottom:0.75rem;">🔍</div>
        <strong>No topics match your search.</strong><br>
        Try a different keyword, or <a href="#" onclick="document.getElementById('helpSearch').value='';filterHelp('');return false;" style="color:var(--gold);">clear the search</a>.
    </div>

    <!-- ══════════════════════════════════════════════════════════
         SECTION 1: GETTING STARTED
    ══════════════════════════════════════════════════════════ -->
    <div class="help-section-label"><span>🚀</span> Getting Started</div>

    <!-- Topic 1 -->
    <div class="help-topic" onclick="toggleHelp(this)">
        <div class="help-topic-header">
            <span class="help-topic-num">1</span>
            <span class="help-topic-title">Logging In &amp; Roles</span>
            <span class="help-topic-arrow">▸</span>
        </div>
        <div class="help-topic-body"><div class="help-body-inner">
            <div class="help-sub">How to Log In</div>
            <p>Go to <strong>admin/admin_login.php</strong> and enter your username and password. If your account has a login time restriction, you will only be able to log in during the allowed hours.</p>

            <div class="help-sub">The 4 Admin Roles</div>
            <ul>
                <li><strong>👑 Owner</strong> — Full access to everything including settings, staff management, and all reports.</li>
                <li><strong>💻 IT Support</strong> — Full access to almost everything; cannot modify owner-level settings like receptionist time restrictions.</li>
                <li><strong>📣 Marketing</strong> — Can view appointments, analytics, daily reports, discounts, and vouchers. Cannot manage staff, services, or products.</li>
                <li><strong>🏪 Receptionist (Cashier)</strong> — Can manage appointments (Kanban), create walk-in bookings, and file daily reports. Cannot access staff, services, analytics, or financial settings.</li>
            </ul>

            <div class="help-sub">Receptionist Login Restrictions</div>
            <p>Owners can set allowed login hours for receptionists from <strong>Staff → Receptionist Settings</strong>. If a receptionist tries to log in outside those hours, access is denied. Contact the owner if you need access during off-hours.</p>

            <div class="help-sub">Forgot Your Password?</div>
            <p>Passwords cannot be self-reset. Ask the <strong>Owner</strong> or <strong>IT</strong> to go to <strong>Staff → 🔑 Reset Password</strong> next to your account. They set a new temporary password and tell it to you directly. Your old password stops working immediately.</p>

            <div class="help-tip">💡 <strong>Tip:</strong> Owners can also reset passwords for receptionists from the Receptionist Accounts tab. IT can reset cashier, marketing, and IT accounts.</div>
        </div></div>
    </div>

    <!-- Topic 2 -->
    <div class="help-topic" onclick="toggleHelp(this)">
        <div class="help-topic-header">
            <span class="help-topic-num">2</span>
            <span class="help-topic-title">Dashboard Overview</span>
            <span class="help-topic-arrow">▸</span>
        </div>
        <div class="help-topic-body"><div class="help-body-inner">
            <div class="help-sub">What the Dashboard Shows</div>
            <ul>
                <li><strong>Today's appointment stats</strong> — count of pending, assigned, approved, completed, and declined appointments for today.</li>
                <li><strong>Quick-action buttons</strong> — shortcuts to Walk-in, Appointments, Daily Report, and other frequently used pages.</li>
                <li><strong>Upcoming appointments</strong> — appointments scheduled in the near future that need attention.</li>
                <li><strong>Recent activity</strong> — latest actions taken in the system.</li>
            </ul>

            <div class="help-sub">Notification Bell 🔔</div>
            <p>The bell icon in the top-right corner updates automatically every <strong>3 seconds</strong> — there is no need to refresh the page. A red badge shows the number of unread notifications. Click the bell to open the panel and see details. Click <strong>Mark all read</strong> to clear the badge.</p>

            <div class="help-tip">💡 <strong>Tip:</strong> Keep the dashboard open as your main tab — it gives you the fastest overview of what needs attention today.</div>
        </div></div>
    </div>

    <!-- Topic 3 -->
    <div class="help-topic" onclick="toggleHelp(this)">
        <div class="help-topic-header">
            <span class="help-topic-num">3</span>
            <span class="help-topic-title">Navigation &amp; Sidebar</span>
            <span class="help-topic-arrow">▸</span>
        </div>
        <div class="help-topic-body"><div class="help-body-inner">
            <div class="help-sub">Using the Sidebar</div>
            <p>The left sidebar lists all pages you have access to. Click any item to navigate. The currently active page is highlighted with a brown background.</p>

            <div class="help-sub">On Mobile / Small Screens</div>
            <p>The sidebar hides automatically on phones and tablets. Tap the <strong>☰ hamburger button</strong> (top-left) to open it, and tap again or anywhere outside to close it. All the same pages are accessible — just behind the menu.</p>

            <div class="help-sub">Pages Depend on Your Role</div>
            <p>The sidebar only shows pages your role can access. Receptionists see fewer items than owners. If you need access to a page that isn't visible, ask the owner to elevate your permissions or create an IT account for you.</p>

            <div class="help-note">ℹ️ Typing a URL directly to a page you don't have access to will redirect you to the Appointments page with an "Access Denied" notice.</div>
        </div></div>
    </div>

    <!-- ══════════════════════════════════════════════════════════
         SECTION 2: APPOINTMENTS & BOOKINGS
    ══════════════════════════════════════════════════════════ -->
    <div class="help-section-label"><span>📅</span> Appointments &amp; Bookings</div>

    <!-- Topic 4 -->
    <div class="help-topic" onclick="toggleHelp(this)">
        <div class="help-topic-header">
            <span class="help-topic-num">4</span>
            <span class="help-topic-title">Understanding Appointment Statuses</span>
            <span class="help-topic-arrow">▸</span>
        </div>
        <div class="help-topic-body"><div class="help-body-inner">
            <p>Every appointment moves through a lifecycle of statuses:</p>
            <ul>
                <li><span class="hs hs-pending">⏳ Pending</span> — The customer has booked online and is waiting for admin action. Requires you to assign a therapist or decline.</li>
                <li><span class="hs hs-assigned">💆 Assigned</span> — A therapist has been assigned. The appointment is confirmed and waiting for the appointment day.</li>
                <li><span class="hs hs-approved">✅ Approved</span> — The appointment has been approved (same as Assigned in most flows).</li>
                <li><span class="hs hs-checkin">🟠 Checked In</span> — The customer has arrived at the spa. The session is in progress.</li>
                <li><span class="hs hs-completed">🎉 Completed</span> — The service is done and payment has been collected. This creates the commission and daily-report entries.</li>
                <li><span class="hs hs-declined">❌ Declined</span> — An admin rejected the booking, typically because of unavailability. A reason must be entered; the customer is notified.</li>
                <li><span class="hs hs-cancelled">🚫 Cancelled</span> — Either the customer or an admin cancelled the appointment. A reason is required.</li>
                <li><span class="hs hs-cancelled">👻 No Show</span> — The customer did not arrive for their scheduled appointment.</li>
            </ul>
            <div class="help-tip">💡 Only <strong>Completed</strong> appointments appear in the Daily Report spreadsheet and count toward therapist commissions.</div>
        </div></div>
    </div>

    <!-- Topic 5 -->
    <div class="help-topic" onclick="toggleHelp(this)">
        <div class="help-topic-header">
            <span class="help-topic-num">5</span>
            <span class="help-topic-title">Managing Appointments (Kanban Board)</span>
            <span class="help-topic-arrow">▸</span>
        </div>
        <div class="help-topic-body"><div class="help-body-inner">
            <div class="help-sub">The Three Kanban Columns</div>
            <ul>
                <li><strong>Pending</strong> — New appointments waiting for a therapist assignment.</li>
                <li><strong>Assigned / Approved</strong> — Confirmed appointments with a therapist set.</li>
                <li><strong>Checked In</strong> — Customers currently in the spa.</li>
            </ul>
            <p>Below the three columns is the <strong>History</strong> section showing Completed, Declined, Cancelled, and No Show appointments.</p>

            <div class="help-sub">Date Filter</div>
            <p>Use the date bar at the top to show appointments for <strong>Today</strong>, <strong>Tomorrow</strong>, a specific calendar date, or <strong>All dates</strong>. The default is Today.</p>

            <div class="help-sub">Search / Filter Bar</div>
            <p>Type a customer name, service name, or therapist name into the search field to filter the visible cards in real time. The Kanban columns update immediately as you type.</p>

            <div class="help-sub">🆕 New Appointment Banner</div>
            <p>If a new booking comes in while you're on the Appointments page, an orange banner appears at the top: <em>"🆕 New appointment"</em>. Click the banner to refresh the board and see the new card in the Pending column.</p>

            <div class="help-sub">Expanding a Card</div>
            <p>Each appointment card shows a summary. Click the card body (outside the action buttons) to expand the full details: customer info, service, therapist, pricing, notes, and action history.</p>
        </div></div>
    </div>

    <!-- Topic 6 -->
    <div class="help-topic" onclick="toggleHelp(this)">
        <div class="help-topic-header">
            <span class="help-topic-num">6</span>
            <span class="help-topic-title">Assigning Therapists</span>
            <span class="help-topic-arrow">▸</span>
        </div>
        <div class="help-topic-body"><div class="help-body-inner">
            <p>Click <strong>Assign</strong> on a Pending appointment card. A modal opens where you pick a therapist from the available list.</p>

            <div class="help-sub">Conflict Detection</div>
            <p>The system automatically checks for time conflicts. If the therapist you selected is already booked at an overlapping time, the assignment is blocked with a warning showing the conflicting appointment's details. Choose a different therapist or a different time.</p>

            <div class="help-sub">Multi-Person Appointments</div>
            <p>If the booking is for more than one person, you assign a therapist <strong>per person</strong>. Each person slot appears separately. You can assign the same therapist to multiple slots only if their schedule allows it (no overlap on the same timeslot).</p>

            <div class="help-sub">Home-Service Travel Buffer</div>
            <p>For <strong>Home Service</strong> appointments, the system adds a <strong>30-minute travel buffer</strong> after the session end time. This means a therapist assigned to a 2-hour home service at 10 AM is considered unavailable until 12:30 PM (2 hrs + 30 min buffer) for conflict-checking purposes.</p>

            <div class="help-tip">💡 <strong>Tip:</strong> Keep the therapist's specialty services up to date in the Therapists page so only qualified therapists appear in the assignment list for each service.</div>
        </div></div>
    </div>

    <!-- Topic 7 -->
    <div class="help-topic" onclick="toggleHelp(this)">
        <div class="help-topic-header">
            <span class="help-topic-num">7</span>
            <span class="help-topic-title">Editing &amp; Reassigning Appointments</span>
            <span class="help-topic-arrow">▸</span>
        </div>
        <div class="help-topic-body"><div class="help-body-inner">
            <div class="help-sub">Editing an Appointment</div>
            <p>Click the <strong>✏️ Edit</strong> button on an appointment card. You can change the appointment date/time and the assigned therapist. Click Save to confirm.</p>

            <div class="help-sub">Conflict Check on Edit</div>
            <p>When you save an edit, the system re-checks conflicts for the new therapist and time. If the new therapist is busy during the requested time, <strong>the entire edit is blocked</strong> — the date change is not saved either. Fix the conflict and try again.</p>

            <div class="help-sub">Declining an Appointment</div>
            <p>Click <strong>❌ Decline</strong> on a Pending appointment. You must enter a decline reason. The customer is automatically notified with the reason you provide. Use this for unavailability, incorrect bookings, or out-of-area requests.</p>

            <div class="help-sub">Cancelling an Appointment</div>
            <p>Click <strong>🚫 Cancel</strong> on an Assigned or Approved appointment. Enter a cancellation reason. The customer is notified. Use this when a confirmed appointment can no longer proceed (e.g., therapist sick, customer requested cancellation via phone).</p>

            <div class="help-note">ℹ️ Only Assigned/Approved appointments (not Completed or Declined) can be cancelled by admin. Declined appointments are final.</div>
        </div></div>
    </div>

    <!-- Topic 8 -->
    <div class="help-topic" onclick="toggleHelp(this)">
        <div class="help-topic-header">
            <span class="help-topic-num">8</span>
            <span class="help-topic-title">Completing an Appointment</span>
            <span class="help-topic-arrow">▸</span>
        </div>
        <div class="help-topic-body"><div class="help-body-inner">
            <p>When the customer has finished their session and is ready to pay, click the <strong>✅ Complete</strong> button on a Checked-In appointment card.</p>

            <div class="help-sub">The Complete Modal</div>
            <ul>
                <li><strong>Price breakdown</strong> — Shows the base price, any booking discounts, the celebration discount, and the balance due.</li>
                <li><strong>Advance payment</strong> — If a deposit was recorded at booking time, it auto-populates in the Advance row and is deducted from the balance due.</li>
                <li><strong>Completion discount</strong> — Optionally add a Senior, PWD, Employee, or Voucher discount at the time of completion.</li>
                <li><strong>Payment method</strong> — Select how the customer is paying (Cash, GCash, Maya, Card, QR PH).</li>
                <li><strong>Cashier PIN</strong> — Receptionist accounts must enter their 4-digit PIN before completing. Owner and IT can complete without a PIN.</li>
            </ul>

            <div class="help-sub">Commission Notes</div>
            <p>Commission is calculated from the <strong>promo price</strong> (price after discounts), not the advance payment. The advance does not reduce the commission base.</p>

            <p>After submission, the appointment moves to <strong>History</strong> and an entry is created in the Daily Report for that date.</p>

            <div class="help-tip">💡 <strong>Tip:</strong> The Complete button is only visible on Checked-In appointments. If a customer is waiting and hasn't checked in yet, click Check-In first, then Complete when done.</div>
        </div></div>
    </div>

    <!-- Topic 9 -->
    <div class="help-topic" onclick="toggleHelp(this)">
        <div class="help-topic-header">
            <span class="help-topic-num">9</span>
            <span class="help-topic-title">Walk-In Bookings</span>
            <span class="help-topic-arrow">▸</span>
        </div>
        <div class="help-topic-body"><div class="help-body-inner">
            <p>Use the <strong>Walk-in</strong> page for customers who walk in without an online booking, or for recording phone bookings directly into the system.</p>

            <div class="help-sub">Customer Information</div>
            <p>Enter the customer's <strong>name and phone number</strong>. These fields are important — they appear on the appointment card and help identify the customer. Do not leave them as "Walk-in Customer."</p>

            <div class="help-sub">Service &amp; Booking Details</div>
            <ul>
                <li><strong>Service</strong> — Search and select the service from the grid.</li>
                <li><strong>Service type</strong> — Onsite (at the spa), Home Service, or Hotel.</li>
                <li><strong>Therapist</strong> — Pick the assigned therapist (conflict-checked).</li>
                <li><strong>Date &amp; Time</strong> — Select from the calendar/time picker.</li>
                <li><strong>People Count</strong> — How many guests for this booking.</li>
            </ul>

            <div class="help-sub">Pricing</div>
            <ul>
                <li><strong>Onsite / Regular</strong>: Base price from the service catalog.</li>
                <li><strong>Home Service</strong>: <code>(regular price × 2) + home service fee</code> — the fee is set per service in the Services page.</li>
                <li><strong>Hotel / Partner</strong>: Uses the partner's custom rate.</li>
                <li><strong>Influencer</strong>: Complimentary (₱0 charged to customer).</li>
            </ul>

            <div class="help-sub">Discounts</div>
            <p>Apply PWD (20%), Senior Citizen (20%), Employee/Staff (50%), or a Voucher code before finalizing. Discounts are applied to the base/promo price.</p>

            <div class="help-sub">Payment Methods</div>
            <p>Cash, GCash, Maya, Card, QR PH, or <strong>Unpaid</strong> (for corporate accounts or deferred billing).</p>

            <div class="help-tip">💡 <strong>Advance Payment:</strong> Use the Advance Payment field when a customer pays a deposit now for a future appointment. See Topic 10 for details.</div>
        </div></div>
    </div>

    <!-- Topic 10 -->
    <div class="help-topic" onclick="toggleHelp(this)">
        <div class="help-topic-header">
            <span class="help-topic-num">10</span>
            <span class="help-topic-title">Advance Payments</span>
            <span class="help-topic-arrow">▸</span>
        </div>
        <div class="help-topic-body"><div class="help-body-inner">
            <p>An <strong>advance payment</strong> (deposit) is money collected from a customer <em>today</em> for an appointment scheduled on a <em>future date</em>.</p>

            <div class="help-sub">How to Record an Advance</div>
            <p>When creating a walk-in booking, enter the deposit amount in the <strong>💰 Advance Payment (₱)</strong> field. The booking is created for the future date, and the deposit is recorded with today's date. A <strong>💰 Advance: ₱X</strong> badge appears on the appointment card.</p>

            <div class="help-sub">In the Daily Report (Day the Advance Is Collected)</div>
            <p>The advance amount appears in the <strong>"Advances Received"</strong> section of <em>today's</em> daily report — because that's when the cash entered the drawer. It adds to Cash on Hand for today.</p>

            <div class="help-sub">At Completion (Future Date)</div>
            <p>When the appointment is completed, the advance auto-populates in the Complete modal and is deducted from the balance due. The customer only pays the remaining balance. The completed appointment also shows the advance in the Daily Report for the completion date, where it reduces the net cash figure.</p>

            <div class="help-sub">Commission</div>
            <p>Commission for the therapist is <strong>always calculated from the full service price</strong> (or promo price after discounts). The advance payment does not reduce the commission base.</p>

            <div class="help-note">ℹ️ If a customer pays the full amount upfront as an advance, set the advance to the full price. At completion, the balance due shows ₱0.</div>
        </div></div>
    </div>

    <!-- ══════════════════════════════════════════════════════════
         SECTION 3: THERAPISTS & STAFF
    ══════════════════════════════════════════════════════════ -->
    <div class="help-section-label"><span>💆</span> Therapists &amp; Staff</div>

    <!-- Topic 11 -->
    <div class="help-topic" onclick="toggleHelp(this)">
        <div class="help-topic-header">
            <span class="help-topic-num">11</span>
            <span class="help-topic-title">Managing Therapists</span>
            <span class="help-topic-arrow">▸</span>
        </div>
        <div class="help-topic-body"><div class="help-body-inner">
            <p>Go to the <strong>Therapists</strong> page from the sidebar. This page is accessible to all admin roles.</p>

            <div class="help-sub">Adding a Therapist</div>
            <p>Fill in the therapist's full name, phone number, and photo (optional). Set their specialties — the services they are qualified to perform. Only services in their specialty list appear in the assignment modal.</p>

            <div class="help-sub">Editing a Therapist</div>
            <p>Click <strong>✏️ Edit</strong> on a therapist card to update their name, photo, specialties, or on-duty status. Changes take effect immediately for all future assignments.</p>

            <div class="help-sub">On-Duty / Off-Duty</div>
            <p>Toggle a therapist's status between <strong>On Duty</strong> and <strong>Off Duty</strong>. Off-duty therapists are hidden from the assignment list but their historical appointments are preserved.</p>

            <div class="help-sub">Attendance Tracking</div>
            <p>The Therapists page shows attendance records. You can log daily attendance to track which therapists were present. This feeds into the commission and deductions calculations.</p>

            <div class="help-tip">💡 <strong>Tip:</strong> Set therapist specialties carefully. If a therapist is assigned to a service that isn't in their specialty, conflict-checking may still allow it — but commission rates may not apply correctly.</div>
        </div></div>
    </div>

    <!-- Topic 12 -->
    <div class="help-topic" onclick="toggleHelp(this)">
        <div class="help-topic-header">
            <span class="help-topic-num">12</span>
            <span class="help-topic-title">Setting Commission Rates</span>
            <span class="help-topic-arrow">▸</span>
        </div>
        <div class="help-topic-body"><div class="help-body-inner">
            <p>Go to <strong>Staff → Commission Matrix tab</strong> (Owner / IT only). Select a therapist, then set their commission rate for each service.</p>

            <div class="help-sub">Commission Rates</div>
            <ul>
                <li><strong>30%</strong> — Standard rate for experienced therapists.</li>
                <li><strong>20%</strong> — Mid-tier rate.</li>
                <li><strong>15%</strong> — Entry or probationary rate.</li>
                <li><strong>Custom %</strong> — Type any percentage manually.</li>
            </ul>
            <p>The <strong>₱ preview</strong> shown next to each rate is what the therapist earns at the service's regular price — useful for a quick sanity check.</p>

            <div class="help-sub">Influencer Flat Rate</div>
            <p>A separate column sets the fixed ₱ amount the therapist earns when serving an influencer booking (rate type = Influencer). This is a flat amount, not a percentage.</p>

            <div class="help-sub">How Commission Is Calculated</div>
            <ul>
                <li><strong>Regular / Home / Onsite</strong>: <code>commission % × promo price</code> (the discounted price, not the full price).</li>
                <li><strong>Hotel / Partner</strong>: <code>commission % × regular service price</code> (not the hotel markup charged to the customer).</li>
                <li><strong>Influencer</strong>: flat rate amount from the Influencer column, regardless of price.</li>
            </ul>

            <div class="help-note">ℹ️ If a therapist has no commission rate set for a service, the system warns you in the Staff page. Set the rate before assigning that therapist to that service.</div>
        </div></div>
    </div>

    <!-- Topic 13 -->
    <div class="help-topic" onclick="toggleHelp(this)">
        <div class="help-topic-header">
            <span class="help-topic-num">13</span>
            <span class="help-topic-title">Staff Accounts &amp; Password Reset</span>
            <span class="help-topic-arrow">▸</span>
        </div>
        <div class="help-topic-body"><div class="help-body-inner">
            <div class="help-sub">Creating an Account</div>
            <p>Go to <strong>Staff → Staff Accounts tab</strong>. Fill in the username, email, and password. Select the account type (Marketing, IT, Receptionist). Click <strong>Create Account</strong>.</p>

            <div class="help-sub">Password Requirements</div>
            <ul>
                <li>At least 8 characters</li>
                <li>At least one uppercase letter</li>
                <li>At least one number</li>
                <li>At least one special character (e.g. @, #, !)</li>
            </ul>

            <div class="help-sub">Resetting a Password</div>
            <p>Click the <strong>🔑 Reset Password</strong> button next to any account. Enter and confirm the new password. The change is immediate — the old password is invalidated and any existing session is cleared. Tell the user their new password directly (the system does not email it).</p>

            <div class="help-sub">Deleting an Account</div>
            <p>Click <strong>🗑️ Delete</strong> to remove an account. Owner accounts cannot be deleted. You cannot delete your own account. Historical records are preserved even after deletion.</p>

            <div class="help-sub">Receptionist PINs</div>
            <p>Go to <strong>Staff → Receptionists tab</strong>. Each receptionist has a 4-digit PIN used to confirm actions like completing appointments and filing reports. Set the PIN from the list; PINs are stored securely (hashed).</p>

            <div class="help-tip">💡 <strong>Tip:</strong> If a receptionist is locked out (session token conflict), click <strong>Clear Session</strong> next to their account. They can then log in again fresh.</div>
        </div></div>
    </div>

    <!-- ══════════════════════════════════════════════════════════
         SECTION 4: DAILY REPORT & SALES
    ══════════════════════════════════════════════════════════ -->
    <div class="help-section-label"><span>📋</span> Daily Report &amp; Sales</div>

    <!-- Topic 14 -->
    <div class="help-topic" onclick="toggleHelp(this)">
        <div class="help-topic-header">
            <span class="help-topic-num">14</span>
            <span class="help-topic-title">Daily Report — Overview</span>
            <span class="help-topic-arrow">▸</span>
        </div>
        <div class="help-topic-body"><div class="help-body-inner">
            <p>The <strong>Daily Report</strong> page captures all sales, expenses, and cash flow for a given day. Select the report date using the date picker at the top.</p>

            <div class="help-sub">Auto-Import from Appointments</div>
            <p>Every time you open a daily report, the system automatically pulls all <strong>Completed</strong> appointments for that date into the Spreadsheet tab. Each completed appointment becomes a row with the service details, therapist, and commission already filled in. You cannot delete or edit these imported rows unless you are the owner or have IT access.</p>

            <div class="help-sub">Manual Entry</div>
            <p>You can add extra rows manually for any transaction not captured by an appointment (e.g., a quick cash sale, a product transaction). Use the <strong>+ Add Row</strong> button in the Spreadsheet tab.</p>

            <div class="help-sub">Report Date</div>
            <p>Always verify the date shown at the top before editing. Filing a report for the wrong date causes reconciliation problems. Use the <strong>← Previous / Next →</strong> arrows or the date picker to navigate.</p>

            <div class="help-tip">💡 <strong>Tip:</strong> It's best practice to file the daily report at the end of each working day before closing out. Delaying entry makes it harder to remember accurate figures.</div>
        </div></div>
    </div>

    <!-- Topic 15 -->
    <div class="help-topic" onclick="toggleHelp(this)">
        <div class="help-topic-header">
            <span class="help-topic-num">15</span>
            <span class="help-topic-title">Daily Report — Editing Commissions</span>
            <span class="help-topic-arrow">▸</span>
        </div>
        <div class="help-topic-body"><div class="help-body-inner">
            <p>In the <strong>Spreadsheet tab</strong>, commission columns (30%, 20%, 15%) are editable for each service row.</p>

            <div class="help-sub">Auto-Calculation</div>
            <p>When you select a service and therapist, the commission fields auto-calculate using the therapist's configured rates from the Commission Matrix. The Net Sales column recalculates automatically.</p>

            <div class="help-sub">Overriding Commission</div>
            <p>Click on any commission field and type a different value to override it. The override is saved per-row. The Net Sales column updates in real time as you type.</p>

            <div class="help-sub">When Overrides Reset</div>
            <ul>
                <li><strong>Changing the service or therapist</strong> on a row resets the commission to the auto-calculated value.</li>
                <li><strong>Changing only the promo price</strong> keeps your manual commission amount intact.</li>
            </ul>

            <div class="help-sub">Receptionist Editing Restrictions</div>
            <p>Receptionists must enter their PIN to unlock spreadsheet editing for the current session. The unlock expires after a set time (configured by the owner). Owners and IT can always edit without a PIN.</p>

            <div class="help-note">ℹ️ Commission fields for auto-imported appointment rows are locked for receptionists. Only owners and IT can modify the commission on an imported row.</div>
        </div></div>
    </div>

    <!-- Topic 16 -->
    <div class="help-topic" onclick="toggleHelp(this)">
        <div class="help-topic-header">
            <span class="help-topic-num">16</span>
            <span class="help-topic-title">Daily Report — Sections Explained</span>
            <span class="help-topic-arrow">▸</span>
        </div>
        <div class="help-topic-body"><div class="help-body-inner">
            <div class="help-sub">Spreadsheet Tab</div>
            <p>The main table of service transactions for the day. Each row = one completed service. Columns: Time In/Out, Slip No., Customer, Service, Therapist, Promo Price, commission amounts, and Net Sales.</p>

            <div class="help-sub">Cash Breakdown Tab</div>
            <p>Enter the number of bills and coins by denomination. The total auto-sums from your entries. This is the physical cash in the drawer at end of day.</p>

            <div class="help-sub">Advances Received</div>
            <p>Shows all advance payments <strong>collected on this date</strong> for future appointments. This is separate from advances that are <em>deducted</em> on completion day. Advance cash goes into the drawer today, so it adds to Cash on Hand.</p>

            <div class="help-sub">Expenses</div>
            <p>Business expenses paid out in cash on this day (supplies, utilities, petty cash, etc.). Each expense entry has a description and amount. Total expenses reduce the net cash figure.</p>

            <div class="help-sub">Unpaids</div>
            <p>Corporate accounts or customers who received service on credit. Enter the customer/company name and amount. These are not yet counted as cash received.</p>

            <div class="help-sub">Service GC &amp; Paid GC (Gift Certificates)</div>
            <p>Track gift certificates sold today — both service GCs (redeemable for a service) and paid GCs (cash-value). Enter the GC number, amount, and buyer.</p>

            <div class="help-sub">Products Sold</div>
            <p>Product sales for the day (retail items sold at the counter). Enter the product name, quantity, and amount.</p>

            <div class="help-sub">Summary / Analysis</div>
            <p>The auto-calculated summary at the bottom shows <strong>Gross Sales</strong> (all service revenue before commission), <strong>Staff Commission Fund (CF)</strong>, <strong>Net Cash</strong> (gross minus commission), <strong>Cash on Hand (COH)</strong> (cash drawer + advances - expenses), and the <strong>Short/Over</strong> figure (variance between actual count and expected).</p>
        </div></div>
    </div>

    <!-- Topic 17 -->
    <div class="help-topic" onclick="toggleHelp(this)">
        <div class="help-topic-header">
            <span class="help-topic-num">17</span>
            <span class="help-topic-title">Submitting &amp; Exporting Reports</span>
            <span class="help-topic-arrow">▸</span>
        </div>
        <div class="help-topic-body"><div class="help-body-inner">
            <div class="help-sub">Submitting a Report</div>
            <p>Click the <strong>Submit Report</strong> button when the day's data is complete and verified. Submitting <strong>locks the report</strong> — no further edits can be made by receptionists (owners can still unlock). The owner receives a notification that the report is ready for review.</p>
            <p>Before submitting, the system validates that the spreadsheet has at least one entry and that the closing cashier name is filled in. Fix any errors shown before submitting.</p>

            <div class="help-sub">Export to Excel (.xlsx)</div>
            <p>Click <strong>Export Excel</strong> to download the report in the official template format. The file loads the client's Excel template and fills in all sections — service rows, denominations, summary values, and formulas — exactly matching the Google Sheets format. Download and save for record-keeping.</p>

            <div class="help-sub">Export to PDF</div>
            <p>Click <strong>Export PDF</strong> to download a printer-friendly PDF summary of the report.</p>

            <div class="help-tip">💡 <strong>Tip:</strong> Export Excel <em>before</em> submitting if you want to review the formatted sheet first. The Export works on both draft and submitted reports.</div>

            <div class="help-note">ℹ️ Once a report is submitted and locked, a receptionist cannot undo it. Ask the Owner or IT to unlock it if a correction is needed.</div>
        </div></div>
    </div>

    <!-- ══════════════════════════════════════════════════════════
         SECTION 5: SYSTEM SETTINGS & OTHER
    ══════════════════════════════════════════════════════════ -->
    <div class="help-section-label"><span>⚙️</span> System Settings &amp; Other</div>

    <!-- Topic 18 -->
    <div class="help-topic" onclick="toggleHelp(this)">
        <div class="help-topic-header">
            <span class="help-topic-num">18</span>
            <span class="help-topic-title">Partners &amp; Hotel Bookings</span>
            <span class="help-topic-arrow">▸</span>
        </div>
        <div class="help-topic-body"><div class="help-body-inner">
            <div class="help-sub">Setting Up Partners</div>
            <p>Go to <strong>Partners</strong> (Owner / IT only). Add a hotel or corporate partner with a name and contact details. Each partner can have custom per-service rates — the price charged to the partner's guests.</p>

            <div class="help-sub">Using a Partner Rate in Walk-In</div>
            <p>When creating a walk-in booking, select <strong>Hotel</strong> as the service type, then choose the partner from the dropdown. The system applies the partner's custom rate for that service.</p>

            <div class="help-sub">Commission on Hotel Bookings</div>
            <p>Even though the customer (or hotel) is charged the partner markup, <strong>therapist commission is calculated from the regular service price</strong>, not the hotel rate. This prevents commission from fluctuating with partner pricing.</p>

            <div class="help-note">ℹ️ Partners must have custom rates set for each service before hotel-type bookings can be priced correctly. If no rate is set, the system falls back to the regular price.</div>
        </div></div>
    </div>

    <!-- Topic 19 -->
    <div class="help-topic" onclick="toggleHelp(this)">
        <div class="help-topic-header">
            <span class="help-topic-num">19</span>
            <span class="help-topic-title">Discounts &amp; Vouchers</span>
            <span class="help-topic-arrow">▸</span>
        </div>
        <div class="help-topic-body"><div class="help-body-inner">
            <div class="help-sub">Discount Types</div>
            <ul>
                <li><strong>PWD (Persons With Disability)</strong> — 20% off the service price.</li>
                <li><strong>Senior Citizen</strong> — 20% off the service price.</li>
                <li><strong>Employee / Staff</strong> — 50% off for in-house staff.</li>
                <li><strong>Voucher</strong> — A fixed ₱ amount deducted using a voucher code.</li>
                <li><strong>Celebration (Birthday)</strong> — 10% off, auto-applied when the customer's birthday matches the appointment date.</li>
            </ul>
            <p>Discounts can be applied during walk-in booking and also at the time of completing an appointment. If both a booking discount and a completion discount are applied, they stack additively.</p>

            <div class="help-sub">Discounts Page</div>
            <p>Shows a history of all discounted transactions — customer, service, discount type, original price, discount amount, and final price. Use this for auditing and to verify that discounts were applied correctly.</p>

            <div class="help-sub">Vouchers Page</div>
            <p>Create voucher codes with a fixed value (e.g., VOUCHER100 = ₱100 off). Set usage limits (single-use or multi-use), expiry dates, and minimum spend requirements. Vouchers can be used during online checkout or at the counter during walk-in.</p>

            <div class="help-tip">💡 <strong>Tip:</strong> Senior Citizen and PWD discounts require a valid ID to be shown at the counter. Record the ID number in the voucher/discount notes for audit purposes.</div>
        </div></div>
    </div>

    <!-- Topic 20 -->
    <div class="help-topic" onclick="toggleHelp(this)">
        <div class="help-topic-header">
            <span class="help-topic-num">20</span>
            <span class="help-topic-title">Notifications</span>
            <span class="help-topic-arrow">▸</span>
        </div>
        <div class="help-topic-body"><div class="help-body-inner">
            <div class="help-sub">The Notification Bell</div>
            <p>The 🔔 bell icon in the admin header auto-refreshes every <strong>3 seconds</strong>. You do not need to reload the page — new notifications appear on their own. A red badge shows the unread count.</p>

            <div class="help-sub">What Triggers an Admin Notification</div>
            <ul>
                <li>A new appointment is booked by a customer online.</li>
                <li>A customer cancels or modifies their booking.</li>
                <li>A payment is completed (for online-payment bookings).</li>
                <li>A daily report is submitted by a receptionist.</li>
            </ul>

            <div class="help-sub">What Customers See</div>
            <ul>
                <li>Their appointment status changes (Assigned, Declined, Cancelled).</li>
                <li>A therapist is assigned to their booking.</li>
                <li>Payment confirmation (if online payment was used).</li>
            </ul>

            <div class="help-sub">New Appointment Banner (Appointments Page)</div>
            <p>While you're viewing the Appointments Kanban, if a new booking comes in, a coloured banner appears at the top of the board. Click it to refresh the Kanban — the new card appears in the Pending column.</p>

            <div class="help-sub">Marking Notifications as Read</div>
            <p>Open the notification panel and click <strong>Mark all read</strong> to clear the badge. Clicking an individual notification also marks it as read and navigates to the relevant page.</p>
        </div></div>
    </div>

    <!-- Topic 21 -->
    <div class="help-topic" onclick="toggleHelp(this)">
        <div class="help-topic-header">
            <span class="help-topic-num">21</span>
            <span class="help-topic-title">Tips &amp; Troubleshooting</span>
            <span class="help-topic-arrow">▸</span>
        </div>
        <div class="help-topic-body"><div class="help-body-inner">
            <div class="help-sub">After Code Updates</div>
            <p>If the system is updated by IT, always restart Apache in XAMPP Control Panel after code changes. PHP's OPcache can serve the old version of a script even after the file is saved. Restarting Apache clears the cache immediately.</p>

            <div class="help-sub">Hard Refresh</div>
            <p>If a page looks wrong or styles are missing, press <strong>Ctrl + Shift + R</strong> (Windows) or <strong>Cmd + Shift + R</strong> (Mac) for a hard refresh. This clears the browser's local cache of CSS and JS files.</p>

            <div class="help-sub">Login Fails After Password Reset</div>
            <p>If a password was reset but the login still fails, clear the browser cookies for the admin site and try again. Old session cookies can sometimes interfere with fresh logins. In Chrome: Settings → Privacy → Cookies → See all cookies → delete the site's cookies.</p>

            <div class="help-sub">Button Seems Stuck / Won't Click</div>
            <p>The system uses <strong>double-click protection</strong> — submit buttons disable themselves after the first click to prevent duplicate orders or completions. If a button says "Processing…" and nothing happens for more than 10 seconds, the request may have timed out. Refresh the page and check if the action was processed before resubmitting.</p>

            <div class="help-sub">Using the Admin on Mobile</div>
            <p>The admin panel is mobile-friendly. Open it in your phone's browser (Chrome or Safari) and use it just like on desktop. The sidebar collapses behind the ☰ menu. Kanban columns stack vertically on phones. All actions — assigning therapists, completing appointments, filing reports — work on mobile.</p>

            <div class="help-sub">Printer Receipt / Email Receipt</div>
            <p>After completing an appointment, a receipt can be sent to the customer's email by clicking the email link that appears in the success message. This requires the customer to have an email address on file.</p>

            <div class="help-tip">💡 <strong>If something is truly broken</strong>, screenshot the error, note the page you were on and what you were doing, and contact the IT admin. Include the date and time so they can check the server logs.</div>
        </div></div>
    </div>

    <!-- Footer note -->
    <div style="margin-top:2rem;padding:1rem 1.25rem;background:var(--bg3);border-radius:10px;
                border:1px solid var(--border2);font-size:0.8rem;color:var(--gray);text-align:center;">
        Recovery Iloilo Admin Panel &nbsp;·&nbsp; For technical issues, contact IT Support
        &nbsp;·&nbsp; <strong style="color:var(--brown);">21 topics</strong> in this guide
    </div>

</div><!-- /help-wrap -->

<script>
function toggleHelp(el) {
    var isOpen = el.classList.contains('open');
    // Close all open topics
    document.querySelectorAll('.help-topic.open').forEach(function(t) {
        t.classList.remove('open');
    });
    // Open this one if it was closed
    if (!isOpen) {
        el.classList.add('open');
        // Gently scroll the opened topic into view
        setTimeout(function() {
            var rect = el.getBoundingClientRect();
            if (rect.top < 80 || rect.top > window.innerHeight * 0.65) {
                el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
        }, 60);
    }
}

function filterHelp(val) {
    var query = val.trim().toLowerCase();
    var topics  = document.querySelectorAll('.help-topic');
    var sections = document.querySelectorAll('.help-section-label');
    var noRes   = document.getElementById('helpNoResults');
    var visible = 0;

    topics.forEach(function(t) {
        if (!query) {
            t.style.display = '';
            visible++;
            return;
        }
        var title = t.querySelector('.help-topic-title').textContent.toLowerCase();
        var body  = t.querySelector('.help-body-inner').textContent.toLowerCase();
        if (title.indexOf(query) !== -1 || body.indexOf(query) !== -1) {
            t.style.display = '';
            visible++;
        } else {
            t.style.display = 'none';
            t.classList.remove('open');
        }
    });

    // Show/hide section labels: hide if every topic under that label is hidden
    sections.forEach(function(sec) {
        if (!query) { sec.style.display = ''; return; }
        var el = sec.nextElementSibling;
        var hasVisible = false;
        while (el && !el.classList.contains('help-section-label')) {
            if (el.classList.contains('help-topic') && el.style.display !== 'none') {
                hasVisible = true;
                break;
            }
            el = el.nextElementSibling;
        }
        sec.style.display = hasVisible ? '' : 'none';
    });

    noRes.style.display = (visible === 0) ? 'block' : 'none';
}
</script>

<?php require_once 'admin_footer.php'; ?>
