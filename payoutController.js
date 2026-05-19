const stripe = require('stripe')(process.env.STRIPE_SECRET_KEY);
const db = require('../config/db');

// Platform fee percentage (e.g., 10%)
const PLATFORM_FEE_PERCENTAGE = process.env.PLATFORM_FEE_PERCENTAGE || 10;

exports.selectWinner = async (req, res) => {
  try {
    const { contest_id, winner_id } = req.body;

    if (!contest_id || !winner_id) {
      return res.status(400).json({ error: 'contest_id and winner_id are required' });
    }

    // 1. Fetch contest and verify it's active
    db.get('SELECT * FROM contests WHERE id = ?', [contest_id], async (err, contest) => {
      if (err) return res.status(500).json({ error: err.message });
      if (!contest) return res.status(404).json({ error: 'Contest not found' });
      
      if (contest.status !== 'active' && contest.status !== 'voting') {
        return res.status(400).json({ error: `Cannot select winner for contest in status: ${contest.status}` });
      }

      // 2. Fetch winner's Stripe Connect account
      db.get('SELECT * FROM freelancer_stripe_accounts WHERE user_id = ?', [winner_id], async (err, accountRow) => {
        if (err) return res.status(500).json({ error: err.message });
        if (!accountRow || !accountRow.stripe_account_id) {
          return res.status(400).json({ error: 'Winner does not have a connected Stripe account.' });
        }
        if (!accountRow.onboarding_complete) {
           return res.status(400).json({ error: 'Winner has not completed Stripe onboarding.' });
        }

        // 3. Calculate payout amounts
        const grossAmount = contest.budget;
        const platformFee = Math.round(grossAmount * (PLATFORM_FEE_PERCENTAGE / 100));
        const netAmount = grossAmount - platformFee;

        try {
          // 4. Create the Transfer
          const transfer = await stripe.transfers.create({
            amount: netAmount,
            currency: 'usd',
            destination: accountRow.stripe_account_id,
            transfer_group: `contest_${contest.id}`,
            metadata: {
              contest_id: contest.id.toString(),
              winner_id: winner_id.toString()
            }
          });

          // 5. Record payout and update contest status in DB
          db.run(
            'INSERT INTO payouts (contest_id, freelancer_id, gross_amount, platform_fee, net_amount, stripe_transfer_id, status) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [contest.id, winner_id, grossAmount, platformFee, netAmount, transfer.id, 'paid'],
            function (insertErr) {
              if (insertErr) console.error('Error saving payout:', insertErr.message);
              
              db.run('UPDATE contests SET status = ?, winner_id = ? WHERE id = ?', ['completed', winner_id, contest.id]);
              
              res.json({ success: true, transfer_id: transfer.id, net_amount: netAmount });
            }
          );
        } catch (stripeError) {
          console.error('Stripe Transfer Error:', stripeError);
          return res.status(500).json({ error: stripeError.message });
        }
      });
    });
  } catch (error) {
    res.status(500).json({ error: error.message });
  }
};

exports.getPayoutHistory = (req, res) => {
  const { freelancer_id } = req.params;
  
  db.all('SELECT * FROM payouts WHERE freelancer_id = ? ORDER BY created_at DESC', [freelancer_id], (err, rows) => {
    if (err) return res.status(500).json({ error: err.message });
    res.json(rows);
  });
};
