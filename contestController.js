const db = require('../config/db');

// Mock function to get current user
const getCurrentUserId = (req) => {
  return req.body.client_id || req.query.client_id || req.headers['x-user-id'];
};

exports.createContest = (req, res) => {
  const { title, description, budget } = req.body;
  const clientId = getCurrentUserId(req);

  if (!title || !budget || !clientId) {
    return res.status(400).json({ error: 'Title, budget, and client_id are required' });
  }

  const budgetInCents = Number(budget);

  db.run(
    'INSERT INTO contests (title, description, budget, client_id, status) VALUES (?, ?, ?, ?, ?)',
    [title, description, budgetInCents, clientId, 'draft'],
    function (err) {
      if (err) return res.status(500).json({ error: err.message });
      res.json({ id: this.lastID, status: 'draft' });
    }
  );
};

exports.getContest = (req, res) => {
  const { id } = req.params;

  db.get('SELECT * FROM contests WHERE id = ?', [id], (err, row) => {
    if (err) return res.status(500).json({ error: err.message });
    if (!row) return res.status(404).json({ error: 'Contest not found' });
    res.json(row);
  });
};

exports.listContests = (req, res) => {
  db.all('SELECT * FROM contests', [], (err, rows) => {
    if (err) return res.status(500).json({ error: err.message });
    res.json(rows);
  });
};
