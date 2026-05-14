const express = require('express');
const router = express.Router();
const pool = require('../config/db');
const auth = require('../middleware/auth');

// Create campaign
router.post('/create', auth, async (req, res) => {
  try {
    if (req.user.role !== 'company') {
      return res.status(403).json({ message: 'Ghir company ymkn ycréa campaign' });
    }

    const { title, description, budget_amount, voting_ends_at } = req.body;
    const id = require('crypto').randomUUID();

    await pool.query(
      `INSERT INTO campaigns 
        (id, company_id, title, description, budget_amount, status, voting_ends_at)
       VALUES (?, ?, ?, ?, ?, 'draft', ?)`,
      [id, req.user.id, title, description, budget_amount, voting_ends_at]
    );

    res.status(201).json({
      message: 'Campaign créée ✅',
      campaign_id: id
    });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// Get all campaigns
router.get('/all', auth, async (req, res) => {
  try {
    const [campaigns] = await pool.query(
      `SELECT id, title, budget_amount, status, voting_ends_at, created_at
       FROM campaigns ORDER BY created_at DESC`
    );
    res.json(campaigns);
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// Get one campaign
router.get('/:id', auth, async (req, res) => {
  try {
    const [campaign] = await pool.query(
      'SELECT * FROM campaigns WHERE id = ?',
      [req.params.id]
    );
    if (campaign.length === 0) {
      return res.status(404).json({ message: 'Campaign mawjodch' });
    }
    res.json(campaign[0]);
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// Fund campaign
router.post('/:id/fund', auth, async (req, res) => {
  try {
    if (req.user.role !== 'company') {
      return res.status(403).json({ message: 'Ghir company ymkn yfund campaign' });
    }

    const [campaign] = await pool.query(
      'SELECT * FROM campaigns WHERE id = ? AND company_id = ?',
      [req.params.id, req.user.id]
    );
    if (campaign.length === 0) {
      return res.status(404).json({ message: 'Campaign mawjodch' });
    }

    const c = campaign[0];
    const walletId = require('crypto').randomUUID();
    const idempotencyKey = `fund-${c.id}-${Date.now()}`;

    // Lock funds in escrow
    await pool.query(
      `UPDATE wallets
       SET locked_balance = locked_balance + ?,
           version = version + 1
       WHERE user_id = ?`,
      [c.budget_amount, req.user.id]
    );

    // Update campaign status
    await pool.query(
      `UPDATE campaigns SET status = 'active' WHERE id = ?`,
      [c.id]
    );

    // Insert transaction
    const txId = require('crypto').randomUUID();
    const [wallet] = await pool.query(
      'SELECT * FROM wallets WHERE user_id = ?', [req.user.id]
    );

    await pool.query(
      `INSERT INTO transactions
        (id, wallet_id, campaign_id, type, direction, amount, balance_after, idempotency_key, description)
       VALUES (?, ?, ?, 'escrow_lock', 'debit', ?, ?, ?, ?)`,
      [txId, wallet[0].id, c.id, c.budget_amount,
       wallet[0].available_balance, idempotencyKey,
       `Escrow lock - ${c.title}`]
    );

    res.json({ message: 'Campaign funded ✅', status: 'active' });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

module.exports = router;