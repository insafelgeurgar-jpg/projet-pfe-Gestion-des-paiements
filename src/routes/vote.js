const express = require('express');
const router = express.Router();
const pool = require('../config/db');
const auth = require('../middleware/auth');

// Cast vote
router.post('/cast', auth, async (req, res) => {
  try {
    const { campaign_id, design_id } = req.body;

    // 1 - Check campaign active
    const [campaign] = await pool.query(
      'SELECT * FROM campaigns WHERE id = ? AND status = "voting"',
      [campaign_id]
    );
    if (campaign.length === 0) {
      return res.status(400).json({ message: 'Campaign machi f voting status' });
    }

    // 2 - Check design belongs to campaign
    const [design] = await pool.query(
      'SELECT * FROM designs WHERE id = ? AND campaign_id = ? AND status = "approved"',
      [design_id, campaign_id]
    );
    if (design.length === 0) {
      return res.status(404).json({ message: 'Design mawjodch' });
    }

    // 3 - Check duplicate vote
    const [existing] = await pool.query(
      'SELECT id FROM votes WHERE user_id = ? AND campaign_id = ?',
      [req.user.id, campaign_id]
    );
    if (existing.length > 0) {
      return res.status(400).json({ message: 'Deja votiti f had campaign' });
    }

    // 4 - Insert vote
    const id = require('crypto').randomUUID();
    await pool.query(
      `INSERT INTO votes (id, user_id, campaign_id, design_id)
       VALUES (?, ?, ?, ?)`,
      [id, req.user.id, campaign_id, design_id]
    );

    // 5 - Increment vote count
    await pool.query(
      'UPDATE designs SET vote_count = vote_count + 1 WHERE id = ?',
      [design_id]
    );

    res.status(201).json({ message: 'Vote msajal ✅' });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// Get campaign votes (admin)
router.get('/campaign/:id', auth, async (req, res) => {
  try {
    const [votes] = await pool.query(
      `SELECT d.title, COUNT(v.id) as vote_count
       FROM votes v
       JOIN designs d ON d.id = v.design_id
       WHERE v.campaign_id = ?
       GROUP BY d.id, d.title
       ORDER BY vote_count DESC`,
      [req.params.id]
    );
    res.json(votes);
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

module.exports = router;