<?php
session_start();
$page_title = "Owner Dashboard";
include_once '../config/database.php';
include_once '../config/auth.php';
include_once '../includes/header.php';

// Initialize database connection
$database = new Database();
$db = $database->getConnection();

// Get financial summary
$financial_query = "SELECT 
    (SELECT COALESCE(SUM(amount), 0) FROM funds) AS total_investment,
    (SELECT COALESCE(SUM(total_amount), 0) FROM purchases) AS total_purchases,
    (SELECT COALESCE(SUM(amount), 0) FROM manufacturing_costs) AS total_manufacturing_costs,
    (SELECT COALESCE(SUM(net_amount), 0) FROM sales) AS total_sales,
    (SELECT COALESCE(SUM(amount), 0) FROM payments) AS total_payments";
$financial_stmt = $db->prepare($financial_query);
$financial_stmt->execute();
$financial = $financial_stmt->fetch(PDO::FETCH_ASSOC);

// Calculate profit
$total_cost = $financial['total_purchases'] + $financial['total_manufacturing_costs'];
$profit = $financial['total_sales'] - $total_cost;
$profit_margin = $financial['total_sales'] > 0 ? ($profit / $financial['total_sales'] * 100) : 0;

// Get recent manufacturing batches
$batches_query = "SELECT b.id, b.batch_number, p.name as product_name, b.quantity_produced, b.status, b.start_date, b.completion_date 
                 FROM manufacturing_batches b 
                 JOIN products p ON b.product_id = p.id 
                 ORDER BY b.created_at DESC LIMIT 5";
$batches_stmt = $db->prepare($batches_query);
$batches_stmt->execute();

// Get pending receivables
$receivables_query = "SELECT s.id, s.invoice_number, c.name as customer_name, s.sale_date, s.net_amount, 
                     (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE sale_id = s.id) as amount_paid,
                     s.payment_due_date
                     FROM sales s 
                     JOIN customers c ON s.customer_id = c.id 
                     WHERE s.payment_status IN ('unpaid', 'partial')
                     ORDER BY s.payment_due_date ASC LIMIT 5";
$receivables_stmt = $db->prepare($receivables_query);
$receivables_stmt->execute();

// Get recent activity logs
$logs_query = "SELECT l.id, u.username, l.action_type, l.module, l.description, l.created_at 
              FROM activity_logs l 
              JOIN users u ON l.user_id = u.id 
              ORDER BY l.created_at DESC LIMIT 10";
$logs_stmt = $db->prepare($logs_query);
$logs_stmt->execute();
?>

<div class="dashboard-stats">
    <div class="stat-card">
        <div class="stat-value"><?php echo number_format($financial['total_investment'], 2); ?></div>
        <div class="stat-label">Total Investment</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo number_format($financial['total_sales'], 2); ?></div>
        <div class="stat-label">Total Sales</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo number_format($profit, 2); ?></div>
        <div class="stat-label">Profit</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo number_format($profit_margin, 2); ?>%</div>
        <div class="stat-label">Profit Margin</div>
    </div>
</div>

<div class="dashboard-grid">
    <div class="dashboard-card">
        <div class="card-header">
            <h2>Recent Manufacturing Batches</h2>
            <a href="manufacturing.php" class="view-all">View All</a>
        </div>
        <div class="card-content">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Batch #</th>
                        <th>Product</th>
                        <th>Quantity</th>
                        <th>Status</th>
                        <th>Start Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($batch = $batches_stmt->fetch(PDO::FETCH_ASSOC)): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($batch['batch_number']); ?></td>
                        <td><?php echo htmlspecialchars($batch['product_name']); ?></td>
                        <td><?php echo htmlspecialchars($batch['quantity_produced']); ?></td>
                        <td><span class="status-badge status-<?php echo $batch['status']; ?>"><?php echo ucfirst($batch['status']); ?></span></td>
                        <td><?php echo htmlspecialchars($batch['start_date']); ?></td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <div class="dashboard-card">
        <div class="card-header">
            <h2>Pending Receivables</h2>
            <a href="sales.php" class="view-all">View All</a>
        </div>
        <div class="card-content">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Invoice #</th>
                        <th>Customer</th>
                        <th>Amount</th>
                        <th>Due Date</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($receivable = $receivables_stmt->fetch(PDO::FETCH_ASSOC)): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($receivable['invoice_number']); ?></td>
                        <td><?php echo htmlspecialchars($receivable['customer_name']); ?></td>
                        <td><?php echo number_format($receivable['net_amount'] - $receivable['amount_paid'], 2); ?></td>
                        <td><?php echo htmlspecialchars($receivable['payment_due_date']); ?></td>
                        <td>
                            <?php 
                            $today = new DateTime();
                            $due_date = new DateTime($receivable['payment_due_date']);
                            $status = 'upcoming';
                            if($today > $due_date) {
                                $status = 'overdue';
                            }
                            ?>
                            <span class="status-badge status-<?php echo $status; ?>">
                                <?php echo $status === 'overdue' ? 'Overdue' : 'Upcoming'; ?>
                            </span>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <div class="dashboard-card full-width">
        <div class="card-header">
            <h2>Recent Activity</h2>
            <a href="activity-logs.php" class="view-all">View All</a>
        </div>
        <div class="card-content">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Action</th>
                        <th>Module</th>
                        <th>Description</th>
                        <th>Timestamp</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($log = $logs_stmt->fetch(PDO::FETCH_ASSOC)): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($log['username']); ?></td>
                        <td><?php echo ucfirst(htmlspecialchars($log['action_type'])); ?></td>
                        <td><?php echo ucfirst(htmlspecialchars($log['module'])); ?></td>
                        <td><?php echo htmlspecialchars($log['description']); ?></td>
                        <td><?php echo htmlspecialchars($log['created_at']); ?></td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include_once '../includes/footer.php'; ?>