const express = require('express');
const fetch = require('node-fetch');
const app = express();

app.get('/api', async (req, res) => {
  const url = 'http://marveltv.info/player_api.php?' + new URLSearchParams(req.query);

  try {
    const r = await fetch(url);
    const data = await r.text();

    res.setHeader('Access-Control-Allow-Origin', '*');
    res.send(data);
  } catch (e) {
    res.status(500).send('error');
  }
});

app.listen(3000, () => console.log('Proxy running'));
