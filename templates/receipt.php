
function renderReceipt($transaction, $show_billing_period = true, $show_member_id = true) {
    $member_label = htmlspecialchars($transaction['full_name']);
    if ($show_member_id && !empty($transaction['member_id'])) {
        $member_label .= ' (' . htmlspecialchars($transaction['member_id']) . ')';
    }
    $billing_period = '';
    if ($show_billing_period) {
        if (!empty($transaction['billing_cycle_month'])) {
            $billing_period = formatBillingPeriod($transaction['billing_cycle_month'], $transaction['billing_cycle_year'] ?? date('Y'));
        } elseif (!empty($transaction['billing_cycle_year'])) {
            $billing_period = htmlspecialchars($transaction['billing_cycle_year']);
        }
    }
    $ts = strtotime($transaction['transaction_date']);
    $date = $ts ? htmlspecialchars(date('F j, Y g:i A', $ts)) : 'N/A';
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Receipt <?php echo htmlspecialchars($transaction['receipt_no']); ?></title>
        <link rel="stylesheet" href="<?php echo APP_URL; ?>/assets/bootstrap/css/bootstrap.min.css">
        <style>
            body { padding: 20px; }
            @media print {
                .no-print { display: none; }
            }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="receipt">
                <div class="text-center mb-4">
                    <h3>GYF Welfare Management System</h3>
                    <h4>Payment Receipt</h4>
                </div>
                
                <div class="table-responsive">
                <table class="table table-bordered">
                    <tr>
                        <td><strong>Receipt No:</strong></td>
                        <td><?php echo htmlspecialchars($transaction['receipt_no']); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Member:</strong></td>
                        <td><?php echo $member_label; ?></td>
                    </tr>
                    <tr>
                        <td><strong>Amount:</strong></td>
                        <td>GH₵ <?php echo number_format($transaction['amount'], 2); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Payment Method:</strong></td>
                        <td><?php echo htmlspecialchars($transaction['payment_method']); ?></td>
                    </tr>
                    <?php if ($show_billing_period && $billing_period): ?>
                    <tr>
                        <td><strong>Billing Period:</strong></td>
                        <td><?php echo $billing_period; ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <td><strong>Date:</strong></td>
                        <td><?php echo $date; ?></td>
                    </tr>
                </table>
                </div>
                
                <div class="text-center mt-4">
                    <button class="btn btn-primary no-print js-print-receipt">Print Receipt</button>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
