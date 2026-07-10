require('dotenv').config();
const { default: makeWASocket, useMultiFileAuthState, DisconnectReason, makeCacheableSignalKeyStore, downloadMediaMessage } = require('@whiskeysockets/baileys');
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const qrcode = require('qrcode-terminal');
const pino = require('pino');
const express = require('express');
const http = require('http');
const { Server } = require('socket.io');
const QRCode = require('qrcode');

const multer = require('multer');

// Configure disk storage for multer uploads
const storage = multer.diskStorage({
  destination: function (req, file, cb) {
    const mediaDir = 'C:\\xampp\\htdocs\\travel_crm\\uploads\\whatsapp_media';
    if (!fs.existsSync(mediaDir)) {
      fs.mkdirSync(mediaDir, { recursive: true });
    }
    cb(null, mediaDir);
  },
  filename: function (req, file, cb) {
    const randomStr = crypto.randomBytes(6).toString('hex');
    const ext = path.extname(file.originalname) || '.jpg';
    cb(null, `${Date.now()}_${randomStr}${ext}`);
  }
});
const upload = multer({ storage: storage });

// --- Web Server & Websocket Initialization ---
const app = express();
const server = http.createServer(app);
const io = new Server(server);

// Store connection states and recent messages in memory for the web view
let connectionState = 'close';
let lastQrDataUrl = null;
const recentMessages = [];
let sock = null;
const crmSentMessageIds = new Set();

// Serve the static frontend dashboard from the "public" directory
app.use(express.static('public'));
app.use(express.json());

// Express GET route to fetch and clear recent messages with CORS enabled
app.get('/api/messages', (req, res) => {
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'GET, OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type');

  if (req.method === 'OPTIONS') {
    return res.sendStatus(200);
  }

  // Copy messages to return, then clear the array
  const messages = [...recentMessages];
  recentMessages.length = 0;

  res.json(messages);
});

// Express POST route to send media (images/PDFs) to WhatsApp
app.post('/api/send-media', upload.single('file'), async (req, res) => {
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'POST, OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type');

  if (req.method === 'OPTIONS') {
    return res.sendStatus(200);
  }

  if (!sock) {
    return res.status(503).json({ success: false, message: 'WhatsApp client is not initialized or connected.' });
  }

  const { number, caption } = req.body;
  const file = req.file;

  if (!number || !file) {
    return res.status(400).json({ success: false, message: 'Missing number or file parameter.' });
  }

  try {
    const cleanNumber = number.replace(/\D/g, '');
    const jid = `${cleanNumber}@s.whatsapp.net`;
    const mimeType = file.mimetype;
    
    let messageOptions = {};
    let isImage = mimeType.startsWith('image/');
    
    if (isImage) {
      messageOptions = {
        image: { url: file.path },
        caption: caption || ''
      };
    } else {
      messageOptions = {
        document: { url: file.path },
        mimetype: mimeType,
        fileName: file.originalname,
        caption: caption || ''
      };
    }

    console.log(`[WhatsApp] Sending media (${mimeType}) to ${cleanNumber}. Path: ${file.path}`);
    const sent = await sock.sendMessage(jid, messageOptions);
    
    if (sent && sent.key && sent.key.id) {
      crmSentMessageIds.add(sent.key.id);
    }

    // Relative path for PHP CRM: uploads/whatsapp_media/filename
    const relativePath = `uploads/whatsapp_media/${file.filename}`;

    res.json({
      success: true,
      message: 'Media sent successfully.',
      media_path: relativePath,
      media_type: isImage ? 'image' : 'document',
      original_filename: file.originalname
    });
  } catch (error) {
    console.error('[WhatsApp] Failed to send media:', error);
    res.status(500).json({ success: false, message: `Failed to send media: ${error.message}` });
  }
});

// Express POST route to send a WhatsApp message
app.post('/api/send-message', async (req, res) => {
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'POST, OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type');

  if (req.method === 'OPTIONS') {
    return res.sendStatus(200);
  }

  const { number, message } = req.body;

  if (!number || !message) {
    return res.status(400).json({ success: false, message: 'Missing number or message parameter.' });
  }

  if (!sock) {
    return res.status(503).json({ success: false, message: 'WhatsApp client is not initialized or connected.' });
  }

  try {
    const cleanNumber = number.replace(/\D/g, '');
    const jid = `${cleanNumber}@s.whatsapp.net`;
    
    console.log(`[WhatsApp] Sending reply to ${cleanNumber}: "${message}"`);
    const sent = await sock.sendMessage(jid, { text: message });
    if (sent && sent.key && sent.key.id) {
      crmSentMessageIds.add(sent.key.id);
      if (crmSentMessageIds.size > 1000) {
        const firstValue = crmSentMessageIds.values().next().value;
        crmSentMessageIds.delete(firstValue);
      }
    }
    res.json({ success: true, message: 'Message sent successfully.' });
  } catch (error) {
    console.error('[WhatsApp] Failed to send message:', error);
    res.status(500).json({ success: false, message: `Failed to send message: ${error.message}` });
  }
});

// Express POST route to logout and clear current session
app.post('/api/logout', async (req, res) => {
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'POST, OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type');

  if (req.method === 'OPTIONS') {
    return res.sendStatus(200);
  }

  console.log('[WhatsApp] Logout requested via API.');

  try {
    if (sock) {
      try {
        console.log('[WhatsApp] Calling sock.logout() to sign out from WhatsApp...');
        await sock.logout();
      } catch (logoutError) {
        console.error('[WhatsApp] Error calling sock.logout():', logoutError.message);
        // Fallback: forcefully end the socket connection if logout fails or is already disconnected
        try {
          sock.end(undefined);
        } catch (endError) {
          console.error('[WhatsApp] Error ending socket:', endError.message);
        }
      }
      sock = null;
    }

    // Delete the auth_info folder contents to allow a fresh login
    const authDir = path.join(__dirname, 'auth_info');
    if (fs.existsSync(authDir)) {
      console.log('[WhatsApp] Deleting session auth_info folder...');
      let retries = 3;
      while (retries > 0) {
        try {
          fs.rmSync(authDir, { recursive: true, force: true });
          console.log('[WhatsApp] Successfully deleted auth_info directory.');
          break;
        } catch (err) {
          retries--;
          console.warn(`[WhatsApp] Failed to delete auth_info directory (retrying in 500ms...):`, err.message);
          if (retries === 0) {
            console.error('[WhatsApp] Failed to delete auth_info directory after retries:', err.message);
          } else {
            await new Promise(resolve => setTimeout(resolve, 500));
          }
        }
      }
    }

    connectionState = 'close';
    io.emit('connection-state', connectionState);

    res.json({ success: true, message: 'Logged out successfully and session cleared.' });

    // Automatically re-initialize the connection so a new QR is generated
    console.log('[WhatsApp] Re-initializing WhatsApp client...');
    setTimeout(async () => {
      try {
        await startWhatsApp();
      } catch (err) {
        console.error('[WhatsApp] Error during startWhatsApp re-initialization:', err);
      }
    }, 1000);

  } catch (error) {
    console.error('[WhatsApp] Error during logout processing:', error);
    res.status(500).json({ success: false, message: `Logout processing failed: ${error.message}` });
  }
});

// Manage browser websocket client connections
io.on('connection', (socket) => {
  console.log('[Web Server] Browser dashboard client connected.');
  
  // Instantly sync the client with the current status and history
  socket.emit('connection-state', connectionState);
  if (connectionState === 'qr' && lastQrDataUrl) {
    socket.emit('qr', lastQrDataUrl);
  }
  socket.emit('recent-messages', recentMessages);
});

// Start listening on the specified port
const PORT = process.env.PORT || 3000;
server.listen(PORT, () => {
  console.log(`\n======================================================`);
  console.log(`   WEB PORTAL RUNNING: http://localhost:${PORT}`);
  console.log(`======================================================\n`);
});

/**
 * Send incoming WhatsApp enquiry to the PHP CRM endpoint
 */
async function sendEnquiryToPhp(senderNumber, messageText, pushName, mediaPath = '', mediaType = 'none', originalFilename = '', mediaDuration = 0) {
  const url = process.env.PHP_ENDPOINT || 'http://localhost/travel_crm/admin_page/enquiry/whatsapp_webhook.php';
  
  const customerName = pushName ? pushName : senderNumber;

  // Prepare form-urlencoded data
  const params = new URLSearchParams();
  params.append('customer_name', customerName);
  params.append('mobile', senderNumber);
  params.append('email', '');
  params.append('subject', 'WhatsApp Enquiry');
  params.append('description', messageText);
  params.append('media_path', mediaPath);
  params.append('media_type', mediaType);
  params.append('original_filename', originalFilename);
  params.append('media_duration', mediaDuration);

  // Send API secret token to authorize with the webhook
  params.append('api_secret', process.env.API_SECRET || 'wa_crm_secret_2026');

  try {
    console.log(`[API] Sending enquiry from ${senderNumber} to PHP endpoint...`);
    const response = await fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded'
      },
      body: params.toString()
    });

    const text = await response.text();
    let result;
    try {
      result = JSON.parse(text);
    } catch (e) {
      console.error(`[API] PHP endpoint returned non-JSON output. Full response text: ${text}`);
      return;
    }

    if (result.success) {
      console.log(`[API] Enquiry saved in CRM: ${result.message}`);
    } else {
      console.error(`[API] CRM import failed: ${result.message}`);
    }
  } catch (error) {
    console.error(`[API] Network error sending request to PHP endpoint:`, error.message);
  }
}

/**
 * Robustly extract plain text message content from various WhatsApp message types
 */
function getMessageText(msg) {
  if (!msg.message) return '';

  const messageContent = msg.message;

  // 1. Direct text message
  if (messageContent.conversation) {
    return messageContent.conversation;
  }

  // 2. Extended text message (formatting, link previews, replies)
  if (messageContent.extendedTextMessage && messageContent.extendedTextMessage.text) {
    return messageContent.extendedTextMessage.text;
  }

  // 3. Caption text on media (images, videos, documents)
  if (messageContent.imageMessage && messageContent.imageMessage.caption) {
    return messageContent.imageMessage.caption;
  }
  if (messageContent.videoMessage && messageContent.videoMessage.caption) {
    return messageContent.videoMessage.caption;
  }
  if (messageContent.documentMessage && messageContent.documentMessage.caption) {
    return messageContent.documentMessage.caption;
  }

  // 4. Interactive message types (button clicks / list choices)
  if (messageContent.templateButtonReplyMessage && messageContent.templateButtonReplyMessage.selectedId) {
    return messageContent.templateButtonReplyMessage.selectedId;
  }
  if (messageContent.buttonsResponseMessage && messageContent.buttonsResponseMessage.selectedButtonId) {
    return messageContent.buttonsResponseMessage.selectedButtonId;
  }

  return '';
}

/**
 * Initialize and run the WhatsApp client
 */
async function startWhatsApp() {
  console.log('[WhatsApp] Initializing WhatsApp connection...');

  // Setup multi-file auth state inside 'auth_info' directory to persist session
  const { state, saveCreds } = await useMultiFileAuthState('auth_info');

  // Shared logger instance for the key store caching and the socket logger
  const logger = pino({ level: 'silent' });

  sock = makeWASocket({
    auth: {
      creds: state.creds,
      keys: makeCacheableSignalKeyStore(state.keys, logger),
    },
    printQRInTerminal: false, // We print manually for extra control over formatting
    logger,
    keepAliveIntervalMs: 30000,
    defaultQueryTimeoutMs: 60000,
  });

  // Save auth state changes (credentials, keys, etc.) to file automatically
  sock.ev.on('creds.update', saveCreds);

  // Monitor connection updates (QR code, connect, disconnect)
  sock.ev.on('connection.update', async (update) => {
    const { connection, lastDisconnect, qr } = update;

    // Output QR code in terminal and encode as base64 image data-url for the web dashboard
    if (qr) {
      connectionState = 'qr';
      io.emit('connection-state', 'qr');
      
      try {
        const qrDataUrl = await QRCode.toDataURL(qr);
        lastQrDataUrl = qrDataUrl;
        io.emit('qr', qrDataUrl);
      } catch (err) {
        console.error('[Web Server] Failed to convert QR code to Image Data URL:', err.message);
      }

      console.log('\n======================================================');
      console.log('   SCAN THIS QR CODE WITH WHATSAPP TO CONNECT');
      console.log('======================================================\n');
      qrcode.generate(qr, { small: true });
      console.log('Instructions: Open WhatsApp > Linked Devices > Link a Device.\n');
    }

    if (connection === 'close') {
      const statusCode = lastDisconnect?.error?.output?.statusCode;
      const shouldReconnect = statusCode !== DisconnectReason.loggedOut;
      
      console.log(`[WhatsApp] Connection closed. Reason code: ${statusCode}`);
      
      lastQrDataUrl = null;
      connectionState = shouldReconnect ? 'connecting' : 'close';
      io.emit('connection-state', connectionState);

      if (shouldReconnect) {
        console.log('[WhatsApp] Reconnecting in 5 seconds...');
        setTimeout(() => startWhatsApp(), 5000);
      } else {
        console.log('[WhatsApp] Logged out. Session files inside "auth_info/" are invalid.');
        console.log('[WhatsApp] Please delete the "auth_info" directory to scan a new QR code.');
      }
    } else if (connection === 'open') {
      lastQrDataUrl = null;
      connectionState = 'open';
      io.emit('connection-state', 'open');

      console.log('\n======================================================');
      console.log('   WHATSAPP LISTENER CONNECTED SUCCESSFULLY');
      console.log('======================================================\n');
    }
  });

  // Listen to incoming/outgoing messages event
  sock.ev.on('messages.upsert', async (m) => {
    // Only capture new notifications/messages (ignore history syncs)
    if (m.type !== 'notify') return;

    for (const msg of m.messages) {
      try {
        const remoteJid = msg.key.remoteJid;
        if (!remoteJid || remoteJid === 'status@broadcast') continue;

        // Only listen to direct individual chats, ignore group messages (@g.us) and broadcasts
        const isIndividualChat = remoteJid.endsWith('@s.whatsapp.net') || remoteJid.endsWith('@lid');
        if (!isIndividualChat) continue;

        let senderNumber = remoteJid.split('@')[0];

        // Resolve real phone number for @lid (internal identifier) contacts
        if (remoteJid.endsWith('@lid')) {
          const realJid = msg.key.remoteJidAlt || msg.key.participantPn || msg.key.participantAlt || msg.key.participant;
          if (realJid) {
            senderNumber = realJid.split('@')[0];
          } else {
            console.log(`[WhatsApp] Note: Sender number is an internal ID (${senderNumber}), not a real phone number.`);
          }
        }

        // If message is sent by ourselves
        if (msg.key.fromMe) {
          // If the message was sent by the CRM, ignore it since it is already logged by PHP send_reply
          if (crmSentMessageIds.has(msg.key.id)) {
            continue;
          }
        }

        // Check if message contains media
        const imageMsg = msg.message.imageMessage || 
                         (msg.message.viewOnceMessage && msg.message.viewOnceMessage.message && msg.message.viewOnceMessage.message.imageMessage) ||
                         (msg.message.viewOnceMessageV2 && msg.message.viewOnceMessageV2.message && msg.message.viewOnceMessageV2.message.imageMessage);
        
        const documentMsg = msg.message.documentMessage;
        const audioMsg = msg.message.audioMessage;

        const hasMedia = imageMsg || documentMsg || audioMsg;

        // Extract message text
        let messageText = getMessageText(msg);

        // Skip empty messages without media (e.g. system alerts)
        if (!messageText.trim() && !hasMedia) continue;

        let mediaPath = '';
        let mediaType = 'none';
        let originalFilename = '';
        let mediaDuration = 0;

        if (hasMedia) {
          if (imageMsg) {
            mediaType = 'image';
          } else if (documentMsg) {
            mediaType = 'document';
            originalFilename = documentMsg.fileName || 'document.pdf';
          } else if (audioMsg) {
            mediaType = audioMsg.ptt ? 'voice' : 'audio';
            mediaDuration = Math.round(audioMsg.seconds || 0);
          }

          try {
            console.log(`[WhatsApp] Media message (${mediaType}) detected. Downloading...`);
            const buffer = await downloadMediaMessage(msg, 'buffer', {});
            if (buffer) {
              const randomStr = crypto.randomBytes(6).toString('hex');
              let filename = '';
              if (mediaType === 'image') {
                filename = `${Date.now()}_${randomStr}.jpg`;
              } else if (mediaType === 'document') {
                const ext = path.extname(originalFilename) || '.pdf';
                // Sanitize original filename
                originalFilename = originalFilename.replace(/[^a-zA-Z0-9.\-_]/g, '_');
                filename = `${Date.now()}_${randomStr}${ext}`;
              } else if (mediaType === 'voice' || mediaType === 'audio') {
                const mimeType = audioMsg.mimetype || 'audio/ogg';
                let ext = '.ogg';
                if (mimeType.includes('audio/mpeg') || mimeType.includes('mp3')) {
                  ext = '.mp3';
                } else if (mimeType.includes('audio/mp4') || mimeType.includes('m4a')) {
                  ext = '.m4a';
                }
                originalFilename = audioMsg.fileName || (mediaType === 'voice' ? 'voice_note' + ext : 'audio' + ext);
                filename = `${Date.now()}_${randomStr}${ext}`;
              }

              const mediaDir = 'C:\\xampp\\htdocs\\travel_crm\\uploads\\whatsapp_media';
              if (!fs.existsSync(mediaDir)) {
                fs.mkdirSync(mediaDir, { recursive: true });
              }
              const fullPath = path.join(mediaDir, filename);
              fs.writeFileSync(fullPath, buffer);
              console.log(`[WhatsApp] Media saved to: ${fullPath}`);
              
              mediaPath = `uploads/whatsapp_media/${filename}`;
              
              if (!messageText.trim()) {
                if (mediaType === 'image') {
                  messageText = '[Image]';
                } else if (mediaType === 'document') {
                  messageText = `[Document: ${originalFilename}]`;
                } else if (mediaType === 'voice') {
                  messageText = '[Voice Note]';
                } else if (mediaType === 'audio') {
                  messageText = '[Audio File]';
                }
              }
            }
          } catch (err) {
            console.error('[WhatsApp] Failed to download media:', err.message);
          }
        }

        console.log(`[WhatsApp] Processing message. fromMe: ${msg.key.fromMe}, Number: ${senderNumber}, Content: "${messageText}", MediaPath: "${mediaPath}", MediaType: "${mediaType}"`);

        // Prepend new message to memory cache for real-time web viewers
        const timestampSec = msg.messageTimestamp;
        const receivedAt = timestampSec ? new Date(Number(timestampSec) * 1000) : new Date();
        const webMsg = {
          senderNumber,
          messageText,
          timestamp: receivedAt.getTime(),
          pushName: msg.key.fromMe ? '' : (msg.pushName || ''),
          direction: msg.key.fromMe ? 'outgoing_manual' : 'incoming',
          mediaPath: mediaPath || '',
          mediaType: mediaType,
          originalFilename: originalFilename,
          mediaDuration: mediaDuration
        };
        recentMessages.unshift(webMsg);
        if (recentMessages.length > 50) recentMessages.pop();

        // Broadcast the message to all browser dashboards
        io.emit('new-message', webMsg);

        // For incoming messages ONLY, send real-time enquiry/notification webhook to PHP
        if (!msg.key.fromMe) {
          await sendEnquiryToPhp(senderNumber, messageText, msg.pushName || '', mediaPath, mediaType, originalFilename, mediaDuration);
        }

      } catch (err) {
        console.error('[WhatsApp] Error processing message:', err);
      }
    }
  });
}

// Start sequence
(async () => {
  // Start the WhatsApp listener
  await startWhatsApp();
})();
