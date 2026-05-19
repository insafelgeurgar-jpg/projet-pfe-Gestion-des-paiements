const stripe = require('stripe')(process.env.STRIPE_SECRET_KEY);
const db = require('../config/db');

// Mock function to get current user. In a real app, this comes from auth middleware.
const getCurrentUserId = (req) => {
  return req.body.user_id || req.query.user_id || req.headers['x-user-id'];
};

exports.onboardFreelancer = async (req, res) => {
  try {
    const userId = getCurrentUserId(req);
    if (!userId) {
      return res.status(401).json({ error: 'Unauthorized: Missing user_id' });
    }

    // Check if user already has an account
    db.get('SELECT * FROM freelancer_stripe_accounts WHERE user_id = ?', [userId], async (err, accountRow) => {
      if (err) return res.status(500).json({ error: err.message });

      let accountId;

      if (accountRow && accountRow.stripe_account_id) {
        accountId = accountRow.stripe_account_id;
      } else {
        // Create a new Express connected account
        const account = await stripe.accounts.create({
          type: 'express',
          capabilities: {
            transfers: { requested: true },
          },
          business_type: 'individual',
        });
        accountId = account.id;

        // Save to DB
        db.run('INSERT INTO freelancer_stripe_accounts (user_id, stripe_account_id) VALUES (?, ?)', [userId, accountId], (insertErr) => {
          if (insertErr) console.error('Error saving Stripe account:', insertErr.message);
        });
      }

      // Create an account link for onboarding
      const accountLink = await stripe.accountLinks.create({
        account: accountId,
        refresh_url: `${process.env.CLIENT_URL}/onboard/refresh?account_id=${accountId}`,
        return_url: `${process.env.CLIENT_URL}/onboard/return?account_id=${accountId}`,
        type: 'account_onboarding',
      });

      res.json({ url: accountLink.url });
    });
  } catch (error) {
    res.status(500).json({ error: error.message });
  }
};

exports.getOnboardingStatus = async (req, res) => {
  try {
    const userId = getCurrentUserId(req);
    if (!userId) {
      return res.status(401).json({ error: 'Unauthorized: Missing user_id' });
    }

    db.get('SELECT * FROM freelancer_stripe_accounts WHERE user_id = ?', [userId], async (err, accountRow) => {
      if (err) return res.status(500).json({ error: err.message });

      if (!accountRow) {
        return res.json({ connected: false, onboarding_complete: false });
      }

      // Optionally, fetch latest status from Stripe
      const account = await stripe.accounts.retrieve(accountRow.stripe_account_id);
      const isComplete = account.details_submitted;

      // Update DB if state changed
      if (isComplete !== Boolean(accountRow.onboarding_complete)) {
        db.run('UPDATE freelancer_stripe_accounts SET onboarding_complete = ? WHERE id = ?', [isComplete ? 1 : 0, accountRow.id]);
      }

      res.json({
        connected: true,
        stripe_account_id: accountRow.stripe_account_id,
        onboarding_complete: isComplete,
        charges_enabled: account.charges_enabled,
        payouts_enabled: account.payouts_enabled
      });
    });
  } catch (error) {
    res.status(500).json({ error: error.message });
  }
};
