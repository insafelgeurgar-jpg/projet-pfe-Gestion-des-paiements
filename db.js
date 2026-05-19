const sqlite3 = require('sqlite3').verbose();
const path = require('path');

const dbPath = path.join(__dirname, '../database.sqlite');
const db = new sqlite3.Database(dbPath, (err) => {
  if (err) {
    console.error('Error opening database:', err.message);
  } else {
    console.log('Connected to the SQLite SQL database.');
    db.exec(`
      CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT,
        email TEXT UNIQUE NOT NULL,
        role TEXT DEFAULT 'client', -- 'client' or 'freelancer'
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
      );

      CREATE TABLE IF NOT EXISTS freelancer_stripe_accounts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        stripe_account_id TEXT NOT NULL UNIQUE,
        onboarding_complete BOOLEAN DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id)
      );

      CREATE TABLE IF NOT EXISTS contests (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        description TEXT,
        budget INTEGER NOT NULL, -- in cents
        client_id INTEGER NOT NULL,
        status TEXT DEFAULT 'draft', -- draft, payment_pending, active, voting, completed, cancelled
        winner_id INTEGER, -- User ID of the winning freelancer
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (client_id) REFERENCES users(id),
        FOREIGN KEY (winner_id) REFERENCES users(id)
      );

      CREATE TABLE IF NOT EXISTS contest_payments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        contest_id INTEGER NOT NULL,
        stripe_session_id TEXT NOT NULL,
        stripe_payment_intent_id TEXT,
        amount INTEGER NOT NULL, -- in cents
        currency TEXT DEFAULT 'usd',
        status TEXT DEFAULT 'pending', -- pending, succeeded, failed
        customer_email TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (contest_id) REFERENCES contests(id)
      );

      CREATE TABLE IF NOT EXISTS submissions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        contest_id INTEGER NOT NULL,
        freelancer_id INTEGER NOT NULL,
        content TEXT NOT NULL, -- URL or description of submission
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (contest_id) REFERENCES contests(id),
        FOREIGN KEY (freelancer_id) REFERENCES users(id)
      );

      CREATE TABLE IF NOT EXISTS votes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        submission_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL, -- User who voted
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (submission_id) REFERENCES submissions(id),
        FOREIGN KEY (user_id) REFERENCES users(id)
      );

      CREATE TABLE IF NOT EXISTS payouts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        contest_id INTEGER NOT NULL,
        freelancer_id INTEGER NOT NULL,
        gross_amount INTEGER NOT NULL,
        platform_fee INTEGER NOT NULL,
        net_amount INTEGER NOT NULL,
        stripe_transfer_id TEXT,
        status TEXT DEFAULT 'pending', -- pending, paid, failed
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (contest_id) REFERENCES contests(id),
        FOREIGN KEY (freelancer_id) REFERENCES users(id)
      );

      CREATE TABLE IF NOT EXISTS payments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        stripe_session_id TEXT NOT NULL,
        amount INTEGER NOT NULL,
        currency TEXT NOT NULL,
        status TEXT NOT NULL,
        customer_email TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
      );
    `, (createErr) => {
      if (createErr) {
        console.error('Error creating tables:', createErr.message);
      } else {
        console.log('Database tables verified/created successfully.');
      }
    });
  }
});

module.exports = db;
