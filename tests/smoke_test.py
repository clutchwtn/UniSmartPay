#!/usr/bin/env python3
"""
UniSmartPay smoke + security tests. Standard library only.

  python tests/smoke_test.py               # run everything
  python tests/smoke_test.py --only func   # functional tests only
  python tests/smoke_test.py --only sec    # security checks only

Functional tests  = the app still works (must always PASS; exit code 1 if any fail).
Security checks   = audit findings. "OPEN" means the hole is still there.
                    Each one names the fix number from the audit list.

Plays the ESP32 terminal itself (HTTP + X-Terminal-Token), so no device is needed.
Run against a fresh dev database:  docker compose down -v && docker compose up -d --build

Settings come from environment variables (defaults = the current seed data).
After fix #1 changes the tokens / admin password, set them, e.g. in Git Bash:
  export RESTO_TOKEN=... BUVETTE_TOKEN=... ADMIN_PASS=...
"""

import http.cookiejar
import json
import os
import random
import re
import sys
import urllib.error
import urllib.parse
import urllib.request

ROOT = os.environ.get("APP_ROOT", "http://localhost:8080/UniSmartPay").rstrip("/")
BASE = ROOT + "/web"

RESTO_TOKEN = os.environ.get("RESTO_TOKEN", "TOKEN_RESTO_FST_001")
BUVETTE_TOKEN = os.environ.get("BUVETTE_TOKEN", "TOKEN_BUVETTE_FST_001")
RESTO_TERMINAL = int(os.environ.get("RESTO_TERMINAL", "1"))
BUVETTE_TERMINAL = int(os.environ.get("BUVETTE_TERMINAL", "2"))
ADMIN_EMAIL = os.environ.get("ADMIN_EMAIL", "admin@unismart.tn")
ADMIN_PASS = os.environ.get("ADMIN_PASS", "Admin@1234")
STUDENT_MAT = os.environ.get("STUDENT_MAT", "TEST/RFID/0001")
STUDENT_PASS = os.environ.get("STUDENT_PASS", "Etudiant@1234")
CARD_UID = os.environ.get("CARD_UID", "9FF79DC2")
PRODUCT_ID = int(os.environ.get("PRODUCT_ID", "1"))
PRODUCT_PRICE = float(os.environ.get("PRODUCT_PRICE", "1.00"))
RESTO_PRICE = 3.50

SEED_TOKEN = "TOKEN_RESTO_FST_001"
SEED_ADMIN = ("admin@unismart.tn", "Admin@1234")


# ---------------------------------------------------------------- HTTP client
class _NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


class Resp:
    def __init__(self, status, headers, body):
        self.status = status
        self.headers = headers
        self.text = body

    def json(self):
        try:
            return json.loads(self.text)
        except ValueError:
            return {}

    @property
    def location(self):
        return self.headers.get("Location", "") if self.headers else ""


class Client:
    """One browser-like session (own cookie jar). Never follows redirects."""

    def __init__(self):
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(self.jar), _NoRedirect()
        )

    def request(self, method, url, *, form=None, data=None, headers=None):
        hdrs = dict(headers or {})
        body = None
        if form is not None:
            body = urllib.parse.urlencode(form).encode()
            hdrs["Content-Type"] = "application/x-www-form-urlencoded"
        elif data is not None:
            body = json.dumps(data).encode()
            hdrs["Content-Type"] = "application/json"
        req = urllib.request.Request(url, data=body, headers=hdrs, method=method)
        try:
            with self.opener.open(req, timeout=15) as r:
                return Resp(r.status, r.headers, r.read().decode("utf-8", "replace"))
        except urllib.error.HTTPError as e:
            return Resp(e.code, e.headers, e.read().decode("utf-8", "replace"))

    def get(self, url, **kw):
        return self.request("GET", url, **kw)

    def post(self, url, **kw):
        return self.request("POST", url, **kw)


def terminal(token):
    return {"X-Terminal-Token": token} if token else {}


def csrf_from(html):
    m = re.search(r'name="csrf_token" value="([0-9a-f]+)"', html) or re.search(
        r'const csrfToken = "([0-9a-f]+)"', html
    )
    return m.group(1) if m else ""


def login_student(matricule=STUDENT_MAT, password=STUDENT_PASS):
    c = Client()
    page = c.get(BASE + "/etudiant/login.php")
    r = c.post(
        BASE + "/etudiant/login.php",
        form={"csrf_token": csrf_from(page.text), "matricule": matricule, "password": password},
    )
    return c, (r.status in (302, 303) and "dashboard" in r.location)


def login_admin(email=ADMIN_EMAIL, password=ADMIN_PASS):
    c = Client()
    page = c.get(BASE + "/admin/login.php")
    r = c.post(
        BASE + "/admin/login.php",
        form={"csrf_token": csrf_from(page.text), "email": email, "password": password},
    )
    return c, (r.status in (302, 303) and "dashboard" in r.location)


def admin_recharge(admin, matricule, amount):
    page = admin.get(BASE + "/admin/dashboard.php")
    r = admin.post(
        BASE + "/admin/dashboard.php",
        form={
            "csrf_token": csrf_from(page.text),
            "action": "recharge",
            "matricule": matricule,
            "montant": f"{amount:.2f}",
            "methode": "ESPECES",
            "note": "smoke test",
        },
    )
    return "Recharge r" in r.text


def card_balance(token=RESTO_TOKEN):
    r = Client().post(BASE + "/api/card_check.php", data={"uid": CARD_UID}, headers=terminal(token))
    return r, r.json().get("solde")


def fail_order(order_id, terminal_id, token):
    """Pay an order with a card that doesn't exist -> server marks it ECHEC (clears the queue)."""
    Client().post(
        BASE + "/api/terminal_order_pay.php",
        data={"order_id": order_id, "terminal_id": terminal_id, "card_uid": "00000000"},
        headers=terminal(token),
    )


# ---------------------------------------------------------------- test runner
results = []  # (group, name, outcome, detail)


def check(group, name, ok, detail=""):
    if group == "func":
        outcome = "PASS" if ok else "FAIL"
    else:
        outcome = "FIXED" if ok else "OPEN"
    results.append((group, name, outcome, detail))
    colour = {"PASS": "32", "FIXED": "32", "FAIL": "31", "OPEN": "33"}[outcome]
    tag = f"\033[{colour}m{outcome:5}\033[0m"
    print(f"  {tag} {name}" + (f"  -- {detail}" if detail and outcome in ("FAIL", "OPEN") else ""))


def guarded(group, name, fn):
    try:
        fn()
    except Exception as e:  # a crashing test counts as a failure, not a crash of the suite
        check(group, name, False, f"exception: {e!r}")


# ---------------------------------------------------------------- functional
def functional():
    print("\nFunctional (must stay green after every fix)")

    def t_reachable():
        r = Client().get(BASE + "/etudiant/login.php")
        check("func", "app reachable (student login page)", r.status == 200, f"HTTP {r.status} at {BASE}")
        if r.status != 200:
            print(f"\n  App not reachable at {BASE}. Is Docker running?  docker compose up -d --build")
            sys.exit(2)

    t_reachable()

    def t_card_check():
        r, solde = card_balance()
        check("func", "terminal card_check with valid token", r.status == 200 and solde is not None,
              f"HTTP {r.status} {r.text[:120]}")

    def t_card_check_no_token():
        r = Client().post(BASE + "/api/card_check.php", data={"uid": CARD_UID})
        check("func", "terminal card_check without token is rejected", r.status == 401, f"HTTP {r.status}")

    def t_student_login():
        c, ok = login_student()
        check("func", "student login", ok)
        if ok:
            pages = ["dashboard.php", "historique.php", "carte.php"]
            bad = [p for p in pages if c.get(BASE + "/etudiant/" + p).status != 200]
            check("func", "student pages load (dashboard, history, card)", not bad, f"failed: {bad}")

    def t_admin_login():
        _, ok = login_admin()
        check("func", "admin login", ok, f"as {ADMIN_EMAIL} (set ADMIN_PASS after fix #1)")

    def t_resto_payment():
        _, before = card_balance()
        r = Client().post(
            BASE + "/api/payment.php",
            data={"card_uid": CARD_UID, "amount": RESTO_PRICE, "terminal_id": RESTO_TERMINAL},
            headers=terminal(RESTO_TOKEN),
        )
        body = r.json()
        ok = r.status == 200 and body.get("success") is True
        check("func", "resto payment from terminal (payment.php)", ok, f"HTTP {r.status} {r.text[:150]}")
        if ok and before is not None:
            check("func", "resto payment debits the right amount",
                  abs(float(body["solde_apres"]) - (before - RESTO_PRICE)) < 0.001,
                  f"before={before} after={body['solde_apres']}")
        if ok:
            ref = str(body.get("reference", ""))
            check("func", "payment reference is timestamp + random part",
                  re.fullmatch(r"\d{14}-[0-9A-F]{8}", ref) is not None, f"reference={ref!r}")
        admin, logged = login_admin()
        if ok and logged:
            check("func", "admin recharge (restores test balance)", admin_recharge(admin, STUDENT_MAT, RESTO_PRICE))

    def t_buvette_flow():
        admin, logged = login_admin()
        if not logged:
            check("func", "cafe order flow", False, "admin login failed")
            return
        page = admin.get(BASE + f"/terminal/buvette.php?terminal_id={BUVETTE_TERMINAL}")
        csrf = csrf_from(page.text)
        r = admin.post(
            BASE + "/api/terminal_order_create_buvette.php",
            data={"csrf_token": csrf, "terminal_id": BUVETTE_TERMINAL,
                  "items": [{"id_produit": PRODUCT_ID, "quantite": 1}]},
        )
        order_id = r.json().get("order_id")
        check("func", "cafe: cashier creates order", bool(order_id), f"HTTP {r.status} {r.text[:150]}")
        if not order_id:
            return

        # Claim as the terminal. Older leftover orders get failed so the queue reaches ours.
        claimed = None
        for _ in range(10):
            cr = Client().get(BASE + f"/api/terminal_order_claim.php?terminal_id={BUVETTE_TERMINAL}",
                              headers=terminal(BUVETTE_TOKEN))
            o = cr.json().get("order") or {}
            if not o:
                break
            if o.get("order_id") == order_id:
                claimed = o
                break
            fail_order(o["order_id"], BUVETTE_TERMINAL, BUVETTE_TOKEN)
        check("func", "cafe: terminal claims the order", claimed is not None)
        if not claimed:
            return

        pr = Client().post(
            BASE + "/api/terminal_order_pay.php",
            data={"order_id": order_id, "terminal_id": BUVETTE_TERMINAL, "card_uid": CARD_UID},
            headers=terminal(BUVETTE_TOKEN),
        )
        paid = pr.json().get("ok") is True
        check("func", "cafe: terminal pays the order with the card", paid, f"HTTP {pr.status} {pr.text[:150]}")

        st = admin.get(BASE + f"/api/terminal_order_status.php?order_id={order_id}")
        statut = (st.json().get("order") or {}).get("statut")
        check("func", "cafe: order status shows PAYE", statut == "PAYE", f"statut={statut} HTTP {st.status}")
        if paid:
            admin_recharge(admin, STUDENT_MAT, PRODUCT_PRICE)

    for name, fn in [
        ("card_check", t_card_check),
        ("card_check no token", t_card_check_no_token),
        ("student login", t_student_login),
        ("admin login", t_admin_login),
        ("resto payment", t_resto_payment),
        ("cafe flow", t_buvette_flow),
    ]:
        guarded("func", name, fn)


# ---------------------------------------------------------------- security
def security(include_lockout):
    print("\nSecurity checks (OPEN = still vulnerable; fix number from the audit list)")

    def s1_seed_token():
        r = Client().post(BASE + "/api/card_check.php", data={"uid": CARD_UID}, headers=terminal(SEED_TOKEN))
        check("sec", "#1  public seed terminal token is rejected", r.status == 401, f"{SEED_TOKEN} -> HTTP {r.status}")

    def s1_seed_admin():
        _, ok = login_admin(*SEED_ADMIN)
        check("sec", "#1  seed admin password Admin@1234 no longer works", not ok)

    def s2_logs():
        r = Client().get(BASE + f"/api/terminal_logs.php?terminal_id={RESTO_TERMINAL}&limit=50")
        leaked = r.status == 200 and '"logs"' in r.text
        check("sec", "#2  terminal_logs needs login", not leaked, f"anonymous got HTTP {r.status}")
        admin, ok = login_admin()
        if ok:
            r = admin.get(BASE + f"/api/terminal_logs.php?terminal_id={RESTO_TERMINAL}&limit=50")
            full = re.search(r'"uid_carte":"(?:[0-9A-F]{2}:?){4,}"', r.text)
            check("sec", "#2  terminal_logs masks card UIDs", not full, "full UID visible to admin")

    def s3_anon_order():
        c = Client()
        csrf = csrf_from(c.get(BASE + "/etudiant/login.php").text)  # anonymous visitor's token
        r = c.post(
            BASE + "/api/terminal_order_create_buvette.php",
            data={"csrf_token": csrf, "terminal_id": BUVETTE_TERMINAL,
                  "items": [{"id_produit": PRODUCT_ID, "quantite": 1}]},
        )
        order_id = r.json().get("order_id")
        check("sec", "#3  anonymous visitor cannot queue cafe orders", not order_id, f"created order {order_id}")
        if order_id:  # clean up so the injected order doesn't sit in the queue
            Client().get(BASE + f"/api/terminal_order_claim.php?terminal_id={BUVETTE_TERMINAL}",
                         headers=terminal(BUVETTE_TOKEN))
            fail_order(order_id, BUVETTE_TERMINAL, BUVETTE_TOKEN)

    def s4_files():
        paths = [".env", ".env.example", "database/unismart_pay_schema.sql",
                 "esp32/unismart_pay_terminal.ino", "docker-compose.yml"]
        exposed = [p for p in paths if Client().get(f"{ROOT}/{p}").status == 200]
        check("sec", "#4  repo files (.env, schema, firmware) not served", not exposed, f"served: {exposed}")

    def s6_cookie():
        r = Client().get(BASE + "/etudiant/login.php")
        cookies = r.headers.get_all("Set-Cookie") or []
        sess = next((ck for ck in cookies if ck.startswith("PHPSESSID")), "")
        low = sess.lower()
        check("sec", "#6  session cookie has HttpOnly + SameSite",
              "httponly" in low and "samesite" in low, sess or "no PHPSESSID cookie")

    def s7_status():
        r = Client().get(BASE + "/api/terminal_order_status.php?order_id=1")
        check("sec", "#7  order status needs login", r.status in (401, 403, 302), f"anonymous got HTTP {r.status}")

    def s11_no_card():
        admin, ok = login_admin()
        if not ok:
            check("sec", "#11 student without a card cannot pay", False, "admin login failed, skipped")
            return
        mat = f"SMOKE/NOCARD/{random.randint(100000, 999999)}"
        page = admin.get(BASE + "/admin/dashboard.php")
        admin.post(BASE + "/admin/dashboard.php", form={
            "csrf_token": csrf_from(page.text), "action": "add_student", "id_faculte": "1",
            "matricule": mat, "nom": "Smoke", "prenom": "NoCard",
            "email": f"{mat.replace('/', '').lower()}@example.test", "password": "Smoke@12345",
        })
        admin_recharge(admin, mat, 5)
        stu, ok = login_student(mat, "Smoke@12345")
        if not ok:
            check("sec", "#11 student without a card cannot pay", False, "could not create/login test student")
            return
        csrf = csrf_from(stu.get(BASE + "/etudiant/paiement_buvette.php").text)
        r = stu.post(BASE + "/api/buvette_checkout.php",
                     data={"csrf_token": csrf, "items": [{"id_produit": PRODUCT_ID, "quantite": 1}]})
        check("sec", "#11 student without a card cannot pay", r.json().get("ok") is not True,
              f"card-less student {mat} paid")

    def p1_server_price():
        _, before = card_balance()
        r = Client().post(BASE + "/api/payment.php",
                          data={"card_uid": CARD_UID, "amount": 50, "terminal_id": RESTO_TERMINAL},
                          headers=terminal(RESTO_TOKEN))
        body = r.json()
        if body.get("success") is True:
            charged = round(before - float(body["solde_apres"]), 3) if before is not None else None
            admin, ok = login_admin()
            if ok and charged:
                admin_recharge(admin, STUDENT_MAT, charged)  # restore the test balance
            check("sec", "P1  terminal cannot choose the resto price", charged == RESTO_PRICE,
                  f"asked 50, charged {charged}")
        else:
            check("sec", "P1  terminal cannot choose the resto price", False, f"payment failed: {r.text[:120]}")

    def p2_buvette_terminal_resto():
        r = Client().post(BASE + "/api/payment.php",
                          data={"card_uid": CARD_UID, "amount": 1, "terminal_id": BUVETTE_TERMINAL},
                          headers=terminal(BUVETTE_TOKEN))
        check("sec", "P2  cafe terminal cannot charge through payment.php", r.json().get("success") is not True,
              "a BUVETTE terminal charged a resto payment")

    def p3_removed_endpoints():
        # Missing URLs fall back to the home page (web/.htaccess), so check that
        # no JSON API answers there anymore rather than expecting a 404.
        served = [p for p in ("terminal/paiement.php", "terminal/solde.php")
                  if "json" in (Client().post(BASE + "/api/" + p, data={}).headers or {}).get("Content-Type", "")]
        check("sec", "P3  unused terminal endpoints are removed", not served, f"still served: {served}")

    def s9_lockout():
        c = Client()
        for _ in range(6):
            csrf = csrf_from(c.get(BASE + "/etudiant/login.php").text)
            c.post(BASE + "/etudiant/login.php",
                   form={"csrf_token": csrf, "matricule": STUDENT_MAT, "password": "wrong-password"})
        _, ok = login_student()
        check("sec", "#9  login locks after repeated failures", not ok,
              "6 bad passwords, then correct one still logged in")

    checks = [s1_seed_token, s1_seed_admin, s2_logs, s3_anon_order, s4_files, s6_cookie, s7_status, s11_no_card,
              p1_server_price, p2_buvette_terminal_resto, p3_removed_endpoints]
    for fn in checks:
        guarded("sec", fn.__name__, fn)
    if include_lockout:
        guarded("sec", "s9_lockout", s9_lockout)
    else:
        print("  skip  #9  lockout (run with --lockout; it locks the test student for a while)")
    print("  manual #5 Node server, #8 prod defaults, #10 rehash, #12-#14: check by reading the diff")


# ---------------------------------------------------------------- main
def main():
    args = sys.argv[1:]
    only = args[args.index("--only") + 1] if "--only" in args else None
    print(f"Target: {BASE}")
    if only in (None, "func"):
        functional()
    if only in (None, "sec"):
        security("--lockout" in args)

    fails = sum(1 for g, _, o, _ in results if o == "FAIL")
    open_ = sum(1 for g, _, o, _ in results if o == "OPEN")
    passed = sum(1 for g, _, o, _ in results if o == "PASS")
    fixed = sum(1 for g, _, o, _ in results if o == "FIXED")
    print(f"\nFunctional: {passed} pass, {fails} fail   |   Security: {fixed} fixed, {open_} open")
    sys.exit(1 if fails else 0)


if __name__ == "__main__":
    main()
