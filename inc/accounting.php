<?php
/**
 * Conservative Revenue Recognition Accounting Module
 *
 * Provides the \App\Accounting namespace classes used by inc/functions.php
 * for credit sale tracking, payment processing, penalties, write-offs, and reversals.
 *
 * Key design principle: revenue is recognized only when payment is received
 * (conservative approach). Until payment, only the cost-price loss is recorded.
 */

namespace App\Accounting;

/**
 * Value object representing the result of a payment.
 */
class PaymentResult {
    /** @var array<int, array{type: string, account: string, amount: float, description: string}> */
    public array $journalEntries = [];
    public float $amountReceived = 0;
    public float $cumulativePayment = 0;
    public float $saleAmount = 0;
    public string $state = '';

    public function __construct(float $amountReceived = 0, float $cumulativePayment = 0, float $saleAmount = 0, string $state = '') {
        $this->amountReceived = $amountReceived;
        $this->cumulativePayment = $cumulativePayment;
        $this->saleAmount = $saleAmount;
        $this->state = $state;
    }

    public function toArray(): array {
        return [
            'amount_received' => $this->amountReceived,
            'cumulative_payment' => $this->cumulativePayment,
            'sale_amount' => $this->saleAmount,
            'state' => $this->state,
            'journal_entries' => $this->journalEntries,
        ];
    }
}

/**
 * Value object for penalty results.
 */
class PenaltyResult {
    public float $penaltyAmount = 0;
    public float $cumulativePenalty = 0;
    /** @var array<int, array{type: string, account: string, amount: float, description: string}> */
    public array $journalEntries = [];

    public function toArray(): array {
        return [
            'penalty_amount' => $this->penaltyAmount,
            'cumulative_penalty' => $this->cumulativePenalty,
            'journal_entries' => $this->journalEntries,
        ];
    }
}

/**
 * Value object for write-off results.
 */
class WriteOffResult {
    public float $requestedAmount = 0;
    public float $actualWriteOff = 0;
    public float $remainingObligation = 0;
    /** @var array<int, array{type: string, account: string, amount: float, description: string}> */
    public array $journalEntries = [];

    public function toArray(): array {
        return [
            'requested_amount' => $this->requestedAmount,
            'actual_write_off' => $this->actualWriteOff,
            'remaining_obligation' => $this->remainingObligation,
            'journal_entries' => $this->journalEntries,
        ];
    }
}

/**
 * Value object for reversal results.
 */
class ReversalResult {
    public float $reversedAmount = 0;
    /** @var array<int, array{type: string, account: string, amount: float, description: string}> */
    public array $journalEntries = [];

    public function toArray(): array {
        return [
            'reversed_amount' => $this->reversedAmount,
            'journal_entries' => $this->journalEntries,
        ];
    }
}

/**
 * Core credit accounting service — manages the lifecycle of a credit transaction.
 *
 * States:
 *  0 = OPEN (credit sale, no payment)
 *  1 = PARTIAL (some payment received)
 *  2 = PAID (fully paid)
 *  3 = WRITE_OFF (unrecoverable)
 *  4 = REVERSED (revenue reversed)
 */
class CreditAccountingService {
    private \PDO $db;

    private const STATE_OPEN = 0;
    private const STATE_PARTIAL = 1;
    private const STATE_PAID = 2;
    private const STATE_WRITE_OFF = 3;
    private const STATE_REVERSED = 4;

    private const STATE_LABELS = [
        self::STATE_OPEN => 'Open',
        self::STATE_PARTIAL => 'Partial',
        self::STATE_PAID => 'Paid',
        self::STATE_WRITE_OFF => 'Write-Off',
        self::STATE_REVERSED => 'Reversed',
    ];

    public function __construct(\PDO $db) {
        $this->db = $db;
    }

    /**
     * Create (or reload) a credit transaction for an order.
     * Returns a ConservativeRevenueRecognition model that can receive payments.
     */
    public function createCreditTransaction(int $orderId, float $saleAmount, ?float $penaltyRate = null, ?int $paymentDeadline = null): ConservativeRevenueRecognition {
        // Load existing state or create fresh
        $stmt = $this->db->prepare('SELECT * FROM credit_transaction_states WHERE transaction_id = :tid');
        $stmt->execute([':tid' => $orderId]);
        $existing = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($existing) {
            $state = (int) $existing['state'];
            $lossAmount = (float) $existing['loss_amount'];
            $revenueAmount = (float) $existing['revenue_amount'];
            $cumulativePayment = (float) $existing['cumulative_payment'];
        } else {
            // Cost-price loss on credit sale (conservative: revenue not yet recognized)
            $lossAmount = $this->estimateCostPrice($orderId, $saleAmount);
            $revenueAmount = 0.0;
            $cumulativePayment = 0.0;
            $state = self::STATE_OPEN;

            $this->saveState($orderId, $state, $saleAmount, $lossAmount, $revenueAmount, $cumulativePayment);

            // Record initial journal entry
            $this->addJournalEntry($orderId, 'debit', 'COST_OF_GOODS_SOLD', $lossAmount, 'Cost of goods sold (credit sale)');
            $this->addJournalEntry($orderId, 'credit', 'INVENTORY', $lossAmount, 'Inventory reduction');
        }

        return new ConservativeRevenueRecognition(
            $this->db,
            $orderId,
            $saleAmount,
            $lossAmount,
            $revenueAmount,
            $cumulativePayment,
            $state,
            $penaltyRate,
            $paymentDeadline
        );
    }

    /**
     * Process a payment for a credit order.
     */
    public function processPayment(int $orderId, float $amount, string $reference = ''): PaymentResult {
        $crm = $this->createCreditTransaction($orderId, $this->getSaleAmount($orderId));
        return $crm->receivePayment($amount, time(), $reference);
    }

    /**
     * Apply a penalty for overdue credit.
     */
    public function applyPenalty(int $orderId, int $daysOverdue): PenaltyResult {
        $saleAmount = $this->getSaleAmount($orderId);
        $crm = $this->createCreditTransaction($orderId, $saleAmount);
        return $crm->applyPenalty($daysOverdue);
    }

    /**
     * Write off an unrecoverable credit amount.
     */
    public function writeOffUnrecoverable(int $orderId, float $amount): WriteOffResult {
        $saleAmount = $this->getSaleAmount($orderId);
        $crm = $this->createCreditTransaction($orderId, $saleAmount);
        return $crm->writeOff($amount);
    }

    /**
     * Reverse revenue recognition for a credit transaction.
     */
    public function reverseRevenueRecognition(int $orderId, string $reason): ReversalResult {
        $saleAmount = $this->getSaleAmount($orderId);
        $crm = $this->createCreditTransaction($orderId, $saleAmount);
        return $crm->reverseRevenue($reason);
    }

    /**
     * Get full ledger for a transaction.
     */
    public function getTransactionLedger(int $orderId): array {
        $stmt = $this->db->prepare('SELECT * FROM credit_transaction_states WHERE transaction_id = :tid');
        $stmt->execute([':tid' => $orderId]);
        $state = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$state) {
            return [];
        }

        $journalEntries = $this->getJournalEntries($orderId);
        $payments = $this->getPayments($orderId);
        $penalties = $this->getPenalties($orderId);
        $writeOffs = $this->getWriteOffs($orderId);
        $reversals = $this->getReversals($orderId);

        return [
            'order_id' => $orderId,
            'state' => self::STATE_LABELS[(int) $state['state']] ?? 'Unknown',
            'sale_amount' => (float) $state['sale_amount'],
            'loss_amount' => (float) $state['loss_amount'],
            'revenue_amount' => (float) $state['revenue_amount'],
            'cumulative_payment' => (float) $state['cumulative_payment'],
            'journal_entries' => $journalEntries,
            'payments' => $payments,
            'penalties' => $penalties,
            'write_offs' => $write_offs,
            'reversals' => $reversals,
        ];
    }

    /**
     * Get period summary for balance sheet display.
     */
    public function getPeriodSummary(string $period = 'all', ?string $startDate = null, ?string $endDate = null): array {
        $sql = 'SELECT cts.*, o.total as order_total
                FROM credit_transaction_states cts
                JOIN orders o ON cts.transaction_id = o.id';

        $params = [];
        $conditions = [];

        if ($period !== 'all' && $startDate && $endDate) {
            $conditions[] = 'o.created_at BETWEEN :start_date AND :end_date';
            $params[':start_date'] = $startDate;
            $params[':end_date'] = $endDate;
        }

        if (!empty($conditions)) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $sql .= ' ORDER BY o.created_at DESC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $orders = [];
        $ordersInLoss = 0;
        $ordersInRevenue = 0;
        $totalLoss = 0.0;
        $totalRevenue = 0.0;
        $totalPending = 0.0;
        $totalPenalties = 0.0;
        $totalWriteOffs = 0.0;

        foreach ($rows as $row) {
            $saleAmount = (float) $row['sale_amount'];
            $lossAmount = (float) $row['loss_amount'];
            $revenueAmount = (float) $row['revenue_amount'];
            $cumulativePayment = (float) $row['cumulative_payment'];
            $state = (int) $row['state'];
            $outstanding = max(0, $saleAmount - $cumulativePayment);

            $stateLabel = self::STATE_LABELS[$state] ?? 'Unknown';

            $orders[] = [
                'order_id' => (int) $row['transaction_id'],
                'state' => $stateLabel,
                'sale_amount' => $saleAmount,
                'paid' => $cumulativePayment,
                'loss' => $lossAmount,
                'revenue' => $revenueAmount,
                'outstanding' => $outstanding,
            ];

            if ($lossAmount > $revenueAmount) {
                $ordersInLoss++;
            }
            if ($revenueAmount > 0) {
                $ordersInRevenue++;
            }

            $totalLoss += $lossAmount;
            $totalRevenue += $revenueAmount;
            $totalPending += $outstanding;

            // Sum penalties and write-offs for this order
            $penalties = $this->getPenalties((int) $row['transaction_id']);
            foreach ($penalties as $p) {
                $totalPenalties += (float) $p['penalty_amount'];
            }

            $writeOffs = $this->getWriteOffs((int) $row['transaction_id']);
            foreach ($writeOffs as $w) {
                $totalWriteOffs += (float) $w['actual_write_off'];
            }
        }

        return [
            'orders' => $orders,
            'orders_in_loss' => $ordersInLoss,
            'orders_in_revenue' => $ordersInRevenue,
            'total_loss' => $totalLoss,
            'total_revenue' => $totalRevenue,
            'total_pending' => $totalPending,
            'total_penalties' => $totalPenalties,
            'total_write_offs' => $totalWriteOffs,
        ];
    }

    // ---- Internal helpers ----

    private function getSaleAmount(int $orderId): float {
        $stmt = $this->db->prepare('SELECT total FROM orders WHERE id = :id');
        $stmt->execute([':id' => $orderId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? (float) $row['total'] : 0.0;
    }

    private function estimateCostPrice(int $orderId, float $saleAmount): float {
        // Try to look up actual cost from order_items
        $stmt = $this->db->prepare(
            'SELECT SUM(oi.quantity * p.cost_price) as total_cost
             FROM order_items oi
             JOIN products p ON oi.product_id = p.id
             WHERE oi.order_id = :oid'
        );
        $stmt->execute([':oid' => $orderId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row && (float) $row['total_cost'] > 0) {
            return (float) $row['total_cost'];
        }
        // Fallback: assume 40% cost ratio
        return round($saleAmount * 0.40, 2);
    }

    private function addJournalEntry(int $orderId, string $type, string $account, float $amount, string $description, string $reference = ''): void {
        $stmt = $this->db->prepare(
            'INSERT INTO credit_journal_entries (transaction_id, entry_type, entry_kind, account, amount, description, reference, created_at)
             VALUES (:tid, :type, :kind, :account, :amount, :desc, :ref, :created_at)'
        );
        $stmt->execute([
            ':tid' => $orderId,
            ':type' => $type,
            ':kind' => 'AUTO',
            ':account' => $account,
            ':amount' => $amount,
            ':desc' => $description,
            ':ref' => $reference,
            ':created_at' => date('c'),
        ]);
    }

    private function saveState(int $orderId, int $state, float $saleAmount, float $lossAmount, float $revenueAmount, float $cumulativePayment): void {
        $stmt = $this->db->prepare(
            'INSERT OR REPLACE INTO credit_transaction_states (transaction_id, state, sale_amount, loss_amount, revenue_amount, cumulative_payment, updated_at)
             VALUES (:tid, :state, :sale, :loss, :rev, :cum, :updated)'
        );
        $stmt->execute([
            ':tid' => $orderId,
            ':state' => $state,
            ':sale' => $saleAmount,
            ':loss' => $lossAmount,
            ':rev' => $revenueAmount,
            ':cum' => $cumulativePayment,
            ':updated' => date('c'),
        ]);
    }

    private function getJournalEntries(int $orderId): array {
        $stmt = $this->db->prepare('SELECT * FROM credit_journal_entries WHERE transaction_id = :tid ORDER BY created_at ASC, id ASC');
        $stmt->execute([':tid' => $orderId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function getPayments(int $orderId): array {
        $stmt = $this->db->prepare('SELECT * FROM credit_payments WHERE transaction_id = :tid ORDER BY created_at ASC, id ASC');
        $stmt->execute([':tid' => $orderId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function getPenalties(int $orderId): array {
        $stmt = $this->db->prepare('SELECT * FROM credit_penalties WHERE transaction_id = :tid ORDER BY created_at ASC, id ASC');
        $stmt->execute([':tid' => $orderId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function getWriteOffs(int $orderId): array {
        $stmt = $this->db->prepare('SELECT * FROM credit_write_offs WHERE transaction_id = :tid ORDER BY created_at ASC, id ASC');
        $stmt->execute([':tid' => $orderId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function getReversals(int $orderId): array {
        $stmt = $this->db->prepare('SELECT * FROM credit_reversals WHERE transaction_id = :tid ORDER BY created_at ASC, id ASC');
        $stmt->execute([':tid' => $orderId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}

/**
 * Conservative Revenue Recognition Model
 *
 * Tracks a single credit order through its lifecycle. Revenue is recognized
 * only when payments are received (conservative approach).
 */
class ConservativeRevenueRecognition {
    private \PDO $db;
    private int $orderId;
    private float $saleAmount;
    private float $lossAmount;
    private float $revenueAmount;
    private float $cumulativePayment;
    private int $state;
    private ?float $penaltyRate;
    private ?int $paymentDeadline;

    public function __construct(
        \PDO $db,
        int $orderId,
        float $saleAmount,
        float $lossAmount,
        float $revenueAmount,
        float $cumulativePayment,
        int $state,
        ?float $penaltyRate = null,
        ?int $paymentDeadline = null
    ) {
        $this->db = $db;
        $this->orderId = $orderId;
        $this->saleAmount = $saleAmount;
        $this->lossAmount = $lossAmount;
        $this->revenueAmount = $revenueAmount;
        $this->cumulativePayment = $cumulativePayment;
        $this->state = $state;
        $this->penaltyRate = $penaltyRate;
        $this->paymentDeadline = $paymentDeadline;
    }

    /**
     * Receive a payment. Updates state and returns journal entries.
     */
    public function receivePayment(float $amount, int $timestamp, string $reference = ''): PaymentResult {
        $result = new PaymentResult();
        $result->amountReceived = $amount;
        $this->cumulativePayment += $amount;

        // Recognize revenue up to the payment amount
        $revenueToRecognize = min($amount, $this->saleAmount - $this->revenueAmount);
        if ($revenueToRecognize > 0) {
            $this->revenueAmount += $revenueToRecognize;

            $result->journalEntries[] = [
                'type' => 'debit',
                'account' => 'CASH',
                'amount' => $amount,
                'description' => 'Cash received from credit payment',
            ];
            $result->journalEntries[] = [
                'type' => 'credit',
                'account' => 'REVENUE',
                'amount' => $revenueToRecognize,
                'description' => 'Revenue recognized: GH₵' . number_format($revenueToRecognize, 2),
            ];

            if ($amount > $revenueToRecognize) {
                $result->journalEntries[] = [
                    'type' => 'credit',
                    'account' => 'COST_OF_GOODS_SOLD',
                    'amount' => $revenueToRecognize,
                    'description' => 'COGS offset by revenue recognition',
                ];
            }
        } else {
            $result->journalEntries[] = [
                'type' => 'debit',
                'account' => 'CASH',
                'amount' => $amount,
                'description' => 'Cash received from credit payment',
            ];
        }

        // Determine state
        if ($this->cumulativePayment >= $this->saleAmount - 0.01) {
            $this->state = 2; // PAID
        } elseif ($this->cumulativePayment > 0) {
            $this->state = 1; // PARTIAL
        }

        $result->cumulativePayment = $this->cumulativePayment;
        $result->saleAmount = $this->saleAmount;
        $result->state = $this->getStateLabel();

        // Record payment
        $stmt = $this->db->prepare(
            'INSERT INTO credit_payments (transaction_id, amount, cumulative_payment, reference, created_at)
             VALUES (:tid, :amount, :cum, :ref, :created_at)'
        );
        $stmt->execute([
            ':tid' => $this->orderId,
            ':amount' => $amount,
            ':cum' => $this->cumulativePayment,
            ':ref' => $reference,
            ':created_at' => date('c', $timestamp),
        ]);

        // Record journal entries
        foreach ($result->journalEntries as $entry) {
            $stmt = $this->db->prepare(
                'INSERT INTO credit_journal_entries (transaction_id, entry_type, entry_kind, account, amount, description, reference, created_at)
                 VALUES (:tid, :type, :kind, :account, :amount, :desc, :ref, :created_at)'
            );
            $stmt->execute([
                ':tid' => $this->orderId,
                ':type' => $entry['type'],
                ':kind' => 'PAYMENT',
                ':account' => $entry['account'],
                ':amount' => $entry['amount'],
                ':desc' => $entry['description'],
                ':ref' => $reference,
                ':created_at' => date('c', $timestamp),
            ]);
        }

        // Update state
        $this->saveState();

        return $result;
    }

    /**
     * Apply a late penalty.
     */
    public function applyPenalty(int $daysOverdue): PenaltyResult {
        $result = new PenaltyResult();
        $rate = $this->penaltyRate ?? 0.05; // default 5%
        $penaltyAmount = round($this->saleAmount * $rate * $daysOverdue / 30, 2);
        $result->penaltyAmount = $penaltyAmount;

        $result->journalEntries[] = [
            'type' => 'debit',
            'account' => 'PENALTY_EXPENSE',
            'amount' => $penaltyAmount,
            'description' => "Late penalty: {$daysOverdue} days overdue",
        ];
        $result->journalEntries[] = [
            'type' => 'credit',
            'account' => 'PENALTY_REVENUE',
            'amount' => $penaltyAmount,
            'description' => "Penalty income: {$daysOverdue} days",
        ];

        // Record penalty
        $stmt = $this->db->prepare(
            'INSERT INTO credit_penalties (transaction_id, days_overdue, penalty_amount, created_at)
             VALUES (:tid, :days, :amount, :created_at)'
        );
        $stmt->execute([
            ':tid' => $this->orderId,
            ':days' => $daysOverdue,
            ':amount' => $penaltyAmount,
            ':created_at' => date('c'),
        ]);

        // Record journal entries
        foreach ($result->journalEntries as $entry) {
            $stmt = $this->db->prepare(
                'INSERT INTO credit_journal_entries (transaction_id, entry_type, entry_kind, account, amount, description, reference, created_at)
                 VALUES (:tid, :type, :kind, :account, :amount, :desc, :ref, :created_at)'
            );
            $stmt->execute([
                ':tid' => $this->orderId,
                ':type' => $entry['type'],
                ':kind' => 'PENALTY',
                ':account' => $entry['account'],
                ':amount' => $entry['amount'],
                ':desc' => $entry['description'],
                ':ref' => '',
                ':created_at' => date('c'),
            ]);
        }

        return $result;
    }

    /**
     * Write off an unrecoverable amount.
     */
    public function writeOff(float $amount): WriteOffResult {
        $result = new WriteOffResult();
        $result->requestedAmount = $amount;
        $actualWriteOff = min($amount, $this->saleAmount - $this->cumulativePayment);
        $result->actualWriteOff = $actualWriteOff;
        $result->remainingObligation = max(0, $this->saleAmount - $this->cumulativePayment - $actualWriteOff);

        $result->journalEntries[] = [
            'type' => 'debit',
            'account' => 'WRITE_OFF_EXPENSE',
            'amount' => $actualWriteOff,
            'description' => 'Credit write-off: unrecoverable debt',
        ];
        $result->journalEntries[] = [
            'type' => 'credit',
            'account' => 'ACCOUNTS_RECEIVABLE',
            'amount' => $actualWriteOff,
            'description' => 'Remove unrecoverable receivable',
        ];

        // Record write-off
        $stmt = $this->db->prepare(
            'INSERT INTO credit_write_offs (transaction_id, requested_amount, actual_write_off, remaining_obligation, created_at)
             VALUES (:tid, :requested, :actual, :remaining, :created_at)'
        );
        $stmt->execute([
            ':tid' => $this->orderId,
            ':requested' => $amount,
            ':actual' => $actualWriteOff,
            ':remaining' => $result->remainingObligation,
            ':created_at' => date('c'),
        ]);

        // Record journal entries
        foreach ($result->journalEntries as $entry) {
            $stmt = $this->db->prepare(
                'INSERT INTO credit_journal_entries (transaction_id, entry_type, entry_kind, account, amount, description, reference, created_at)
                 VALUES (:tid, :type, :kind, :account, :amount, :desc, :ref, :created_at)'
            );
            $stmt->execute([
                ':tid' => $this->orderId,
                ':type' => $entry['type'],
                ':kind' => 'WRITE_OFF',
                ':account' => $entry['account'],
                ':amount' => $entry['amount'],
                ':desc' => $entry['description'],
                ':ref' => '',
                ':created_at' => date('c'),
            ]);
        }

        if ($result->remainingObligation <= 0.01) {
            $this->state = 3; // WRITE_OFF
            $this->saveState();
        }

        return $result;
    }

    /**
     * Reverse revenue recognition.
     */
    public function reverseRevenue(string $reason): ReversalResult {
        $result = new ReversalResult();
        $result->reversedAmount = $this->revenueAmount;

        $result->journalEntries[] = [
            'type' => 'debit',
            'account' => 'REVENUE_REVERSAL',
            'amount' => $this->revenueAmount,
            'description' => 'Revenue reversal: ' . $reason,
        ];
        $result->journalEntries[] = [
            'type' => 'credit',
            'account' => 'REVENUE',
            'amount' => $this->revenueAmount,
            'description' => 'Reverse recognized revenue',
        ];

        // Record reversal
        $stmt = $this->db->prepare(
            'INSERT INTO credit_reversals (transaction_id, reason, created_at)
             VALUES (:tid, :reason, :created_at)'
        );
        $stmt->execute([
            ':tid' => $this->orderId,
            ':reason' => $reason,
            ':created_at' => date('c'),
        ]);

        // Record journal entries
        foreach ($result->journalEntries as $entry) {
            $stmt = $this->db->prepare(
                'INSERT INTO credit_journal_entries (transaction_id, entry_type, entry_kind, account, amount, description, reference, created_at)
                 VALUES (:tid, :type, :kind, :account, :amount, :desc, :ref, :created_at)'
            );
            $stmt->execute([
                ':tid' => $this->orderId,
                ':type' => $entry['type'],
                ':kind' => 'REVERSAL',
                ':account' => $entry['account'],
                ':amount' => $entry['amount'],
                ':desc' => $entry['description'],
                ':ref' => '',
                ':created_at' => date('c'),
            ]);
        }

        // Reverse the revenue
        $this->revenueAmount = 0;
        $this->state = 4; // REVERSED
        $this->saveState();

        return $result;
    }

    private function getStateLabel(): string {
        $labels = [0 => 'Open', 1 => 'Partial', 2 => 'Paid', 3 => 'Write-Off', 4 => 'Reversed'];
        return $labels[$this->state] ?? 'Unknown';
    }

    private function saveState(): void {
        $stmt = $this->db->prepare(
            'INSERT OR REPLACE INTO credit_transaction_states (transaction_id, state, sale_amount, loss_amount, revenue_amount, cumulative_payment, updated_at)
             VALUES (:tid, :state, :sale, :loss, :rev, :cum, :updated)'
        );
        $stmt->execute([
            ':tid' => $this->orderId,
            ':state' => $this->state,
            ':sale' => $this->saleAmount,
            ':loss' => $this->lossAmount,
            ':rev' => $this->revenueAmount,
            ':cum' => $this->cumulativePayment,
            ':updated' => date('c'),
        ]);
    }
}
