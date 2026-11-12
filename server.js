'use strict';

require('dotenv').config();

const { createApp } = require('./src/core/app');
const { loadConfig } = require('./src/config');

async function main() {
  const config = loadConfig();
  const app = await createApp(config);

  app.server.listen(config.port, config.host, () => {
    console.log(`RIVUNE server listening on http://${config.host}:${config.port}`);
    console.log(`Music directory: ${config.musicDir}`);
    console.log('For other devices on your Wi-Fi, use this computer’s LAN IP address.');
  });

  const shutdown = async (signal) => {
    console.log(`\n${signal} received; shutting down...`);
    await app.close();
    process.exit(0);
  };

  process.on('SIGINT', () => shutdown('SIGINT'));
  process.on('SIGTERM', () => shutdown('SIGTERM'));
}

main().catch((error) => {
  console.error('Failed to start RIVUNE:', error);
  process.exit(1);
});
