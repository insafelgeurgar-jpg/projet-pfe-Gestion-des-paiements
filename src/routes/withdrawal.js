const express = require('express');
const router = express.Router();
const pool = require('../config/db');
const auth = require('../middleware/auth');

const requiredFields = {
  paypal        : ['email'],
  bank_transfer : ['iban', 'bank_name', 'account_holder'],
  cashplus      : ['phone', 'full_name'],
  wafacash      : ['phone', 'full_name'],
  wise          : ['email', 'currency', 'country'],
  cash          : ['full_name', 'id_number', 'city'],
  cheque        : ['full_name', 'address'],
  cmi_visa      : ['card_last4'],
  cmi_mastercard: ['card_last4'],
};

// Request withdrawal
router.post('/request', auth, async (req, res) => {
  try {
    const { amount, method, payment_details } = req.body;

    // 1 - Validate method
    if (!requiredFields[method]) {
      return res.status(400).json({ message: 'Method mawjodch' });
    }

    // 2 - Validate required fields
    const missing = requiredFields[method].filter(f => !payment_details[f]);
    if (missing.length > 0) {
      return res.status(400).json({ message: `Khssk tzid: ${missing.join(', ')}` });
    }

    // 3 - Get wallet
    const [wallets] = await pool.query(
      'SELECT * FROM wallets WHERE user_id = ?',
      [req.user.id]
    );
    if (wallets.length === 0) {
      return res.status(404).json({ message: 'Wallet mawjodch' });
    }

    const wallet = wallets[0];

    // 4 - Check balance
    if (parseFloat(wallet.available_balance) < parseFloat(amount)) {
      return res.status(400).json({ message: 'Balance mkafiach' });
    }

    // 5 - Check minimum 200 MAD for voters
    if (req.user.role === 'voter' && parseFloat(amount) < 200) {
      return res.status(400).json({ message: 'Voter mayshobsh hta y9l3 200 MAD' });
    }

    const id = require('crypto').randomUUID();
    const idempotencyKey = `withdraw-${req.user.id}-${Date.now()}`;

    // 6 - Insert withdrawal
    await pool.query(
      `INSERT INTO withdrawals 
        (id, user_id, wallet_id, amount, method, payment_details, status, idempotency_key)
       VALUES (?, ?, ?, ?, ?, ?, 'pending', ?)`,
      [id, req.user.id, wallet.id, amount, method,
       JSON.stringify(payment_details), idempotencyKey]
    );

    // 7 - Debit balance
    await pool.query(
      `UPDATE wallets 
       SET available_balance = available_balance - ?,
           version = version + 1
       WHERE id = ? AND available_balance >= ?`,
      [amount, wallet.id, amount]
    );

    // 8 - Insert transaction
    const txId = require('crypto').randomUUID();
    const balanceAfter = parseFloat(wallet.available_balance) - parseFloat(amount);

    await pool.query(
      `INSERT INTO transactions
        (id, wallet_id, type, direction, amount, balance_after, idempotency_key, description)
       VALUES (?, ?, 'withdrawal', 'debit', ?, ?, ?, ?)`,
      [txId, wallet.id, amount, balanceAfter,
       `tx-${idempotencyKey}`, `Withdrawal via ${method}`]
    );

    res.status(201).json({
      message: 'Talb dial withdrawal mrsol ✅',
      withdrawal_id: id,
      status: 'pending'
    });

  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// Get my withdrawals
router.get('/my', auth, async (req, res) => {
  try {
    const [withdrawals] = await pool.query(
      `SELECT id, amount, method, status, requested_at, completed_at
       FROM withdrawals 
       WHERE user_id = ? 
       ORDER BY requested_at DESC`,
      [req.user.id]
    );
    res.json(withdrawals);
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

module.exports = router;