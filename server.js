const express = require('express');
const cors = require('cors');
require('dotenv').config();

const app = express();

app.use(cors());
app.use(express.json());

// Routes
app.use('/api/auth',       require('./src/routes/auth'));
app.use('/api/wallet',     require('./src/routes/wallet'));
app.use('/api/withdrawal', require('./src/routes/withdrawal'));
app.use('/api/campaign',   require('./src/routes/campaign'));

const PORT = process.env.PORT || 3000;
app.listen(PORT, () => {
  console.log(`Server running http://localhost:${PORT}`);
});