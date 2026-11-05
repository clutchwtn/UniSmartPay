#!/usr/bin/env node

/**
 * RFID Reader Server - Node.js and WebSocket
 * Alternative to the Web Serial API
 * 
 * Installation:
 * npm install express socket.io serialport
 * 
 * Lancement:
 * node node_rfid_server.js
 * 
 * Accès Web:
 * http://localhost:3000
 */

const express = require('express');
const http = require('http');
const socketIo = require('socket.io');
const SerialPort = require('serialport').SerialPort;
const ReadlineParser = require('@serialport/parser-readline').ReadlineParser;

const app = express();
const server = http.createServer(app);
const io = socketIo(server, {
    cors: {
        origin: "*",
        methods: ["GET", "POST"]
    }
});

const PORT = process.env.PORT || 3000;
const SERIAL_BAUD = 115200;

let serialPort = null;
let parser = null;
let connectedClients = 0;

// ===== Servir les fichiers statiques =====
app.use(express.static('public'));

// ===== API REST =====
app.get('/', (req, res) => {
    res.send(`
        <!DOCTYPE html>
        <html lang="fr">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>RFID Reader Server</title>
            <script src="https://cdn.socket.io/4.5.4/socket.io.min.js"></script>
            <style>
                * { margin: 0; padding: 0; box-sizing: border-box; }
                body { font-family: 'Segoe UI', sans-serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
                .container { background: white; padding: 40px; border-radius: 12px; box-shadow: 0 10px 40px rgba(0,0,0,0.2); max-width: 500px; width: 100%; }
                h1 { color: #333; margin-bottom: 10px; font-size: 2em; }
                .status { display: flex; align-items: center; gap: 10px; margin-bottom: 20px; }
                .status-dot { width: 12px; height: 12px; border-radius: 50%; background: #ef4444; animation: pulse 2s infinite; }
                .status-dot.connected { background: #10b981; }
                @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }
                .info-box { background: #f0f9ff; padding: 15px; border-radius: 8px; border-left: 4px solid #0ea5e9; margin-bottom: 20px; color: #0c7a99; line-height: 1.6; }
                .console { background: #1e1e1e; color: #10b981; padding: 15px; border-radius: 8px; font-family: monospace; font-size: 0.9em; max-height: 250px; overflow-y: auto; margin-bottom: 20px; border: 1px solid #333; }
                .console-line { margin: 4px 0; }
                .uid-display { background: #f0fdf4; border: 2px solid #10b981; padding: 15px; border-radius: 8px; text-align: center; margin-bottom: 20px; }
                .uid-value { font-family: monospace; font-size: 1.5em; font-weight: bold; color: #10b981; }
                button { padding: 12px 20px; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; transition: all 0.3s; margin-right: 10px; }
                .btn-primary { background: #667eea; color: white; }
                .btn-primary:hover { background: #764ba2; }
                .btn-success { background: #10b981; color: white; }
                .btn-success:hover { background: #059669; }
                .btn-danger { background: #ef4444; color: white; }
                .btn-danger:hover { background: #dc2626; }
                .connections { text-align: center; color: #666; margin-top: 20px; font-size: 0.9em; }
            </style>
        </head>
        <body>
            <div class="container">
                <h1>RFID Reader Server</h1>
                <div class="status">
                    <div class="status-dot" id="status-dot"></div>
                    <span id="status-text">Connexion...</span>
                </div>
                
                <div class="info-box">
                    <strong>Serveur WebSocket actif</strong><br>
                    Port Serial: En attente de config<br>
                    Baud Rate: 115200<br>
                    Format: Chaque ligne = 1 UID
                </div>
                
                <div id="uid-display" style="display: none;">
                    <div class="uid-display">
                        <strong>UID détecté:</strong><br>
                        <div class="uid-value" id="uid-value">000000</div>
                    </div>
                </div>
                
                <div>
                    <h3>Console</h3>
                    <div class="console" id="console">Serveur démarré...<br></div>
                </div>
                
                <div>
                    <button class="btn-primary" onclick="connectSerial()">Connecter le port série</button>
                    <button class="btn-danger" onclick="disconnect()" disabled id="btn-disconnect">Déconnecter</button>
                </div>
                
                <div class="connections">
                    Clients connectés: <span id="client-count">0</span>
                </div>
            </div>
            
            <script>
                const socket = io();
                let currentUID = '';
                
                const console_el = document.getElementById('console');
                const status_dot = document.getElementById('status-dot');
                const status_text = document.getElementById('status-text');
                const uid_display = document.getElementById('uid-display');
                const uid_value = document.getElementById('uid-value');
                const client_count = document.getElementById('client-count');
                
                function addLog(msg, type = 'info') {
                    const colors = {
                        'info': '#fff',
                        'success': '#10b981',
                        'error': '#ef4444',
                        'warning': '#f59e0b'
                    };
                    const line = document.createElement('div');
                    line.className = 'console-line';
                    line.style.color = colors[type];
                    line.textContent = msg;
                    console_el.appendChild(line);
                    console_el.scrollTop = console_el.scrollHeight;
                }
                
                socket.on('connect', () => {
                    addLog('Connecté au serveur', 'success');
                    status_dot.classList.add('connected');
                    status_text.textContent = 'Connecté';
                });
                
                socket.on('disconnect', () => {
                    addLog('Déconnecté', 'error');
                    status_dot.classList.remove('connected');
                    status_text.textContent = 'Déconnecté';
                });
                
                socket.on('serial:connected', (data) => {
                    addLog(\`Port série connecté: \${data.port}\`, 'success');
                });
                
                socket.on('serial:disconnected', (data) => {
                    addLog('Port série déconnecté', 'warning');
                });
                
                socket.on('serial:data', (data) => {
                    const uid = data.uid.trim();
                    if (uid) {
                        currentUID = uid;
                        uid_value.textContent = uid;
                        uid_display.style.display = 'block';
                        addLog(\`UID reçu: \${uid}\`, 'success');
                    }
                });
                
                socket.on('serial:error', (error) => {
                    addLog(\`Erreur: \${error}\`, 'error');
                });
                
                socket.on('clients:count', (count) => {
                    client_count.textContent = count;
                });
                
                function connectSerial() {
                    const ports = ['/dev/ttyUSB0', '/dev/ttyACM0', 'COM3', 'COM4', 'COM5'];
                    const port = prompt('Port sériel (ex: COM3, /dev/ttyUSB0):\\n\\nPorts courants: ' + ports.join(', '));
                    
                    if (port) {
                        addLog(\`Tentative connexion: \${port}...\`, 'warning');
                        socket.emit('serial:connect', { port });
                    }
                }
                
                function disconnect() {
                    socket.emit('serial:disconnect');
                    uid_display.style.display = 'none';
                }
            </script>
        </body>
        </html>
    `);
});

app.get('/api/status', (req, res) => {
    res.json({
        status: 'operational',
        clients: connectedClients,
        serial_connected: serialPort ? true : false,
        timestamp: new Date().toISOString()
    });
});

// ===== WebSocket Events =====
io.on('connection', (socket) => {
    connectedClients++;
    console.log(`[CLIENT] Connecté - Total: ${connectedClients}`);
    io.emit('clients:count', connectedClients);
    
    socket.on('serial:connect', (data) => {
        const port = data.port;
        console.log(`[SERIAL] Tentative connexion au port: ${port}`);
        
        connectToSerial(port, (err) => {
            if (err) {
                socket.emit('serial:error', err);
                console.error(`[SERIAL] Erreur: ${err}`);
            } else {
                io.emit('serial:connected', { port });
                console.log(`[SERIAL] Connecté: ${port}`);
            }
        });
    });
    
    socket.on('serial:disconnect', () => {
        if (serialPort) {
            serialPort.close((err) => {
                if (!err) {
                    io.emit('serial:disconnected');
                    console.log('[SERIAL] Déconnecté');
                }
            });
        }
    });
    
    socket.on('disconnect', () => {
        connectedClients--;
        console.log(`[CLIENT] Deconnecté - Restants: ${connectedClients}`);
        io.emit('clients:count', connectedClients);
    });
});

// ===== Connexion Sérielle =====
function connectToSerial(port, callback) {
    if (serialPort) {
        serialPort.close();
    }
    
    serialPort = new SerialPort({
        path: port,
        baudRate: SERIAL_BAUD,
        autoOpen: true
    });
    
    parser = serialPort.pipe(new ReadlineParser({ delimiter: '\\n' }));
    
    serialPort.on('error', (err) => {
        console.error('[SERIAL ERROR]', err);
        callback(err.message);
    });
    
    parser.on('data', (line) => {
        const uid = line.toString().trim();
        if (uid && uid.length > 0) {
            console.log(`[UID REÇU] ${uid}`);
            io.emit('serial:data', { uid });
            
            // Envoyer à l'API PHP
            sendToAPI(uid);
        }
    });
    
    serialPort.on('open', () => {
        console.log('[SERIAL] Port ouvert et prêt');
        callback(null);
    });
}

// ===== Envoyer à l'API PHP =====
function sendToAPI(uid) {
    const api_url = 'http://localhost/UniSmartPay/web/api/terminal/log.php';
    
    fetch(api_url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            type_message: 'SCAN',
            message: 'UID scanné',
            uid_carte: uid,
            donnees_json: { uid }
        })
    })
    .then(r => r.json())
    .then(data => console.log('[API] Réponse:', data))
    .catch(err => console.error('[API ERROR]', err));
}

// ===== Démarrage =====
server.listen(PORT, () => {
    console.log('\\n=================================');
    console.log('RFID Reader Server');
    console.log('=================================');
    console.log(`Serveur: http://localhost:${PORT}`);
    console.log(`Port série: à configurer via l'interface`);
    console.log(`API PHP: http://localhost/UniSmartPay/web/api/terminal/log.php`);
    console.log('\\nAccès:');
    console.log(`  - Web UI: http://localhost:${PORT}`);
    console.log(`  - Status: http://localhost:${PORT}/api/status`);
    console.log('\\nPrêt à recevoir les UID des lecteurs RFID.\\n');
});

process.on('SIGINT', () => {
    console.log('\\n[SHUTDOWN] Fermeture du serveur...');
    if (serialPort) {
        serialPort.close(() => {
            console.log('[SERIAL] Fermé');
            process.exit(0);
        });
    } else {
        process.exit(0);
    }
});
