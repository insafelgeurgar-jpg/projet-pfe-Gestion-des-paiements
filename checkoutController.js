const stripe = require('stripe')(process.env.STRIPE_SECRET_KEY);
const db = require('../config/db');

exports.createCheckoutSession = async (req, res) => {
  try {
    const { contest_id } = req.body;

    if (!contest_id) {
      return res.status(400).json({ error: 'contest_id is required' });
    }

    // Fetch contest details to get budget
    db.get('SELECT * FROM contests WHERE id = ?', [contest_id], async (err, contest) => {
      if (err) return res.status(500).json({ error: err.message });
      if (!contest) return res.status(404).json({ error: 'Contest not found' });
      if (contest.status !== 'draft' && contest.status !== 'payment_pending') {
         return res.status(400).json({ error: 'Contest is not in a payable state' });
      }

      // Update contest status
      db.run('UPDATE contests SET status = ? WHERE id = ?', ['payment_pending', contest_id]);

      const session = await stripe.checkout.sessions.create({
        payment_method_types: ['card'],
        line_items: [
          {
            price_data: {
              currency: 'usd',
              product_data: {
                name: `Contest: ${contest.title}`,
                description: `Funding for contest #${contest.id}`,
              },
              unit_amount: contest.budget, // Amount in cents
            },
            quantity: 1,
          },
        ],
        mode: 'payment',
        success_url: `${process.env.CLIENT_URL}/success?contest_id=${contest.id}`,
        cancel_url: `${process.env.CLIENT_URL}/cancel?contest_id=${contest.id}`,
        client_reference_id: contest.id.toString(),
        payment_intent_data: {
          // Add transfer_group to group the incoming payment and future payout transfer
          transfer_group: `contest_${contest.id}`,
        },
      });

      // Record pending payment
      db.run(
        'INSERT INTO contest_payments (contest_id, stripe_session_id, amount) VALUES (?, ?, ?)',
        [contest.id, session.id, contest.budget],
        (insertErr) => {
          if (insertErr) console.error('Error saving pending payment:', insertErr.message);
        }
      );

      res.json({ id: session.id, url: session.url });
    });
  } catch (error) {
    res.status(500).json({ error: error.message });
  }
};