const express = require('express');
const router = express.Router();
const pool = require('../config/db');
const auth = require('../middleware/auth');

// Calculate and distribute rewards
router.post('/distribute/:campaign_id', auth, async (req, res) => {
  try {
    if (req.user.role !== 'admin') {
      return res.status(403).json({ message: 'Ghir admin ymkn ydistribui rewards' });
    }

    const { campaign_id } = req.params;

    // 1 - Get campaign
    const [campaigns] = await pool.query(
      'SELECT * FROM campaigns WHERE id = ?',
      [campaign_id]
    );
    if (campaigns.length === 0) {
      return res.status(404).json({ message: 'Campaign mawjodch' });
    }

    const campaign = campaigns[0];
    const budget = parseFloat(campaign.budget_amount);

    // 2 - Calculate pools
    const designer_pool = budget * 0.70;
    const voter_pool    = budget * 0.25;
    const platform_fee  = budget * 0.05;

    // 3 - Find winning design
    const [designs] = await pool.query(
      `SELECT id, designer_id, vote_count FROM designs
       WHERE campaign_id = ? AND status = 'approved'
       ORDER BY vote_count DESC`,
      [campaign_id]
    );
    if (designs.length === 0) {
      return res.status(400).json({ message: 'Machi designs' });
    }

    const total_votes = designs.reduce((sum, d) => sum + d.vote_count, 0);
    const winning_design = designs[0];

    // 4 - Mark winning votes
    await pool.query(
      'UPDATE votes SET is_winning_vote = 1 WHERE campaign_id = ? AND design_id = ?',
      [campaign_id, winning_design.id]
    );

    // 5 - Distribute designer rewards
    for (const design of designs) {
      if (design.vote_count === 0) continue;
      const share = (designer_pool * design.vote_count) / total_votes;

      // Credit designer wallet
      await pool.query(
        `UPDATE wallets SET available_balance = available_balance + ?,
         version = version + 1 WHERE user_id = ?`,
        [share, design.designer_id]
      );

      // Get wallet id
      const [dWallet] = await pool.query(
        'SELECT id, available_balance FROM wallets WHERE user_id = ?',
        [design.designer_id]
      );

      // Insert transaction
      const txId = require('crypto').randomUUID();
      await pool.query(
        `INSERT INTO transactions
          (id, wallet_id, campaign_id, type, direction, amount, balance_after, idempotency_key, description)
         VALUES (?, ?, ?, 'reward', 'credit', ?, ?, ?, ?)`,
        [txId, dWallet[0].id, campaign_id, share,
         dWallet[0].available_balance, `reward-designer-${design.designer_id}-${campaign_id}`,
         `Designer reward - ${campaign.title}`]
      );

      // Insert reward result
      await pool.query(
        `INSERT INTO reward_results
          (id, campaign_id, user_id, role, reward_amount, calc_snapshot, transaction_id)
         VALUES (?, ?, ?, 'designer', ?, ?, ?)`,
        [require('crypto').randomUUID(), campaign_id, design.designer_id,
         share, JSON.stringify({ budget, designer_pool, vote_count: design.vote_count, total_votes }), txId]
      );
    }

    // 6 - Distribute voter rewards
    const [correct_voters] = await pool.query(
      'SELECT user_id FROM votes WHERE campaign_id = ? AND is_winning_vote = 1',
      [campaign_id]
    );

    if (correct_voters.length > 0) {
      const voter_share = voter_pool / correct_voters.length;

      for (const voter of correct_voters) {
        await pool.query(
          `UPDATE wallets SET available_balance = available_balance + ?,
           version = version + 1 WHERE user_id = ?`,
          [voter_share, voter.user_id]
        );

        const [vWallet] = await pool.query(
          'SELECT id, available_balance FROM wallets WHERE user_id = ?',
          [voter.user_id]
        );

        const txId = require('crypto').randomUUID();
        await pool.query(
          `INSERT INTO transactions
            (id, wallet_id, campaign_id, type, direction, amount, balance_after, idempotency_key, description)
           VALUES (?, ?, ?, 'reward', 'credit', ?, ?, ?, ?)`,
          [txId, vWallet[0].id, campaign_id, voter_share,
           vWallet[0].available_balance, `reward-voter-${voter.user_id}-${campaign_id}`,
           `Voter reward - ${campaign.title}`]
        );

        await pool.query(
          `INSERT INTO reward_results
            (id, campaign_id, user_id, role, reward_amount, calc_snapshot, transaction_id)
           VALUES (?, ?, ?, 'voter', ?, ?, ?)`,
          [require('crypto').randomUUID(), campaign_id, voter.user_id,
           voter_share, JSON.stringify({ budget, voter_pool, correct_voters: correct_voters.length }), txId]
        );
      }
    }

    // 7 - Update campaign status
    await pool.query(
      'UPDATE campaigns SET status = "completed", completed_at = NOW() WHERE id = ?',
      [campaign_id]
    );

    res.json({
      message: 'Rewards distributed ✅',
      budget,
      designer_pool,
      voter_pool,
      platform_fee,
      winning_design: winning_design.id,
      correct_voters: correct_voters.length
    });

  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

module.exports = router;