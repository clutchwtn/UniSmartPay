# Report and presentation notes

This file is a content guide for preparing a project report or presentation.
It does not describe runtime installation.

## Suggested report structure

1. **Problem and objectives**
   - University payment use case.
   - Student, cafeteria and terminal requirements.
2. **System design**
   - PHP/MySQL web application.
   - ESP32, RC522 and local network.
   - RESTO and BUVETTE payment flows.
3. **Implementation**
   - Database entities and transaction handling.
   - RFID UID verification.
   - Web and terminal logging.
4. **Testing**
   - Successful payment.
   - Unknown or inactive card.
   - Insufficient balance.
   - Expired or missing cafeteria order.
   - Network failure.
5. **Limitations and future work**
   - Terminal authentication, HTTPS and offline recovery.

## Recommended evidence

- Application home and login screens.
- Student balance and transaction history.
- Product scan and cafeteria cart.
- Terminal mode selection.
- ESP32 wiring and Serial Monitor output.
- A successful payment record and its server log.

Do not include real passwords, Wi-Fi credentials, card secrets or personal
student data in screenshots or submitted documents. Replace them with test
values.

## Build note

The repository currently contains the application source, ESP32 sketch and
documentation. If separate LaTeX report or slide source is added later, document
its exact build command here and keep generated PDFs out of the source tree
unless they are explicitly required for submission.
