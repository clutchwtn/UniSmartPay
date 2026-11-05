# Optional Node.js RFID serial bridge

`node_rfid_server.js` is an optional bridge for reading an ESP32 serial port
and broadcasting received lines to browser clients with Socket.IO. It is not
required for the direct PHP payment flow in `unismart_pay_terminal.ino`.

## Requirements

- Node.js 14 or newer.
- An ESP32 connected by USB.
- The repository's PHP application running if database logging is enabled.

## Install

From the repository root:

```bash
npm install express socket.io serialport
```

If no `package.json` exists yet, npm creates one during installation. Keep
dependency versions compatible with the installed Node.js version.

## Start

```bash
node node_rfid_server.js
```

The web console is served at:

```text
http://localhost:3000
```

The status endpoint is:

```bash
curl http://localhost:3000/api/status
```

Use the web console to select a serial port such as `COM3`, `/dev/ttyUSB0` or
`/dev/ttyACM0`. The serial speed should match the ESP32 sketch: 115200 baud.

## Data flow

```text
ESP32 USB serial
       |
       v
Node.js bridge
       |
       +--> Socket.IO browser clients
       |
       +--> optional PHP terminal log API
```

The bridge is for monitoring and integration experiments. The supported payment
flow is still handled directly by the ESP32 and PHP APIs.

## Troubleshooting

**Serial port missing:** reconnect the USB cable, install the board's USB
driver and check `COM3`, `/dev/ttyUSB0` or `/dev/ttyACM0`.

**Permission denied on Linux:** add your user to the serial device group, then
log in again. Avoid running the whole application as root.

**Port already in use:** close Arduino IDE's Serial Monitor and any other
serial client.

**PHP logs unavailable:** verify the PHP URL configured in
`node_rfid_server.js`, Apache, MySQL and the terminal log endpoint.
