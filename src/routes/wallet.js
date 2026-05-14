const express = require('express');
const router = express.Router();
const pool = require('../config/db');
const jwt = require('jsonwebtoken');

function auth(req, res, next) {
  try {
    const token = req.headers.authorization?.split(' ')[1];
    if (!token) return res.status(401).json({ message: 'Token mawjodch' });
    const decoded = jwt.verify(token, process.env.JWT_SECRET);
    req.user = decoded;
    next();
  } catch (err) {
    res.status(401).json({ message: 'Token khata' });
  }
}

router.get('/balance', auth, async (req, res) => {
  try {
    const [wallet] = await pool.query(
      'SELECT available_balance, locked_balance, currency FROM wallets WHERE user_id = ?',
      [req.user.id]
    );
    if (wallet.length === 0) {
      return res.status(404).json({ message: 'Wallet mawjodch' });
    }
    res.json(wallet[0]);
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

router.get('/transactions', auth, async (req, res) => {
  try {
    const [wallet] = await pool.query(
      'SELECT id FROM wallets WHERE user_id = ?',
      [req.user.id]
    );
    if (wallet.length === 0) {
      return res.status(404).json({ message: 'Wallet mawjodch' });
    }
    const [transactions] = await pool.query(
      `SELECT type, direction, amount, balance_after, description, created_at 
       FROM transactions WHERE wallet_id = ? 
       ORDER BY created_at DESC`,
      [wallet[0].id]
    );
    res.json(transactions);
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

module.exports = router;