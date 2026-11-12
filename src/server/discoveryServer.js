const dgram = require('dgram');

const DISCOVERY_PORT = 39876;
const DISCOVERY_MESSAGE = 'MOBS_JUKEBOX_DISCOVER_V1';

function createDiscoveryServer({ httpPort = 8080 } = {}) {
  const socket = dgram.createSocket({
    type: 'udp4',
    reuseAddr: true
  });

  socket.on('message', (message, remote) => {
    if (message.toString().trim() !== DISCOVERY_MESSAGE) {
      return;
    }

    console.log(`Discovery request from ${remote.address}:${remote.port}`);

    const response = Buffer.from(JSON.stringify({
      service: 'mobs-jukebox',
      version: 2,
      port: httpPort
    }));

    socket.send(
      response,
      remote.port,
      remote.address,
      (error) => {
        if (error) {
          console.error('Discovery response failed:', error.message);
        } else {
          console.log(`Discovery response sent to ${remote.address}:${remote.port}`);
        }
      }
    );
  });

  socket.on('error', (error) => {
    console.error('Discovery server error:', error.message);
  });

  function start() {
    return new Promise((resolve, reject) => {
      socket.once('error', reject);

      socket.bind(DISCOVERY_PORT, '0.0.0.0', () => {
        socket.removeListener('error', reject);
        console.log(`UDP discovery listening on ${DISCOVERY_PORT}`);
        resolve();
      });
    });
  }

  function close() {
    return new Promise((resolve) => {
      if (!socket.address()) return resolve();
      socket.close(resolve);
    });
  }

  return { start, close };
}

module.exports = { createDiscoveryServer };
