<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/security.php';

// Check if user is treasurer
if (!isTreasurer()) {
    redirectTo('/member/login.php');
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="row">
    <div class="col-12">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?php echo APP_URL; ?>/treasurer/dashboard.php">Dashboard</a></li>
                <li class="breadcrumb-item active">Treasurer Help</li>
            </ol>
        </nav>
        <h2 class="mb-4">📖 Treasurer Panel Help Guide</h2>
    </div>
</div>

<!-- Getting Started -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">🚀 Getting Started</h5>
            </div>
            <div class="card-body">
                <p>Welcome to the GYF Welfare Management System. As a treasurer, you have access to manage member contributions, record payments, and track financial activities. This guide will walk you through each feature.</p>
                
                <h6>Quick Access:</h6>
                <div class="row">
                    <div class="col-md-3 mb-2">
                        <a href="<?php echo APP_URL; ?>/treasurer/dashboard.php" class="btn btn-outline-primary w-100">📊 Dashboard</a>
                    </div>
                    <div class="col-md-3 mb-2">
                        <a href="<?php echo APP_URL; ?>/treasurer/members.php" class="btn btn-outline-primary w-100">👥 Members</a>
                    </div>
                    <div class="col-md-3 mb-2">
                        <a href="<?php echo APP_URL; ?>/treasurer/transactions.php" class="btn btn-outline-primary w-100">💳 Transactions</a>
                    </div>
                    <div class="col-md-3 mb-2">
                        <a href="<?php echo APP_URL; ?>/treasurer/settings.php" class="btn btn-outline-primary w-100">⚙️ Settings</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Dashboard -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">📊 Dashboard Overview</h5>
            </div>
            <div class="card-body">
                <p>The dashboard is your home screen. It gives you a quick snapshot of the welfare fund's health.</p>
                
                <h6>What you'll see:</h6>
                <ul>
                    <li><strong>Monthly Collection:</strong> Total amount collected this month.</li>
                    <li><strong>Yearly Collection:</strong> Total amount collected this year.</li>
                    <li><strong>Total Members:</strong> Number of registered members (excluding the treasurer account).</li>
                    <li><strong>Pending Payments:</strong> Members who haven't paid for the current month.</li>
                </ul>
                
                <h6>Recent Transactions:</h6>
                <p>A table showing the last 10 payments recorded. You can click "View All" to go to the full transactions page.</p>
                
                <h6>Members Pending Payment:</h6>
                <p>A list of members who haven't paid for the current month. You can:</p>
                <ul>
                    <li><strong>Send Reminder:</strong> Click the "Send Reminder" button to email the member a payment reminder. The email includes their current status and a link to make a payment.</li>
                    <li><strong>Remind All:</strong> Use the "Remind All" button to send reminders to all pending members at once.</li>
                </ul>
                
                <h6>Monthly Collection Chart:</h6>
                <p>A bar chart showing monthly collections for the current year. It helps you visualize collection trends over time.</p>
                
                <div class="alert alert-info">
                    <strong>💡 Tip:</strong> Check the dashboard regularly to monitor collection progress and identify members who need reminders.
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Members Management -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">👥 Members Management</h5>
            </div>
            <div class="card-body">
                <p>The Members page lets you view and manage all registered members.</p>
                
                <h6>Members Table:</h6>
                <p>Each row shows:</p>
                <ul>
                    <li><strong>Photo:</strong> Member's passport photo or a placeholder with their initial.</li>
                    <li><strong>Member ID:</strong> Unique identifier for the member.</li>
                    <li><strong>Full Name:</strong> Member's full name.</li>
                    <li><strong>Phone:</strong> Contact phone number.</li>
                    <li><strong>Email:</strong> Email address.</li>
                    <li><strong>Status:</strong> A colored badge showing the member's current state: <span class="text-success">Active</span>, <span class="text-warning">Suspended</span>, <span class="text-secondary">Deactivated</span>, or <span class="text-danger">Deleted</span>. A "Deletions: n/3" hint is shown for members who have been deleted before.</li>
                    <li><strong>Monthly Status:</strong> Whether the member has paid for the current month (Paid/Pending badge).</li>
                    <li><strong>Yearly Progress:</strong> A progress bar showing how much of the annual target the member has contributed.</li>
                </ul>
                
                <h6>Actions:</h6>
                <ul>
                    <li><strong>View:</strong> Click "View" to open the member detail page. This is the best place to see everything about a member in one screen.</li>
                    <li><strong>Pay:</strong> Click "Pay" to record a payment for that member. This takes you to the transactions page with the member pre-selected.</li>
                    <li><strong>Statement:</strong> Click "Statement" to view the member's contribution statement with full transaction history.</li>
                </ul>
                
                <h6>Member Status Management:</h6>
                <p>Below each member's action buttons there is a <strong>Status</strong> row with buttons to manage the member's account state. Only the treasurer can change a member's status:</p>
                <ul>
                    <li><strong>Suspend:</strong> Temporarily blocks the member from logging in (e.g. pending investigation). They can be unsuspended later.</li>
                    <li><strong>Deactivate:</strong> Disables the member's login without deleting their record or history. Can be reactivated at any time.</li>
                    <li><strong>Delete:</strong> Soft-deletes the member. Their row stays in the database (with a "Deleted" badge) so you can reactivate them. Each deletion increments a counter.</li>
                    <li><strong>3-Strike Ban:</strong> If a member is deleted <strong>3 times</strong>, they become <span class="text-danger">permanently banned</span> and can no longer be deleted again, nor re-register with the same email/phone. The banned state is shown as "Banned (permanent)".</li>
                </ul>
                <p>Suspended, deactivated, and deleted members <strong>cannot log in</strong>. Reactivating (or unsuspending) restores login access immediately.</p>

                <h6>Import CSV:</h6>
                <p>You can bulk-import members from a CSV file. The CSV should have these columns:</p>
                <ul>
                    <li><strong>Required:</strong> full_name, email, phone</li>
                    <li><strong>Optional:</strong> dob (YYYY-MM-DD), gender (Male/Female/Other), address, occupation, emergency_contact_name, emergency_contact_relationship, emergency_contact_phone</li>
                </ul>
                <p>If a member already exists (same email or phone), they will be skipped. New members will be assigned a member ID and a temporary password will be generated for them.</p>
                
                <h6>Print List:</h6>
                <p>Click "Print List" to print the members table for offline reference.</p>
                
                <div class="alert alert-warning">
                    <strong>⚠️ Note:</strong> The members table only shows non-treasurer accounts. The treasurer's own account is hidden from the list.
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Member Detail Page -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">👤 Member Detail Page</h5>
            </div>
            <div class="card-body">
                <p>When you click "View" on a member, you go to the member detail page. This is your one-stop shop for everything related to that member.</p>
                
                <h6>Member Info Header:</h6>
                <p>Shows the member's photo, name, member ID, email, phone, and registration date. Buttons here let you:</p>
                <ul>
                    <li>Go back to the members list</li>
                    <li>Open the member's statement</li>
                    <li>Record a new payment</li>
                </ul>
                
                <h6>Financial Dashboard:</h6>
                <ul>
                    <li><strong>Yearly Total:</strong> How much the member has contributed this year.</li>
                    <li><strong>Yearly Progress:</strong> Percentage of the annual target achieved.</li>
                    <li><strong>Monthly Target:</strong> The expected monthly contribution amount.</li>
                    <li><strong>Monthly Contributions Chart:</strong> A vertical bar chart for each month of the current year, showing how much was paid each month.</li>
                    <li><strong>Payment Methods:</strong> A breakdown of payment methods used (Cash, Mobile Money, Bank Transfer, Card) with counts and totals.</li>
                    <li><strong>Quick Summary:</strong> First payment date, last payment date, average payment amount, largest payment, and total transaction count.</li>
                </ul>
                
                <h6>Record Payment:</h6>
                <p>Click the "Record Payment" button to open a form where you can:</p>
                <ul>
                    <li>Enter the payment amount</li>
                    <li>Select the payment method</li>
                    <li>Choose the billing month and year</li>
                    <li>Set the exact date and time of the transaction</li>
                    <li>Add optional notes</li>
                </ul>
                <p>The system will:</p>
                <ul>
                    <li>Check if the member exists</li>
                    <li>Validate the amount and date</li>
                    <li>Allow multiple payments for the same billing cycle (welfare partial payments supported)</li>
                    <li>Enforce the annual contribution limit</li>
                    <li>Generate a unique receipt number</li>
                    <li>Send a receipt email to the member (and CC you)</li>
                </ul>
                
                <h6>Transaction History:</h6>
                <p>A filterable table showing all the member's transactions. You can filter by year, month, and payment method. Each row has buttons to view or print the receipt.</p>
                <p><strong>Print Receipt:</strong> Click the green <strong>Print</strong> button on any transaction row to open a print-ready receipt in a new window. The receipt includes all payment details and can be printed or saved as PDF.</p>
                
                <h6>Export:</h6>
                <p>You can export the member's statement as PDF or CSV from the Actions section.</p>
                
                <div class="alert alert-success">
                    <strong>✅ Pro Tip:</strong> Use the member detail page as your main workspace. It combines payment recording, history viewing, and statement generation in one place.
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Transactions -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">💳 Transactions Page</h5>
            </div>
            <div class="card-body">
                <p>The Transactions page is where you record and manage all payments. It's accessible from the navbar or by clicking "Pay" on a member.</p>
                
                <h6>Recording a Payment:</h6>
                <p>Fill in the payment form at the top:</p>
                <ul>
                    <li><strong>Member ID:</strong> Enter or search for the member's ID. You can also click "Browse Members" to search visually.</li>
                    <li><strong>Amount:</strong> The amount paid.</li>
                    <li><strong>Payment Method:</strong> Cash, Mobile Money, Bank Transfer, or Card.</li>
                    <li><strong>Billing Month/Year:</strong> The month and year this payment covers.</li>
                    <li><strong>Date & Time:</strong> When the payment was made. Defaults to now.</li>
                    <li><strong>Notes:</strong> Any additional notes about this payment.</li>
                </ul>
                <p>Click "Record Payment" to save. The system will:</p>
                <ul>
                    <li>Validate all inputs</li>
                    <li>Allow multiple payments per billing cycle (welfare partial payments)</li>
                    <li>Enforce annual limits</li>
                    <li>Generate a receipt</li>
                    <li>Send a receipt email to the member (and CC you)</li>
                </ul>
                
                <h6>Transaction History Table:</h6>
                <p>Shows all recorded transactions with advanced filters:</p>
                <ul>
                    <li><strong>Filters:</strong> Filter by year, month, payment method, or search by receipt number/member name.</li>
                    <li><strong>Sort:</strong> Click column headers to sort by date, amount, receipt number, member name, or payment method.</li>
                    <li><strong>Pagination:</strong> Navigate through large transaction histories.</li>
                    <li><strong>Actions:</strong> For each transaction, you can view the receipt, print it, or void it (if needed).</li>
                </ul>
                
                <h6>Export:</h6>
                <p>You can export transactions to CSV or PDF using the buttons at the top.</p>
                
                <h6>Batch Payment Modal:</h6>
                <p>The batch payment feature lets you record the same payment for multiple billing cycles at once. This is useful when a member pays for several months in advance. Use the "Apply to all active members" checkbox to record a payment for all members for a specific month.</p>
                
                <div class="alert alert-warning">
                    <strong>⚠️ Voiding Transactions:</strong> Use the void feature carefully. Voiding a transaction removes it from the member's payment history and may affect their progress calculations. Only void transactions that were recorded in error.
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Settings -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">⚙️ Settings</h5>
            </div>
            <div class="card-body">
                <p>The Settings page lets you configure the welfare fund parameters.</p>
                
                <h6>Annual Contribution Target:</h6>
                <p>Set the total amount each member should contribute annually. This affects:</p>
                <ul>
                    <li>The yearly progress bars on the members table and member detail page</li>
                    <li>The "Pending Payments" count on the dashboard</li>
                    <li>The annual limit check when recording payments</li>
                </ul>
                
                <h6>Monthly Contribution Target:</h6>
                <p>Set the expected monthly contribution per member. This affects:</p>
                <ul>
                    <li>The monthly contribution chart on the member detail page</li>
                    <li>The monthly target display on the dashboard</li>
                </ul>
                
                <div class="alert alert-info">
                    <strong>💡 Tip:</strong> Update these settings at the beginning of each year or whenever the contribution amounts change. All progress calculations and charts will automatically update.
                </div>

                <h6>⚠️ Danger Zone — Reset Database:</h6>
                <p>At the bottom of the Settings page there is a red <strong>Danger Zone</strong> card for resetting data. Use it with extreme care.</p>
                <ul>
                    <li><strong>Select what to reset:</strong> Transactions, Audit Logs, Password Reset Tokens, and/or All Members (except the treasurer account).</li>
                    <li><strong>Confirm:</strong> Type the word <code>RESET</code> into the confirmation box, then click "Reset Selected Data".</li>
                    <li>The treasurer account and the welfare settings (annual/monthly targets) are <strong>always preserved</strong>.</li>
                    <li>Resetting members also removes their transactions to avoid orphaned records.</li>
                </ul>
                <div class="alert alert-danger">
                    <strong>🛑 This action is permanent and cannot be undone.</strong> Export your data first if you might need it.
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Email & Notifications -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">📧 Email & Notifications</h5>
            </div>
            <div class="card-body">
                <p>The system sends automatic emails for several events. All emails are sent via Gmail SMTP using the treasurer's email address as the sender.</p>
                
                <h6>Emails sent automatically:</h6>
                <ul>
                    <li><strong>Payment Receipts:</strong> Sent to the member (and CC'd to the treasurer) whenever a payment is recorded. The receipt includes the member's photo (if uploaded), payment details, and billing period.</li>
                    <li><strong>Payment Reminders:</strong> Sent to members who haven't paid for the current month. You can send individual reminders from the dashboard or use "Remind All" for everyone.</li>
                    <li><strong>Password Reset:</strong> Sent when a member or treasurer requests a password reset. The link expires after 1 hour.</li>
                </ul>
                
                <h6>Receipt Email Contents:</h6>
                <p>Each receipt email includes:</p>
                <ul>
                    <li>Member's passport photo (circular avatar) — if they uploaded one during registration</li>
                    <li>Receipt number, amount, payment method</li>
                    <li>Billing period and transaction date</li>
                    <li>GYF Welfare branding</li>
                </ul>
                
                <h6>Email Limits:</h6>
                <p>Gmail free tier allows up to <strong>500 emails per day</strong>. For a small welfare group, this is more than enough. If you need to send to many members at once, the system batches them automatically.</p>
                
                <div class="alert alert-info">
                    <strong>💡 Tip:</strong> If a member reports not receiving emails, first check their email address in the members list, then ask them to check their spam/junk folder.
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Profile -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">👤 Profile</h5>
            </div>
            <div class="card-body">
                <p>Your profile page lets you manage your personal information and password.</p>
                
                <h6>Editable Fields:</h6>
                <ul>
                    <li><strong>Full Name:</strong> Your full name.</li>
                    <li><strong>Phone:</strong> Your contact phone number. You can edit the country code and phone number separately.</li>
                    <li><strong>Address:</strong> Your address.</li>
                </ul>
                
                <h6>Password:</h6>
                <p>You can change your password by entering your current password and a new password.</p>
                
                <div class="alert alert-warning">
                    <strong>⚠️ Note:</strong> Your email address cannot be changed from this page. Contact an administrator if you need to update your email.
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Security Features -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header bg-warning">
                <h5 class="mb-0">🔒 Security Features</h5>
            </div>
            <div class="card-body">
                <p>The system includes several security features to protect member data and prevent unauthorized access.</p>
                
                <h6>Session Security:</h6>
                <ul>
                    <li><strong>Device Fingerprinting:</strong> Each session is bound to the user's IP address and browser. If someone tries to use a session from a different device, they are automatically logged out.</li>
                    <li><strong>Auto-Logout:</strong> Sessions expire after 1 hour of inactivity. You will be redirected to the login page if you leave the system idle for too long.</li>
                    <li><strong>Secure Cookies:</strong> Session cookies are HttpOnly and SameSite to prevent XSS and CSRF attacks.</li>
                    <li><strong>Remember Me:</strong> You can stay logged in for 30 days using the "Remember Me" option. Tokens are securely hashed and rotated on each login.</li>
                </ul>
                
                <h6>Password Reset:</h6>
                <ul>
                    <li>Members can reset their password using the <a href="<?php echo APP_URL; ?>/member/forgot-password.php">Forgot Password</a> page with their Member ID</li>
                    <li>Treasurer can reset using their registered email address</li>
                    <li>Reset links expire after 1 hour</li>
                    <li>Rate limiting prevents abuse (max 3 requests per 15 minutes)</li>
                </ul>
                
                <h6>Account Lockout:</h6>
                <p>After 5 failed login attempts, the account is locked for 15 minutes. This prevents brute-force attacks. You will see a message indicating how long to wait before trying again.</p>
                
                <h6>Audit Logging:</h6>
                <p>All important actions are logged with timestamp, user ID, action description, and IP address. You can view these logs in the <a href="<?php echo APP_URL; ?>/treasurer/audit-logs.php">Audit Logs</a> page.</p>
                
                <h6>CSRF Protection:</h6>
                <p>All forms include CSRF tokens to prevent cross-site request forgery attacks. You never need to worry about this — it works automatically.</p>
            </div>
        </div>
    </div>
</div>

<!-- Audit Logs -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">📋 Audit Logs</h5>
            </div>
            <div class="card-body">
                <p>The Audit Logs page shows a record of all important actions taken in the system. This helps maintain transparency and accountability.</p>
                
                <h6>What's Logged:</h6>
                <ul>
                    <li>Payment recordings</li>
                    <li>Settings updates</li>
                    <li>Member imports</li>
                    <li>Password changes</li>
                    <li>Transaction voids</li>
                </ul>
                
                <h6>Log Details:</h6>
                <p>Each log entry shows:</p>
                <ul>
                    <li><strong>Timestamp:</strong> When the action occurred</li>
                    <li><strong>User:</strong> Who performed the action</li>
                    <li><strong>Action:</strong> What was done</li>
                    <li><strong>IP Address:</strong> Where the action was performed from</li>
                </ul>
                
                <div class="alert alert-info">
                    <strong>💡 Tip:</strong> Review audit logs periodically to ensure all actions are authorized and legitimate.
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Common Tasks -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header bg-success text-white">
                <h5 class="mb-0">✅ Common Tasks - Quick Reference</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <h6>How to record a payment:</h6>
                        <ol>
                            <li>Go to <a href="<?php echo APP_URL; ?>/treasurer/transactions.php">Transactions</a> or click "Pay" on a member</li>
                            <li>Enter the member ID or browse members</li>
                            <li>Fill in the amount, payment method, and billing period</li>
                            <li>Click "Record Payment"</li>
                            <li>A receipt will be generated and emailed to the member</li>
                        </ol>
                    </div>
                    <div class="col-md-6 mb-3">
                        <h6>How to send a payment reminder:</h6>
                        <ol>
                            <li>Go to the <a href="<?php echo APP_URL; ?>/treasurer/dashboard.php">Dashboard</a></li>
                            <li>Look at the "Members Pending Payment" section</li>
                            <li>Click "Send Reminder" next to a member's name</li>
                            <li>Or click "Remind All" to remind everyone at once</li>
                        </ol>
                    </div>
                    <div class="col-md-6 mb-3">
                        <h6>How to import members:</h6>
                        <ol>
                            <li>Go to <a href="<?php echo APP_URL; ?>/treasurer/members.php">Members</a></li>
                            <li>Click "Import CSV"</li>
                            <li>Select your CSV file</li>
                            <li>Click "Import Members"</li>
                            <li>Generated passwords will be shown for new members</li>
                        </ol>
                    </div>
                    <div class="col-md-6 mb-3">
                        <h6>How to view a member's statement:</h6>
                        <ol>
                            <li>Go to <a href="<?php echo APP_URL; ?>/treasurer/members.php">Members</a></li>
                            <li>Click "View" on the member</li>
                            <li>Click "Statement" button in the member detail page</li>
                            <li>Or go directly to <code>/treasurer/statement.php?member_id=XXX</code></li>
                        </ol>
                    </div>
                    <div class="col-md-6 mb-3">
                        <h6>How to update welfare settings:</h6>
                        <ol>
                            <li>Go to <a href="<?php echo APP_URL; ?>/treasurer/settings.php">Settings</a></li>
                            <li>Enter the new annual and monthly contribution targets</li>
                            <li>Click "Update Settings"</li>
                            <li>All progress bars and charts will update automatically</li>
                        </ol>
                    </div>
                    <div class="col-md-6 mb-3">
                        <h6>How to check who hasn't paid:</h6>
                        <ol>
                            <li>Go to the <a href="<?php echo APP_URL; ?>/treasurer/dashboard.php">Dashboard</a></li>
                            <li>Check the "Pending Payments" stat card</li>
                            <li>Scroll down to the "Members Pending Payment" section for the full list</li>
                            <li>You can send reminders directly from there</li>
                        </ol>
                    </div>
                    <div class="col-md-6 mb-3">
                        <h6>How to search for a member:</h6>
                        <ol>
                            <li>Go to the <a href="<?php echo APP_URL; ?>/treasurer/dashboard.php">Dashboard</a></li>
                            <li>Type in the search box at the top (Member ID, Phone, or Name)</li>
                            <li>Results appear instantly with photos</li>
                            <li>Click a result to open the Member Detail page</li>
                        </ol>
                        <p class="small text-muted mb-0">Phone search is country-code tolerant: searching <code>0595360050</code>, <code>233595360050</code>, or <code>+233 595360050</code> all find the same member. Only members who exist in the database are returned.</p>
                    </div>
                    <div class="col-md-6 mb-3">
                        <h6>How to suspend or delete a member:</h6>
                        <ol>
                            <li>Go to <a href="<?php echo APP_URL; ?>/treasurer/members.php">Members</a></li>
                            <li>Find the member and use the <strong>Status</strong> buttons (Suspend / Deactivate / Delete / Reactivate)</li>
                            <li>Confirm the popup — the change applies immediately</li>
                            <li>Suspended/deactivated/deleted members cannot log in</li>
                        </ol>
                    </div>
                    <div class="col-md-6 mb-3">
                        <h6>How to reset the database:</h6>
                        <ol>
                            <li>Go to <a href="<?php echo APP_URL; ?>/treasurer/settings.php">Settings</a></li>
                            <li>Scroll to the red <strong>Danger Zone</strong> card</li>
                            <li>Tick the tables to clear, type <code>RESET</code>, and confirm</li>
                            <li>The treasurer account and settings are always kept</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>


<!-- Executive Tier System -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header bg-warning">
                <h5 class="mb-0">⭐ Executive Tier System (Gold & Silver)</h5>
            </div>
            <div class="card-body">
                <p>The system supports two executive tiers: <strong>Gold</strong> and <strong>Silver</strong>. Executives have separate annual and monthly contribution targets, which are usually higher than regular member targets.</p>
                
                <h6>How to promote a member to executive:</h6>
                <ol>
                    <li>Go to <a href="<?php echo APP_URL; ?>/treasurer/members.php">Members</a></li>
                    <li>Find the member in the list</li>
                    <li>Click the <strong>⭐ Gold</strong> or <strong>🥈 Silver</strong> button next to their name</li>
                    <li>Confirm the promotion</li>
                    <li>The member receives an automatic email notification</li>
                </ol>
                
                <h6>How to set executive targets:</h6>
                <ol>
                    <li>Go to <a href="<?php echo APP_URL; ?>/treasurer/settings.php">Settings</a></li>
                    <li>Scroll to the <strong>⭐ Executive Targets</strong> card</li>
                    <li>Enter the annual and monthly amounts for Gold and Silver executives</li>
                    <li>Click <strong>Update Executive Targets</strong></li>
                </ol>
                
                <h6>How executive targets work:</h6>
                <ul>
                    <li>When a member is promoted to Gold or Silver, their contributions are automatically tracked against the executive targets instead of regular member targets</li>
                    <li>If a member had already made payments before promotion, those payments carry over and are counted toward the new executive target</li>
                    <li>The annual limit check in the Record Payment page uses the executive target for Gold/Silver members</li>
                    <li>Progress bars, debt calculations, and defaulters list all respect the executive tier</li>
                    <li>Executive badges appear on the member dashboard, members list, and receipts</li>
                </ul>
                
                <h6>How to demote an executive:</h6>
                <ol>
                    <li>Go to <a href="<?php echo APP_URL; ?>/treasurer/members.php">Members</a></li>
                    <li>Find the executive member</li>
                    <li>Click the <strong>Demote</strong> button</li>
                    <li>Confirm — the member returns to regular member status and regular targets apply</li>
                </ol>
                
                <div class="alert alert-info">
                    <strong>💡 Note:</strong> Executive targets are set per calendar year. You can configure different targets for each year in Settings.
                </div>
            </div>
        </div>
    </div>
</div><!-- Tips & Best Practices -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0">💡 Tips & Best Practices</h5>
            </div>
            <div class="card-body">
                <ul>
                    <li><strong>Record payments promptly:</strong> Enter payments as soon as they are received to keep records accurate.</li>
                    <li><strong>Use the correct billing period:</strong> Always select the correct month and year when recording a payment. This ensures accurate progress tracking.</li>
                    <li><strong>Send reminders regularly:</strong> Use the dashboard's reminder feature to encourage timely payments.</li>
                    <li><strong>Verify before voiding:</strong> Double-check before voiding a transaction. If you make a mistake, record a new payment with the correct details instead.</li>
                    <li><strong>Keep settings updated:</strong> If contribution amounts change, update them in Settings immediately.</li>
                    <li><strong>Review audit logs:</strong> Periodically check the audit logs to ensure all actions are legitimate.</li>
                    <li><strong>Export regularly:</strong> Export monthly/yearly reports for your records using the CSV or PDF export features.</li>
                    <li><strong>Use the member detail page:</strong> It's the most efficient way to manage a member's account - record payments, view history, and generate statements all in one place.</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<!-- Troubleshooting -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header bg-warning">
                <h5 class="mb-0">🔧 Troubleshooting</h5>
            </div>
            <div class="card-body">
                <h6>Payment not recorded?</h6>
                <ul>
                    <li>Check if you filled in all required fields</li>
                    <li>Ensure the member exists (check member ID)</li>
                    <li>Verify the billing period hasn't already been paid</li>
                    <li>Make sure the amount doesn't exceed the annual limit</li>
                </ul>
                
                <h6>Receipt email not sent?</h6>
                <ul>
                    <li>Check the member's email address in their profile</li>
                    <li>Verify the email service is configured correctly</li>
                    <li>Check spam/junk folder</li>
                    <li>Contact support if emails consistently fail</li>
                </ul>
                
                <h6>Password reset not working?</h6>
                <ul>
                    <li>Members: Use the <a href="<?php echo APP_URL; ?>/member/forgot-password.php">Forgot Password</a> page and enter your Member ID</li>
                    <li>Treasurer: Enter your registered email address on the forgot password page</li>
                    <li>Reset links expire after 1 hour</li>
                    <li>Check spam folder if you don't see the email</li>
                </ul>
                
                <h6>Member photo not showing in receipt email?</h6>
                <ul>
                    <li>Photos are included automatically if the member has uploaded one during registration</li>
                    <li>The photo appears as a circular avatar at the top of the receipt email</li>
                    <li>If no photo was uploaded, the receipt is sent without the image (this is normal)</li>
                </ul>
                
                <h6>Member not showing up?</h6>
                <ul>
                    <li>Make sure the member was successfully imported or registered</li>
                    <li>Check if the member was deleted</li>
                    <li>Refresh the page</li>
                </ul>
                
                <h6>Progress bar showing 0%?</h6>
                <ul>
                    <li>The member hasn't made any payments yet</li>
                    <li>Check if payments were recorded for the correct billing year</li>
                    <li>Verify the annual target is set in Settings</li>
                </ul>
                
                <div class="alert alert-danger">
                    <strong>🆘 Need more help?</strong> Contact the system administrator or check the system documentation.
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>





